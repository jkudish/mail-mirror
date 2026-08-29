<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;

final readonly class RawMessageSource
{
    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public mixed $stream,
        public string $providerObjectId,
        public string $mediaType = 'message/rfc822',
        public array $providerMetadata = [],
    ) {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('A raw message source must be a readable stream.');
        }
    }
}
