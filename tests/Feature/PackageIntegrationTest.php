<?php

declare(strict_types=1);

use Jkudish\MailMirror\MailMirrorServiceProvider;

it('loads through Laravel package discovery', function (): void {
    expect(app()->getProvider(MailMirrorServiceProvider::class))
        ->toBeInstanceOf(MailMirrorServiceProvider::class);
});
