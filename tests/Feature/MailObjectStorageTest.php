<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Exceptions\ImmutableObjectConflict;
use Jkudish\MailMirror\Exceptions\MailObjectException;
use Jkudish\MailMirror\Exceptions\ObjectIntegrityFailure;
use Jkudish\MailMirror\Exceptions\StaleCheckpoint;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAddress;
use Jkudish\MailMirror\Models\MailAttachment;
use Jkudish\MailMirror\Models\MailLocalMessagePurge;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailMessageHeader;
use Jkudish\MailMirror\Models\MailMessageParticipant;
use Jkudish\MailMirror\Models\MailRawObject;
use Jkudish\MailMirror\Models\MailSyncCheckpoint;
use Jkudish\MailMirror\Models\MailThread;
use Jkudish\MailMirror\Storage\MailObjectStorage;
use Mockery\MockInterface;
use ZBateson\MailMimeParser\MailMimeParser;

beforeEach(function (): void {
    Storage::fake('mail-mirror-test');
    config()->set('mail-mirror.storage_disk', 'mail-mirror-test');
});

/** @return array{MailAccount, MailMessage} */
function storageMessage(string $suffix): array
{
    $account = MailAccount::query()->create([
        'driver' => MailDriver::Gmail,
        'provider_account_id' => "storage-account-{$suffix}",
    ]);
    $message = MailMessage::query()->create([
        'mail_account_id' => $account->id,
        'provider_message_id' => "storage-message-{$suffix}",
    ]);

    return [$account, $message];
}

/** @return resource */
function storageStream(string $contents): mixed
{
    $stream = fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new RuntimeException('Synthetic stream could not be opened.');
    }

    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

/** @return resource */
function fixtureStream(): mixed
{
    $stream = fopen(__DIR__.'/../Fixtures/synthetic-message.eml', 'rb');

    if ($stream === false) {
        throw new RuntimeException('Synthetic fixture is unavailable.');
    }

    return $stream;
}

it('streams one private raw source through an explicitly matched account and message', function (): void {
    [$account, $message] = storageMessage('same-account');
    $source = fixtureStream();
    $bytes = stream_get_contents($source);
    rewind($source);

    $raw = app(MailObjectStorage::class)->storeRaw($account, $message, $source, 'provider-raw-001');
    fclose($source);
    $read = app(MailObjectStorage::class)->readRaw($account, $raw);
    $objectKey = $raw->object_key;
    assert(is_string($objectKey));

    expect(stream_get_contents($read))->toBe($bytes)
        ->and($raw->checksum)->toBe(hash('sha256', $bytes))
        ->and($raw->byte_size)->toBe(strlen($bytes))
        ->and($raw->media_type)->toBe('message/rfc822')
        ->and($objectKey)->toStartWith("mail-mirror/accounts/{$account->id}/messages/{$message->id}/raw/sha256/")
        ->and($raw->toArray())->not->toHaveKey('object_key');
    fclose($read);
    Storage::disk('mail-mirror-test')->assertExists($objectKey);
    expect(Storage::disk('mail-mirror-test')->getVisibility($objectKey))->toBe('private');
});

it('denies cross-account reads and writes even when provider identifiers match', function (): void {
    [$first, $firstMessage] = storageMessage('first-boundary');
    [$second, $secondMessage] = storageMessage('second-boundary');
    $secondMessage->provider_message_id = $firstMessage->provider_message_id;
    $secondMessage->save();
    $source = storageStream('invented private bytes');
    $raw = app(MailObjectStorage::class)->storeRaw($first, $firstMessage, $source, 'shared-provider-object');
    fclose($source);

    expect(fn () => app(MailObjectStorage::class)->readRaw($second, $raw))
        ->toThrow(AccountResourceMismatch::class)
        ->and(fn () => app(MailObjectStorage::class)->storeRaw($second, $firstMessage, storageStream('invented private bytes'), 'shared-provider-object'))
        ->toThrow(AccountResourceMismatch::class);

    $secondSource = storageStream('invented private bytes');
    $secondRaw = app(MailObjectStorage::class)->storeRaw($second, $secondMessage, $secondSource, 'shared-provider-object');
    fclose($secondSource);

    expect($secondRaw->object_key)->not->toBe($raw->object_key)
        ->and($secondRaw->object_key)->toStartWith("mail-mirror/accounts/{$second->id}/");
});

