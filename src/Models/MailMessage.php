<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $mail_thread_id
 * @property string $provider_message_id
 * @property string $provider_occurrence_id
 */
final class MailMessage extends AccountScopedModel
{
    /** @return BelongsTo<MailThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MailThread::class, 'mail_thread_id');
    }

    /** @return HasMany<MailMessageParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(MailMessageParticipant::class);
    }

    /** @return HasMany<MailMessageHeader, $this> */
    public function headers(): HasMany
    {
        return $this->hasMany(MailMessageHeader::class);
    }

    /** @return HasMany<MailAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class);
    }

    /** @return HasMany<MailRawObject, $this> */
    public function rawObjects(): HasMany
    {
        return $this->hasMany(MailRawObject::class);
    }

    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id', 'mail_thread_id'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'sent_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }
}
