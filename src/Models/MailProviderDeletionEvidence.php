<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

/**
 * @property string $scan_id
 * @property string $proof_code
 */
final class MailProviderDeletionEvidence extends AccountScopedModel
{
    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id', 'provider_message_id'];
    }
}
