<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Carbon\CarbonImmutable;
use Jkudish\MailMirror\Enums\MailSourceChangeKind;

/**
 * @property int $id
 * @property MailSourceChangeKind $kind
 * @property int|null $mail_message_id
 * @property int|null $mail_raw_object_id
 * @property int|null $mail_container_id
 * @property string|null $provider_message_id
 * @property string|null $provider_container_id
 * @property bool|null $provider_deleted
 * @property CarbonImmutable|null $acknowledged_at
 */
final class MailSourceChange extends AccountScopedModel
{
    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return [
            'mail_account_id',
            'kind',
            'mail_message_id',
            'mail_raw_object_id',
            'mail_container_id',
            'provider_message_id',
            'provider_container_id',
            'provider_deleted',
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'kind' => MailSourceChangeKind::class,
            'mail_message_id' => 'integer',
            'mail_raw_object_id' => 'integer',
            'mail_container_id' => 'integer',
            'provider_deleted' => 'boolean',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }
}
