<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * @property string $provider_message_id
 * @property string $stage
 * @property string $code
 * @property string $summary
 * @property int $attempt_count
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $waived_at
 * @property string|null $waiver_reason
 * @property string|null $waiver_audit_reference
 */
final class MailImportError extends AccountScopedModel
{
    public function waive(string $reason, string $auditReference): void
    {
        if ($this->resolved_at !== null || $this->waived_at !== null) {
            throw new InvalidArgumentException('Only an open import error may be waived.');
        }

        if (trim($reason) === '' || mb_strlen($reason) > 160 || trim($auditReference) === '' || mb_strlen($auditReference) > 160) {
            throw new InvalidArgumentException('A safe waiver reason and opaque host audit reference are required.');
        }

        $this->forceFill([
            'waived_at' => now(),
            'waiver_reason' => $reason,
            'waiver_audit_reference' => $auditReference,
        ])->save();
    }

    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id', 'provider_message_id', 'stage'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'attempt_count' => 'integer',
            'last_failed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'waived_at' => 'immutable_datetime',
        ];
    }
}
