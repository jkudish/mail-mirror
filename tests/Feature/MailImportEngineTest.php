<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Jkudish\MailMirror\Contracts\MailboxReader;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Enums\MailImportStage;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\StaleCheckpoint;
use Jkudish\MailMirror\Import\MailImportEngine;
use Jkudish\MailMirror\Import\ReconciliationService;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAddress;
use Jkudish\MailMirror\Models\MailAttachment;
use Jkudish\MailMirror\Models\MailContainer;
use Jkudish\MailMirror\Models\MailImportError;
use Jkudish\MailMirror\Models\MailInventoryItem;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailMessageContainerMembership;
use Jkudish\MailMirror\Models\MailMessageHeader;
use Jkudish\MailMirror\Models\MailMessageParticipant;
use Jkudish\MailMirror\Models\MailRawObject;
use Jkudish\MailMirror\Models\MailReconciliationReport;
use Jkudish\MailMirror\Models\MailSyncCheckpoint;
use Jkudish\MailMirror\Models\MailThread;
use Jkudish\MailMirror\Read\InventoryPage;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MailReadService;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\ProviderDeletionEvidence;
use Jkudish\MailMirror\Read\RawMessageSource;
use Jkudish\MailMirror\Read\RetrievedMessage;
use Jkudish\MailMirror\Storage\MailObjectStorage;

final class DeterministicImportReader implements MailboxReader
{
    /** @var array<string, InventoryPage> */
    public array $pages = [];

    /** @var array<string, int> */
    public array $attempts = [];

    /** @var array<string, Closure(MessageReference, int): RetrievedMessage> */
    public array $retrievers = [];

    /** @var list<string|null> */
    public array $requestedCursors = [];

    /** @var Closure(): void|null */
    public ?Closure $onInventory = null;

    public function driver(): MailDriver
    {
        return MailDriver::Gmail;
    }

    public function inventoryPage(MailAccount $account, ?string $cursor): InventoryPage
    {
        $this->requestedCursors[] = $cursor;
        ($this->onInventory) ? ($this->onInventory)() : null;
        $this->onInventory = null;

        return $this->pages[$cursor ?? 'start'];
    }

    public function retrieve(MailAccount $account, MessageReference $message): RetrievedMessage
    {
        $attempt = ($this->attempts[$message->providerMessageId] ?? 0) + 1;
        $this->attempts[$message->providerMessageId] = $attempt;

        if (isset($this->retrievers[$message->providerMessageId])) {
            return ($this->retrievers[$message->providerMessageId])($message, $attempt);
        }

        return new RetrievedMessage(
            $message,
            'Invented subject '.$message->providerMessageId,
            '<'.$message->providerMessageId.'@invented.test>',
            providerMetadata: ['invented_state' => 'stable'],
        );
    }
}

function importAccount(string $providerId = 'synthetic-import-account', string $ownerId = 'owner-one'): MailAccount
{
    return MailAccount::query()->create([
        'owner_type' => 'synthetic-owner',
        'owner_id' => $ownerId,
        'driver' => MailDriver::Gmail,
        'provider_account_id' => $providerId,
    ]);
}

/** @param array<string, mixed> $metadata */
function reference(MailAccount $account, string $id, array $metadata = []): MessageReference
{
    return new MessageReference($account->id, MailDriver::Gmail, $id, providerMetadata: $metadata);
}

function importEngine(DeterministicImportReader $reader): MailImportEngine
{
    $registry = new MailDriverRegistry;
    $registry->register(MailDriver::Gmail, $reader);

    return new MailImportEngine(new MailReadService($registry), new ReconciliationService, app(MailObjectStorage::class));
}

