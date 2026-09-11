<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Carbon\CarbonImmutable;

/** @property CarbonImmutable $purged_at */
final class MailLocalMessagePurge extends AccountScopedModel
{
    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id', 'provider_message_id', 'purged_at'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + ['purged_at' => 'immutable_datetime'];
    }
}
