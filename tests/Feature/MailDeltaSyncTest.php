<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Jkudish\MailMirror\Contracts\DeltaMailboxReader;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Enums\MailImportStage;
use Jkudish\MailMirror\Enums\MailSourceChangeKind;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\DeltaRepairRequired;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Exceptions\StaleCheckpoint;
use Jkudish\MailMirror\Import\MailImportEngine;
use Jkudish\MailMirror\Import\ReconciliationService;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailContainer;
use Jkudish\MailMirror\Models\MailDeltaCheckpoint;
use Jkudish\MailMirror\Models\MailDeltaPendingMessage;
use Jkudish\MailMirror\Models\MailImportError;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailMessageContainerMembership;
use Jkudish\MailMirror\Models\MailProviderDeletionEvidence;
use Jkudish\MailMirror\Models\MailRawObject;
use Jkudish\MailMirror\Models\MailReconciliationReport;
use Jkudish\MailMirror\Models\MailSourceChange;
use Jkudish\MailMirror\Models\MailSyncCheckpoint;
use Jkudish\MailMirror\Read\ChangedMessageState;
use Jkudish\MailMirror\Read\InventoryPage;
use Jkudish\MailMirror\Read\MailboxChangesPage;
use Jkudish\MailMirror\Read\MailboxContainerState;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MailReadService;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\ProviderDeletionEvidence;
use Jkudish\MailMirror\Read\RawMessageSource;
use Jkudish\MailMirror\Read\RetrievedMessage;
use Jkudish\MailMirror\Read\SourceChangeService;
use Jkudish\MailMirror\Storage\MailObjectStorage;

final class DeterministicDeltaReader implements DeltaMailboxReader
{
    /** @var array<string, MailboxChangesPage> */
    public array $changePages = [];

    /** @var array<string, InventoryPage> */
    public array $inventoryPages = [];

    /** @var list<string> */
    public array $retrieved = [];

    /** @var array<string, bool> */
    public array $failRetrieval = [];

    public ?Closure $onChanges = null;

    public ?string $repairCursor = null;

    public function driver(): MailDriver
    {
        return MailDriver::Gmail;
    }

    public function inventoryPage(MailAccount $account, ?string $cursor): InventoryPage
    {
        return $this->inventoryPages[$cursor ?? 'start'];
    }

    public function changesPage(MailAccount $account, ?string $cursor): MailboxChangesPage
    {
        ($this->onChanges) ? ($this->onChanges)() : null;
        $this->onChanges = null;

        if ($this->repairCursor !== null) {
            $repairCursor = $this->repairCursor;
            $this->repairCursor = null;

            throw new DeltaRepairRequired($repairCursor);
        }

        return $this->changePages[$cursor ?? 'start'];
    }

    public function retrieve(MailAccount $account, MessageReference $message): RetrievedMessage
    {
        $this->retrieved[] = $message->providerMessageId;

        if ($this->failRetrieval[$message->providerMessageId] ?? false) {
            throw new MailImportFailure(MailImportStage::Retrieve, MailImportCode::SyntheticFailure);
        }

        $stream = fopen('php://temp', 'w+b');
        assert(is_resource($stream));
        fwrite($stream, 'Synthetic raw bytes for '.$message->providerMessageId);
        rewind($stream);

        return new RetrievedMessage(
            $message,
            'Synthetic '.$message->providerMessageId,
            containers: [['provider_id' => 'inbox', 'name' => 'Inbox', 'kind' => 'system']],
            rawSource: new RawMessageSource($stream, 'raw-'.$message->providerMessageId),
        );
    }
}

function deltaAccount(string $providerId, string $ownerId = 'owner-one'): MailAccount
{
    return MailAccount::query()->create([
        'owner_type' => 'synthetic-owner',
        'owner_id' => $ownerId,
        'driver' => MailDriver::Gmail,
        'provider_account_id' => $providerId,
    ]);
}

function deltaReference(MailAccount $account, string $id): MessageReference
{
    return new MessageReference($account->id, MailDriver::Gmail, $id, 'thread-'.$id);
}

function deltaEngine(DeterministicDeltaReader $reader): MailImportEngine
{
    $registry = new MailDriverRegistry;
    $registry->register(MailDriver::Gmail, $reader);

    return new MailImportEngine(
        new MailReadService($registry),
        new ReconciliationService,
        app(MailObjectStorage::class),
        app(Dispatcher::class),
    );
}

