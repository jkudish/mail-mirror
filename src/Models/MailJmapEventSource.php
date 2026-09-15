<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $mail_account_id
 * @property string $event_source_origin
 * @property string|null $last_event_id
 */
final class MailJmapEventSource extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        $configured = config('mail-mirror.database_connection');

        return parent::getConnectionName() ?? (is_string($configured) ? $configured : null);
    }
}
