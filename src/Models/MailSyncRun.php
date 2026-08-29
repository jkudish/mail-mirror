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
            if ($run->isDirty('status')) {
                throw new LogicException('A sync run status must be changed with transitionTo().');
            }
        });
    }

    public function transitionTo(SyncRunStatus $status, ?string $failureReason = null): void
    {
        $from = $this->status;
        $this->assertTransitionAllowed($from, $status);

        $attributes = [
            'status' => $status->value,
            'failure_reason' => $status === SyncRunStatus::Failed ? $failureReason : null,
        ];

        if ($status === SyncRunStatus::Running) {
            $attributes['started_at'] = CarbonImmutable::now();
        } else {
            $attributes['finished_at'] = CarbonImmutable::now();
        }

        $updated = $this->newModelQuery()
            ->whereKey($this->getKey())
            ->where('status', $from->value)
            ->update($attributes);

        if ($updated !== 1) {
            throw new LogicException('The sync run status changed before this transition could be persisted.');
        }

        $this->refresh();
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
