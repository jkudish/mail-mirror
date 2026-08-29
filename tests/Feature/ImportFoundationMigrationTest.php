<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('upgrades the durable legacy foundation and also supports the simplified clean install', function (): void {
    expect(Schema::hasColumn('mail_messages', 'internet_message_id'))->toBeTrue()
        ->and(Schema::hasTable('mail_sync_runs'))->toBeFalse()
        ->and(Schema::hasTable('mail_reconciliation_reports'))->toBeTrue();

    Schema::drop('mail_reconciliation_reports');
    Schema::drop('mail_provider_deletion_evidence');
    Schema::drop('mail_import_errors');
    Schema::drop('mail_sync_checkpoints');
    Schema::drop('mail_inventory_items');
    Schema::table('mail_messages', function (Blueprint $table): void {
        $table->dropIndex(['mail_account_id', 'internet_message_id']);
        $table->dropColumn('internet_message_id');
    });
    Schema::table('mail_messages', function (Blueprint $table): void {
        $table->string('provider_occurrence_id');
        $table->unique(['mail_account_id', 'provider_occurrence_id']);
    });
    Schema::create('mail_sync_runs', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
        $table->string('status');
        $table->string('operation');
        $table->timestamps();
        $table->unique(['mail_account_id', 'id']);
    });
    Schema::create('mail_sync_checkpoints', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
        $table->unsignedBigInteger('mail_sync_run_id')->nullable();
        $table->string('checkpoint_key');
        $table->text('provider_cursor')->nullable();
        $table->timestamps();
    });

    /** @var Migration $upgrade */
    $upgrade = require __DIR__.'/../../database/migrations/mail_mirror_upgrade_import_foundation.php';
    // Anonymous Laravel migrations expose up() outside the abstract base type.
    // @phpstan-ignore method.notFound
    $upgrade->up();

    expect(Schema::hasColumn('mail_messages', 'provider_occurrence_id'))->toBeFalse()
        ->and(Schema::hasColumn('mail_messages', 'internet_message_id'))->toBeTrue()
        ->and(Schema::hasTable('mail_sync_runs'))->toBeFalse()
        ->and(Schema::hasColumns('mail_sync_checkpoints', ['scan_id', 'provider_cursor', 'version', 'processed_count']))->toBeTrue()
        ->and(Schema::hasTable('mail_import_errors'))->toBeTrue()
        ->and(Schema::hasTable('mail_provider_deletion_evidence'))->toBeTrue()
        ->and(Schema::hasTable('mail_reconciliation_reports'))->toBeTrue();
});
