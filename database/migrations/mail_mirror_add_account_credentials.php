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
        Schema::create('mail_account_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->enum('credential_type', ['oauth_token_set', 'api_token']);
            $table->unsignedSmallInteger('schema_version');
            $table->text('encrypted_payload');
            $table->enum('status', ['ready', 'disabled', 'revoked']);
            $table->unsignedBigInteger('version');
            $table->timestampTz('last_activated_at');
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('mail_account_id');
            $table->unique(['mail_account_id', 'id']);
            $table->index('status');
        });

        Schema::create('mail_account_credential_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mail_account_credential_id');
            $table->unsignedBigInteger('version');
            $table->enum('transition', ['stored', 'rotated', 'disabled', 'revoked', 'reconnected']);
            $table->enum('credential_type', ['oauth_token_set', 'api_token']);
            $table->unsignedSmallInteger('schema_version');
            $table->enum('from_status', ['ready', 'disabled', 'revoked'])->nullable();
            $table->enum('to_status', ['ready', 'disabled', 'revoked']);
            $table->timestampTz('occurred_at');

            $table->unique(
                ['mail_account_id', 'mail_account_credential_id', 'version'],
                'mail_credential_history_version_unique',
            );
            $table->foreign(['mail_account_id', 'mail_account_credential_id'])
                ->references(['mail_account_id', 'id'])
                ->on('mail_account_credentials')
                ->cascadeOnDelete();
        });

        $this->protectAccountIdentity();
        $this->protectCredentialIdentityAndHistory();
    }

    private function protectAccountIdentity(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION mail_mirror_reject_account_identity_change()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.driver <> OLD.driver
                        OR NEW.provider_account_id <> OLD.provider_account_id
                        OR (
                            OLD.owner_type IS NOT NULL
                            AND (NEW.owner_type IS DISTINCT FROM OLD.owner_type OR NEW.owner_id IS DISTINCT FROM OLD.owner_id)
                        )
                    THEN
                        RAISE EXCEPTION 'MailMirror account identity is immutable.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER mail_mirror_account_identity_immutable
                BEFORE UPDATE ON mail_accounts
                FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_account_identity_change();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER mail_mirror_account_identity_immutable
            BEFORE UPDATE OF owner_type, owner_id, driver, provider_account_id ON mail_accounts
            FOR EACH ROW
            WHEN NEW.driver != OLD.driver
                OR NEW.provider_account_id != OLD.provider_account_id
                OR (
                    OLD.owner_type IS NOT NULL
                    AND (NEW.owner_type IS NOT OLD.owner_type OR NEW.owner_id IS NOT OLD.owner_id)
                )
            BEGIN
                SELECT RAISE(ABORT, 'MailMirror account identity is immutable.');
            END;
            SQL);
    }

    private function protectCredentialIdentityAndHistory(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION mail_mirror_reject_credential_account_change()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.mail_account_id <> OLD.mail_account_id THEN
                        RAISE EXCEPTION 'MailMirror credential account identity is immutable.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER mail_mirror_credential_account_immutable
                BEFORE UPDATE ON mail_account_credentials
                FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_credential_account_change();

                CREATE OR REPLACE FUNCTION mail_mirror_reject_credential_history_change()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'MailMirror credential history is immutable.';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER mail_mirror_credential_history_immutable
                BEFORE UPDATE OR DELETE ON mail_account_credential_history
                FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_credential_history_change();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER mail_mirror_credential_account_immutable
            BEFORE UPDATE OF mail_account_id ON mail_account_credentials
            FOR EACH ROW
            WHEN NEW.mail_account_id != OLD.mail_account_id
            BEGIN
                SELECT RAISE(ABORT, 'MailMirror credential account identity is immutable.');
            END;

            CREATE TRIGGER mail_mirror_credential_history_update_immutable
            BEFORE UPDATE ON mail_account_credential_history
            BEGIN
                SELECT RAISE(ABORT, 'MailMirror credential history is immutable.');
            END;

            CREATE TRIGGER mail_mirror_credential_history_delete_immutable
            BEFORE DELETE ON mail_account_credential_history
            BEGIN
                SELECT RAISE(ABORT, 'MailMirror credential history is immutable.');
            END;
            SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS mail_mirror_reject_credential_history_change() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS mail_mirror_reject_credential_account_change() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS mail_mirror_reject_account_identity_change() CASCADE');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS mail_mirror_account_identity_immutable');
        }

        Schema::dropIfExists('mail_account_credential_history');
        Schema::dropIfExists('mail_account_credentials');
    }
};
