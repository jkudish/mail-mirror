<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Import;

use InvalidArgumentException;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailImportError;
use Jkudish\MailMirror\Models\MailInventoryItem;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailProviderDeletionEvidence;
use Jkudish\MailMirror\Models\MailReconciliationReport;

final class ReconciliationService
{
    public function reconcile(MailAccount $account, string $scanId): MailReconciliationReport
    {
        $checkpoint = $account->syncCheckpoint()->where('scan_id', $scanId)->first();

        if ($checkpoint === null || $checkpoint->scan_completed_at === null) {
            throw new InvalidArgumentException('Only a completed full inventory scan may be reconciled.');
        }

        $inventory = MailInventoryItem::query()->forAccount($account)->where('scan_id', $scanId)
            ->pluck('provider_message_id')->all();
        $mirrored = MailMessage::query()->forAccount($account)->pluck('provider_message_id')->all();
        $deleted = MailProviderDeletionEvidence::query()->forAccount($account)->pluck('provider_message_id')->all();
        $openErrors = MailImportError::query()->forAccount($account)->whereNull('resolved_at')->whereNull('waived_at')
            ->pluck('provider_message_id')->all();
        $waivedErrors = MailImportError::query()->forAccount($account)->whereNull('resolved_at')->whereNotNull('waived_at')
            ->pluck('provider_message_id')->all();

        /** @var list<string> $inventory */
        /** @var list<string> $mirrored */
        /** @var list<string> $deleted */
        /** @var list<string> $openErrors */
        /** @var list<string> $waivedErrors */
        $mirroredInventory = $this->intersection($inventory, $mirrored);
        $missing = $this->difference($inventory, $mirrored);
        $transient = $this->intersection($missing, $openErrors);
        $waived = $this->intersection($missing, $waivedErrors);
        $unexplained = $this->difference($missing, [...$transient, ...$waived]);
        $providerDeleted = $this->intersection($this->difference($mirrored, $inventory), $deleted);
        $unexpected = $this->difference($this->difference($mirrored, $inventory), $deleted);
        $summary = [
            'mirrored' => $mirroredInventory,
            'provider_deleted' => $providerDeleted,
            'transient_errors' => $transient,
            'waived_errors' => $waived,
            'unexplained_missing' => $unexplained,
            'unexpected_active' => $unexpected,
        ];

        return MailReconciliationReport::query()->firstOrCreate(
            ['mail_account_id' => $account->id, 'scan_id' => $scanId],
            [
                'inventory_count' => count($inventory),
                'mirrored_count' => count($mirroredInventory),
                'provider_deleted_count' => count($providerDeleted),
                'transient_error_count' => count($transient),
                'waived_error_count' => count($waived),
                'unexplained_missing_count' => count($unexplained),
                'unexpected_active_count' => count($unexpected),
                'summary' => $summary,
            ],
        );
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function intersection(array $left, array $right): array
    {
        $values = array_values(array_unique(array_intersect($left, $right)));
        sort($values);

        return $values;
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function difference(array $left, array $right): array
    {
        $values = array_values(array_unique(array_diff($left, $right)));
        sort($values);

        return $values;
    }
}