it('applies state-only changes without retrieving raw and fully retrieves new messages', function (): void {
    Storage::fake('delta-objects');
    config()->set('mail-mirror.storage_disk', 'delta-objects');
    $account = deltaAccount('delta-state-and-new');
    $existing = MailMessage::query()->create([
        'mail_account_id' => $account->id,
        'provider_message_id' => 'existing',
        'provider_metadata' => ['keep' => 'value', 'mailbox_state' => ['unread' => true]],
    ]);
    $reader = new DeterministicDeltaReader;
    $reader->changePages['start'] = new MailboxChangesPage([
        new ChangedMessageState(deltaReference($account, 'existing'), [
            'mailbox_state' => ['unread' => false],
        ], [['provider_id' => 'archive', 'name' => 'Archive', 'kind' => 'system']]),
        new ChangedMessageState(deltaReference($account, 'new'), [], []),
    ], [], [], false, 'cursor-2', true);

    $result = deltaEngine($reader)->syncChanges($account);

    expect($result->caughtUp)->toBeTrue()
        ->and($reader->retrieved)->toBe(['new'])
        ->and($existing->refresh()->provider_metadata)->toMatchArray([
            'keep' => 'value',
            'mailbox_state' => ['unread' => false],
        ])
        ->and(MailRawObject::query()->forAccount($account)->count())->toBe(1)
        ->and(MailDeltaCheckpoint::query()->forAccount($account)->value('provider_cursor'))->toBe('cursor-2')
        ->and(MailSourceChange::query()->forAccount($account)->where('kind', MailSourceChangeKind::MessageChanged)->count())->toBe(2);
});

it('retains the applied cursor until a failed new-message retrieval is replayed successfully', function (): void {
    $account = deltaAccount('delta-retry-obligation');
    $reader = new DeterministicDeltaReader;
    $reader->changePages['start'] = new MailboxChangesPage([
        new ChangedMessageState(deltaReference($account, 'retry-message'), [], []),
    ], [], [], false, 'cursor-after-retry', true);
    $reader->failRetrieval['retry-message'] = true;
    $engine = deltaEngine($reader);

    $failed = $engine->syncChanges($account);

    expect($failed->caughtUp)->toBeFalse()
        ->and(MailDeltaCheckpoint::query()->forAccount($account)->value('provider_cursor'))->toBeNull()
        ->and(MailImportError::query()->forAccount($account)->where('provider_message_id', 'retry-message')->whereNull('resolved_at')->exists())->toBeTrue();

    $reader->failRetrieval['retry-message'] = false;
    $replayed = $engine->syncChanges($account);

    expect($replayed->caughtUp)->toBeTrue()
        ->and($reader->retrieved)->toBe(['retry-message', 'retry-message'])
        ->and(MailDeltaCheckpoint::query()->forAccount($account)->value('provider_cursor'))->toBe('cursor-after-retry')
        ->and(MailImportError::query()->forAccount($account)->where('provider_message_id', 'retry-message')->whereNull('resolved_at')->exists())->toBeFalse();
});

