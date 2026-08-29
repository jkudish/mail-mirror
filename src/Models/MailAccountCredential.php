<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Jkudish\MailMirror\Enums\ConnectionStatus;
use Jkudish\MailMirror\Enums\CredentialType;
use Jkudish\MailMirror\Models\Builders\MailAccountCredentialBuilder;
use LogicException;

/**
 * @property int $id
 * @property CredentialType $credential_type
 * @property int $schema_version
 * @property string $encrypted_payload
 * @property ConnectionStatus $status
 * @property int $version
 * @property CarbonImmutable $last_activated_at
 * @property CarbonImmutable|null $disabled_at
 * @property CarbonImmutable|null $revoked_at
 *
 * @method static Builder<static> forAccount(MailAccount|int $account)
 */
final class MailAccountCredential extends AccountScopedModel
{
    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['encrypted_payload'];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('Account credentials must be changed through the connection lifecycle.');
        });
    }

    /**
     * @param  QueryBuilder  $query
     * @return MailAccountCredentialBuilder<MailAccountCredential>
     */
    public function newEloquentBuilder($query): MailAccountCredentialBuilder
    {
        /** @var MailAccountCredentialBuilder<MailAccountCredential> $builder */
        $builder = new MailAccountCredentialBuilder($query);

        return $builder;
    }

    /** @return HasMany<MailAccountCredentialHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(MailAccountCredentialHistory::class);
    }

    /** @return array<string, class-string<ConnectionStatus>|class-string<CredentialType>|string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'credential_type' => CredentialType::class,
            'schema_version' => 'integer',
            'status' => ConnectionStatus::class,
            'version' => 'integer',
            'last_activated_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
