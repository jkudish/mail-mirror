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
        Schema::table('mail_delta_checkpoints', function (Blueprint $table): void {
            $table->uuid('repair_scan_id')->nullable()->after('repair_cursor');
        });

        Schema::create('mail_delta_pending_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_id');
            $table->string('provider_thread_id')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'provider_message_id'], 'mail_delta_pending_message_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER mail_delta_pending_messages_account_immutable
                BEFORE UPDATE ON mail_delta_pending_messages
                FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_delta_account_change();

                CREATE OR REPLACE FUNCTION mail_mirror_reject_delta_pending_identity_change()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.provider_message_id IS DISTINCT FROM OLD.provider_message_id THEN
                        RAISE EXCEPTION 'MailMirror pending delta identity is immutable.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER mail_delta_pending_messages_identity_immutable
                BEFORE UPDATE ON mail_delta_pending_messages
                FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_delta_pending_identity_change();
                SQL);

            return;
        }

        DB::unprepared("CREATE TRIGGER mail_delta_pending_messages_account_immutable BEFORE UPDATE OF mail_account_id ON mail_delta_pending_messages FOR EACH ROW WHEN NEW.mail_account_id != OLD.mail_account_id BEGIN SELECT RAISE(ABORT, 'MailMirror delta account path is immutable.'); END");
        DB::unprepared("CREATE TRIGGER mail_delta_pending_messages_identity_immutable BEFORE UPDATE OF provider_message_id ON mail_delta_pending_messages BEGIN SELECT RAISE(ABORT, 'MailMirror pending delta identity is immutable.'); END");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS mail_mirror_reject_delta_pending_identity_change() CASCADE');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS mail_delta_pending_messages_identity_immutable');
            DB::statement('DROP TRIGGER IF EXISTS mail_delta_pending_messages_account_immutable');
        }

        Schema::dropIfExists('mail_delta_pending_messages');
        Schema::table('mail_delta_checkpoints', function (Blueprint $table): void {
            $table->dropColumn('repair_scan_id');
        });
    }
};
