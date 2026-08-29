<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\MailMirrorServiceProvider;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MailReadService;
use Jkudish\MailMirror\Storage\MailObjectStorage;

it('loads through Laravel package discovery', function (): void {
    expect(app()->getProvider(MailMirrorServiceProvider::class))
        ->toBeInstanceOf(MailMirrorServiceProvider::class)
        ->and(config('mail-mirror.database_connection'))->toBeNull()
        ->and(config('mail-mirror.storage_disk'))->toBe('local')
        ->and(app(MailDriverRegistry::class))->toBe(app(MailDriverRegistry::class))
        ->and(app(MailReadService::class))->toBe(app(MailReadService::class))
        ->and(app(MailObjectStorage::class))->toBe(app(MailObjectStorage::class))
        ->and(app(MailAccountConnection::class))->toBe(app(MailAccountConnection::class))
        ->and(Schema::hasTable('mail_accounts'))->toBeTrue();
});

it('loads migrations from one source without publishing a duplicate copy', function (): void {
    $publishableDestinations = array_values(ServiceProvider::pathsToPublish(MailMirrorServiceProvider::class));

    expect($publishableDestinations)
        ->each->not->toContain('create_mail_mirror_tables');
});

it('honors an explicit model connection before the package default', function (): void {
    config()->set('mail-mirror.database_connection', 'configured-connection');

    expect((new MailAccount)->setConnection('account-connection')->getConnectionName())->toBe('account-connection')
        ->and((new MailMessage)->setConnection('record-connection')->getConnectionName())->toBe('record-connection');
});