it('persists container rename deletion and message deletion reappearance as replayable changes', function (): void {
    $account = deltaAccount('delta-lifecycle');
    $message = MailMessage::query()->create(['mail_account_id' => $account->id, 'provider_message_id' => 'lifecycle']);
    $removed = MailContainer::query()->create([
        'mail_account_id' => $account->id,
        'provider_container_id' => 'removed',
        'name' => 'Removed',
    ]);
    MailMessageContainerMembership::query()->create([
        'mail_account_id' => $account->id,
        'mail_message_id' => $message->id,
        'mail_container_id' => $removed->id,
    ]);
    MailContainer::query()->create([
        'mail_account_id' => $account->id,
        'provider_container_id' => 'renamed',
        'name' => 'Old name',
    ]);
    $reader = new DeterministicDeltaReader;
    $reader->changePages = [
        'start' => new MailboxChangesPage([], [
            new ProviderDeletionEvidence($account->id, 'lifecycle', 'synthetic_delta', 'delete-1'),
        ], [
            new MailboxContainerState($account->id, 'renamed', 'New name'),
        ], true, 'cursor-deleted', true),
        'cursor-deleted' => new MailboxChangesPage([
            new ChangedMessageState(deltaReference($account, 'lifecycle'), ['state' => 'returned'], []),
        ], [], [
            new MailboxContainerState($account->id, 'renamed', 'New name'),
        ], true, 'cursor-returned', true),
    ];
    $engine = deltaEngine($reader);

    $engine->syncChanges($account);
    $engine->syncChanges($account);
    $membershipChange = MailSourceChange::query()->forAccount($account)
        ->where('kind', MailSourceChangeKind::MessageChanged)
        ->where('mail_message_id', $message->id)
        ->firstOrFail();
    $containerDelete = MailSourceChange::query()->forAccount($account)
        ->where('kind', MailSourceChangeKind::ContainerDeleted)
        ->where('mail_container_id', $removed->id)
        ->firstOrFail();

    expect(MailProviderDeletionEvidence::query()->forAccount($account)->count())->toBe(0)
        ->and($message->refresh()->provider_metadata)->toMatchArray(['state' => 'returned'])
        ->and(MailContainer::query()->forAccount($account)->where('provider_container_id', 'removed')->exists())->toBeFalse()
        ->and(MailContainer::query()->forAccount($account)->where('provider_container_id', 'renamed')->value('name'))->toBe('New name')
        ->and(MailSourceChange::query()->forAccount($account)->where('kind', MailSourceChangeKind::ContainerDeleted)->count())->toBe(1)
        ->and($membershipChange->id)->toBeLessThan($containerDelete->id)
        ->and(MailSourceChange::query()->forAccount($account)->where('kind', MailSourceChangeKind::ProviderDeletionChanged)->pluck('provider_deleted')->all())->toBe([true, false]);
});

it('rolls back source changes and applied cursor together and fences stale workers', function (): void {
    $account = deltaAccount('delta-atomic');
    $reader = new DeterministicDeltaReader;
    $reader->changePages['start'] = new MailboxChangesPage([
        new ChangedMessageState(deltaReference($account, 'atomic-message'), [], []),
    ], [], [], false, 'cursor-atomic', true);
    $postgres = DB::connection()->getDriverName() === 'pgsql';

    if ($postgres) {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION reject_delta_checkpoint_function() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'forced delta rollback'; END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER reject_delta_checkpoint BEFORE INSERT ON mail_delta_checkpoints
            FOR EACH ROW EXECUTE FUNCTION reject_delta_checkpoint_function();
            SQL);
    } else {
        DB::unprepared("CREATE TRIGGER reject_delta_checkpoint BEFORE INSERT ON mail_delta_checkpoints BEGIN SELECT RAISE(ABORT, 'forced delta rollback'); END");
    }

    try {
        expect(fn () => deltaEngine($reader)->syncChanges($account))->toThrow(QueryException::class)
            ->and(MailMessage::query()->forAccount($account)->count())->toBe(0)
            ->and(MailSourceChange::query()->forAccount($account)->count())->toBe(0)
            ->and(MailDeltaCheckpoint::query()->forAccount($account)->count())->toBe(0);
    } finally {
        if ($postgres) {
            DB::statement('DROP TRIGGER reject_delta_checkpoint ON mail_delta_checkpoints');
            DB::statement('DROP FUNCTION reject_delta_checkpoint_function');
        } else {
            DB::statement('DROP TRIGGER reject_delta_checkpoint');
        }
    }

    MailDeltaCheckpoint::query()->create([
        'mail_account_id' => $account->id,
        'provider_cursor' => 'cursor-before-stale',
        'version' => 1,
    ]);
    $reader->changePages['cursor-before-stale'] = $reader->changePages['start'];
    $reader->onChanges = fn () => MailDeltaCheckpoint::query()->forAccount($account)->increment('version');

    expect(fn () => deltaEngine($reader)->syncChanges($account))->toThrow(StaleCheckpoint::class)
        ->and(MailDeltaCheckpoint::query()->forAccount($account)->value('provider_cursor'))->toBe('cursor-before-stale');
});

