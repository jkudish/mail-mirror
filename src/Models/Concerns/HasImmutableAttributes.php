<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models\Concerns;

use LogicException;

trait HasImmutableAttributes
{
    protected static function bootHasImmutableAttributes(): void
    {
        static::updating(function (self $model): void {
            foreach ($model->immutableAttributes() as $attribute) {
                if ($model->isDirty($attribute)) {
                    throw new LogicException(sprintf('%s is immutable.', $attribute));
                }
            }
        });
    }

    /** @return list<string> */
    protected function immutableAttributes(): array
    {
        return ['mail_account_id'];
    }
}
