<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Tests;

use Jkudish\MailMirror\MailMirrorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate', ['--database' => 'testing']);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [MailMirrorServiceProvider::class];
    }
}