it('converges identical retries and rejects immutable byte or metadata changes', function (): void {
    [$account, $message] = storageMessage('idempotent');
    $firstSource = storageStream('stable synthetic raw bytes');
    $first = app(MailObjectStorage::class)->storeRaw(
        $account,
        $message,
        $firstSource,
        'stable-provider-object',
        providerMetadata: ['native' => 'stable', 'nested' => ['first' => 1, 'second' => 2]],
    );
    fclose($firstSource);
    $retrySource = storageStream('stable synthetic raw bytes');
    $retry = app(MailObjectStorage::class)->storeRaw(
        $account,
        $message,
        $retrySource,
        'stable-provider-object',
        providerMetadata: ['native' => 'stable', 'nested' => ['first' => 1, 'second' => 2]],
    );
    fclose($retrySource);

    expect($retry->is($first))->toBeTrue()
        ->and(MailRawObject::query()->count())->toBe(1);

    $reorderedMetadata = storageStream('stable synthetic raw bytes');
    $reorderedRetry = app(MailObjectStorage::class)->storeRaw(
        $account,
        $message,
        $reorderedMetadata,
        'stable-provider-object',
        providerMetadata: ['nested' => ['second' => 2, 'first' => 1], 'native' => 'stable'],
    );
    fclose($reorderedMetadata);

    expect($reorderedRetry->is($first))->toBeTrue();

    $different = storageStream('different synthetic raw bytes');
    expect(fn () => app(MailObjectStorage::class)->storeRaw($account, $message, $different, 'stable-provider-object'))
        ->toThrow(ImmutableObjectConflict::class);
    fclose($different);

    $changedMetadata = storageStream('stable synthetic raw bytes');
    expect(fn () => app(MailObjectStorage::class)->storeRaw(
        $account,
        $message,
        $changedMetadata,
        'stable-provider-object',
        providerMetadata: ['native' => 'changed', 'nested' => ['first' => 1, 'second' => 2]],
    ))->toThrow(ImmutableObjectConflict::class);
    fclose($changedMetadata);

    $first->checksum = str_repeat('0', 64);
    expect(fn () => $first->save())->toThrow(LogicException::class);
    $first->refresh();
    $first->provider_metadata = ['native' => 'changed'];
    expect(fn () => $first->save())->toThrow(LogicException::class);
});

it('cleans only unreferenced rollback objects in the supplied account namespace', function (): void {
    [$account, $message] = storageMessage('rollback-cleanup-boundary');
    [$other] = storageMessage('rollback-cleanup-other');
    $source = storageStream('durable rollback cleanup bytes');
    $storage = app(MailObjectStorage::class);
    $raw = $storage->storeRaw($account, $message, $source, 'rollback-cleanup-object');
    fclose($source);
    $key = $raw->object_key;
    assert(is_string($key));

    $storage->cleanupRolledBackRaw($account, $key);
    Storage::disk('mail-mirror-test')->assertExists($key);

    $orphan = "mail-mirror/accounts/{$account->id}/messages/999/raw/sha256/".str_repeat('a', 64);
    Storage::disk('mail-mirror-test')->put($orphan, 'invented orphan bytes');
    $storage->cleanupRolledBackRaw($account, $orphan);

    Storage::disk('mail-mirror-test')->assertMissing($orphan);
    expect(fn () => $storage->cleanupRolledBackRaw($other, $key))
        ->toThrow(AccountResourceMismatch::class);
    Storage::disk('mail-mirror-test')->assertExists($key);
});

