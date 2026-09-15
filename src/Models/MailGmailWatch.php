<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $mail_account_id
 * @property string $project_id
 * @property string $topic
 * @property string $subscription
 * @property string $history_id_hint
 */
final class MailGmailWatch extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        $configured = config('mail-mirror.database_connection');

        return parent::getConnectionName() ?? (is_string($configured) ? $configured : null);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
