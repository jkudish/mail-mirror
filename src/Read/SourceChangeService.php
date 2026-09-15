<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Jkudish\MailMirror\Exceptions\AccountResourceMismatch;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailSourceChange;

final readonly class SourceChangeService
{
    /** @return Collection<int, MailSourceChange> */
    public function pendingAccount(
        int $mailAccountId,
        ?string $ownerType,
        string|int|null $ownerId,
        int $limit = 100,
    ): Collection {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('The pending change limit must be between one and 500.');
        }

        $account = $this->account($mailAccountId, $ownerType, $ownerId);

        return MailSourceChange::query()->forAccount($account)
            ->whereNull('acknowledged_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @param array<array-key, mixed> $changeIds */
    public function acknowledgeAccount(
        int $mailAccountId,
        ?string $ownerType,
        string|int|null $ownerId,
        array $changeIds,
    ): int {
        if ($changeIds === [] || count($changeIds) > 500) {
            throw new InvalidArgumentException('Acknowledgement requires one to 500 positive change IDs.');
        }

        /** @var array<int, int> $ids */
        $ids = [];

        foreach ($changeIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Acknowledgement requires one to 500 positive change IDs.');
            }

            $ids[$id] = $id;
        }

        $account = $this->account($mailAccountId, $ownerType, $ownerId);

        return MailSourceChange::query()->forAccount($account)
            ->whereIn('id', array_values($ids))
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);
    }

    private function account(int $mailAccountId, ?string $ownerType, string|int|null $ownerId): MailAccount
    {
        try {
            return MailAccount::query()->whereKey($mailAccountId)
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId === null ? null : (string) $ownerId)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new AccountResourceMismatch('The requested account does not match the supplied owner tuple.');
        }
    }
}
