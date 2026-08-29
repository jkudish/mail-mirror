<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Carbon\CarbonImmutable;
use Jkudish\MailMirror\Enums\SyncRunStatus;
use LogicException;

/**
 * @property SyncRunStatus $status
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
final class MailSyncRun extends AccountScopedModel
{
    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            if ($run->status !== SyncRunStatus::Pending) {
                throw new LogicException('A sync run must begin pending.');
            }
        });

        self::updating(function (self $run): void {
            if (! $run->isDirty('status')) {
                return;
            }

            $original = $run->getRawOriginal('status');

            if (! is_string($original)) {
                throw new LogicException('The persisted sync run status is invalid.');
            }

            $from = SyncRunStatus::from($original);
            $run->assertTransitionAllowed($from, $run->status);
        });
    }

    public function transitionTo(SyncRunStatus $status, ?string $failureReason = null): void
    {
        $this->assertTransitionAllowed($this->status, $status);

        $this->status = $status;
        $this->failure_reason = $status === SyncRunStatus::Failed ? $failureReason : null;

        if ($status === SyncRunStatus::Running) {
            $this->started_at = CarbonImmutable::now();
        } else {
            $this->finished_at = CarbonImmutable::now();
        }

        $this->save();
    }

    private function assertTransitionAllowed(SyncRunStatus $from, SyncRunStatus $to): void
    {
        $allowed = match ($from) {
            SyncRunStatus::Pending => [SyncRunStatus::Running],
            SyncRunStatus::Running => [SyncRunStatus::Completed, SyncRunStatus::Failed],
            SyncRunStatus::Completed, SyncRunStatus::Failed => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw new LogicException(sprintf('Cannot transition a sync run from %s to %s.', $from->value, $to->value));
        }
    }

    /** @return array<string, class-string<SyncRunStatus>|string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'status' => SyncRunStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