it('purges the matched normalized message and objects while preserving shared records', function (): void {
    [$account, $message] = storageMessage('local-purge');
    $account->forceFill(['owner_type' => 'synthetic-owner', 'owner_id' => 'owner-one'])->save();
    $thread = MailThread::query()->create([
        'mail_account_id' => $account->id,
        'provider_thread_id' => 'shared-purge-thread',
        'subject' => 'Shared invented subject',
    ]);
    $message->forceFill([
        'mail_thread_id' => $thread->id,
        'internet_message_id' => '<purged@invented.test>',
        'subject' => 'Private invented purge subject',
        'provider_metadata' => ['private' => 'invented normalized metadata'],
    ])->save();
    $remaining = MailMessage::query()->create([
        'mail_account_id' => $account->id,
        'mail_thread_id' => $thread->id,
        'provider_message_id' => 'remaining-shared-message',
    ]);
    $sharedAddress = MailAddress::query()->create([
        'mail_account_id' => $account->id,
        'address' => 'shared@invented.test',
    ]);
    $orphanedAddress = MailAddress::query()->create([
        'mail_account_id' => $account->id,
        'address' => 'purged-only@invented.test',
    ]);

    foreach ([[$message, $sharedAddress], [$message, $orphanedAddress], [$remaining, $sharedAddress]] as $position => [$participantMessage, $address]) {
        MailMessageParticipant::query()->create([
            'mail_account_id' => $account->id,
            'mail_message_id' => $participantMessage->id,
            'mail_address_id' => $address->id,
            'role' => 'to',
            'position' => $position,
        ]);
    }

    MailMessageHeader::query()->create([
        'mail_account_id' => $account->id,
        'mail_message_id' => $message->id,
        'name' => 'X-Invented-Private',
        'value' => 'private normalized header',
        'position' => 0,
    ]);
    $checkpoint = MailSyncCheckpoint::query()->create([
        'mail_account_id' => $account->id,
        'scan_id' => '11111111-1111-4111-8111-111111111111',
        'version' => 7,
        'processed_count' => 1,
        'scan_started_at' => now(),
        'scan_completed_at' => now(),
    ]);
    $rawSource = storageStream('invented purge raw bytes');
    $raw = app(MailObjectStorage::class)->storeRaw($account, $message, $rawSource, 'purge-raw');
    fclose($rawSource);
    $attachmentSource = storageStream('invented purge attachment bytes');
    $attachment = app(MailObjectStorage::class)->storeAttachment(
        $account,
        $message,
        $attachmentSource,
        'purge-attachment',
        'mime:2',
        'text/plain',
    );
    fclose($attachmentSource);
    $rawKey = $raw->object_key;
    $attachmentKey = $attachment->object_key;
    assert(is_string($rawKey) && is_string($attachmentKey));

    expect(fn () => app(MailObjectStorage::class)->purgeMessage(
        $account->id,
        'synthetic-owner',
        'wrong-owner',
        $message->provider_message_id,
        7,
    ))->toThrow(AccountResourceMismatch::class)
        ->and(fn () => app(MailObjectStorage::class)->purgeMessage(
            $account->id,
            'synthetic-owner',
            'owner-one',
            $message->provider_message_id,
            6,
        ))->toThrow(StaleCheckpoint::class)
        ->and(app(MailObjectStorage::class)->purgeMessage(
            $account->id,
            'synthetic-owner',
            'owner-one',
            $message->provider_message_id,
            7,
        ))->toBeTrue()
        ->and(app(MailObjectStorage::class)->purgeMessage(
            $account->id,
            'synthetic-owner',
            'owner-one',
            $message->provider_message_id,
            7,
        ))->toBeFalse()
        ->and(MailRawObject::query()->forAccount($account)->count())->toBe(0)
        ->and(MailAttachment::query()->forAccount($account)->count())->toBe(0)
        ->and(MailMessage::query()->forAccount($account)->whereKey($message->id)->exists())->toBeFalse()
        ->and(MailMessageHeader::query()->forAccount($account)->where('mail_message_id', $message->id)->count())->toBe(0)
        ->and(MailMessageParticipant::query()->forAccount($account)->where('mail_message_id', $message->id)->count())->toBe(0)
        ->and(MailLocalMessagePurge::query()->forAccount($account)->where('provider_message_id', $message->provider_message_id)->count())->toBe(1)
        ->and(MailThread::query()->forAccount($account)->whereKey($thread->id)->exists())->toBeTrue()
        ->and(MailAddress::query()->forAccount($account)->whereKey($sharedAddress->id)->exists())->toBeTrue()
        ->and(MailAddress::query()->forAccount($account)->whereKey($orphanedAddress->id)->exists())->toBeFalse()
        ->and($checkpoint->refresh()->version)->toBe(8);
    Storage::disk('mail-mirror-test')->assertMissing([$rawKey, $attachmentKey]);

    $lateSource = storageStream('invented late materialization');
    expect(fn () => app(MailObjectStorage::class)->storeAttachment(
        $account,
        $message,
        $lateSource,
        'late-attachment',
        'mime:3',
        'text/plain',
    ))->toThrow(AccountResourceMismatch::class);
    fclose($lateSource);
});

