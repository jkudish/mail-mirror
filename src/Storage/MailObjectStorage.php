<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemManager;
use InvalidArgumentException;
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
use Jkudish\MailMirror\Models\MailMessageParticipant;
use Jkudish\MailMirror\Models\MailRawObject;
use Jkudish\MailMirror\Models\MailSyncCheckpoint;
use Jkudish\MailMirror\Models\MailThread;
use Throwable;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

final class MailObjectStorage
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
        private readonly MailMimeParser $mimeParser,
    ) {}

    /**
     * @param  resource  $source
     * @param  array<string, mixed>  $providerMetadata
     */
    public function storeRaw(
        MailAccount $account,
        MailMessage $message,
        mixed $source,
        string $providerObjectId,
        string $mediaType = 'message/rfc822',
        array $providerMetadata = [],
    ): MailRawObject {
        $message = $this->matchedMessage($account, $message);
        $diskName = $this->diskName();
        $disk = $this->filesystems->disk($diskName);
        $staged = $this->stage($source);
        $key = $this->canonicalKey($account->id, $message->id, 'raw', $staged['checksum']);
        $attributes = [
            'provider_object_id' => $providerObjectId,
            'kind' => 'rfc822',
            'media_type' => $mediaType,
            'byte_size' => $staged['byte_size'],
            'checksum' => $staged['checksum'],
            'storage_disk' => $diskName,
            'object_key' => $key,
            'provider_metadata' => $providerMetadata,
        ];

        try {
            return $message->getConnection()->transaction(function () use (
                $account,
                $message,
                $disk,
                $staged,
                $key,
                $attributes,
            ): MailRawObject {
                $this->lockMatchedMessage($account, $message);
                $existing = MailRawObject::query()
                    ->forAccount($account)
                    ->where('mail_message_id', $message->id)
                    ->first();

                if ($existing !== null) {
                    if (! $this->matches($existing, $attributes)) {
                        throw new ImmutableObjectConflict('The raw source is immutable and already contains different bytes or metadata.');
                    }

                    $this->placeCanonicalObject($disk, $staged['stream'], $key, $staged['checksum'], $staged['byte_size']);

                    return $existing;
                }

                $raw = MailRawObject::query()->create($attributes + [
                    'mail_account_id' => $account->id,
                    'mail_message_id' => $message->id,
                ]);

                $this->placeCanonicalObject($disk, $staged['stream'], $key, $staged['checksum'], $staged['byte_size']);

                return $raw;
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                $existing = $this->rawAfterRace($account, $message);

                if ($existing !== null) {
                    if (! $this->matches($existing, $attributes)) {
                        throw new ImmutableObjectConflict('The raw source is immutable and already contains different bytes or metadata.');
                    }

                    $this->placeCanonicalObject($disk, $staged['stream'], $key, $staged['checksum'], $staged['byte_size']);

                    return $existing;
                }
            }

            throw new MailObjectException('Raw source storage could not be persisted.');
        } finally {
            fclose($staged['stream']);
        }
    }

    /**
     * @param  resource  $source
     * @param  array<string, mixed>  $providerMetadata
     */
    public function storeAttachment(
        MailAccount $account,
        MailMessage $message,
        mixed $source,
        string $providerAttachmentId,
        string $sourcePartId,
        string $mediaType,
        ?string $filename = null,
        ?string $contentId = null,
        bool $isInline = false,
        array $providerMetadata = [],
    ): MailAttachment {
        $message = $this->matchedMessage($account, $message);
        $diskName = $this->diskName();
        $disk = $this->filesystems->disk($diskName);
        $staged = $this->stage($source);
        $partNamespace = hash('sha256', $sourcePartId);
        $key = $this->canonicalKey($account->id, $message->id, "attachments/{$partNamespace}", $staged['checksum']);
        $attributes = [
            'provider_attachment_id' => $providerAttachmentId,
            'source_part_id' => $sourcePartId,
            'filename' => $filename,
            'media_type' => $mediaType,
            'byte_size' => $staged['byte_size'],
            'checksum' => $staged['checksum'],
            'storage_disk' => $diskName,
            'object_key' => $key,
            'content_id' => $contentId,
            'is_inline' => $isInline,
            'provider_metadata' => $providerMetadata,
        ];

        try {
            return $message->getConnection()->transaction(function () use (
                $account,
                $message,
                $disk,
                $staged,
                $sourcePartId,
                $key,
                $attributes,
            ): MailAttachment {
                $this->lockMatchedMessage($account, $message);
                $existing = MailAttachment::query()
                    ->forAccount($account)
                    ->where('mail_message_id', $message->id)
                    ->where('source_part_id', $sourcePartId)
                    ->first();

                if ($existing !== null) {
                    if (! $this->matches($existing, $attributes)) {
                        throw new ImmutableObjectConflict('The attachment source part is immutable and already contains different bytes or metadata.');
                    }

                    $this->placeCanonicalObject($disk, $staged['stream'], $key, $staged['checksum'], $staged['byte_size']);

                    return $existing;
                }

                $placeholder = MailAttachment::query()
                    ->forAccount($account)
                    ->where('mail_message_id', $message->id)
                    ->where('provider_attachment_id', $attributes['provider_attachment_id'])
                    ->whereNull('source_part_id')
                    ->first();

                if ($placeholder !== null) {
                    MailAttachment::query()->whereKey($placeholder->id)->update($attributes);
                    $attachment = $placeholder->fresh();

                    if (! $attachment instanceof MailAttachment) {
                        throw new MailObjectException('Attachment storage could not be persisted.');
                    }

                    $this->placeCanonicalObject($disk, $staged['stream'], $key, $staged['checksum'], $staged['byte_size']);

                    return $attachment;
                }

                $attachment = MailAttachment::query()->create($attributes + [
                    'mail_account_id' => $account->id,
                    'mail_message_id' => $message->id,
                ]);

                $this->placeCanonicalObject($disk, $staged['stream'], $key, $staged['checksum'], $staged['byte_size']);

                return $attachment;
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                $existing = $this->attachmentAfterRace($account, $message, $sourcePartId);

                if ($existing !== null) {
                    if (! $this->matches($existing, $attributes)) {
                        throw new ImmutableObjectConflict('The attachment source part is immutable and already contains different bytes or metadata.');
                    }

                    $this->placeCanonicalObject($disk, $staged['stream'], $key, $staged['checksum'], $staged['byte_size']);

                    return $existing;
                }
            }

            throw new MailObjectException('Attachment storage could not be persisted.');
        } finally {
            fclose($staged['stream']);
        }
    }

    /** @return resource */
    public function readRaw(MailAccount $account, MailRawObject $raw): mixed
    {
        $raw = $this->matchedRaw($account, $raw);

        return $this->verifiedStream($raw);
    }

    public function cleanupRolledBackRaw(MailAccount $account, string $objectKey): void
    {
        $prefix = "mail-mirror/accounts/{$account->id}/messages/";

        if (! str_starts_with($objectKey, $prefix)) {
            throw new AccountResourceMismatch('The rollback object does not belong to the supplied account.');
        }

        $referenced = MailRawObject::query()->forAccount($account)
            ->where('object_key', $objectKey)->exists();

        if (! $referenced) {
            $this->filesystems->disk($this->diskName())->delete($objectKey);
        }
    }

    public function purgeMessage(
        int $mailAccountId,
        ?string $ownerType,
        string|int|null $ownerId,
        string $providerMessageId,
        int $expectedCheckpointVersion,
    ): bool {
        if ($providerMessageId === '' || strlen($providerMessageId) > 255 || $expectedCheckpointVersion < 0) {
            throw new InvalidArgumentException('A local message purge requires a bounded provider message ID and checkpoint version.');
        }

        try {
            $account = MailAccount::query()->whereKey($mailAccountId)
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId === null ? null : (string) $ownerId)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new AccountResourceMismatch('The requested account does not match the supplied owner tuple.');
        }

        try {
            return $account->getConnection()->transaction(function () use (
                $account,
                $providerMessageId,
                $expectedCheckpointVersion,
            ): bool {
                MailAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                $alreadyPurged = MailLocalMessagePurge::query()->forAccount($account)
                    ->where('provider_message_id', $providerMessageId)
                    ->lockForUpdate()
                    ->exists();

                if ($alreadyPurged) {
                    return false;
                }

                $message = MailMessage::query()->forAccount($account)
                    ->where('provider_message_id', $providerMessageId)
                    ->lockForUpdate()
                    ->first();

                if ($message === null) {
                    throw new AccountResourceMismatch('The requested message does not belong to the supplied account.');
                }

                $checkpoint = MailSyncCheckpoint::query()->forAccount($account)
                    ->where('version', $expectedCheckpointVersion)
                    ->lockForUpdate()
                    ->first();

                if ($checkpoint === null) {
                    throw new StaleCheckpoint;
                }

                $rawObjects = MailRawObject::query()->forAccount($account)
                    ->where('mail_message_id', $message->id)
                    ->lockForUpdate()
                    ->get();
                $attachments = MailAttachment::query()->forAccount($account)
                    ->where('mail_message_id', $message->id)
                    ->whereNotNull('object_key')
                    ->lockForUpdate()
                    ->get();

                foreach ($rawObjects->concat($attachments) as $object) {
                    $this->deleteStoredObject($account, $message, $object);
                }

                $addressIds = MailMessageParticipant::query()->forAccount($account)
                    ->where('mail_message_id', $message->id)
                    ->pluck('mail_address_id')
                    ->all();
                $threadId = $message->mail_thread_id;
                MailRawObject::query()->forAccount($account)
                    ->where('mail_message_id', $message->id)
                    ->delete();
                MailMessage::query()->forAccount($account)->whereKey($message->id)->delete();
                MailLocalMessagePurge::query()->create([
                    'mail_account_id' => $account->id,
                    'provider_message_id' => $providerMessageId,
                    'purged_at' => now(),
                ]);

                if ($threadId !== null && ! MailMessage::query()->forAccount($account)->where('mail_thread_id', $threadId)->exists()) {
                    MailThread::query()->forAccount($account)->whereKey($threadId)->delete();
                }

                if ($addressIds !== []) {
                    MailAddress::query()->forAccount($account)
                        ->whereIn('id', $addressIds)
                        ->whereDoesntHave('participants')
                        ->delete();
                }

                $checkpoint->forceFill(['version' => $checkpoint->version + 1])->save();

                return true;
            }, 3);
        } catch (AccountResourceMismatch|MailObjectException|StaleCheckpoint $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MailObjectException('The local message could not be purged.');
        }
    }

    /** @return resource */
    public function readAttachment(MailAccount $account, MailAttachment $attachment): mixed
    {
        $attachment = $this->matchedAttachment($account, $attachment);

        return $this->verifiedStream($attachment);
    }

    /** @return list<MailAttachment> */
    public function regenerateAttachments(MailAccount $account, MailRawObject $raw): array
    {
        $raw = $this->matchedRaw($account, $raw);
        $source = $this->verifiedStream($raw);

        try {
            $message = $this->mimeParser->parse($source, true);
            $stored = [];

            foreach ($message->getAllAttachmentParts() as $part) {
                $partSource = $part->getBinaryContentResourceHandle();

                if (! is_resource($partSource)) {
                    throw new ObjectIntegrityFailure('The raw source contains an unreadable attachment part.');
                }

                try {
                    $sourcePartId = $this->mimePartId($part);
                    $stored[] = $this->storeAttachment(
                        $account,
                        $this->messageForRaw($account, $raw),
                        $partSource,
                        'raw:'.$sourcePartId,
                        $sourcePartId,
                        $part->getContentType('application/octet-stream'),
                        $part->getFilename(),
                        $part->getContentId(),
                        strtolower((string) $part->getContentDisposition()) === 'inline',
                    );
                } finally {
                    fclose($partSource);
                }
            }

            return $stored;
        } catch (MailObjectException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ObjectIntegrityFailure('The raw source could not be parsed for attachment regeneration.');
        }
    }

    /**
     * @return array{
     *     account_id: int|string,
     *     message_id: int|string,
     *     raw: array{resource_id: int|string, media_type: string|null, byte_size: int|null, checksum: string|null, status: string}|null,
     *     attachments: list<array{resource_id: int|string, source_part_id: string|null, media_type: string|null, byte_size: int|null, checksum: string|null, status: string}>
     * }
     */
    public function integrityReport(MailAccount $account, MailMessage $message): array
    {
        $message = $this->matchedMessage($account, $message);
        $raw = MailRawObject::query()->forAccount($account)->where('mail_message_id', $message->id)->first();
        $attachments = MailAttachment::query()->forAccount($account)->where('mail_message_id', $message->id)->get();
        $attachmentReports = [];

        foreach ($attachments as $attachment) {
            $attachmentReports[] = [
                'resource_id' => $attachment->id,
                'source_part_id' => $attachment->source_part_id,
                'media_type' => $attachment->media_type,
                'byte_size' => $attachment->byte_size,
                'checksum' => $attachment->checksum,
                'status' => $this->integrityStatus($attachment),
            ];
        }

        return [
            'account_id' => $account->id,
            'message_id' => $message->id,
            'raw' => $raw === null ? null : [
                'resource_id' => $raw->id,
                'media_type' => $raw->media_type,
                'byte_size' => $raw->byte_size,
                'checksum' => $raw->checksum,
                'status' => $this->integrityStatus($raw),
            ],
            'attachments' => $attachmentReports,
        ];
    }

    private function matchedMessage(MailAccount $account, MailMessage $message): MailMessage
    {
        try {
            $matched = MailMessage::query()->forAccount($account)->whereKey($message->id)->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new AccountResourceMismatch('The requested message does not belong to the supplied account.');
        }

        return $matched;
    }

    private function lockMatchedMessage(MailAccount $account, MailMessage $message): void
    {
        $matched = MailMessage::query()
            ->forAccount($account)
            ->whereKey($message->id)
            ->lockForUpdate()
            ->first();

        if ($matched === null) {
            throw new AccountResourceMismatch('The requested message does not belong to the supplied account.');
        }
    }

    private function matchedRaw(MailAccount $account, MailRawObject $raw): MailRawObject
    {
        $matched = MailRawObject::query()->forAccount($account)->whereKey($raw->id)->first();

        if ($matched === null) {
            throw new AccountResourceMismatch('The requested raw source does not belong to the supplied account.');
        }

        return $matched;
    }

    private function matchedAttachment(MailAccount $account, MailAttachment $attachment): MailAttachment
    {
        $matched = MailAttachment::query()->forAccount($account)->whereKey($attachment->id)->first();

        if ($matched === null) {
            throw new AccountResourceMismatch('The requested attachment does not belong to the supplied account.');
        }

        return $matched;
    }

    private function messageForRaw(MailAccount $account, MailRawObject $raw): MailMessage
    {
        $message = MailMessage::query()->forAccount($account)->whereKey($raw->mail_message_id)->first();

        if ($message === null) {
            throw new AccountResourceMismatch('The requested raw source has no matching message for the supplied account.');
        }

        return $message;
    }

    private function rawAfterRace(MailAccount $account, MailMessage $message): ?MailRawObject
    {
        try {
            return MailRawObject::query()
                ->forAccount($account)
                ->where('mail_message_id', $message->id)
                ->first();
        } catch (Throwable) {
            throw new MailObjectException('Raw source storage could not be persisted.');
        }
    }

    private function attachmentAfterRace(
        MailAccount $account,
        MailMessage $message,
        string $sourcePartId,
    ): ?MailAttachment {
        try {
            return MailAttachment::query()
                ->forAccount($account)
                ->where('mail_message_id', $message->id)
                ->where('source_part_id', $sourcePartId)
                ->first();
        } catch (Throwable) {
            throw new MailObjectException('Attachment storage could not be persisted.');
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return $sqlState === '23000' || $sqlState === '23505';
    }

    private function mimePartId(IMessagePart $part): string
    {
        $segments = [];
        $current = $part;

        while (($parent = $current->getParent()) !== null) {
            $index = array_search($current, $parent->getChildParts(), true);

            if (! is_int($index)) {
                throw new ObjectIntegrityFailure('The raw source contains an unidentifiable attachment part.');
            }

            array_unshift($segments, (string) ($index + 1));
            $current = $parent;
        }

        if ($segments === []) {
            return 'mime:root';
        }

        return 'mime:'.implode('.', $segments);
    }

    /** @param resource $source
     * @return array{stream: resource, checksum: string, byte_size: int}
     */
    private function stage(mixed $source): array
    {
        if (! is_resource($source)) {
            throw new MailObjectException('Object input must be a readable stream.');
        }

        $buffer = fopen('php://temp/maxmemory:1048576', 'w+b');

        if ($buffer === false) {
            throw new MailObjectException('Object staging could not be initialized.');
        }

        $hash = hash_init('sha256');
        $byteSize = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new MailObjectException('Object input could not be read.');
                }

                if ($chunk === '') {
                    continue;
                }

                $written = fwrite($buffer, $chunk);

                if ($written !== strlen($chunk)) {
                    throw new MailObjectException('Object staging was interrupted.');
                }

                hash_update($hash, $chunk);
                $byteSize += $written;
            }

            rewind($buffer);

            return ['stream' => $buffer, 'checksum' => hash_final($hash), 'byte_size' => $byteSize];
        } catch (Throwable $exception) {
            fclose($buffer);

            if ($exception instanceof MailObjectException) {
                throw $exception;
            }

            throw new MailObjectException('Object staging failed.');
        }
    }

    /** @param resource $staged */
    private function placeCanonicalObject(
        Filesystem $disk,
        mixed $staged,
        string $canonicalKey,
        string $checksum,
        int $byteSize,
    ): void {
        $writeAttempted = false;

        try {
            if ($disk->exists($canonicalKey)) {
                if ($this->probe($disk, $canonicalKey, $checksum, $byteSize) === 'healthy') {
                    if ($disk->getVisibility($canonicalKey) !== 'private'
                        && ! $disk->setVisibility($canonicalKey, 'private')) {
                        throw new ObjectIntegrityFailure('The immutable object could not be finalized.');
                    }

                    return;
                }

                if (! $disk->delete($canonicalKey)) {
                    throw new ObjectIntegrityFailure('The immutable object could not be repaired.');
                }
            }

            rewind($staged);
            $writeAttempted = true;

            if (! $disk->writeStream($canonicalKey, $staged, ['visibility' => 'private']) || ! $disk->setVisibility($canonicalKey, 'private')) {
                throw new ObjectIntegrityFailure('The immutable object could not be finalized.');
            }
        } catch (MailObjectException $exception) {
            if ($writeAttempted) {
                $this->deleteQuietly($disk, $canonicalKey);
            }

            throw $exception;
        } catch (Throwable) {
            if ($writeAttempted) {
                $this->deleteQuietly($disk, $canonicalKey);
            }

            throw new ObjectIntegrityFailure('The immutable object could not be finalized.');
        }

        if ($this->probe($disk, $canonicalKey, $checksum, $byteSize) !== 'healthy') {
            $this->deleteQuietly($disk, $canonicalKey);

            throw new ObjectIntegrityFailure('The immutable object failed integrity verification.');
        }
    }

    /** @return resource */
    private function verifiedStream(MailRawObject|MailAttachment $object): mixed
    {
        $diskName = $object->getAttribute('storage_disk');
        $key = $object->getAttribute('object_key');
        $checksum = $object->getAttribute('checksum');
        $byteSize = $object->getAttribute('byte_size');

        if (! is_string($diskName) || ! is_string($key) || ! is_string($checksum) || ! is_int($byteSize)) {
            throw new ObjectIntegrityFailure('The immutable object metadata is incomplete.');
        }

        $disk = $this->filesystems->disk($diskName);
        $source = $this->safeReadStream($disk, $key);
        $verified = fopen('php://temp/maxmemory:1048576', 'w+b');

        if ($source === null || $verified === false) {
            throw new ObjectIntegrityFailure('The immutable object is missing or unreadable.');
        }

        $hash = hash_init('sha256');
        $actualSize = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false || fwrite($verified, $chunk) !== strlen($chunk)) {
                    throw new ObjectIntegrityFailure('The immutable object is incomplete or unreadable.');
                }

                hash_update($hash, $chunk);
                $actualSize += strlen($chunk);
            }
        } finally {
            fclose($source);
        }

        if ($actualSize !== $byteSize || ! hash_equals($checksum, hash_final($hash))) {
            fclose($verified);
            throw new ObjectIntegrityFailure('The immutable object failed integrity verification.');
        }

        rewind($verified);

        return $verified;
    }

    private function integrityStatus(MailRawObject|MailAttachment $object): string
    {
        $diskName = $object->getAttribute('storage_disk');
        $key = $object->getAttribute('object_key');
        $checksum = $object->getAttribute('checksum');
        $byteSize = $object->getAttribute('byte_size');

        if (! is_string($diskName) || ! is_string($key) || ! is_string($checksum) || ! is_int($byteSize)) {
            return 'metadata_incomplete';
        }

        return $this->probe($this->filesystems->disk($diskName), $key, $checksum, $byteSize);
    }

    private function deleteStoredObject(
        MailAccount $account,
        MailMessage $message,
        MailRawObject|MailAttachment $object,
    ): void {
        $diskName = $object->getAttribute('storage_disk');
        $key = $object->getAttribute('object_key');
        $prefix = "mail-mirror/accounts/{$account->id}/messages/{$message->id}/";

        if (! is_string($diskName) || $diskName === '' || ! is_string($key) || ! str_starts_with($key, $prefix)) {
            throw new ObjectIntegrityFailure('A local message object has invalid account-qualified storage metadata.');
        }

        try {
            $disk = $this->filesystems->disk($diskName);

            if (! $disk->exists($key)) {
                return;
            }

            if (! $disk->delete($key) || $this->storedObjectExists($disk, $key)) {
                throw new ObjectIntegrityFailure('A local message object could not be purged.');
            }
        } catch (MailObjectException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ObjectIntegrityFailure('A local message object could not be purged.');
        }
    }

    private function storedObjectExists(Filesystem $disk, string $key): bool
    {
        return $disk->exists($key);
    }

    private function probe(Filesystem $disk, string $key, string $checksum, int $byteSize): string
    {
        $source = $this->safeReadStream($disk, $key);

        if ($source === null) {
            return 'missing';
        }

        $hash = hash_init('sha256');
        $actualSize = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    return 'unreadable';
                }

                hash_update($hash, $chunk);
                $actualSize += strlen($chunk);
            }
        } finally {
            fclose($source);
        }

        if ($actualSize !== $byteSize) {
            return 'size_mismatch';
        }

        return hash_equals($checksum, hash_final($hash)) ? 'healthy' : 'checksum_mismatch';
    }

    /** @return resource|null */
    private function safeReadStream(Filesystem $disk, string $key): mixed
    {
        try {
            $stream = $disk->readStream($key);

            return is_resource($stream) ? $stream : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $expected */
    private function matches(MailRawObject|MailAttachment $object, array $expected): bool
    {
        foreach ($expected as $attribute => $value) {
            $actual = $object->getAttribute($attribute);

            if ($attribute === 'provider_metadata') {
                if ($this->normalizedMetadata($actual) !== $this->normalizedMetadata($value)) {
                    return false;
                }

                continue;
            }

            if ($actual !== $value) {
                return false;
            }
        }

        return true;
    }

    private function normalizedMetadata(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalizedMetadata($item), $value);
    }

    private function diskName(): string
    {
        $disk = config('mail-mirror.storage_disk');

        if (! is_string($disk) || $disk === '') {
            throw new MailObjectException('The MailMirror storage disk is not configured.');
        }

        return $disk;
    }

    private function canonicalKey(int|string|null $accountId, int|string|null $messageId, string $kind, string $checksum): string
    {
        return "mail-mirror/accounts/{$accountId}/messages/{$messageId}/{$kind}/sha256/{$checksum}";
    }

    private function deleteQuietly(Filesystem $disk, string $key): void
    {
        try {
            $disk->delete($key);
        } catch (Throwable) {
            // Cleanup is best-effort; no private storage details escape.
        }
    }
}
