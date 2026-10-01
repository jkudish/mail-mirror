<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Write;

use InvalidArgumentException;
use Jkudish\MailMirror\Enums\MailDriver;

/**
 * One provider draft as a provider re-read found it. $revision is the opaque
 * token a caller passes back to replace, delete, or submit this exact draft;
 * it changes whenever the provider message or its raw bytes change.
 *
 * Gmail keeps the draft ID across updates and changes the message ID. JMAP
 * emails are immutable, so the draft ID is the email ID and changes on replace.
 */
final readonly class DraftRevision
{
    public string $revision;

    /** @param array<string, mixed> $providerEvidence */
    public function __construct(
        public int $mailAccountId,
        public MailDriver $driver,
        public string $draftId,
        public string $providerMessageId,
        public ?string $messageId,
        public string $threadId,
        public string $rawSha256,
        public array $providerEvidence,
    ) {
        foreach ([$draftId, $providerMessageId, $threadId] as $id) {
            if (trim($id) === '' || mb_strlen($id) > 255) {
                throw new InvalidArgumentException('A draft revision requires bounded opaque provider IDs.');
            }
        }

        if ($mailAccountId < 1 || preg_match('/\A[0-9a-f]{64}\z/', $rawSha256) !== 1) {
            throw new InvalidArgumentException('A draft revision requires an account and a raw SHA-256.');
        }

        $this->revision = hash('sha256', $draftId."\0".$providerMessageId."\0".$rawSha256);
    }
}