it('preserves every object reference when one filesystem deletion fails and converges on retry', function (): void {
    [$account, $message] = storageMessage('partial-local-purge');
    $checkpoint = MailSyncCheckpoint::query()->create([
        'mail_account_id' => $account->id,
        'scan_id' => '22222222-2222-4222-8222-222222222222',
        'version' => 3,
        'processed_count' => 1,
        'scan_started_at' => now(),
        'scan_completed_at' => now(),
    ]);
    $prefix = "mail-mirror/accounts/{$account->id}/messages/{$message->id}";
    $rawKey = "{$prefix}/raw/sha256/".str_repeat('a', 64);
    $attachmentKey = "{$prefix}/attachments/part/sha256/".str_repeat('b', 64);
    MailRawObject::query()->create([
        'mail_account_id' => $account->id,
        'mail_message_id' => $message->id,
        'provider_object_id' => 'partial-purge-raw',
        'kind' => 'rfc822',
        'storage_disk' => 'mail-mirror-test',
        'object_key' => $rawKey,
    ]);
    MailAttachment::query()->create([
        'mail_account_id' => $account->id,
        'mail_message_id' => $message->id,
        'provider_attachment_id' => 'partial-purge-attachment',
        'source_part_id' => 'mime:2',
        'storage_disk' => 'mail-mirror-test',
        'object_key' => $attachmentKey,
    ]);
    $disk = mock(Filesystem::class, function (MockInterface $mock) use ($rawKey, $attachmentKey): void {
        $mock->shouldReceive('exists')->with($rawKey)->twice()->andReturn(true, false);
        $mock->shouldReceive('delete')->with($rawKey)->once()->andReturnTrue();
        $mock->shouldReceive('exists')->with($attachmentKey)->once()->andReturnTrue();
        $mock->shouldReceive('delete')->with($attachmentKey)->once()->andReturnFalse();
    });
    /** @var FilesystemManager&MockInterface $manager */
    $manager = mock(FilesystemManager::class, function (MockInterface $mock) use ($disk): void {
        $mock->shouldReceive('disk')->with('mail-mirror-test')->andReturn($disk);
    });
    $storage = new MailObjectStorage($manager, new MailMimeParser);

    expect(fn () => $storage->purgeMessage(
        $account->id,
        null,
        null,
        $message->provider_message_id,
        3,
    ))->toThrow(ObjectIntegrityFailure::class)
        ->and(MailRawObject::query()->forAccount($account)->count())->toBe(1)
        ->and(MailAttachment::query()->forAccount($account)->count())->toBe(1)
        ->and(MailMessage::query()->forAccount($account)->whereKey($message->id)->exists())->toBeTrue()
        ->and(MailLocalMessagePurge::query()->forAccount($account)->count())->toBe(0)
        ->and($checkpoint->refresh()->version)->toBe(3);

    expect(app(MailObjectStorage::class)->purgeMessage(
        $account->id,
        null,
        null,
        $message->provider_message_id,
        3,
    ))->toBeTrue()
        ->and(MailRawObject::query()->forAccount($account)->count())->toBe(0)
        ->and(MailAttachment::query()->forAccount($account)->count())->toBe(0)
        ->and(MailLocalMessagePurge::query()->forAccount($account)->count())->toBe(1);
});

