<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

final readonly class RetrievedMessage
{
    /**
     * @param  list<array{name: string, value: string}>  $headers
     * @param  list<array{role: string, address: string, name?: string, provider_metadata?: array<string, mixed>}>  $participants
     * @param  list<array{provider_id: string, filename?: string, media_type?: string, byte_size?: int, provider_metadata: array<string, mixed>}>  $attachments
     * @param  list<array{provider_id: string, name?: string, provider_metadata: array<string, mixed>}>  $containers
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public MessageReference $reference,
        public ?string $subject,
        public array $headers = [],
        public array $participants = [],
        public array $attachments = [],
        public array $containers = [],
        public array $providerMetadata = [],
    ) {}
}
