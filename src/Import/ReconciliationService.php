<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Import;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailReconciliationReport;

final class ReconciliationService
{
    public function reconcile(MailAccount $account, string $scanId): MailReconciliationReport
    {
        $checkpoint = $account->syncCheckpoint()->where('scan_id', $scanId)->first();

        if ($checkpoint === null || $checkpoint->scan_completed_at === null) {
            throw new InvalidArgumentException('Only a completed full inventory scan may be reconciled.');
        }

        $existing = MailReconciliationReport::query()->forAccount($account)->where('scan_id', $scanId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $accountId = $account->id;
        $inventory = DB::table('mail_inventory_items as inventory')
            ->where('inventory.mail_account_id', $accountId)
            ->where('inventory.scan_id', $scanId);
        $mirrored = (clone $inventory)->whereExists(function (Builder $query) use ($accountId): void {
            $query->selectRaw('1')->from('mail_messages as messages')
                ->where('messages.mail_account_id', $accountId)
                ->whereColumn('messages.provider_message_id', 'inventory.provider_message_id');
        });
        $missing = (clone $inventory)->whereNotExists(function (Builder $query) use ($accountId): void {
            $query->selectRaw('1')->from('mail_messages as messages')
                ->where('messages.mail_account_id', $accountId)
                ->whereColumn('messages.provider_message_id', 'inventory.provider_message_id');
        });
        $transient = (clone $missing)->whereExists(function (Builder $query) use ($accountId): void {
            $query->selectRaw('1')->from('mail_import_errors as errors')
                ->where('errors.mail_account_id', $accountId)
                ->whereColumn('errors.provider_message_id', 'inventory.provider_message_id')
                ->whereNull('errors.resolved_at')->whereNull('errors.waived_at');
        });
        $waived = (clone $missing)
            ->whereNotExists(function (Builder $query) use ($accountId): void {
                $query->selectRaw('1')->from('mail_import_errors as errors')
                    ->where('errors.mail_account_id', $accountId)
                    ->whereColumn('errors.provider_message_id', 'inventory.provider_message_id')
                    ->whereNull('errors.resolved_at')->whereNull('errors.waived_at');
            })
            ->whereExists(function (Builder $query) use ($accountId): void {
                $query->selectRaw('1')->from('mail_import_errors as errors')
                    ->where('errors.mail_account_id', $accountId)
                    ->whereColumn('errors.provider_message_id', 'inventory.provider_message_id')
                    ->whereNull('errors.resolved_at')->whereNotNull('errors.waived_at');
            });
        $unexplained = (clone $missing)->whereNotExists(function (Builder $query) use ($accountId): void {
            $query->selectRaw('1')->from('mail_import_errors as errors')
                ->where('errors.mail_account_id', $accountId)
                ->whereColumn('errors.provider_message_id', 'inventory.provider_message_id')
                ->whereNull('errors.resolved_at');
        });
        $activeOutsideInventory = DB::table('mail_messages as messages')
            ->where('messages.mail_account_id', $accountId)
            ->whereNotExists(function (Builder $query) use ($accountId, $scanId): void {
                $query->selectRaw('1')->from('mail_inventory_items as inventory')
                    ->where('inventory.mail_account_id', $accountId)
                    ->where('inventory.scan_id', $scanId)
                    ->whereColumn('inventory.provider_message_id', 'messages.provider_message_id');
            });
        $providerDeleted = (clone $activeOutsideInventory)->whereExists(function (Builder $query) use ($accountId, $scanId): void {
            $query->selectRaw('1')->from('mail_provider_deletion_evidence as deletions')
                ->where('deletions.mail_account_id', $accountId)
                ->where('deletions.scan_id', $scanId)
                ->whereColumn('deletions.provider_message_id', 'messages.provider_message_id');
        });
        $unexpected = (clone $activeOutsideInventory)->whereNotExists(function (Builder $query) use ($accountId, $scanId): void {
            $query->selectRaw('1')->from('mail_provider_deletion_evidence as deletions')
                ->where('deletions.mail_account_id', $accountId)
                ->where('deletions.scan_id', $scanId)
                ->whereColumn('deletions.provider_message_id', 'messages.provider_message_id');
        });

        $categories = [
            'mirrored' => $this->summarize($mirrored),
            'provider_deleted' => $this->summarize($providerDeleted),
            'transient_errors' => $this->summarize($transient),
            'waived_errors' => $this->summarize($waived),
            'unexplained_missing' => $this->summarize($unexplained),
            'unexpected_active' => $this->summarize($unexpected),
        ];

        return MailReconciliationReport::query()->create([
            'mail_account_id' => $accountId,
            'scan_id' => $scanId,
            'inventory_count' => (clone $inventory)->count(),
            'mirrored_count' => $categories['mirrored']['count'],
            'provider_deleted_count' => $categories['provider_deleted']['count'],
            'transient_error_count' => $categories['transient_errors']['count'],
            'waived_error_count' => $categories['waived_errors']['count'],
            'unexplained_missing_count' => $categories['unexplained_missing']['count'],
            'unexpected_active_count' => $categories['unexpected_active']['count'],
            'summary' => array_map(
                fn (array $category): array => ['sample' => $category['sample'], 'truncated' => $category['truncated']],
                $categories,
            ),
        ]);
    }

    /** @return array{count: int, sample: list<string>, truncated: bool} */
    private function summarize(Builder $query): array
    {
        $configured = config('mail-mirror.reconciliation_sample_limit', 20);
        $limit = is_int($configured) && $configured >= 1 && $configured <= 100 ? $configured : 20;
        $count = (clone $query)->count();
        $sample = (clone $query)->orderBy('provider_message_id')->limit($limit)
            ->pluck('provider_message_id')->map(static function (mixed $id): string {
                if (! is_string($id)) {
                    throw new InvalidArgumentException('Reconciliation provider IDs must be strings.');
                }

                return $id;
            })->all();

        /** @var list<string> $sample */
        return ['count' => $count, 'sample' => $sample, 'truncated' => $count > count($sample)];
    }
}
