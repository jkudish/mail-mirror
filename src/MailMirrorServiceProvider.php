<?php

declare(strict_types=1);

namespace Jkudish\MailMirror;

use Illuminate\Contracts\Foundation\Application;
use Jkudish\MailMirror\Contracts\PubSubAccessTokenProvider;
use Jkudish\MailMirror\Credentials\EnvironmentPubSubAccessTokenProvider;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Gmail\GmailMailboxReader;
use Jkudish\MailMirror\Gmail\GmailOAuth;
use Jkudish\MailMirror\Gmail\GmailPubSubService;
use Jkudish\MailMirror\Gmail\GmailWatchService;
use Jkudish\MailMirror\Import\MailImportEngine;
use Jkudish\MailMirror\Import\ReconciliationService;
use Jkudish\MailMirror\Jmap\FastmailEventSourceService;
use Jkudish\MailMirror\Jmap\FastmailJmapMailboxReader;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MailMessageStateReader;
use Jkudish\MailMirror\Read\MailReadService;
use Jkudish\MailMirror\Read\SourceChangeService;
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
        $this->app->singleton(PubSubAccessTokenProvider::class, EnvironmentPubSubAccessTokenProvider::class);
        $this->app->singleton(GmailOAuth::class);
        $this->app->singleton(GmailMailboxReader::class);
        $this->app->singleton(GmailWatchService::class);
        $this->app->singleton(GmailPubSubService::class);
        $this->app->singleton(FastmailJmapMailboxReader::class);
        $this->app->singleton(FastmailEventSourceService::class);
        $this->app->singleton(MailDriverRegistry::class, function (Application $app): MailDriverRegistry {
            $registry = new MailDriverRegistry;
            $registry->register(MailDriver::Gmail, $app->make(GmailMailboxReader::class));
            $registry->register(MailDriver::Jmap, $app->make(FastmailJmapMailboxReader::class));

            return $registry;
        });
        $this->app->singleton(MailReadService::class);
        $this->app->singleton(MailMessageStateReader::class);
        $this->app->singleton(SourceChangeService::class);
        $this->app->singleton(MailObjectStorage::class);
        $this->app->singleton(MailAccountConnection::class);
        $this->app->singleton(ReconciliationService::class);
        $this->app->singleton(MailImportEngine::class);
    }
}
