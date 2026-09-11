<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class MailAddress extends AccountScopedModel
{
    /** @return HasMany<MailMessageParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(MailMessageParticipant::class);
    }
}
