<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Jkudish\MailMirror\Contracts\MailboxReader;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\StaleCheckpoint;
use Jkudish\MailMirror\Import\MailImportEngine;
use Jkudish\MailMirror\Import\ReconciliationService;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailImportError;
use Jkudish\MailMirror\Models\MailInventoryItem;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailRawObject;
use Jkudish\MailMirror\Models\MailReconciliationReport;
use Jkudish\MailMirror\Models\MailSyncCheckpoint;
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
            throw new MailImportFailure('retrieve', 'rate_limited', 'The provider requested a bounded retry.', true);
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
        ->and($error->summary)->toBe('The provider returned mismatched message identity.')
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
        ->and($report?->summary['unexpected_active'])->toBe(['message-c'])
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
        throw new MailImportFailure('retrieve', 'synthetic_failure', 'The invented message is temporarily unavailable.');
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
