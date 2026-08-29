<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Credentials;

use InvalidArgumentException;
use Jkudish\MailMirror\Enums\CredentialType;
use SensitiveParameter;

final readonly class ApiTokenCredential
{
    use GuardsCredentialSerialization;

    public function __construct(#[SensitiveParameter] private string $token)
    {
        if ($token === '') {
            throw new InvalidArgumentException('An API token must not be empty.');
        }
    }

    public function token(): string
    {
        return $this->token;
    }

    public function type(): CredentialType
    {
        return CredentialType::ApiToken;
    }

    public function schemaVersion(): int
    {
        return 1;
    }
}
