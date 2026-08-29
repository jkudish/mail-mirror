<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MailSyncCheckpoint extends AccountScopedModel
{
    /** @return BelongsTo<MailSyncRun, $this> */
    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(MailSyncRun::class, 'mail_sync_run_id');
    }

    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id', 'mail_sync_run_id'];
    }
}
