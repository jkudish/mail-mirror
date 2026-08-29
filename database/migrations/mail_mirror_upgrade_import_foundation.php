<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mail_messages', 'provider_occurrence_id')) {
            return;
        }

        Schema::table('mail_messages', function (Blueprint $table): void {
            $table->dropUnique(['mail_account_id', 'provider_occurrence_id']);
            $table->dropColumn('provider_occurrence_id');
            $table->string('internet_message_id')->nullable();
            $table->index(['mail_account_id', 'internet_message_id']);
        });

        Schema::dropIfExists('mail_sync_checkpoints');
        Schema::dropIfExists('mail_sync_runs');

        $this->createImportTables();
    }

    private function createImportTables(): void
    {
        Schema::create('mail_inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->uuid('scan_id');
            $table->string('provider_message_id');
            $table->string('provider_thread_id')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();
            $table->unique(['mail_account_id', 'provider_message_id']);
            $table->unique(['mail_account_id', 'id']);
        });

        Schema::create('mail_sync_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->uuid('scan_id');
            $table->text('provider_cursor')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->timestampTz('scan_started_at');
            $table->timestampTz('scan_completed_at')->nullable();
            $table->timestamps();
            $table->unique('mail_account_id');
        });

        Schema::create('mail_import_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_id');
            $table->string('stage');
            $table->string('code');
            $table->string('summary');
            $table->unsignedInteger('attempt_count')->default(1);
            $table->timestampTz('last_failed_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('waived_at')->nullable();
            $table->string('waiver_reason')->nullable();
            $table->string('waiver_audit_reference')->nullable();
            $table->timestamps();
            $table->unique(['mail_account_id', 'provider_message_id', 'stage'], 'mail_import_error_message_stage_unique');
            $table->unique(['mail_account_id', 'id']);
        });

        Schema::create('mail_provider_deletion_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_id');
            $table->string('proof_code');
            $table->string('audit_reference');
            $table->json('provider_metadata')->nullable();
            $table->timestamps();
            $table->unique(['mail_account_id', 'provider_message_id']);
            $table->unique(['mail_account_id', 'id']);
        });

        Schema::create('mail_reconciliation_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->uuid('scan_id');
            $table->unsignedBigInteger('inventory_count');
            $table->unsignedBigInteger('mirrored_count');
            $table->unsignedBigInteger('provider_deleted_count');
            $table->unsignedBigInteger('transient_error_count');
            $table->unsignedBigInteger('waived_error_count');
            $table->unsignedBigInteger('unexplained_missing_count');
            $table->unsignedBigInteger('unexpected_active_count');
            $table->json('summary');
            $table->timestamps();
            $table->unique(['mail_account_id', 'scan_id']);
            $table->unique(['mail_account_id', 'id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('mail_messages', 'internet_message_id')) {
            return;
        }

        Schema::dropIfExists('mail_reconciliation_reports');
        Schema::dropIfExists('mail_provider_deletion_evidence');
        Schema::dropIfExists('mail_import_errors');
        Schema::dropIfExists('mail_sync_checkpoints');
        Schema::dropIfExists('mail_inventory_items');

        Schema::table('mail_messages', function (Blueprint $table): void {
            $table->dropIndex(['mail_account_id', 'internet_message_id']);
            $table->dropColumn('internet_message_id');
        });
    }
};
