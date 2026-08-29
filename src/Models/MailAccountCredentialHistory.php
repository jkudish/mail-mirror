<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Jkudish\MailMirror\Enums\ConnectionStatus;
use Jkudish\MailMirror\Enums\ConnectionTransition;
use Jkudish\MailMirror\Enums\CredentialType;
use Jkudish\MailMirror\Models\Builders\MailAccountCredentialHistoryBuilder;
use LogicException;

/**
 * @property int $id
 * @property int $mail_account_credential_id
 * @property int $version
 * @property ConnectionTransition $transition
 * @property CredentialType $credential_type
 * @property int $schema_version
 * @property ConnectionStatus|null $from_status
 * @property ConnectionStatus $to_status
 * @property CarbonImmutable $occurred_at
 */
final class MailAccountCredentialHistory extends AccountScopedModel
{
    public $timestamps = false;

    protected $table = 'mail_account_credential_history';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('Credential lifecycle history is immutable.');
        });

        self::deleting(function (): never {
            throw new LogicException('Credential lifecycle history is immutable.');
        });
    }

    /**
     * @param  QueryBuilder  $query
     * @return MailAccountCredentialHistoryBuilder<MailAccountCredentialHistory>
     */
    public function newEloquentBuilder($query): MailAccountCredentialHistoryBuilder
    {
        /** @var MailAccountCredentialHistoryBuilder<MailAccountCredentialHistory> $builder */
        $builder = new MailAccountCredentialHistoryBuilder($query);

        return $builder;
    }

    /** @return array<string, class-string<ConnectionStatus>|class-string<ConnectionTransition>|class-string<CredentialType>|string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'version' => 'integer',
            'transition' => ConnectionTransition::class,
            'credential_type' => CredentialType::class,
            'schema_version' => 'integer',
            'from_status' => ConnectionStatus::class,
            'to_status' => ConnectionStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id', 'mail_account_credential_id'];
    }
}
