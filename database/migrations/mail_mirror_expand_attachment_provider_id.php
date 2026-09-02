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
        Schema::table('mail_attachments', function (Blueprint $table): void {
            $table->string('provider_attachment_id', 512)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('mail_attachments')->whereRaw('LENGTH(provider_attachment_id) > 255')->exists()) {
            throw new RuntimeException('MailMirror cannot shrink provider attachment IDs while longer values exist.');
        }

        Schema::table('mail_attachments', function (Blueprint $table): void {
            $table->string('provider_attachment_id')->change();
        });
    }
};
