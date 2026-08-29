<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jkudish\MailMirror\Models\Concerns\HasImmutableAttributes;

/**
 * @property int $mail_account_id
 * @property array<string, mixed>|null $provider_metadata
 *
 * @method static Builder<static> forAccount(MailAccount|int $account)
 */
abstract class AccountScopedModel extends Model
{
    use HasImmutableAttributes;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        $instanceConnection = parent::getConnectionName();
        $connection = config('mail-mirror.database_connection');

        return $instanceConnection ?? (is_string($connection) ? $connection : null);
    }

    /** @return BelongsTo<MailAccount, $this> */
    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /** @param Builder<static> $query */
    public function scopeForAccount(Builder $query, MailAccount|int $account): void
    {
        $query->where($this->qualifyColumn('mail_account_id'), $account instanceof MailAccount ? $account->getKey() : $account);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['provider_metadata' => 'array'];
    }
}
