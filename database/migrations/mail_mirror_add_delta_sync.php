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
        Schema::create('mail_delta_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->text('provider_cursor')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->text('repair_cursor')->nullable();
            $table->timestampTz('repair_started_at')->nullable();
            $table->timestamps();

            $table->unique('mail_account_id');
        });

        Schema::create('mail_source_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 64);
            $table->unsignedBigInteger('mail_message_id')->nullable();
            $table->unsignedBigInteger('mail_raw_object_id')->nullable();
            $table->unsignedBigInteger('mail_container_id')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('provider_container_id')->nullable();
            $table->boolean('provider_deleted')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['mail_account_id', 'acknowledged_at', 'id'], 'mail_source_changes_pending_index');
        });

        $this->protectAccountPaths();
    }

    private function protectAccountPaths(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION mail_mirror_reject_delta_account_change()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.mail_account_id <> OLD.mail_account_id THEN
                        RAISE EXCEPTION 'MailMirror delta account path is immutable.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER mail_delta_checkpoints_account_immutable
                BEFORE UPDATE ON mail_delta_checkpoints
                FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_delta_account_change();

                CREATE TRIGGER mail_source_changes_account_immutable
                BEFORE UPDATE ON mail_source_changes
                FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_delta_account_change();

                CREATE OR REPLACE FUNCTION mail_mirror_reject_source_change_identity_change()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.kind IS DISTINCT FROM OLD.kind
                        OR NEW.mail_message_id IS DISTINCT FROM OLD.mail_message_id
                        OR NEW.mail_raw_object_id IS DISTINCT FROM OLD.mail_raw_object_id
                        OR NEW.mail_container_id IS DISTINCT FROM OLD.mail_container_id
                        OR NEW.provider_message_id IS DISTINCT FROM OLD.provider_message_id
                        OR NEW.provider_container_id IS DISTINCT FROM OLD.provider_container_id
                        OR NEW.provider_deleted IS DISTINCT FROM OLD.provider_deleted THEN
                        RAISE EXCEPTION 'MailMirror source change identity is immutable.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER mail_source_changes_identity_immutable
                BEFORE UPDATE ON mail_source_changes
                FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_source_change_identity_change();
                SQL);

            return;
        }

        foreach (['mail_delta_checkpoints', 'mail_source_changes'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_account_immutable BEFORE UPDATE OF mail_account_id ON {$table} FOR EACH ROW WHEN NEW.mail_account_id != OLD.mail_account_id BEGIN SELECT RAISE(ABORT, 'MailMirror delta account path is immutable.'); END");
        }

        DB::unprepared("CREATE TRIGGER mail_source_changes_identity_immutable BEFORE UPDATE OF kind, mail_message_id, mail_raw_object_id, mail_container_id, provider_message_id, provider_container_id, provider_deleted ON mail_source_changes BEGIN SELECT RAISE(ABORT, 'MailMirror source change identity is immutable.'); END");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS mail_mirror_reject_source_change_identity_change() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS mail_mirror_reject_delta_account_change() CASCADE');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS mail_source_changes_identity_immutable');
            DB::statement('DROP TRIGGER IF EXISTS mail_source_changes_account_immutable');
            DB::statement('DROP TRIGGER IF EXISTS mail_delta_checkpoints_account_immutable');
        }

        Schema::dropIfExists('mail_source_changes');
        Schema::dropIfExists('mail_delta_checkpoints');
    }
};