it('keeps an expired cursor pending until bounded inventory repair completes', function (): void {
    $account = deltaAccount('delta-repair');
    $reader = new DeterministicDeltaReader;
    $reader->repairCursor = 'cursor-after-repair';
    $reader->inventoryPages = [
        'start' => new InventoryPage([], 'repair-page-2', false),
        'repair-page-2' => new InventoryPage([], null, true),
    ];
    $reader->changePages['cursor-after-repair'] = new MailboxChangesPage([], [], [], false, 'cursor-after-replay', true);
    $engine = deltaEngine($reader);

    $first = $engine->syncChanges($account, repairPageLimit: 1);
    $checkpoint = MailDeltaCheckpoint::query()->forAccount($account)->firstOrFail();

    expect($first->repairPending)->toBeTrue()
        ->and($checkpoint->provider_cursor)->toBeNull()
        ->and($checkpoint->repair_cursor)->toBe('cursor-after-repair');

    $second = $engine->syncChanges($account, repairPageLimit: 1);

    expect($second->caughtUp)->toBeTrue()
        ->and($second->repairPending)->toBeFalse()
        ->and($checkpoint->refresh()->provider_cursor)->toBe('cursor-after-replay')
        ->and($checkpoint->repair_cursor)->toBeNull();
});

it('binds repair to a fresh scan and keeps repair pending when that scan does not converge', function (): void {
    $account = deltaAccount('delta-repair-nonconvergent');
    $reader = new DeterministicDeltaReader;
    $reader->inventoryPages['start'] = new InventoryPage([], null, true);
    $engine = deltaEngine($reader);
    $priorReport = $engine->sync($account);
    MailMessage::query()->create([
        'mail_account_id' => $account->id,
        'provider_message_id' => 'unexpected-after-prior-scan',
    ]);
    $reader->repairCursor = 'repair-baseline';

    $result = $engine->syncChanges($account);
    $delta = MailDeltaCheckpoint::query()->forAccount($account)->firstOrFail();

    expect($result->caughtUp)->toBeFalse()
        ->and($result->repairPending)->toBeTrue()
        ->and($delta->repair_cursor)->toBe('repair-baseline')
        ->and($delta->repair_scan_id)->not->toBe($priorReport?->scan_id)
        ->and(MailReconciliationReport::query()->forAccount($account)->count())->toBe(2)
        ->and(MailReconciliationReport::query()->forAccount($account)->latest('id')->value('unexpected_active_count'))->toBe(1)
        ->and(MailSyncCheckpoint::query()->forAccount($account)->value('scan_id'))->toBe($delta->repair_scan_id);
});

it('keeps repair pending when its bound inventory has an unresolved retrieval failure', function (): void {
    $account = deltaAccount('delta-repair-retrieval-failure');
    $reader = new DeterministicDeltaReader;
    $reader->repairCursor = 'repair-failure-baseline';
    $reader->inventoryPages['start'] = new InventoryPage([
        deltaReference($account, 'repair-failure-message'),
    ], null, true);
    $reader->failRetrieval['repair-failure-message'] = true;

    $result = deltaEngine($reader)->syncChanges($account);
    $delta = MailDeltaCheckpoint::query()->forAccount($account)->firstOrFail();

    expect($result->caughtUp)->toBeFalse()
        ->and($result->repairPending)->toBeTrue()
        ->and($delta->repair_cursor)->toBe('repair-failure-baseline')
        ->and(MailReconciliationReport::query()->forAccount($account)->latest('id')->value('transient_error_count'))->toBe(1)
        ->and(MailSyncCheckpoint::query()->forAccount($account)->value('scan_id'))->toBe($delta->repair_scan_id);
});

