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
        Schema::create('mail_gmail_watches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('project_id');
            $table->string('topic', 512);
            $table->string('subscription', 512);
            $table->string('history_id_hint');
            $table->timestampTz('expires_at');
            $table->timestamps();
        });

        Schema::create('mail_jmap_event_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('event_source_origin', 512);
            $table->string('last_event_id', 1024)->nullable();
            $table->timestamps();
        });

        foreach (['mail_gmail_watches', 'mail_jmap_event_sources'] as $table) {
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_account_immutable BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION mail_mirror_reject_delta_account_change()");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_account_immutable BEFORE UPDATE OF mail_account_id ON {$table} FOR EACH ROW WHEN NEW.mail_account_id != OLD.mail_account_id BEGIN SELECT RAISE(ABORT, 'MailMirror notification account path is immutable.'); END");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_jmap_event_sources');
        Schema::dropIfExists('mail_gmail_watches');
    }
};
