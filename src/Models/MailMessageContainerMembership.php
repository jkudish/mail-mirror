<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MailMessageContainerMembership extends AccountScopedModel
{
    /** @return BelongsTo<MailMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    /** @return BelongsTo<MailContainer, $this> */
    public function container(): BelongsTo
    {
        return $this->belongsTo(MailContainer::class, 'mail_container_id');
    }

    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id', 'mail_message_id', 'mail_container_id'];
    }
}