it('imports paginated duplicate delivery idempotently and converges on a second unchanged scan', function (): void {
    $account = importAccount();
    $reader = new DeterministicImportReader;
    $reader->pages = [
        'start' => new InventoryPage([reference($account, 'message-a')], 'opaque-page-2', false),
        'opaque-page-2' => new InventoryPage([reference($account, 'message-a'), reference($account, 'message-b')], null, true),
    ];
    $engine = importEngine($reader);

    $first = $engine->sync($account);
    $second = $engine->sync($account);

    expect($first)->not->toBeNull()
        ->and($first?->mirrored_count)->toBe(2)
        ->and($first?->unexplained_missing_count)->toBe(0)
        ->and($first?->unexpected_active_count)->toBe(0)
        ->and($second?->unexplained_missing_count)->toBe(0)
        ->and($second?->unexpected_active_count)->toBe(0)
        ->and(MailMessage::query()->forAccount($account)->count())->toBe(2)
        ->and(MailInventoryItem::query()->forAccount($account)->count())->toBe(2)
        ->and(MailImportError::query()->count())->toBe(0);
});

it('resumes from the cursor committed after durable page work when interrupted', function (): void {
    $account = importAccount('crash-resume');
    $reader = new DeterministicImportReader;
    $reader->pages = [
        'start' => new InventoryPage([reference($account, 'message-a')], 'opaque-resume', false),
        'opaque-resume' => new InventoryPage([reference($account, 'message-b')], null, true),
    ];
    $engine = importEngine($reader);

    expect(fn () => $engine->sync($account, afterDurablePage: function (): void {
        throw new RuntimeException('Synthetic process interruption after commit.');
    }))->toThrow(RuntimeException::class, 'Synthetic process interruption');

    expect(MailMessage::query()->forAccount($account)->pluck('provider_message_id')->all())->toBe(['message-a'])
        ->and(MailSyncCheckpoint::query()->forAccount($account)->value('provider_cursor'))->toBe('opaque-resume');

    $report = $engine->sync($account);

    expect($reader->requestedCursors)->toBe([null, 'opaque-resume'])
        ->and($report?->mirrored_count)->toBe(2)
        ->and(MailMessage::query()->forAccount($account)->count())->toBe(2);
});

it('advances the checkpoint only after raw object storage and database work are durable', function (): void {
    Storage::fake('import-objects');
    config()->set('mail-mirror.storage_disk', 'import-objects');
    $account = importAccount('storage-ordering');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([reference($account, 'message-with-raw')], null, true)];
    $reader->retrievers['message-with-raw'] = function (MessageReference $message): RetrievedMessage {
        $stream = fopen('php://temp', 'w+b');
        assert(is_resource($stream));
        fwrite($stream, "From: invented@invented.test\r\nMessage-ID: <raw@invented.test>\r\n\r\nInvented body.");
        rewind($stream);

        return new RetrievedMessage(
            $message,
            'Invented raw message',
            rawSource: new RawMessageSource($stream, 'provider-raw-synthetic-001'),
        );
    };
    $observed = false;

    importEngine($reader)->sync($account, afterDurablePage: function (MailSyncCheckpoint $checkpoint) use (&$observed): void {
        $raw = MailRawObject::query()->forAccount($checkpoint->mail_account_id)->firstOrFail();
        Storage::disk('import-objects')->assertExists((string) $raw->object_key);
        expect($checkpoint->scan_completed_at)->not->toBeNull();
        $observed = true;
    });

    expect($observed)->toBeTrue();
});

it('bounds retryable failures and records only sparse content-safe errors', function (): void {
    $account = importAccount('retry-rate-limit');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([reference($account, 'rate-limited')], null, true)];
    $reader->retrievers['rate-limited'] = function (MessageReference $message, int $attempt): RetrievedMessage {
        if ($attempt < 3) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::RateLimited, retryable: true);
        }

        return new RetrievedMessage($message, 'Invented recovered message');
    };

    $report = importEngine($reader)->sync($account);

    expect($reader->attempts['rate-limited'])->toBe(3)
        ->and($report?->mirrored_count)->toBe(1)
        ->and(MailImportError::query()->count())->toBe(0);
});

it('classifies malformed payloads without persisting provider content or secrets', function (): void {
    $account = importAccount('malformed');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([reference($account, 'malformed-message')], null, true)];
    $reader->retrievers['malformed-message'] = fn (MessageReference $message): RetrievedMessage => new RetrievedMessage(
        new MessageReference($message->mailAccountId, $message->driver, 'wrong-message'),
        'Secret-like invented payload must not persist',
    );

    $report = importEngine($reader)->sync($account);
    $error = MailImportError::query()->firstOrFail();

    expect($report?->transient_error_count)->toBe(1)
        ->and($error->code)->toBe('malformed_payload')
        ->and($error->summary)->toBe('The provider returned a malformed message payload.')
        ->and(json_encode([$report?->toArray(), $error->toArray()]))->not->toContain('Secret-like');
});

