<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_local_message_purges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_id');
            $table->timestampTz('purged_at');
            $table->timestamps();

            $table->unique(['mail_account_id', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_local_message_purges');
    }
};