it('keeps durable retry references when an enclosing host transaction rolls back after filesystem deletion', function (): void {
    [$account, $message] = storageMessage('outer-transaction-purge');
    $checkpoint = MailSyncCheckpoint::query()->create([
        'mail_account_id' => $account->id,
        'scan_id' => '33333333-3333-4333-8333-333333333333',
        'version' => 4,
        'processed_count' => 1,
        'scan_started_at' => now(),
        'scan_completed_at' => now(),
    ]);
    $rawSource = storageStream('invented outer transaction raw bytes');
    $raw = app(MailObjectStorage::class)->storeRaw($account, $message, $rawSource, 'outer-transaction-raw');
    fclose($rawSource);
    $rawKey = $raw->object_key;
    assert(is_string($rawKey));

    expect(function () use ($account, $message): void {
        DB::transaction(function () use ($account, $message): void {
            app(MailObjectStorage::class)->purgeMessage(
                $account->id,
                null,
                null,
                $message->provider_message_id,
                4,
            );

            throw new RuntimeException('Force the host eligibility transaction to roll back.');
        });
    })->toThrow(RuntimeException::class)
        ->and(MailRawObject::query()->forAccount($account)->count())->toBe(1)
        ->and(MailMessage::query()->forAccount($account)->whereKey($message->id)->exists())->toBeTrue()
        ->and(MailLocalMessagePurge::query()->forAccount($account)->count())->toBe(0)
        ->and($checkpoint->refresh()->version)->toBe(4);
    Storage::disk('mail-mirror-test')->assertMissing($rawKey);

    expect(app(MailObjectStorage::class)->purgeMessage(
        $account->id,
        null,
        null,
        $message->provider_message_id,
        4,
    ))->toBeTrue()
        ->and(MailRawObject::query()->forAccount($account)->count())->toBe(0)
        ->and(MailMessage::query()->forAccount($account)->whereKey($message->id)->exists())->toBeFalse()
        ->and(MailLocalMessagePurge::query()->forAccount($account)->count())->toBe(1)
        ->and($checkpoint->refresh()->version)->toBe(5);
});

it('enforces database uniqueness as the final concurrent-write guard and adopts recoverable objects', function (): void {
    [$account, $message] = storageMessage('race');
    $bytes = 'synthetic race bytes';
    $checksum = hash('sha256', $bytes);
    $canonical = "mail-mirror/accounts/{$account->id}/messages/{$message->id}/raw/sha256/{$checksum}";
    Storage::disk('mail-mirror-test')->put($canonical, $bytes, ['visibility' => 'public']);

    $source = storageStream($bytes);
    $raw = app(MailObjectStorage::class)->storeRaw($account, $message, $source, 'race-provider-object');
    fclose($source);

    expect($raw->object_key)->toBe($canonical)
        ->and(Storage::disk('mail-mirror-test')->getVisibility($canonical))->toBe('private')
        ->and(fn () => MailRawObject::query()->create([
            'mail_account_id' => $account->id,
            'mail_message_id' => $message->id,
            'provider_object_id' => 'losing-race-object',
            'kind' => 'rfc822',
        ]))->toThrow(QueryException::class);
});

it('cleans a partial canonical object when streaming finalization is interrupted', function (): void {
    $disk = mock(Filesystem::class, function (MockInterface $mock): void {
        $mock->shouldReceive('exists')->once()->andReturnFalse();
        $mock->shouldReceive('writeStream')->once()->andReturnFalse();
        $mock->shouldReceive('delete')->once()->andReturnTrue();
    });
    /** @var FilesystemManager&MockInterface $manager */
    $manager = mock(FilesystemManager::class, function (MockInterface $mock) use ($disk): void {
        $mock->shouldReceive('disk')->with('mail-mirror-test')->andReturn($disk);
    });
    [$account, $message] = storageMessage('interrupted');
    $source = storageStream('private interrupted marker');

    expect(fn () => (new MailObjectStorage($manager, new MailMimeParser))->storeRaw(
        $account,
        $message,
        $source,
        'interrupted-provider-object',
    ))->toThrow(ObjectIntegrityFailure::class, 'could not be finalized');
    fclose($source);

    expect(MailRawObject::query()->count())->toBe(0);
});