it('keeps deletion evidence distinct and reports an unexplained active message without proof', function (): void {
    $account = importAccount('deletion-state');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([
        reference($account, 'message-a', ['state' => 'one']),
        reference($account, 'message-b'),
        reference($account, 'message-c'),
    ], null, true)];
    $engine = importEngine($reader);
    $engine->sync($account);

    $reader->pages = ['start' => new InventoryPage(
        [reference($account, 'message-a', ['state' => 'two'])],
        null,
        true,
        [new ProviderDeletionEvidence($account->id, 'message-b', 'provider_tombstone', 'audit-synthetic-001')],
    )];
    $report = $engine->sync($account);

    expect($report?->provider_deleted_count)->toBe(1)
        ->and($report?->unexpected_active_count)->toBe(1)
        ->and($report?->summary['unexpected_active'])->toBe(['sample' => ['message-c'], 'truncated' => false])
        ->and(MailInventoryItem::query()->forAccount($account)->where('provider_message_id', 'message-a')->value('provider_metadata'))
        ->toBe(['state' => 'two']);
});

it('resolves a later successful import and supports explicit audited waiver', function (): void {
    $account = importAccount('resolution-waiver');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([
        reference($account, 'eventual-success'), reference($account, 'waived-message'),
    ], null, true)];
    $reader->retrievers['eventual-success'] = $reader->retrievers['waived-message'] = function (): RetrievedMessage {
        throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::SyntheticFailure);
    };
    $engine = importEngine($reader);
    $first = $engine->sync($account);

    MailImportError::query()->where('provider_message_id', 'waived-message')->firstOrFail()
        ->waive('Invented malformed fixture accepted for this corpus.', 'host-audit-opaque-3096');
    unset($reader->retrievers['eventual-success']);
    $second = $engine->sync($account);

    expect($first?->transient_error_count)->toBe(2)
        ->and($second?->transient_error_count)->toBe(0)
        ->and($second?->waived_error_count)->toBe(1)
        ->and(MailImportError::query()->where('provider_message_id', 'eventual-success')->value('resolved_at'))->not->toBeNull()
        ->and(MailImportError::query()->where('provider_message_id', 'waived-message')->value('waiver_audit_reference'))
        ->toBe('host-audit-opaque-3096');
});

it('does not reconcile an incomplete scan and rejects mismatched owner paths without side effects', function (): void {
    $account = importAccount('incomplete-owner');
    $reader = new DeterministicImportReader;
    $reader->pages = [
        'start' => new InventoryPage([reference($account, 'message-a')], 'still-incomplete', false),
        'still-incomplete' => new InventoryPage([reference($account, 'message-b')], null, true),
    ];
    $engine = importEngine($reader);

    expect($engine->sync($account, pageLimit: 1))->toBeNull()
        ->and(MailReconciliationReport::query()->count())->toBe(0)
        ->and(fn () => $engine->syncAccount($account->id, 'synthetic-owner', 'wrong-owner'))
        ->toThrow(AccountResourceMismatch::class)
        ->and(MailMessage::query()->forAccount($account)->count())->toBe(1);
});

it('rolls back all page work when a stale checkpoint is detected', function (): void {
    $account = importAccount('stale-checkpoint');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([reference($account, 'message-a')], null, true)];
    $reader->onInventory = function () use ($account): void {
        MailSyncCheckpoint::query()->forAccount($account)->increment('version');
    };

    expect(fn () => importEngine($reader)->sync($account))->toThrow(StaleCheckpoint::class)
        ->and(MailMessage::query()->count())->toBe(0)
        ->and(MailInventoryItem::query()->count())->toBe(0)
        ->and(MailImportError::query()->count())->toBe(0);
});

