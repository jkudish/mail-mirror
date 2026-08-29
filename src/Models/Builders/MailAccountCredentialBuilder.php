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
final class MailAccountCredentialBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     * @return never
     */
    public function update(array $values)
    {
        throw new LogicException('Account credentials must be changed through the connection lifecycle.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @param  string|array<int, string>  $uniqueBy
     * @param  array<int, string>|null  $update
     * @return never
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('Account credentials must be changed through the connection lifecycle.');
    }

    /** @return never */
    public function delete()
    {
        throw new LogicException('Account credentials must be changed through the connection lifecycle.');
    }

    /** @return never */
    public function forceDelete()
    {
        throw new LogicException('Account credentials must be changed through the connection lifecycle.');
    }

    /** @return never */
    public function truncate()
    {
        throw new LogicException('Account credentials must be changed through the connection lifecycle.');
    }
}
