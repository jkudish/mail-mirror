<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RetrievedMessage
{
    /**
     * @param  list<mixed>  $headers
     * @param  list<mixed>  $participants
     * @param  list<mixed>  $attachments
     * @param  list<mixed>  $containers
     * @param  array<string, mixed>  $providerMetadata
     * @param  array<string, mixed>  $providerThreadMetadata
     */
    public function __construct(
        public MessageReference $reference,
        public ?string $subject,
        public ?string $internetMessageId = null,
        public array $headers = [],
        public array $participants = [],
        public array $attachments = [],
        public array $containers = [],
        public array $providerMetadata = [],
        public ?RawMessageSource $rawSource = null,
        public array $providerThreadMetadata = [],
        public ?DateTimeImmutable $sentAt = null,
        public ?DateTimeImmutable $receivedAt = null,
    ) {
        if (($subject !== null && mb_strlen($subject) > 255)
            || ($internetMessageId !== null && mb_strlen($internetMessageId) > 255)) {
            throw new InvalidArgumentException('Retrieved message scalar metadata is too large.');
        }

        foreach ($headers as $header) {
            if (! is_array($header)
                || ! isset($header['name'], $header['value'])
                || ! is_string($header['name']) || trim($header['name']) === ''
                || mb_strlen($header['name']) > 255
                || ! is_string($header['value'])
                || (isset($header['provider_metadata']) && ! is_array($header['provider_metadata']))) {
                throw new InvalidArgumentException('Retrieved message headers are malformed.');
            }
        }

        $roles = ['from', 'sender', 'reply_to', 'to', 'cc', 'bcc'];

        foreach ($participants as $participant) {
            if (! is_array($participant)
                || ! isset($participant['role'], $participant['address'])
                || ! is_string($participant['role']) || ! in_array($participant['role'], $roles, true)
                || ! is_string($participant['address']) || trim($participant['address']) === ''
                || mb_strlen($participant['address']) > 255
                || (isset($participant['name']) && (! is_string($participant['name']) || mb_strlen($participant['name']) > 255))
                || (isset($participant['provider_metadata']) && ! is_array($participant['provider_metadata']))) {
                throw new InvalidArgumentException('Retrieved message participants are malformed.');
            }
        }

        $attachmentIds = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)
                || ! isset($attachment['provider_id']) || ! is_string($attachment['provider_id'])
                || trim($attachment['provider_id']) === ''
                || mb_strlen($attachment['provider_id']) > 255
                || (isset($attachment['filename']) && (! is_string($attachment['filename']) || mb_strlen($attachment['filename']) > 255))
                || (isset($attachment['media_type']) && (! is_string($attachment['media_type']) || mb_strlen($attachment['media_type']) > 255))
                || (isset($attachment['byte_size']) && (! is_int($attachment['byte_size']) || $attachment['byte_size'] < 0))
                || (isset($attachment['content_id']) && (! is_string($attachment['content_id']) || mb_strlen($attachment['content_id']) > 255))
                || (isset($attachment['is_inline']) && ! is_bool($attachment['is_inline']))
                || (isset($attachment['provider_metadata']) && ! is_array($attachment['provider_metadata']))) {
                throw new InvalidArgumentException('Retrieved message attachments are malformed.');
            }

            if (isset($attachmentIds[$attachment['provider_id']])) {
                throw new InvalidArgumentException('Retrieved message attachment identities must be unique.');
            }

            $attachmentIds[$attachment['provider_id']] = true;
        }

        $containerIds = [];

        foreach ($containers as $container) {
            if (! is_array($container)
                || ! isset($container['provider_id']) || ! is_string($container['provider_id'])
                || trim($container['provider_id']) === ''
                || mb_strlen($container['provider_id']) > 255
                || (isset($container['name']) && (! is_string($container['name']) || mb_strlen($container['name']) > 255))
                || (isset($container['kind']) && (! is_string($container['kind']) || mb_strlen($container['kind']) > 255))
                || (isset($container['membership_id']) && (! is_string($container['membership_id']) || mb_strlen($container['membership_id']) > 255))
                || (isset($container['provider_metadata']) && ! is_array($container['provider_metadata']))
                || (isset($container['membership_metadata']) && ! is_array($container['membership_metadata']))) {
                throw new InvalidArgumentException('Retrieved message containers are malformed.');
            }

            if (isset($containerIds[$container['provider_id']])) {
                throw new InvalidArgumentException('Retrieved message container identities must be unique.');
            }

            $containerIds[$container['provider_id']] = true;
        }
    }
}
