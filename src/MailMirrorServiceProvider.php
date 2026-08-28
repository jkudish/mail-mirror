<?php

declare(strict_types=1);

namespace Jkudish\MailMirror;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class MailMirrorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('mail-mirror');
    }
}
