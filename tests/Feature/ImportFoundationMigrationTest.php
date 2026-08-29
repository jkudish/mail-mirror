<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailMessage;

function importUpgradeMigration(): object
{
    $migration = require __DIR__.'/../../database/migrations/mail_mirror_upgrade_import_foundation.php';

    if (! is_object($migration)) {
        throw new RuntimeException('The import upgrade migration could not be loaded.');
    }

    return $migration;
}

it('supports the simplified clean install with protected import tables', function (): void {
    expect(Schema::hasColumn('mail_messages', 'internet_message_id'))->toBeTrue()
        ->and(Schema::hasTable('mail_sync_runs'))->toBeFalse()
        ->and(Schema::hasColumns('mail_provider_deletion_evidence', ['scan_id', 'provider_message_id']))->toBeTrue()
        ->and(Schema::hasTable('mail_reconciliation_reports'))->toBeTrue();
});

it('round trips empty legacy resumability schema without losing message identity', function (): void {
    $account = MailAccount::query()->create([
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'round-trip-account',
    ]);
    MailMessage::query()->create([
        'mail_account_id' => $account->id,
        'provider_message_id' => 'round-trip-message',
        'internet_message_id' => '<round-trip@invented.test>',
    ]);
    $migration = importUpgradeMigration();

    // Anonymous Laravel migrations expose down() outside the abstract base type.
    // @phpstan-ignore method.notFound
    $migration->down();

    expect(Schema::hasColumn('mail_messages', 'provider_occurrence_id'))->toBeTrue()
        ->and(Schema::hasColumn('mail_messages', 'internet_message_id'))->toBeFalse()
        ->and(DB::table('mail_messages')->value('provider_occurrence_id'))->toBe('round-trip-message')
        ->and(Schema::hasColumns('mail_sync_runs', ['status', 'operation', 'provider_metadata']))->toBeTrue()
        ->and(Schema::hasColumns('mail_sync_checkpoints', ['mail_sync_run_id', 'checkpoint_key', 'provider_cursor']))->toBeTrue();

    // @phpstan-ignore method.notFound
    $migration->up();

    expect(Schema::hasColumn('mail_messages', 'provider_occurrence_id'))->toBeFalse()
        ->and(Schema::hasColumn('mail_messages', 'internet_message_id'))->toBeTrue()
        ->and(DB::table('mail_messages')->value('provider_message_id'))->toBe('round-trip-message')
        ->and(Schema::hasTable('mail_sync_runs'))->toBeFalse()
        ->and(Schema::hasColumns('mail_sync_checkpoints', ['scan_id', 'version', 'processed_count']))->toBeTrue();
});

it('rejects populated legacy resumability state before any schema mutation', function (): void {
    $account = MailAccount::query()->create([
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'populated-legacy-account',
    ]);
    $migration = importUpgradeMigration();
    // @phpstan-ignore method.notFound
    $migration->down();
    DB::table('mail_sync_runs')->insert([
        'mail_account_id' => $account->id,
        'status' => 'running',
        'operation' => 'invented-legacy-import',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(function () use ($migration): void {
        // @phpstan-ignore method.notFound
        $migration->up();
    })->toThrow(RuntimeException::class, 'cannot replace populated legacy sync state')
        ->and(Schema::hasColumn('mail_messages', 'provider_occurrence_id'))->toBeTrue()
        ->and(Schema::hasColumn('mail_messages', 'internet_message_id'))->toBeFalse()
        ->and(Schema::hasTable('mail_sync_runs'))->toBeTrue()
        ->and(Schema::hasTable('mail_sync_checkpoints'))->toBeTrue()
        ->and(Schema::hasTable('mail_inventory_items'))->toBeFalse()
        ->and(DB::table('mail_sync_runs')->count())->toBe(1);
});
