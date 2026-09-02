<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAttachment;
use Jkudish\MailMirror\Models\MailMessage;

function attachmentProviderIdMigration(): object
{
    $migration = require __DIR__.'/../../database/migrations/mail_mirror_expand_attachment_provider_id.php';

    if (! is_object($migration)) {
        throw new RuntimeException('The attachment provider ID migration could not be loaded.');
    }

    return $migration;
}

function attachmentProviderIdLength(): ?int
{
    if (DB::getDriverName() === 'sqlite') {
        $column = DB::selectOne("SELECT type FROM pragma_table_info('mail_attachments') WHERE name = 'provider_attachment_id'");
        $type = is_object($column) ? ($column->type ?? null) : null;

        if (is_string($type) && preg_match('/\((\d+)\)/', $type, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    } else {
        $length = DB::table('information_schema.columns')
            ->where('table_name', 'mail_attachments')
            ->where('column_name', 'provider_attachment_id')
            ->value('character_maximum_length');

        if (is_numeric($length)) {
            return (int) $length;
        }
    }

    throw new RuntimeException('The provider attachment ID length could not be inspected.');
}

it('expands legacy attachment IDs while preserving uniqueness and fails closed on unsafe rollback', function (): void {
    $migration = attachmentProviderIdMigration();
    // @phpstan-ignore method.notFound
    $migration->down();
    expect(attachmentProviderIdLength())->toBeIn([null, 255]);

    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-owner',
        'owner_id' => 'migration-owner',
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'migration-account',
    ]);
    $message = MailMessage::query()->create([
        'mail_account_id' => $account->id,
        'provider_message_id' => 'migration-message',
    ]);

    // @phpstan-ignore method.notFound
    $migration->up();
    $nativeId = str_repeat('a', 404);
    MailAttachment::query()->create([
        'mail_account_id' => $account->id,
        'mail_message_id' => $message->id,
        'provider_attachment_id' => $nativeId,
    ]);

    expect(attachmentProviderIdLength())->toBeIn([null, 512])
        ->and(fn () => MailAttachment::query()->create([
            'mail_account_id' => $account->id,
            'mail_message_id' => $message->id,
            'provider_attachment_id' => $nativeId,
        ]))->toThrow(QueryException::class)
        ->and(function () use ($migration): void {
            // @phpstan-ignore method.notFound
            $migration->down();
        })->toThrow(RuntimeException::class, 'cannot shrink provider attachment IDs')
        ->and(attachmentProviderIdLength())->toBeIn([null, 512]);

    MailAttachment::query()->delete();
    // @phpstan-ignore method.notFound
    $migration->down();
    expect(attachmentProviderIdLength())->toBeIn([null, 255]);

    // Restore the current schema for any later assertions in this process.
    // @phpstan-ignore method.notFound
    $migration->up();
});
