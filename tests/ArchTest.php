<?php

declare(strict_types=1);

arch('the package does not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('the package remains independent of consumer applications')
    ->expect('Jkudish\\MailMirror')
    ->not->toUse('App');

arch('the package does not own consumer UI, MCP, AI, policy, or approval concerns')
    ->expect('Jkudish\\MailMirror')
    ->not->toUse([
        'Illuminate\\Auth',
        'Illuminate\\Contracts\\Auth',
        'Illuminate\\Foundation\\Auth',
        'Illuminate\\Support\\Facades\\Auth',
        'Illuminate\\Support\\Facades\\Gate',
        'Illuminate\\View',
        'Inertia',
        'Livewire',
        'Filament',
        'Laravel\\Mcp',
        'OpenAI',
        'Anthropic',
    ]);