it('repairs a corrupt canonical object on an identical retry', function (): void {
    [$account, $message] = storageMessage('repair');
    $bytes = 'synthetic recoverable bytes';
    $checksum = hash('sha256', $bytes);
    $canonical = "mail-mirror/accounts/{$account->id}/messages/{$message->id}/raw/sha256/{$checksum}";
    Storage::disk('mail-mirror-test')->put($canonical, 'partial', ['visibility' => 'private']);
    $source = storageStream($bytes);

    $raw = app(MailObjectStorage::class)->storeRaw($account, $message, $source, 'repair-provider-object');
    fclose($source);

    expect($raw->object_key)->toBe($canonical)
        ->and(Storage::disk('mail-mirror-test')->get($canonical))->toBe($bytes)
        ->and(MailRawObject::query()->count())->toBe(1);
});

it('redacts database failures at the storage boundary', function (): void {
    [$account, $message] = storageMessage('database-redaction');
    $postgres = DB::connection()->getDriverName() === 'pgsql';

    if ($postgres) {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS reject_private_raw_metadata_function() CASCADE;

            CREATE FUNCTION reject_private_raw_metadata_function()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'private-database-marker';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER reject_private_raw_metadata
            BEFORE INSERT ON mail_raw_objects
            FOR EACH ROW EXECUTE FUNCTION reject_private_raw_metadata_function();
            SQL);
    } else {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_private_raw_metadata
            BEFORE INSERT ON mail_raw_objects
            BEGIN
                SELECT RAISE(ABORT, 'private-database-marker');
            END
            SQL);
    }

    $source = storageStream('private bytes hidden from database errors');

    try {
        app(MailObjectStorage::class)->storeRaw(
            $account,
            $message,
            $source,
            'database-error-provider-object',
            providerMetadata: ['private' => 'metadata-marker'],
        );
        throw new RuntimeException('Expected database failure redaction.');
    } catch (MailObjectException $exception) {
        expect($exception->getMessage())->toBe('Raw source storage could not be persisted.')
            ->and($exception->getMessage())->not->toContain('private-database-marker')
            ->and($exception->getMessage())->not->toContain('metadata-marker')
            ->and($exception->getPrevious())->toBeNull();
    } finally {
        fclose($source);

        if ($postgres) {
            DB::statement('DROP TRIGGER reject_private_raw_metadata ON mail_raw_objects');
            DB::statement('DROP FUNCTION reject_private_raw_metadata_function');
        } else {
            DB::statement('DROP TRIGGER reject_private_raw_metadata');
        }
    }
});

it('detects missing and corrupt objects without exposing content or storage keys', function (): void {
    [$account, $message] = storageMessage('integrity');
    $privateMarker = 'private-invented-marker-3095';
    $source = storageStream($privateMarker);
    $raw = app(MailObjectStorage::class)->storeRaw($account, $message, $source, 'integrity-provider-object');
    fclose($source);
    $objectKey = $raw->object_key;
    $checksum = $raw->checksum;
    assert(is_string($objectKey));
    assert(is_string($checksum));
    Storage::disk('mail-mirror-test')->put($objectKey, 'corrupt');

    try {
        app(MailObjectStorage::class)->readRaw($account, $raw);
        throw new RuntimeException('Expected corrupt object detection.');
    } catch (ObjectIntegrityFailure $exception) {
        expect($exception->getMessage())->not->toContain($privateMarker);
        expect($exception->getMessage())->not->toContain($objectKey);
        expect($exception->getMessage())->not->toContain($checksum);
    }

    $rawReport = app(MailObjectStorage::class)->integrityReport($account, $message)['raw'];
    assert($rawReport !== null);
    expect($rawReport['status'])->toBe('size_mismatch');

    Storage::disk('mail-mirror-test')->delete($objectKey);
    expect(fn () => app(MailObjectStorage::class)->readRaw($account, $raw))
        ->toThrow(ObjectIntegrityFailure::class, 'missing or unreadable');
});