it('hydrates the complete provider-neutral graph idempotently and replaces stale state', function (): void {
    $account = importAccount('complete-hydration');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([
        new MessageReference($account->id, MailDriver::Gmail, 'complete-message', 'thread-one'),
    ], null, true)];
    $reader->retrievers['complete-message'] = fn (MessageReference $reference): RetrievedMessage => new RetrievedMessage(
        reference: $reference,
        subject: 'First subject',
        internetMessageId: '<complete@invented.test>',
        headers: [
            ['name' => 'X-Invented', 'value' => 'one'],
            ['name' => 'X-Removed', 'value' => 'stale'],
        ],
        participants: [
            ['role' => 'from', 'address' => 'first@invented.test', 'name' => 'First Invented', 'provider_metadata' => ['native' => 'from-one']],
            ['role' => 'to', 'address' => 'removed@invented.test'],
        ],
        attachments: [
            ['provider_id' => 'attachment-one', 'filename' => 'one.txt', 'media_type' => 'text/plain', 'byte_size' => 12, 'provider_metadata' => ['blob' => 'native-one']],
            ['provider_id' => 'attachment-removed', 'provider_metadata' => ['blob' => 'removed']],
        ],
        containers: [
            ['provider_id' => 'container-one', 'name' => 'First', 'provider_metadata' => ['role' => 'inbox']],
            ['provider_id' => 'container-removed', 'name' => 'Removed', 'provider_metadata' => []],
        ],
        providerMetadata: ['state' => 'one'],
        providerThreadMetadata: ['native_thread' => 'one'],
        sentAt: new DateTimeImmutable('2026-01-02T03:04:05+00:00'),
        receivedAt: new DateTimeImmutable('2026-01-02T03:05:06+00:00'),
    );
    $engine = importEngine($reader);

    $engine->sync($account);
    $engine->sync($account);

    $message = MailMessage::query()->forAccount($account)->firstOrFail();
    $materializedSource = fopen('php://temp', 'w+b');
    assert(is_resource($materializedSource));
    fwrite($materializedSource, 'invented materialized attachment bytes');
    rewind($materializedSource);
    $materialized = app(MailObjectStorage::class)->storeAttachment(
        $account,
        $message,
        $materializedSource,
        'attachment-one',
        'invented-source-part',
        'text/plain',
        'one.txt',
        providerMetadata: ['blob' => 'native-one'],
    );
    fclose($materializedSource);

    expect(MailMessage::query()->forAccount($account)->count())->toBe(1)
        ->and($message->sent_at?->toIso8601String())->toBe('2026-01-02T03:04:05+00:00')
        ->and($message->received_at?->toIso8601String())->toBe('2026-01-02T03:05:06+00:00')
        ->and(MailMessageHeader::query()->forAccount($account)->count())->toBe(2)
        ->and(MailMessageParticipant::query()->forAccount($account)->count())->toBe(2)
        ->and(MailAttachment::query()->forAccount($account)->count())->toBe(2)
        ->and(MailMessageContainerMembership::query()->forAccount($account)->count())->toBe(2);

    $reader->pages = ['start' => new InventoryPage([
        new MessageReference($account->id, MailDriver::Gmail, 'complete-message', 'thread-two'),
    ], null, true)];
    $reader->retrievers['complete-message'] = fn (MessageReference $reference): RetrievedMessage => new RetrievedMessage(
        reference: $reference,
        subject: 'Second subject',
        headers: [['name' => 'X-Invented', 'value' => 'two']],
        participants: [['role' => 'to', 'address' => 'second@invented.test', 'provider_metadata' => ['native' => 'to-two']]],
        attachments: [['provider_id' => 'attachment-two', 'filename' => 'two.txt', 'provider_metadata' => ['blob' => 'native-two']]],
        containers: [['provider_id' => 'container-two', 'name' => 'Second', 'provider_metadata' => ['role' => 'archive']]],
        providerMetadata: ['state' => 'two'],
        providerThreadMetadata: ['native_thread' => 'two'],
        sentAt: new DateTimeImmutable('2026-02-03T04:05:06+00:00'),
        receivedAt: new DateTimeImmutable('2026-02-03T04:06:07+00:00'),
    );
    $engine->sync($account);
    $message = MailMessage::query()->forAccount($account)->firstOrFail();

    expect($message->subject)->toBe('Second subject')
        ->and($message->sent_at?->toIso8601String())->toBe('2026-02-03T04:05:06+00:00')
        ->and($message->received_at?->toIso8601String())->toBe('2026-02-03T04:06:07+00:00')
        ->and($message->provider_metadata)->toBe(['state' => 'two'])
        ->and($message->thread?->provider_thread_id)->toBe('thread-two')
        ->and($message->thread?->provider_metadata)->toBe(['native_thread' => 'two'])
        ->and(MailMessageHeader::query()->forAccount($account)->pluck('value')->all())->toBe(['two'])
        ->and(MailMessageParticipant::query()->forAccount($account)->count())->toBe(1)
        ->and(MailAddress::query()->forAccount($account)->where('address', 'second@invented.test')->exists())->toBeTrue()
        ->and(MailAttachment::query()->forAccount($account)->orderBy('provider_attachment_id')->pluck('provider_attachment_id')->all())
        ->toBe(['attachment-one', 'attachment-two'])
        ->and($materialized->refresh()->source_part_id)->toBe('invented-source-part')
        ->and($materialized->object_key)->not->toBeNull()
        ->and(MailMessageContainerMembership::query()->forAccount($account)->count())->toBe(1)
        ->and(MailContainer::query()->forAccount($account)->where('provider_container_id', 'container-two')->value('provider_metadata'))
        ->toBe(['role' => 'archive']);
});

