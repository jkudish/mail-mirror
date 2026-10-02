<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Contracts;

use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Write\DraftContent;
use Jkudish\MailMirror\Write\DraftUploadSession;

/** Optional draft capability: prepare without MIME, then recover/upload the same session. */
interface ResumableDraftDriver extends DraftDriver
{
    public function prepareDraftUpload(MailAccount $account, string $operationKey, DraftContent $content, ?string $providerThreadId): DraftUploadSession;

    /** Query first; upload only provider-confirmed remaining bytes. Never initiate another session. */
    public function uploadDraft(MailAccount $account, DraftContent $content, DraftUploadSession $session): string;
}
