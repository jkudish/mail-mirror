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
        $duplicateRawSource = DB::table('mail_raw_objects')
            ->select(['mail_account_id', 'mail_message_id'])
            ->groupBy(['mail_account_id', 'mail_message_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateRawSource) {
            throw new RuntimeException('MailMirror object storage requires at most one raw source per message.');
        }

        Schema::table('mail_attachments', function (Blueprint $table): void {
            $table->string('source_part_id')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('storage_disk')->nullable();
            $table->string('object_key')->nullable();

            $table->unique(
                ['mail_account_id', 'mail_message_id', 'source_part_id'],
                'mail_attachment_source_part_unique',
            );
        });

        Schema::table('mail_raw_objects', function (Blueprint $table): void {
            $table->string('storage_disk')->nullable();
            $table->string('object_key')->nullable();

            $table->unique(
                ['mail_account_id', 'mail_message_id'],
                'mail_raw_object_message_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('mail_raw_objects', function (Blueprint $table): void {
            $table->dropUnique('mail_raw_object_message_unique');
            $table->dropColumn(['storage_disk', 'object_key']);
        });

        Schema::table('mail_attachments', function (Blueprint $table): void {
            $table->dropUnique('mail_attachment_source_part_unique');
            $table->dropColumn(['source_part_id', 'checksum', 'storage_disk', 'object_key']);
        });
    }
};