it('keeps identical provider graph IDs account-qualified', function (): void {
    $first = importAccount('graph-first', 'owner-first');
    $second = importAccount('graph-second', 'owner-second');

    foreach ([$first, $second] as $account) {
        $reader = new DeterministicImportReader;
        $reader->pages = ['start' => new InventoryPage([
            new MessageReference($account->id, MailDriver::Gmail, 'shared-message', 'shared-thread'),
        ], null, true)];
        $reader->retrievers['shared-message'] = fn (MessageReference $reference): RetrievedMessage => new RetrievedMessage(
            reference: $reference,
            subject: 'Account-qualified',
            participants: [['role' => 'from', 'address' => 'shared@invented.test']],
            attachments: [['provider_id' => 'shared-attachment', 'provider_metadata' => []]],
            containers: [['provider_id' => 'shared-container', 'name' => 'Shared', 'provider_metadata' => []]],
        );
        importEngine($reader)->sync($account);
    }

    expect(MailMessage::query()->where('provider_message_id', 'shared-message')->count())->toBe(2)
        ->and(MailThread::query()->where('provider_thread_id', 'shared-thread')->count())->toBe(2)
        ->and(MailAddress::query()->where('address', 'shared@invented.test')->count())->toBe(2)
        ->and(MailAttachment::query()->where('provider_attachment_id', 'shared-attachment')->count())->toBe(2)
        ->and(MailContainer::query()->where('provider_container_id', 'shared-container')->count())->toBe(2);
});

it('expires deletion proof on reappearance and requires fresh proof for a later disappearance', function (): void {
    $account = importAccount('deletion-generation');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([reference($account, 'message-a')], null, true)];
    $engine = importEngine($reader);
    $engine->sync($account);

    $reader->pages = ['start' => new InventoryPage([], null, true, [
        new ProviderDeletionEvidence($account->id, 'message-a', 'provider_tombstone', 'opaque-delete-one'),
    ])];
    expect($engine->sync($account)?->provider_deleted_count)->toBe(1);

    $reader->pages = ['start' => new InventoryPage([reference($account, 'message-a')], null, true)];
    $engine->sync($account);

    $reader->pages = ['start' => new InventoryPage([], null, true)];
    $report = $engine->sync($account);

    expect($report?->provider_deleted_count)->toBe(0)
        ->and($report?->unexpected_active_count)->toBe(1);
});

