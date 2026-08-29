<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Exceptions\GmailAuthorizationException;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAccountCredential;
use Jkudish\MailMirror\Read\AccountProfile;
use SensitiveParameter;
use Throwable;

final readonly class GmailOAuth
{
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.modify';

    private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const PROFILE_ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

    public function __construct(
        private Factory $http,
        private MailAccountConnection $connections,
    ) {}

    public function authorizationUrl(string $state): string
    {
        if (trim($state) === '' || mb_strlen($state) > 1024) {
            throw new GmailAuthorizationException;
        }

        $clientId = $this->configuration('client_id');
        $redirectUri = $this->configuration('redirect_uri');
        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
            'access_type' => 'offline',
            'include_granted_scopes' => 'false',
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986);

        return self::AUTHORIZATION_ENDPOINT.'?'.$query;
    }

    public function exchange(#[SensitiveParameter] string $code): GmailAuthorization
    {
        if ($code === '') {
            throw new GmailAuthorizationException;
        }

        $response = $this->tokenRequest([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->configuration('redirect_uri'),
        ]);
        $credential = $this->credentialFromResponse($response, null, true, requireRefreshToken: true);

        return new GmailAuthorization($credential, $this->profile($credential));
    }

    public function refresh(
        MailAccount $account,
        MailAccountCredential $stored,
        OAuthTokenSetCredential $current,
    ): OAuthTokenSetCredential {
        $refreshToken = $current->refreshToken();

        if ($refreshToken === null) {
            throw new GmailAuthorizationException(true);
        }

        $response = $this->tokenRequest([
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $replacement = $this->credentialFromResponse($response, $refreshToken, false, $current->scopes());
        $this->connections->rotate($account, $stored, $replacement, $stored->version);

        return $replacement;
    }

    public function profile(OAuthTokenSetCredential $credential): AccountProfile
    {
        $this->assertEnabled();

        try {
            $response = $this->http->withToken($credential->accessToken())
                ->acceptJson()->timeout($this->timeout())->get(self::PROFILE_ENDPOINT);
        } catch (Throwable) {
            throw new GmailAuthorizationException;
        }

        if (! $response->successful()) {
            throw new GmailAuthorizationException(in_array($response->status(), [400, 401, 403], true));
        }

        $payload = $response->json();
        $email = is_array($payload) ? ($payload['emailAddress'] ?? null) : null;
        $historyId = is_array($payload) ? ($payload['historyId'] ?? null) : null;
        $messagesTotal = is_array($payload) ? ($payload['messagesTotal'] ?? null) : null;
        $threadsTotal = is_array($payload) ? ($payload['threadsTotal'] ?? null) : null;

        if (! is_string($email) || trim($email) === '' || ! is_string($historyId)
            || trim($historyId) === '' || mb_strlen($historyId) > 255
            || ! is_int($messagesTotal) || $messagesTotal < 0
            || ! is_int($threadsTotal) || $threadsTotal < 0) {
            throw new GmailAuthorizationException;
        }

        return new AccountProfile($email, $email, [
            'history_id' => $historyId,
            'messages_total' => $messagesTotal,
            'threads_total' => $threadsTotal,
        ]);
    }

    /** @param array<string, string> $parameters */
    private function tokenRequest(#[SensitiveParameter] array $parameters): Response
    {
        $this->assertEnabled();

        try {
            return $this->http->asForm()->acceptJson()->timeout($this->timeout())->post(
                self::TOKEN_ENDPOINT,
                $parameters + [
                    'client_id' => $this->configuration('client_id'),
                    'client_secret' => $this->clientSecret(),
                ],
            );
        } catch (Throwable) {
            throw new GmailAuthorizationException;
        }
    }

    /** @param list<string> $fallbackScopes */
    private function credentialFromResponse(
        Response $response,
        #[SensitiveParameter] ?string $fallbackRefreshToken,
        bool $scopeRequired,
        array $fallbackScopes = [],
        bool $requireRefreshToken = false,
    ): OAuthTokenSetCredential {
        $payload = $response->json();
        $invalidGrant = is_array($payload) && ($payload['error'] ?? null) === 'invalid_grant';

        if (! $response->successful() || ! is_array($payload)) {
            throw new GmailAuthorizationException($invalidGrant || in_array($response->status(), [400, 401, 403], true));
        }

        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? $fallbackRefreshToken;
        $expiresIn = $payload['expires_in'] ?? null;
        $scopeValue = $payload['scope'] ?? null;
        $scopes = is_string($scopeValue)
            ? array_values(array_filter(preg_split('/\s+/', trim($scopeValue)) ?: []))
            : $fallbackScopes;

        if (! is_string($accessToken) || $accessToken === ''
            || (! is_string($refreshToken) && $refreshToken !== null)
            || ($requireRefreshToken && ! is_string($refreshToken))
            || ! is_int($expiresIn) || $expiresIn < 1
            || ($scopeRequired && ! is_string($scopeValue))
            || $scopes !== [self::SCOPE]) {
            throw new GmailAuthorizationException;
        }

        return new OAuthTokenSetCredential(
            $accessToken,
            $refreshToken,
            (new DateTimeImmutable)->modify('+'.$expiresIn.' seconds'),
            $scopes,
        );
    }

    private function assertEnabled(): void
    {
        if (config('mail-mirror.gmail.enabled') !== true) {
            throw new GmailAuthorizationException;
        }
    }

    private function configuration(string $key): string
    {
        $value = config('mail-mirror.gmail.'.$key);

        if (! is_string($value) || trim($value) === '') {
            throw new GmailAuthorizationException;
        }

        return $value;
    }

    private function clientSecret(): string
    {
        $value = getenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET');

        if (! is_string($value) || trim($value) === '') {
            throw new GmailAuthorizationException;
        }

        return $value;
    }

    private function timeout(): int
    {
        $timeout = config('mail-mirror.gmail.timeout_seconds', 30);

        return is_int($timeout) && $timeout >= 1 && $timeout <= 120 ? $timeout : 30;
    }
}
