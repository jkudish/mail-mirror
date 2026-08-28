<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Tests;

use Jkudish\MailMirror\MailMirrorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [MailMirrorServiceProvider::class];
    }
}
