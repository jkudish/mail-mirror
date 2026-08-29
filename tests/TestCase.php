<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Tests;

use Jkudish\MailMirror\MailMirrorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', ['--database' => 'testing']);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');

        if (getenv('MAIL_MIRROR_TEST_POSTGRES') === '1') {
            $app['config']->set('database.connections.testing', [
                'driver' => 'pgsql',
                'host' => getenv('MAIL_MIRROR_TEST_POSTGRES_HOST') ?: '/var/run/postgresql',
                'port' => getenv('MAIL_MIRROR_TEST_POSTGRES_PORT') ?: '5432',
                'database' => getenv('MAIL_MIRROR_TEST_POSTGRES_DATABASE') ?: 'mail_mirror_test',
                'username' => getenv('MAIL_MIRROR_TEST_POSTGRES_USERNAME') ?: (getenv('PGUSER') ?: getenv('USER')),
                'password' => getenv('MAIL_MIRROR_TEST_POSTGRES_PASSWORD') ?: '',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);

            return;
        }

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
