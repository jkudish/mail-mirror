<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $scan_id
 * @property string|null $provider_cursor
 * @property int $version
 * @property int $processed_count
 * @property CarbonImmutable $scan_started_at
 * @property CarbonImmutable|null $scan_completed_at
 */
final class MailSyncCheckpoint extends AccountScopedModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'version' => 'integer',
            'processed_count' => 'integer',
            'scan_started_at' => 'immutable_datetime',
            'scan_completed_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id'];
    }
}
