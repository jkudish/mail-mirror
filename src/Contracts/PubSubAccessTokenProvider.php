<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Contracts;

interface PubSubAccessTokenProvider
{
    public function accessToken(): string;
}
