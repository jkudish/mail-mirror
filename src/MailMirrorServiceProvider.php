<?php

declare(strict_types=1);

namespace Jkudish\MailMirror;

use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MailReadService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class MailMirrorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('mail-mirror')
            ->hasConfigFile()
            ->hasMigration('create_mail_mirror_tables')
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(MailDriverRegistry::class);
        $this->app->singleton(MailReadService::class);
    }
}
