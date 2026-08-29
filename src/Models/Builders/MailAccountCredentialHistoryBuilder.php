<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
final class MailAccountCredentialHistoryBuilder extends Builder
{
    /** @return never */
    public function delete()
    {
        throw new LogicException('Credential lifecycle history is immutable.');
    }
}
