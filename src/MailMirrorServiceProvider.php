<?php

declare(strict_types=1);

namespace Jkudish\MailMirror;

use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MailReadService;
use Jkudish\MailMirror\Storage\MailObjectStorage;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class MailMirrorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('mail-mirror')
            ->hasConfigFile();
    }

    public function bootingPackage(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(MailDriverRegistry::class);
        $this->app->singleton(MailReadService::class);
        $this->app->singleton(MailObjectStorage::class);
        $this->app->singleton(MailAccountConnection::class);
    }
}