it('materializes and regenerates attachments from an unchanged synthetic MIME source', function (): void {
    [$account, $message] = storageMessage('regeneration');
    $source = fixtureStream();
    $raw = app(MailObjectStorage::class)->storeRaw($account, $message, $source, 'mime-provider-object');
    fclose($source);
    $originalRawChecksum = $raw->checksum;

    [$attachment] = app(MailObjectStorage::class)->regenerateAttachments($account, $raw);
    $attachmentKey = $attachment->object_key;
    assert(is_string($attachmentKey));
    $read = app(MailObjectStorage::class)->readAttachment($account, $attachment);

    expect(stream_get_contents($read))->toBe("Synthetic attachment bytes.\n")
        ->and($attachment->source_part_id)->toStartWith('mime:')
        ->and($attachment->media_type)->toBe('text/plain');
    fclose($read);

    $attachment->filename = 'replacement.txt';
    expect(fn () => $attachment->save())->toThrow(LogicException::class);
    $attachment->refresh();

    Storage::disk('mail-mirror-test')->delete($attachmentKey);
    [$regenerated] = app(MailObjectStorage::class)->regenerateAttachments($account, $raw);

    expect($regenerated->is($attachment))->toBeTrue()
        ->and(Storage::disk('mail-mirror-test')->exists($attachmentKey))->toBeTrue()
        ->and($raw->refresh()->checksum)->toBe($originalRawChecksum)
        ->and(MailAttachment::query()->count())->toBe(1);
});

it('regenerates an attachment represented by the MIME message root', function (): void {
    [$account, $message] = storageMessage('root-attachment');
    $source = storageStream(<<<'EML'
        From: Synthetic Sender <sender@root-attachment.test>
        To: Synthetic Recipient <recipient@root-attachment.test>
        Subject: Synthetic root attachment
        MIME-Version: 1.0
        Content-Type: application/octet-stream
        Content-Disposition: attachment; filename="synthetic-root.bin"

        Synthetic root attachment bytes.
        EML);
    $raw = app(MailObjectStorage::class)->storeRaw($account, $message, $source, 'root-attachment-object');
    fclose($source);

    [$attachment] = app(MailObjectStorage::class)->regenerateAttachments($account, $raw);

    expect($attachment->source_part_id)->toBe('mime:root')
        ->and($attachment->filename)->toBe('synthetic-root.bin');
});

it('returns a metadata-only machine-readable integrity report', function (): void {
    [$account, $message] = storageMessage('report');
    $source = fixtureStream();
    $raw = app(MailObjectStorage::class)->storeRaw($account, $message, $source, 'report-provider-object');
    fclose($source);
    app(MailObjectStorage::class)->regenerateAttachments($account, $raw);

    $report = app(MailObjectStorage::class)->integrityReport($account, $message);
    $json = json_encode($report, JSON_THROW_ON_ERROR);
    $rawReport = $report['raw'];
    assert($rawReport !== null);

    expect($report)->toHaveKeys(['account_id', 'message_id', 'raw', 'attachments'])
        ->and($rawReport['status'])->toBe('healthy')
        ->and($report['attachments'][0]['status'])->toBe('healthy')
        ->and($report['attachments'][0])->toHaveKeys(['resource_id', 'source_part_id', 'media_type', 'byte_size', 'checksum', 'status']);
    expect($json)->not->toContain('Private synthetic body marker');
    expect($json)->not->toContain('Synthetic attachment bytes');
    expect($json)->not->toContain('invented-note.txt');
    expect($json)->not->toContain('object_key');
    expect($json)->not->toContain('provider');
});
