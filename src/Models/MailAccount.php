<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use Jkudish\MailMirror\Enums\MailDriver;
use LogicException;

/**
 * @property int $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property MailDriver $driver
 * @property string $provider_account_id
 * @property array<string, mixed>|null $provider_metadata
 *
 * @method static Builder<static> ownedBy(string $ownerType, string|int $ownerId)
 */
final class MailAccount extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        self::saving(function (self $account): void {
            if (($account->owner_type === null) !== ($account->owner_id === null)) {
                throw new InvalidArgumentException('Owner type and owner ID must both be null or both be present.');
            }

            if ($account->exists && ($account->getOriginal('owner_type') !== null || $account->getOriginal('owner_id') !== null)) {
                if ($account->isDirty('owner_type') || $account->isDirty('owner_id')) {
                    throw new LogicException('An attached owner is immutable.');
                }
            }

            if ($account->exists && ($account->isDirty('driver') || $account->isDirty('provider_account_id'))) {
                throw new LogicException('The account provider identity is immutable.');
            }
        });
    }

    public function getConnectionName(): ?string
    {
        $instanceConnection = parent::getConnectionName();
        $connection = config('mail-mirror.database_connection');

        return $instanceConnection ?? (is_string($connection) ? $connection : null);
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<MailIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(MailIdentity::class);
    }

    /** @return HasMany<MailThread, $this> */
    public function threads(): HasMany
    {
        return $this->hasMany(MailThread::class);
    }

    /** @return HasMany<MailMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class);
    }

    /** @return HasMany<MailContainer, $this> */
    public function containers(): HasMany
    {
        return $this->hasMany(MailContainer::class);
    }

    /** @return HasOne<MailSyncCheckpoint, $this> */
    public function syncCheckpoint(): HasOne
    {
        return $this->hasOne(MailSyncCheckpoint::class);
    }

    /** @return HasOne<MailAccountCredential, $this> */
    public function credential(): HasOne
    {
        return $this->hasOne(MailAccountCredential::class);
    }

    /** @return HasMany<MailAccountCredentialHistory, $this> */
    public function credentialHistory(): HasMany
    {
        return $this->hasMany(MailAccountCredentialHistory::class);
    }

    /** @return HasOne<MailGmailWatch, $this> */
    public function gmailWatch(): HasOne
    {
        return $this->hasOne(MailGmailWatch::class);
    }

    /** @return HasOne<MailJmapEventSource, $this> */
    public function jmapEventSource(): HasOne
    {
        return $this->hasOne(MailJmapEventSource::class);
    }

    /** @param Builder<static> $query */
    public function scopeOwnedBy(Builder $query, string $ownerType, string|int $ownerId): void
    {
        $query->where($this->qualifyColumn('owner_type'), $ownerType)
            ->where($this->qualifyColumn('owner_id'), (string) $ownerId);
    }

    /** @return array<string, class-string<MailDriver>|string> */
    protected function casts(): array
    {
        return [
            'driver' => MailDriver::class,
            'provider_metadata' => 'array',
        ];
    }
}
