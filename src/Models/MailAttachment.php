<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MailAttachment extends AccountScopedModel
{
    /** @return BelongsTo<MailMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id', 'mail_message_id'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + ['is_inline' => 'boolean'];
    }
}
