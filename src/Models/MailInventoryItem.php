<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

/**
 * @property string $scan_id
 * @property string $provider_message_id
 * @property string|null $provider_thread_id
 * @property array<string, mixed>|null $provider_metadata
 */
final class MailInventoryItem extends AccountScopedModel
{
    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id', 'provider_message_id'];
    }
}
