<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Enums;

enum CredentialType: string
{
    case OAuthTokenSet = 'oauth_token_set';
    case ApiToken = 'api_token';
}