it('resumes its exact completed repair report instead of consuming or starting another scan', function (): void {
    $account = deltaAccount('delta-repair-completed-report');
    $scanId = '10000000-0000-4000-8000-000000003735';
    MailSyncCheckpoint::query()->create([
        'mail_account_id' => $account->id,
        'scan_id' => $scanId,
        'version' => 1,
        'processed_count' => 0,
        'scan_started_at' => now(),
        'scan_completed_at' => now(),
    ]);
    MailReconciliationReport::query()->create([
        'mail_account_id' => $account->id,
        'scan_id' => $scanId,
        'inventory_count' => 0,
        'mirrored_count' => 0,
        'provider_deleted_count' => 0,
        'transient_error_count' => 0,
        'waived_error_count' => 0,
        'unexplained_missing_count' => 0,
        'unexpected_active_count' => 0,
        'summary' => [],
    ]);
    MailDeltaCheckpoint::query()->create([
        'mail_account_id' => $account->id,
        'version' => 1,
        'repair_cursor' => 'completed-repair-baseline',
        'repair_scan_id' => $scanId,
        'repair_started_at' => now(),
    ]);
    $reader = new DeterministicDeltaReader;
    $reader->changePages['completed-repair-baseline'] = new MailboxChangesPage([], [], [], false, 'completed-repair-replayed', true);

    $result = deltaEngine($reader)->syncChanges($account);

    expect($result->caughtUp)->toBeTrue()
        ->and(MailSyncCheckpoint::query()->forAccount($account)->value('scan_id'))->toBe($scanId)
        ->and(MailReconciliationReport::query()->forAccount($account)->count())->toBe(1)
        ->and(MailDeltaCheckpoint::query()->forAccount($account)->value('provider_cursor'))->toBe('completed-repair-replayed');
});

it('replays the captured repair baseline before catching up changes that occurred during inventory', function (): void {
    Storage::fake('delta-repair-objects');
    config()->set('mail-mirror.storage_disk', 'delta-repair-objects');
    $account = deltaAccount('delta-repair-mutation');
    $reader = new DeterministicDeltaReader;
    $reader->repairCursor = 'repair-mutation-baseline';
    $reader->inventoryPages = [
        'start' => new InventoryPage([], 'repair-mutation-page-2', false),
        'repair-mutation-page-2' => new InventoryPage([], null, true),
    ];
    $reader->changePages['repair-mutation-baseline'] = new MailboxChangesPage([
        new ChangedMessageState(deltaReference($account, 'arrived-during-repair'), [], []),
    ], [], [], false, 'repair-mutation-caught-up', true);
    $engine = deltaEngine($reader);

    $first = $engine->syncChanges($account, repairPageLimit: 1);
    $second = $engine->syncChanges($account, repairPageLimit: 1);

    expect($first->repairPending)->toBeTrue()
        ->and($second->caughtUp)->toBeTrue()
        ->and($second->processedCount)->toBe(1)
        ->and($reader->retrieved)->toBe(['arrived-during-repair'])
        ->and(MailMessage::query()->forAccount($account)->where('provider_message_id', 'arrived-during-repair')->exists())->toBeTrue()
        ->and(MailDeltaCheckpoint::query()->forAccount($account)->value('provider_cursor'))->toBe('repair-mutation-caught-up');
});

it('advances past an unavailable change and resolves it from a later deletion page', function (): void {
    $account = deltaAccount('delta-unavailable-then-delete');
    $reference = deltaReference($account, 'raced-delete');
    $reader = new DeterministicDeltaReader;
    $reader->changePages = [
        'start' => new MailboxChangesPage([], [], [], false, 'race-page-2', false, unavailableMessages: [$reference]),
        'race-page-2' => new MailboxChangesPage([], [
            new ProviderDeletionEvidence($account->id, 'raced-delete', 'synthetic_delta', 'raced-delete-proof'),
        ], [], false, 'race-complete', true),
    ];

    $result = deltaEngine($reader)->syncChanges($account);

    expect($result->caughtUp)->toBeTrue()
        ->and(MailDeltaCheckpoint::query()->forAccount($account)->value('provider_cursor'))->toBe('race-complete')
        ->and(MailDeltaPendingMessage::query()->forAccount($account)->count())->toBe(0)
        ->and(MailImportError::query()->forAccount($account)->where('provider_message_id', 'raced-delete')->whereNull('resolved_at')->exists())->toBeFalse()
        ->and(MailProviderDeletionEvidence::query()->forAccount($account)->where('provider_message_id', 'raced-delete')->exists())->toBeTrue();
});

