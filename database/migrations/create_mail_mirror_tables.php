<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create MailMirror's account-rooted provider record tables. */
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->enum('driver', ['gmail', 'jmap']);
            $table->string('provider_account_id');
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['driver', 'provider_account_id']);
        });

        Schema::create('mail_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_identity_id');
            $table->string('email_address')->nullable();
            $table->string('display_name')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'provider_identity_id']);
            $table->unique(['mail_account_id', 'id']);
        });

        Schema::create('mail_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_thread_id');
            $table->text('subject')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'provider_thread_id']);
            $table->unique(['mail_account_id', 'id']);
        });

        Schema::create('mail_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mail_thread_id')->nullable();
            $table->string('provider_message_id');
            $table->string('provider_occurrence_id');
            $table->text('subject')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'provider_message_id']);
            $table->unique(['mail_account_id', 'provider_occurrence_id']);
            $table->unique(['mail_account_id', 'id']);
            $table->foreign(['mail_account_id', 'mail_thread_id'])
                ->references(['mail_account_id', 'id'])
                ->on('mail_threads')
                ->restrictOnDelete();
        });

        Schema::create('mail_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_address_id')->nullable();
            $table->string('address');
            $table->string('display_name')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'address']);
            $table->unique(['mail_account_id', 'provider_address_id']);
            $table->unique(['mail_account_id', 'id']);
        });

        Schema::create('mail_message_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mail_message_id');
            $table->unsignedBigInteger('mail_address_id');
            $table->unsignedBigInteger('mail_identity_id')->nullable();
            $table->enum('role', ['from', 'sender', 'reply_to', 'to', 'cc', 'bcc']);
            $table->unsignedInteger('position')->default(0);
            $table->string('provider_participant_id')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'mail_message_id', 'role', 'position'], 'mail_participant_position_unique');
            $table->foreign(['mail_account_id', 'mail_message_id'])
                ->references(['mail_account_id', 'id'])->on('mail_messages')->cascadeOnDelete();
            $table->foreign(['mail_account_id', 'mail_address_id'])
                ->references(['mail_account_id', 'id'])->on('mail_addresses')->restrictOnDelete();
            $table->foreign(['mail_account_id', 'mail_identity_id'])
                ->references(['mail_account_id', 'id'])->on('mail_identities')->restrictOnDelete();
        });

        Schema::create('mail_message_headers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mail_message_id');
            $table->string('name');
            $table->text('value');
            $table->unsignedInteger('position')->default(0);
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'mail_message_id', 'name', 'position'], 'mail_header_position_unique');
            $table->foreign(['mail_account_id', 'mail_message_id'])
                ->references(['mail_account_id', 'id'])->on('mail_messages')->cascadeOnDelete();
        });

        Schema::create('mail_containers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_container_id');
            $table->string('name');
            $table->string('kind')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'provider_container_id']);
            $table->unique(['mail_account_id', 'id']);
        });

        Schema::create('mail_message_container_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mail_message_id');
            $table->unsignedBigInteger('mail_container_id');
            $table->string('provider_membership_id')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'mail_message_id', 'mail_container_id'], 'mail_container_membership_unique');
            $table->foreign(['mail_account_id', 'mail_message_id'])
                ->references(['mail_account_id', 'id'])->on('mail_messages')->cascadeOnDelete();
            $table->foreign(['mail_account_id', 'mail_container_id'])
                ->references(['mail_account_id', 'id'])->on('mail_containers')->cascadeOnDelete();
        });

        Schema::create('mail_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mail_message_id');
            $table->string('provider_attachment_id');
            $table->string('filename')->nullable();
            $table->string('media_type')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('content_id')->nullable();
            $table->boolean('is_inline')->default(false);
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'provider_attachment_id']);
            $table->foreign(['mail_account_id', 'mail_message_id'])
                ->references(['mail_account_id', 'id'])->on('mail_messages')->cascadeOnDelete();
        });

        Schema::create('mail_raw_objects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mail_message_id')->nullable();
            $table->string('provider_object_id');
            $table->string('kind');
            $table->string('media_type')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('checksum')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'kind', 'provider_object_id'], 'mail_raw_object_provider_unique');
            $table->foreign(['mail_account_id', 'mail_message_id'])
                ->references(['mail_account_id', 'id'])->on('mail_messages')->restrictOnDelete();
        });

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
            $table->foreign(['mail_account_id', 'mail_sync_run_id'])
                ->references(['mail_account_id', 'id'])->on('mail_sync_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sync_checkpoints');
        Schema::dropIfExists('mail_sync_runs');
        Schema::dropIfExists('mail_raw_objects');
        Schema::dropIfExists('mail_attachments');
        Schema::dropIfExists('mail_message_container_memberships');
        Schema::dropIfExists('mail_containers');
        Schema::dropIfExists('mail_message_headers');
        Schema::dropIfExists('mail_message_participants');
        Schema::dropIfExists('mail_addresses');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_threads');
        Schema::dropIfExists('mail_identities');
        Schema::dropIfExists('mail_accounts');
    }
};
