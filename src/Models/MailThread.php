<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $provider_thread_id */
final class MailThread extends AccountScopedModel
{
    /** @return HasMany<MailMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class);
    }
}