it('closes a waived episode on success and requires a new waiver after failure reopens', function (): void {
    $account = importAccount('waiver-episodes');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([reference($account, 'episode-message')], null, true)];
    $reader->retrievers['episode-message'] = fn (): RetrievedMessage => throw new MailImportFailure(
        MailImportStage::Retrieve, MailImportCode::SyntheticFailure,
    );
    $engine = importEngine($reader);
    $engine->sync($account);
    $error = MailImportError::query()->firstOrFail();
    $error->waive('Invented explicit acceptance.', 'opaque-waiver-one');

    $engine->sync($account);
    expect($error->refresh()->waived_at)->not->toBeNull()
        ->and($error->resolved_at)->toBeNull();

    unset($reader->retrievers['episode-message']);
    $engine->sync($account);
    expect($error->refresh()->resolved_at)->not->toBeNull();

    $reader->retrievers['episode-message'] = fn (): RetrievedMessage => throw new MailImportFailure(
        MailImportStage::Retrieve, MailImportCode::SyntheticFailure,
    );
    $report = $engine->sync($account);

    expect($error->refresh()->resolved_at)->toBeNull()
        ->and($error->waived_at)->toBeNull()
        ->and($error->waiver_reason)->toBeNull()
        ->and($error->waiver_audit_reference)->toBeNull()
        ->and($error->summary)->toBe('The provider message could not be imported.')
        ->and($report?->transient_error_count)->toBe(1)
        ->and($report?->waived_error_count)->toBe(0)
        ->and($report?->mirrored_count)->toBe(0);

    $error->waive('Invented explicit acceptance of the new episode.', 'opaque-waiver-two');
    $waivedReport = $engine->sync($account);

    expect($error->refresh()->waived_at)->not->toBeNull()
        ->and($error->resolved_at)->toBeNull()
        ->and($waivedReport?->transient_error_count)->toBe(0)
        ->and($waivedReport?->waived_error_count)->toBe(1)
        ->and($waivedReport?->mirrored_count)->toBe(0);
});

it('normalizes hostile driver failure metadata to package-owned values', function (): void {
    $marker = 'invented_secret_token_marker';
    $failure = new MailImportFailure($marker, $marker);
    $stateFailure = new MailImportFailure('inventory', 'state_mismatch');
    $account = importAccount('hostile-failure-metadata');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([reference($account, 'hostile-message')], null, true)];
    $reader->retrievers['hostile-message'] = fn (): RetrievedMessage => throw $failure;

    expect($failure->stage)->toBe(MailImportStage::Retrieve)
        ->and($failure->safeCode)->toBe(MailImportCode::UnexpectedFailure)
        ->and($failure->getMessage())->not->toContain($marker)
        ->and($stateFailure->stage)->toBe(MailImportStage::Inventory)
        ->and($stateFailure->safeCode)->toBe(MailImportCode::StateMismatch);

    $report = importEngine($reader)->sync($account);
    $error = MailImportError::query()->firstOrFail();
    $persisted = json_encode([$error->toArray(), $report?->toArray()], JSON_THROW_ON_ERROR);

    expect($error->stage)->toBe('retrieve')
        ->and($error->code)->toBe('unexpected_failure')
        ->and($error->summary)->toBe('The provider message could not be retrieved safely.')
        ->and($report?->transient_error_count)->toBe(1)
        ->and($persisted)->not->toContain($marker);
});

it('reconciles exclusively on the configured mail mirror connection', function (): void {
    config()->set('database.connections.mail_mirror_isolated', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('mail_mirror_isolated');
    expect(Artisan::call('migrate:fresh', ['--database' => 'mail_mirror_isolated']))->toBe(0);
    config()->set('mail-mirror.database_connection', 'mail_mirror_isolated');

    $account = importAccount('isolated-connection');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([reference($account, 'isolated-message')], null, true)];
    $report = importEngine($reader)->sync($account);

    expect($account->getConnectionName())->toBe('mail_mirror_isolated')
        ->and($report?->getConnectionName())->toBe('mail_mirror_isolated')
        ->and(DB::connection('mail_mirror_isolated')->table('mail_messages')->count())->toBe(1)
        ->and(DB::connection('mail_mirror_isolated')->table('mail_reconciliation_reports')->count())->toBe(1)
        ->and(DB::connection('testing')->table('mail_messages')->count())->toBe(0)
        ->and(DB::connection('testing')->table('mail_reconciliation_reports')->count())->toBe(0);
});

