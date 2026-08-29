<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

final class MailProviderDeletionEvidence extends AccountScopedModel
{
    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id', 'provider_message_id'];
    }
}