it('keeps an unavailable new message pending until a later bounded retry retrieves it', function (): void {
    Storage::fake('delta-pending-objects');
    config()->set('mail-mirror.storage_disk', 'delta-pending-objects');
    $account = deltaAccount('delta-new-unavailable');
    $reference = deltaReference($account, 'temporarily-unavailable');
    $reader = new DeterministicDeltaReader;
    $reader->changePages = [
        'start' => new MailboxChangesPage([], [], [], false, 'pending-cursor', true, unavailableMessages: [$reference]),
        'pending-cursor' => new MailboxChangesPage([], [], [], false, 'pending-caught-up', true),
    ];
    $engine = deltaEngine($reader);

    $first = $engine->syncChanges($account);
    expect(fn () => DB::table('mail_delta_pending_messages')
        ->where('mail_account_id', $account->id)
        ->update(['provider_message_id' => 'rewritten-pending-id']))
        ->toThrow(QueryException::class);
    $second = $engine->syncChanges($account);

    expect($first->caughtUp)->toBeFalse()
        ->and($second->caughtUp)->toBeTrue()
        ->and($reader->retrieved)->toBe(['temporarily-unavailable'])
        ->and(MailDeltaPendingMessage::query()->forAccount($account)->count())->toBe(0)
        ->and(MailMessage::query()->forAccount($account)->where('provider_message_id', 'temporarily-unavailable')->exists())->toBeTrue();
});

it('retains one deletion evidence episode while another message forces page replay', function (): void {
    $account = deltaAccount('delta-stable-deletion-evidence');
    $reader = new DeterministicDeltaReader;
    $reader->changePages['start'] = new MailboxChangesPage([
        new ChangedMessageState(deltaReference($account, 'failing-neighbor'), [], []),
    ], [
        new ProviderDeletionEvidence($account->id, 'stable-deletion', 'synthetic_delta', 'stable-proof'),
    ], [], false, 'not-yet-applied', true);
    $reader->failRetrieval['failing-neighbor'] = true;
    $engine = deltaEngine($reader);

    $engine->syncChanges($account);
    $firstEvidence = MailProviderDeletionEvidence::query()->forAccount($account)->where('provider_message_id', 'stable-deletion')->firstOrFail();
    $engine->syncChanges($account);

    expect($firstEvidence->fresh()?->scan_id)->toBe($firstEvidence->scan_id)
        ->and(MailProviderDeletionEvidence::query()->forAccount($account)->where('provider_message_id', 'stable-deletion')->count())->toBe(1)
        ->and(MailSourceChange::query()->forAccount($account)->where('kind', MailSourceChangeKind::ProviderDeletionChanged)->where('provider_message_id', 'stable-deletion')->count())->toBe(1);
});

it('exposes account-isolated ordered pending changes and acknowledges only the supplied owner path', function (): void {
    $first = deltaAccount('source-change-first');
    $second = deltaAccount('source-change-second', 'owner-two');
    foreach ([$first, $second] as $account) {
        MailSourceChange::query()->create([
            'mail_account_id' => $account->id,
            'kind' => MailSourceChangeKind::MessageChanged,
            'provider_message_id' => 'same-provider-id',
        ]);
    }
    $service = app(SourceChangeService::class);
    $pending = $service->pendingAccount($first->id, 'synthetic-owner', 'owner-one');

    expect($pending)->toHaveCount(1)
        ->and($pending->first()?->mail_account_id)->toBe($first->id)
        ->and(fn () => DB::table('mail_source_changes')->where('id', $pending->firstOrFail()->id)->update(['kind' => MailSourceChangeKind::ContainerChanged->value]))->toThrow(QueryException::class)
        ->and($service->acknowledgeAccount($first->id, 'synthetic-owner', 'owner-one', [$pending->firstOrFail()->id]))->toBe(1)
        ->and(MailSourceChange::query()->forAccount($second)->whereNull('acknowledged_at')->count())->toBe(1)
        ->and(fn () => $service->pendingAccount($first->id, 'synthetic-owner', 'owner-two'))->toThrow(AccountResourceMismatch::class);
});

it('records source changes for the historical inventory path', function (): void {
    $account = deltaAccount('legacy-inventory-handoff');
    $reader = new DeterministicDeltaReader;
    $reader->inventoryPages['start'] = new InventoryPage([deltaReference($account, 'legacy-message')], null, true);

    deltaEngine($reader)->sync($account);

    expect(MailSourceChange::query()->forAccount($account)->where('provider_message_id', 'legacy-message')->pluck('kind')->all())->toBe([
        MailSourceChangeKind::RawChanged,
        MailSourceChangeKind::MessageChanged,
    ]);
});