it('returns the existing immutable report when the same completed scan is reconciled again', function (): void {
    $account = importAccount('same-scan-report');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([reference($account, 'same-scan-message')], null, true)];
    $first = importEngine($reader)->sync($account);
    $service = new ReconciliationService;

    $second = $service->reconcile($account, (string) $first?->scan_id);

    expect($second->id)->toBe($first?->id)
        ->and(MailReconciliationReport::query()->forAccount($account)->count())->toBe(1);
});

it('bounds high-cardinality reconciliation reports to configured metadata samples', function (): void {
    config()->set('mail-mirror.reconciliation_sample_limit', 3);
    $account = importAccount('bounded-report');
    $reader = new DeterministicImportReader;
    $references = [];

    for ($index = 0; $index < 125; $index++) {
        $references[] = reference($account, sprintf('message-%03d', $index));
    }

    $reader->pages = ['start' => new InventoryPage($references, null, true)];
    $report = importEngine($reader)->sync($account);

    expect($report?->inventory_count)->toBe(125)
        ->and($report?->mirrored_count)->toBe(125)
        ->and($report?->summary['mirrored'])->toBe([
            'sample' => ['message-000', 'message-001', 'message-002'],
            'truncated' => true,
        ])
        ->and(strlen((string) json_encode($report?->summary)))->toBeLessThan(1000);
});

it('removes a newly written raw object when later page work rolls back', function (): void {
    Storage::fake('rollback-objects');
    config()->set('mail-mirror.storage_disk', 'rollback-objects');
    $account = importAccount('raw-rollback');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([reference($account, 'raw-rollback-message')], null, true)];
    $reader->retrievers['raw-rollback-message'] = function (MessageReference $reference): RetrievedMessage {
        $stream = fopen('php://temp', 'w+b');
        assert(is_resource($stream));
        fwrite($stream, 'invented rollback raw bytes');
        rewind($stream);

        return new RetrievedMessage(
            $reference,
            'Rollback raw',
            rawSource: new RawMessageSource($stream, 'rollback-raw-object'),
        );
    };
    $postgres = DB::connection()->getDriverName() === 'pgsql';

    if ($postgres) {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION reject_checkpoint_after_raw_function() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'forced checkpoint rollback'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER reject_checkpoint_after_raw BEFORE UPDATE ON mail_sync_checkpoints
            FOR EACH ROW EXECUTE FUNCTION reject_checkpoint_after_raw_function();
            SQL);
    } else {
        DB::unprepared("CREATE TRIGGER reject_checkpoint_after_raw BEFORE UPDATE ON mail_sync_checkpoints BEGIN SELECT RAISE(ABORT, 'forced checkpoint rollback'); END");
    }

    try {
        expect(fn () => importEngine($reader)->sync($account))->toThrow(QueryException::class)
            ->and(MailMessage::query()->forAccount($account)->count())->toBe(0)
            ->and(MailRawObject::query()->forAccount($account)->count())->toBe(0)
            ->and(Storage::disk('rollback-objects')->allFiles())->toBe([]);
    } finally {
        if ($postgres) {
            DB::statement('DROP TRIGGER reject_checkpoint_after_raw ON mail_sync_checkpoints');
            DB::statement('DROP FUNCTION reject_checkpoint_after_raw_function');
        } else {
            DB::statement('DROP TRIGGER reject_checkpoint_after_raw');
        }
    }
});

it('enforces report immutability and import paths through direct database writes', function (): void {
    $account = importAccount('database-import-protection');
    $reader = new DeterministicImportReader;
    $reader->pages = ['start' => new InventoryPage([reference($account, 'protected-message')], null, true)];
    $report = importEngine($reader)->sync($account);
    $other = importAccount('database-import-protection-other');

    expect(fn () => DB::table('mail_reconciliation_reports')->where('id', $report?->id)->update(['mirrored_count' => 99]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('mail_inventory_items')->where('mail_account_id', $account->id)->update(['mail_account_id' => $other->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('mail_inventory_items')->where('mail_account_id', $account->id)->update(['provider_message_id' => 'changed']))
        ->toThrow(QueryException::class);
});
