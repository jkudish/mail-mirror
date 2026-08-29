<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mail_messages', 'provider_occurrence_id')) {
            $this->assertLegacyStateIsEmpty();

            Schema::table('mail_messages', function (Blueprint $table): void {
                $table->dropUnique(['mail_account_id', 'provider_occurrence_id']);
                $table->dropColumn('provider_occurrence_id');
                $table->string('internet_message_id')->nullable();
                $table->index(['mail_account_id', 'internet_message_id']);
            });

            Schema::drop('mail_sync_checkpoints');
            Schema::drop('mail_sync_runs');
            $this->createImportTables();
        }

        $this->protectImportState();
    }

    private function assertLegacyStateIsEmpty(): void
    {
        if (DB::table('mail_sync_runs')->exists() || DB::table('mail_sync_checkpoints')->exists()) {
            throw new RuntimeException(
                'MailMirror cannot replace populated legacy sync state; finish or explicitly migrate it before upgrading.',
            );
        }
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
            $table->index(['mail_account_id', 'scan_id']);
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
            $table->uuid('scan_id');
            $table->string('provider_message_id');
            $table->string('proof_code');
            $table->string('audit_reference');
            $table->json('provider_metadata')->nullable();
            $table->timestamps();
            $table->unique(['mail_account_id', 'provider_message_id']);
            $table->unique(['mail_account_id', 'id']);
            $table->index(['mail_account_id', 'scan_id']);
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

    private function protectImportState(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION mail_mirror_reject_import_account_change()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.mail_account_id <> OLD.mail_account_id THEN
                        RAISE EXCEPTION 'MailMirror import account path is immutable.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION mail_mirror_reject_import_message_path_change()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.provider_message_id <> OLD.provider_message_id THEN
                        RAISE EXCEPTION 'MailMirror import provider message path is immutable.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION mail_mirror_reject_import_error_path_change()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.provider_message_id <> OLD.provider_message_id OR NEW.stage <> OLD.stage THEN
                        RAISE EXCEPTION 'MailMirror import error path is immutable.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION mail_mirror_reject_reconciliation_report_update()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'MailMirror reconciliation reports are immutable.';
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            foreach (['mail_inventory_items', 'mail_sync_checkpoints', 'mail_import_errors', 'mail_provider_deletion_evidence'] as $table) {
                DB::statement("CREATE TRIGGER {$table}_account_immutable BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_import_account_change()");
            }
            foreach (['mail_inventory_items', 'mail_provider_deletion_evidence'] as $table) {
                DB::statement("CREATE TRIGGER {$table}_message_path_immutable BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_import_message_path_change()");
            }
            DB::statement('CREATE TRIGGER mail_import_errors_path_immutable BEFORE UPDATE ON mail_import_errors FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_import_error_path_change()');
            DB::statement('CREATE TRIGGER mail_reconciliation_reports_update_immutable BEFORE UPDATE ON mail_reconciliation_reports FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_reconciliation_report_update()');

            return;
        }

        foreach (['mail_inventory_items', 'mail_sync_checkpoints', 'mail_import_errors', 'mail_provider_deletion_evidence'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_account_immutable BEFORE UPDATE OF mail_account_id ON {$table} FOR EACH ROW WHEN NEW.mail_account_id != OLD.mail_account_id BEGIN SELECT RAISE(ABORT, 'MailMirror import account path is immutable.'); END");
        }
        foreach (['mail_inventory_items', 'mail_provider_deletion_evidence'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_message_path_immutable BEFORE UPDATE OF provider_message_id ON {$table} FOR EACH ROW WHEN NEW.provider_message_id != OLD.provider_message_id BEGIN SELECT RAISE(ABORT, 'MailMirror import provider message path is immutable.'); END");
        }
        DB::unprepared("CREATE TRIGGER mail_import_errors_path_immutable BEFORE UPDATE OF provider_message_id, stage ON mail_import_errors FOR EACH ROW WHEN NEW.provider_message_id != OLD.provider_message_id OR NEW.stage != OLD.stage BEGIN SELECT RAISE(ABORT, 'MailMirror import error path is immutable.'); END");
        DB::unprepared("CREATE TRIGGER mail_reconciliation_reports_update_immutable BEFORE UPDATE ON mail_reconciliation_reports FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'MailMirror reconciliation reports are immutable.'); END");
    }

    private function dropImportProtection(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS mail_mirror_reject_reconciliation_report_update() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS mail_mirror_reject_import_error_path_change() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS mail_mirror_reject_import_message_path_change() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS mail_mirror_reject_import_account_change() CASCADE');

            return;
        }

        foreach (['mail_inventory_items', 'mail_sync_checkpoints', 'mail_import_errors', 'mail_provider_deletion_evidence'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_account_immutable");
        }
        foreach (['mail_inventory_items', 'mail_provider_deletion_evidence'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_message_path_immutable");
        }
        DB::statement('DROP TRIGGER IF EXISTS mail_import_errors_path_immutable');
        DB::statement('DROP TRIGGER IF EXISTS mail_reconciliation_reports_update_immutable');
    }

    public function down(): void
    {
        $this->dropImportProtection();
        Schema::dropIfExists('mail_reconciliation_reports');
        Schema::dropIfExists('mail_provider_deletion_evidence');
        Schema::dropIfExists('mail_import_errors');
        Schema::dropIfExists('mail_sync_checkpoints');
        Schema::dropIfExists('mail_inventory_items');

        if (Schema::hasColumn('mail_messages', 'internet_message_id')) {
            Schema::table('mail_messages', function (Blueprint $table): void {
                $table->dropIndex(['mail_account_id', 'internet_message_id']);
                $table->dropColumn('internet_message_id');
                $table->string('provider_occurrence_id')->nullable();
            });
            DB::table('mail_messages')->update(['provider_occurrence_id' => DB::raw('provider_message_id')]);
            Schema::table('mail_messages', function (Blueprint $table): void {
                $table->unique(['mail_account_id', 'provider_occurrence_id']);
            });
        }

        Schema::create('mail_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'running', 'completed', 'failed']);
            $table->string('operation');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();
            $table->unique(['mail_account_id', 'id']);
        });

        Schema::create('mail_sync_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mail_sync_run_id')->nullable();
            $table->string('checkpoint_key');
            $table->text('provider_cursor')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();
            $table->unique(['mail_account_id', 'checkpoint_key']);
            $table->index('mail_sync_run_id');
            $table->foreign(['mail_account_id', 'mail_sync_run_id'])
                ->references(['mail_account_id', 'id'])->on('mail_sync_runs')->noActionOnDelete();
        });
    }
};
