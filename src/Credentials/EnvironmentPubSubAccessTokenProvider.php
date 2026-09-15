<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Credentials;

use Jkudish\MailMirror\Contracts\PubSubAccessTokenProvider;
use Jkudish\MailMirror\Exceptions\ProviderNotificationException;

final class EnvironmentPubSubAccessTokenProvider implements PubSubAccessTokenProvider
{
    public function accessToken(): string
    {
        $variable = config('mail-mirror.gmail.pubsub.access_token_environment');
        $token = is_string($variable) && $variable !== '' ? getenv($variable) : false;

        if (! is_string($token) || $token === '' || strlen($token) > 8192) {
            throw new ProviderNotificationException('authentication_failed');
        }

        return $token;
    }
}
