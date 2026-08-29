<?php

declare(strict_types=1);

use Jkudish\MailMirror\MailMirrorServiceProvider;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MailReadService;

it('loads through Laravel package discovery', function (): void {
    expect(app()->getProvider(MailMirrorServiceProvider::class))
        ->toBeInstanceOf(MailMirrorServiceProvider::class)
        ->and(config('mail-mirror.database_connection'))->toBeNull()
        ->and(app(MailDriverRegistry::class))->toBe(app(MailDriverRegistry::class))
        ->and(app(MailReadService::class))->toBe(app(MailReadService::class));
});
