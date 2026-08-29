<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum MailImportStage: string
{
    case Inventory = 'inventory';
    case Retrieve = 'retrieve';
    case Parse = 'parse';
    case Hydrate = 'hydrate';
    case Storage = 'storage';

    public static function normalize(self|string $stage): self
    {
        return $stage instanceof self ? $stage : (self::tryFrom($stage) ?? self::Retrieve);
    }
}
