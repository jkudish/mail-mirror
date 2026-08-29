<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Credentials;

use DateTimeImmutable;
use InvalidArgumentException;
use Jkudish\MailMirror\Enums\CredentialType;
use SensitiveParameter;

final readonly class OAuthTokenSetCredential
{
    use GuardsCredentialSerialization;

    /** @var list<string> */
    private array $scopes;

    /**
     * @param  array<array-key, string>  $scopes
     */
    public function __construct(
        #[SensitiveParameter] private string $accessToken,
        #[SensitiveParameter] private ?string $refreshToken = null,
        private ?DateTimeImmutable $expiresAt = null,
        array $scopes = [],
    ) {
        if ($accessToken === '' || $refreshToken === '') {
            throw new InvalidArgumentException('OAuth token values must not be empty.');
        }

        if (! array_is_list($scopes)) {
            throw new InvalidArgumentException('OAuth scopes must be a list.');
        }

        foreach ($scopes as $scope) {
            if ($scope === '') {
                throw new InvalidArgumentException('OAuth scopes must not be empty.');
            }
        }

        $this->scopes = $scopes;
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function type(): CredentialType
    {
        return CredentialType::OAuthTokenSet;
    }

    public function schemaVersion(): int
    {
        return 1;
    }
}
