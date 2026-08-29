<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

/**
 * @property string $scan_id
 * @property int $inventory_count
 * @property int $mirrored_count
 * @property int $provider_deleted_count
 * @property int $transient_error_count
 * @property int $waived_error_count
 * @property int $unexplained_missing_count
 * @property int $unexpected_active_count
 * @property array<string, list<string>> $summary
 */
final class MailReconciliationReport extends AccountScopedModel
{
    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return [
            'mail_account_id', 'scan_id', 'inventory_count', 'mirrored_count',
            'provider_deleted_count', 'transient_error_count', 'waived_error_count',
            'unexplained_missing_count', 'unexpected_active_count', 'summary',
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + ['summary' => 'array'];
    }
}
