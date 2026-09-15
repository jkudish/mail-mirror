<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Carbon\CarbonImmutable;

/**
 * @property string|null $provider_cursor
 * @property int $version
 * @property string|null $repair_cursor
 * @property string|null $repair_scan_id
 * @property CarbonImmutable|null $repair_started_at
 */
final class MailDeltaCheckpoint extends AccountScopedModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'version' => 'integer',
            'repair_started_at' => 'immutable_datetime',
        ];
    }
}
