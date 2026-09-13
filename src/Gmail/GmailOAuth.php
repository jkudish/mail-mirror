<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\ConnectionStatus;
use Jkudish\MailMirror\Exceptions\ConnectionCredentialException;
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

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        if (trim($state) === '' || mb_strlen($state) > 1024 || ! $this->validCodeChallenge($codeChallenge)) {
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
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return self::AUTHORIZATION_ENDPOINT.'?'.$query;
    }

    public function exchange(
        #[SensitiveParameter] string $code,
        #[SensitiveParameter] string $codeVerifier,
    ): GmailAuthorization {
        if ($code === '' || ! $this->validCodeVerifier($codeVerifier)) {
            throw new GmailAuthorizationException;
        }

        $response = $this->tokenRequest([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->configuration('redirect_uri'),
            'code_verifier' => $codeVerifier,
        ]);
        $credential = $this->credentialFromResponse($response, null, true, requireRefreshToken: true);

        return new GmailAuthorization($credential, $this->profile($credential));
    }

    public function exchangeRefreshToken(#[SensitiveParameter] string $refreshToken): GmailAuthorization
    {
        if ($refreshToken === '') {
            throw new GmailAuthorizationException;
        }

        $response = $this->tokenRequest([
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        $credential = $this->credentialFromResponse($response, $refreshToken, true);

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
        $attemptedVersion = $stored->version;

        try {
            $this->connections->rotate($account, $stored, $replacement, $attemptedVersion);
        } catch (ConnectionCredentialException) {
            $stored->refresh();

            if ($stored->status !== ConnectionStatus::Ready || $stored->version === $attemptedVersion) {
                throw new GmailAuthorizationException;
            }

            try {
                $winner = $this->connections->credentials($account, $stored);
            } catch (ConnectionCredentialException) {
                throw new GmailAuthorizationException;
            }

            if (! $winner instanceof OAuthTokenSetCredential
                || $winner->scopes() !== [self::SCOPE]
                || ($winner->expiresAt() !== null && $winner->expiresAt() <= new DateTimeImmutable)) {
                throw new GmailAuthorizationException;
            }

            return $winner;
        }

        return $replacement;
    }

    public function profile(OAuthTokenSetCredential $credential): AccountProfile
    {
        $this->assertEnabled();

        try {
            $response = $this->http->withToken($credential->accessToken())
                ->acceptJson()->timeout($this->timeout())->withoutRedirecting()->get(self::PROFILE_ENDPOINT);
        } catch (Throwable) {
            throw new GmailAuthorizationException;
        }

        if (! $response->successful()) {
            throw new GmailAuthorizationException(
                accessRejected: $response->status() === 401,
                permissionDenied: $response->status() === 403,
            );
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
            return $this->http->asForm()->acceptJson()->timeout($this->timeout())->withoutRedirecting()->post(
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
            throw new GmailAuthorizationException(grantInvalid: $invalidGrant);
        }

        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? $fallbackRefreshToken;
        $expiresIn = $payload['expires_in'] ?? null;
        $scopeValue = $payload['scope'] ?? null;
        $scopes = is_string($scopeValue)
            ? (preg_split('/\s+/', trim($scopeValue), flags: PREG_SPLIT_NO_EMPTY) ?: [])
            : $fallbackScopes;

        if (! is_string($accessToken) || $accessToken === ''
            || (! is_string($refreshToken) && $refreshToken !== null) || $refreshToken === ''
            || ($requireRefreshToken && ! is_string($refreshToken))
            || ! is_int($expiresIn) || $expiresIn < 1
            || ($scopeRequired && ! is_string($scopeValue))
            || $scopes !== [self::SCOPE]) {
            throw new GmailAuthorizationException(
                grantInvalid: $requireRefreshToken && ! is_string($refreshToken),
            );
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

    private function validCodeChallenge(string $codeChallenge): bool
    {
        return strlen($codeChallenge) === 43 && preg_match('/^[A-Za-z0-9_-]+$/', $codeChallenge) === 1;
    }

    private function validCodeVerifier(#[SensitiveParameter] string $codeVerifier): bool
    {
        $length = strlen($codeVerifier);

        return $length >= 43 && $length <= 128
            && preg_match('/^[A-Za-z0-9._~-]+$/', $codeVerifier) === 1;
    }
}
