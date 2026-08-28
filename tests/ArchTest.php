<?php

declare(strict_types=1);

arch('the package does not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('the package has no persistence, transport, queue, mail, or UI concerns')
    ->expect('Jkudish\\MailMirror')
    ->not->toUse([
        'Illuminate\\Database',
        'Illuminate\\Filesystem',
        'Illuminate\\Http',
        'Illuminate\\Mail',
        'Illuminate\\Queue',
        'Illuminate\\View',
    ]);
