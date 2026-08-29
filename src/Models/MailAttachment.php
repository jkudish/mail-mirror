<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $mail_message_id
 * @property string|null $source_part_id
 * @property string|null $filename
 * @property string|null $media_type
 * @property int|null $byte_size
 * @property string|null $checksum
 * @property string|null $storage_disk
 * @property string|null $object_key
 * @property string|null $content_id
 * @property bool $is_inline
 */
final class MailAttachment extends AccountScopedModel
{
    /** @var list<string> */
    protected $hidden = ['object_key'];

    /** @return BelongsTo<MailMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return [
            'mail_account_id',
            'mail_message_id',
            'provider_attachment_id',
            'source_part_id',
            'filename',
            'media_type',
            'byte_size',
            'checksum',
            'storage_disk',
            'object_key',
            'content_id',
            'is_inline',
            'provider_metadata',
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'byte_size' => 'integer',
            'is_inline' => 'boolean',
        ];
    }
}
