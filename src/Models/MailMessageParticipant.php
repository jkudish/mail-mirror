<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MailMessageParticipant extends AccountScopedModel
{
    /** @return BelongsTo<MailMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    /** @return BelongsTo<MailAddress, $this> */
    public function address(): BelongsTo
    {
        return $this->belongsTo(MailAddress::class, 'mail_address_id');
    }

    /** @return BelongsTo<MailIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(MailIdentity::class, 'mail_identity_id');
    }

    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id', 'mail_message_id', 'mail_address_id', 'mail_identity_id'];
    }
}
