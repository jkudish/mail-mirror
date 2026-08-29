<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

use Jkudish\MailMirror\Credentials\GuardsCredentialSerialization;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Read\AccountProfile;

final readonly class GmailAuthorization
{
    use GuardsCredentialSerialization;

    public function __construct(
        private OAuthTokenSetCredential $credential,
        public AccountProfile $profile,
    ) {}

    public function credential(): OAuthTokenSetCredential
    {
        return $this->credential;
    }
}
