<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use Illuminate\Support\Facades\Crypt;
use Jkudish\MailMirror\Enums\MailWriteCode;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use LogicException;
use Throwable;

/**
 * Gmail create attempt identity, not message identity. Persist checkpoint() before
 * authorizing MIME upload. It contains an encrypted sensitive session URL; never
 * log it or put it in audits/jobs. Consumers own durable storage and attempt state.
 */
final readonly class DraftUploadSession
{
    private function __construct(
        public MailAccountTarget $account,
        public string $operationKey,
        public string $rawSha256,
        public int $size,
        public ?string $providerThreadId,
        private string $encryptedUri,
    ) {}

    public static function issued(MailAccountTarget $account, string $operationKey, #[\SensitiveParameter] DraftContent $content, ?string $providerThreadId, #[\SensitiveParameter] string $uri): self
    {
        if (trim($operationKey) === '' || strlen($operationKey) > 255
            || ($providerThreadId !== null && (trim($providerThreadId) === '' || strlen($providerThreadId) > 255))) {
            throw new MailWriteFailure(MailWriteCode::InvalidDraftUpload);
        }

        return new self($account, $operationKey, $content->sha256, $content->size(), $providerThreadId, self::encryptUri($uri));
    }

    /** Explicit durable checkpoint, encrypted/authenticated with the consumer's Laravel key. */
    public function checkpoint(): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 2,
            'account' => $this->account->mailAccountId,
            'owner_type' => $this->account->ownerType,
            'owner_id' => $this->account->ownerId,
            'operation' => $this->operationKey,
            'sha256' => $this->rawSha256,
            'size' => $this->size,
            'thread' => $this->providerThreadId,
            'encrypted_uri' => $this->encryptedUri,
        ], JSON_THROW_ON_ERROR));
    }

    public static function fromCheckpoint(#[\SensitiveParameter] string $checkpoint): self
    {
        try {
            $data = json_decode(Crypt::decryptString($checkpoint), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($data) || ! in_array($data['version'] ?? null, [1, 2], true) || ! is_int($data['account'] ?? null)
                || ! array_key_exists('owner_type', $data) || ! array_key_exists('owner_id', $data)
                || ! array_key_exists('thread', $data)
                || ($data['owner_type'] !== null && ! is_string($data['owner_type']))
                || ($data['owner_id'] !== null && ! is_string($data['owner_id']) && ! is_int($data['owner_id']))
                || ($data['thread'] !== null && ! is_string($data['thread']))
                || ! is_string($data['operation'] ?? null) || trim($data['operation']) === '' || strlen($data['operation']) > 255
                || ! is_string($data['sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/', $data['sha256']) !== 1
                || ! is_int($data['size'] ?? null) || $data['size'] < 1) {
                throw new LogicException;
            }

            // Migrate an existing encrypted v1 checkpoint in memory, without
            // invalidating its same-session recovery or keeping the raw URL.
            $encryptedUri = $data['version'] === 1
                ? (is_string($data['uri'] ?? null) ? self::encryptUri($data['uri']) : throw new LogicException)
                : ($data['encrypted_uri'] ?? null);

            if (! is_string($encryptedUri) || $encryptedUri === '') {
                throw new LogicException;
            }

            return new self(new MailAccountTarget($data['account'], $data['owner_type'], $data['owner_id']),
                $data['operation'], $data['sha256'], $data['size'], $data['thread'], $encryptedUri);
        } catch (Throwable) {
            throw new MailWriteFailure(MailWriteCode::InvalidDraftUpload);
        }
    }

    /** Fails before credentials or provider access, including when the target's owner changed. */
    public function assertMatches(MailAccountTarget $account, #[\SensitiveParameter] DraftContent $content, ?string $providerThreadId): void
    {
        if ($this->account->mailAccountId !== $account->mailAccountId
            || $this->account->ownerType !== $account->ownerType
            || (string) $this->account->ownerId !== (string) $account->ownerId
            || $this->rawSha256 !== $content->sha256 || $this->size !== $content->size()
            || $this->providerThreadId !== $providerThreadId) {
            throw new MailWriteFailure(MailWriteCode::InvalidDraftUpload);
        }

    }

    /** Driver-only transport destination. Sensitive: do not log or serialize. */
    public function uri(): string
    {
        try {
            $uri = Crypt::decryptString($this->encryptedUri);
            self::validateUri($uri);

            return $uri;
        } catch (Throwable) {
            throw new MailWriteFailure(MailWriteCode::InvalidDraftUpload);
        }
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return ['account' => $this->account->mailAccountId, 'operation' => $this->operationKey, 'session' => '[redacted]'];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Use checkpoint() for explicit durable upload-session storage.');
    }

    private static function encryptUri(#[\SensitiveParameter] string $uri): string
    {
        try {
            self::validateUri($uri);

            return Crypt::encryptString($uri);
        } catch (Throwable) {
            throw new MailWriteFailure(MailWriteCode::InvalidDraftUpload);
        }
    }

    private static function validateUri(#[\SensitiveParameter] string $uri): void
    {
        $parts = parse_url($uri);
        $query = [];
        parse_str(is_array($parts) ? ($parts['query'] ?? '') : '', $query);

        if (strlen($uri) > 8192 || preg_match('/[\x00-\x20\x7F]/', $uri) === 1 || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ! in_array($parts['host'] ?? null, ['www.googleapis.com', 'gmail.googleapis.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['port'])
            || ($parts['path'] ?? null) !== '/upload/gmail/v1/users/me/drafts'
            || ($query['uploadType'] ?? null) !== 'resumable'
            || ! is_string($query['upload_id'] ?? null) || $query['upload_id'] === ''
            || count($query) !== 2 || count(explode('&', $parts['query'] ?? '')) !== 2) {
            throw new MailWriteFailure(MailWriteCode::InvalidDraftUpload);
        }
    }
}
