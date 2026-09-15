<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;

final readonly class ChangedMessageState
{
    /**
     * @param  array<string, mixed>  $providerMetadata
     * @param  list<array<string, mixed>>  $containers
     */
    public function __construct(
        public MessageReference $reference,
        public array $providerMetadata,
        public array $containers,
    ) {
        foreach ($containers as $container) {
            if (! is_string($container['provider_id'] ?? null)
                || trim($container['provider_id']) === ''
                || mb_strlen($container['provider_id']) > 255
                || (isset($container['name']) && (! is_string($container['name']) || mb_strlen($container['name']) > 255))
                || (isset($container['kind']) && (! is_string($container['kind']) || mb_strlen($container['kind']) > 255))
                || (isset($container['membership_id']) && (! is_string($container['membership_id']) || mb_strlen($container['membership_id']) > 255))
                || (isset($container['provider_metadata']) && ! is_array($container['provider_metadata']))
                || (isset($container['membership_metadata']) && ! is_array($container['membership_metadata']))) {
                throw new InvalidArgumentException('Changed message containers require bounded provider metadata.');
            }
        }
    }
}
