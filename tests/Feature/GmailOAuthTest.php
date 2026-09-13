<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jkudish\MailMirror\Exceptions\ConnectionCredentialException;
use Jkudish\MailMirror\Exceptions\GmailAuthorizationException;
use Jkudish\MailMirror\Gmail\GmailAuthorization;
use Jkudish\MailMirror\Gmail\GmailMailboxReader;
use Jkudish\MailMirror\Gmail\GmailOAuth;

beforeEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET=synthetic-client-secret-3206');
    config()->set('mail-mirror.gmail', [
        'enabled' => true,
        'client_id' => 'synthetic-client-id.apps.example.test',
        'redirect_uri' => 'https://consumer.example.test/oauth/gmail/callback',
        'page_size' => 100,
        'timeout_seconds' => 5,
        'max_raw_bytes' => 52428800,
    ]);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET');
});

function gmailCodeVerifier(): string
{
    return str_repeat('synthetic-verifier-', 3);
}

function gmailCodeChallenge(): string
{
    return rtrim(strtr(base64_encode(hash('sha256', gmailCodeVerifier(), true)), '+/', '-_'), '=');
}

it('builds an authorization request with exactly gmail.modify and no other capability', function (): void {
    $url = app(GmailOAuth::class)->authorizationUrl('opaque-state-3206', gmailCodeChallenge());
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['scope'] ?? null)->toBe(GmailOAuth::SCOPE)
        ->and($query['include_granted_scopes'] ?? null)->toBe('false')
        ->and($query['code_challenge'] ?? null)->toBe(gmailCodeChallenge())
        ->and($query['code_challenge_method'] ?? null)->toBe('S256')
        ->and($query)->not->toHaveKey('client_secret')
        ->and($url)->not->toContain('mail.google.com', 'gmail.settings', 'gmail.compose', 'gmail.send');
});

it('exchanges an authorization code, validates the exact grant, and discovers the profile', function (): void {
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            return Http::response([
                'access_token' => 'synthetic-access-3206',
                'refresh_token' => 'synthetic-refresh-3206',
                'expires_in' => 3600,
                'scope' => GmailOAuth::SCOPE,
            ]);
        }

        return Http::response([
            'emailAddress' => 'mirror@invented.test',
            'messagesTotal' => 2,
            'threadsTotal' => 1,
            'historyId' => 'history-100',
        ]);
    });

    $authorization = app(GmailOAuth::class)->exchange('synthetic-code-3206', gmailCodeVerifier());

    expect($authorization)->toBeInstanceOf(GmailAuthorization::class)
        ->and($authorization->profile->providerAccountId)->toBe('mirror@invented.test')
        ->and($authorization->credential()->scopes())->toBe([GmailOAuth::SCOPE])
        ->and(fn () => serialize($authorization))->toThrow(ConnectionCredentialException::class);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code_verifier'] === gmailCodeVerifier()
        && $request['client_secret'] === 'synthetic-client-secret-3206');
});

it('exchanges an existing refresh token, validates the exact grant, and discovers the profile', function (): void {
    $refreshToken = 'synthetic-restored-refresh-3206';

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            return Http::response([
                'access_token' => 'synthetic-restored-access-3206',
                'expires_in' => 1800,
                'scope' => GmailOAuth::SCOPE,
            ]);
        }

        return Http::response([
            'emailAddress' => 'restored@invented.test',
            'messagesTotal' => 12,
            'threadsTotal' => 7,
            'historyId' => 'history-restored-100',
        ]);
    });

    $authorization = app(GmailOAuth::class)->exchangeRefreshToken($refreshToken);

    expect($authorization)->toBeInstanceOf(GmailAuthorization::class)
        ->and($authorization->credential()->accessToken())->toBe('synthetic-restored-access-3206')
        ->and($authorization->credential()->refreshToken())->toBe($refreshToken)
        ->and($authorization->credential()->scopes())->toBe([GmailOAuth::SCOPE])
        ->and($authorization->credential()->expiresAt())->not->toBeNull()
        ->and($authorization->profile->providerAccountId)->toBe('restored@invented.test')
        ->and($authorization->profile->emailAddress)->toBe('restored@invented.test')
        ->and($authorization->profile->providerMetadata)->toBe([
            'history_id' => 'history-restored-100',
            'messages_total' => 12,
            'threads_total' => 7,
        ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request->method() === 'POST'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === $refreshToken
        && $request['client_id'] === 'synthetic-client-id.apps.example.test'
        && $request['client_secret'] === 'synthetic-client-secret-3206'
        && ! isset($request['redirect_uri'])
        && ! isset($request['code'])
        && ! isset($request['code_verifier']));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/profile'
        && $request->hasHeader('Authorization', 'Bearer synthetic-restored-access-3206'));
    Http::assertSentCount(2);
});

it('rejects invalid, revoked, and broader restored refresh-token grants without leaking the token', function (array $response, int $status): void {
    $refreshToken = 'hostile-restored-refresh-secret-3206';

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response($response, $status),
    ]);

    try {
        app(GmailOAuth::class)->exchangeRefreshToken($refreshToken);
        throw new RuntimeException('The unsafe restored refresh-token grant was accepted.');
    } catch (GmailAuthorizationException $failure) {
        expect((string) $failure)->not->toContain($refreshToken)
            ->and($failure->grantInvalid)->toBe(($response['error'] ?? null) === 'invalid_grant');
    }

    Http::assertSentCount(1);
})->with([
    'invalid response' => [['access_token' => 'hostile-restored-access-3206', 'expires_in' => 3600], 200],
    'revoked token' => [['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400],
    'broader grant' => [[
        'access_token' => 'hostile-restored-access-3206',
        'expires_in' => 3600,
        'scope' => GmailOAuth::SCOPE.' openid',
    ], 200],
    'numeric-looking extra scope' => [[
        'access_token' => 'hostile-restored-access-3206',
        'expires_in' => 3600,
        'scope' => GmailOAuth::SCOPE.' 0',
    ], 200],
    'empty replacement refresh token' => [[
        'access_token' => 'hostile-restored-access-3206',
        'refresh_token' => '',
        'expires_in' => 3600,
        'scope' => GmailOAuth::SCOPE,
    ], 200],
]);

it('rejects an empty restored refresh token before network access', function (): void {
    expect(fn () => app(GmailOAuth::class)->exchangeRefreshToken(''))
        ->toThrow(GmailAuthorizationException::class);

    Http::assertNothingSent();
});

it('requires the provider gate and token client configuration before restoring a refresh token', function (array $configuration, ?string $secret): void {
    if (array_key_exists('enabled', $configuration)) {
        config()->set('mail-mirror.gmail.enabled', $configuration['enabled']);
    }

    if (array_key_exists('client_id', $configuration)) {
        config()->set('mail-mirror.gmail.client_id', $configuration['client_id']);
    }

    putenv($secret === null ? 'MAIL_MIRROR_GMAIL_CLIENT_SECRET' : "MAIL_MIRROR_GMAIL_CLIENT_SECRET={$secret}");

    expect(fn () => app(GmailOAuth::class)->exchangeRefreshToken('synthetic-restored-refresh-3206'))
        ->toThrow(GmailAuthorizationException::class);

    Http::assertNothingSent();
})->with([
    'disabled provider' => [['enabled' => false], 'synthetic-client-secret-3206'],
    'missing client id' => [['client_id' => ''], 'synthetic-client-secret-3206'],
    'missing client secret' => [[], null],
]);

it('restores without redirect configuration and accepts a rotated refresh token', function (): void {
    config()->set('mail-mirror.gmail.redirect_uri', '');
    Http::fake(function (Request $request) {
        return $request->url() === 'https://oauth2.googleapis.com/token'
            ? Http::response([
                'access_token' => 'synthetic-rotated-access-3206',
                'refresh_token' => 'synthetic-rotated-refresh-3206',
                'expires_in' => 3600,
                'scope' => GmailOAuth::SCOPE,
            ])
            : Http::response([
                'emailAddress' => 'rotated@invented.test',
                'messagesTotal' => 1,
                'threadsTotal' => 1,
                'historyId' => 'history-rotated-100',
            ]);
    });

    $authorization = app(GmailOAuth::class)->exchangeRefreshToken('synthetic-original-refresh-3206');

    expect($authorization->credential()->refreshToken())->toBe('synthetic-rotated-refresh-3206');
});

it('rejects an authorization-code exchange that cannot establish refresh integration', function (): void {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'synthetic-access-without-refresh-3206',
            'expires_in' => 3600,
            'scope' => GmailOAuth::SCOPE,
        ]),
    ]);

    try {
        app(GmailOAuth::class)->exchange('synthetic-code-3206', gmailCodeVerifier());
        throw new RuntimeException('The exchange without refresh integration was accepted.');
    } catch (GmailAuthorizationException $failure) {
        expect($failure->grantInvalid)->toBeTrue();
    }
    Http::assertSentCount(1);
});

it('rejects missing, narrower, broader, full-mailbox, and malformed grants without leaking secrets', function (string $scope): void {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'hostile-access-secret-3206',
            'refresh_token' => 'hostile-refresh-secret-3206',
            'expires_in' => 3600,
            'scope' => $scope,
        ]),
    ]);

    try {
        app(GmailOAuth::class)->exchange('hostile-code-secret-3206', gmailCodeVerifier());
        throw new RuntimeException('The unsafe scope grant was accepted.');
    } catch (GmailAuthorizationException $exception) {
        expect($exception->getMessage())->toBe('Gmail authorization could not be completed safely.')
            ->and((string) $exception)->not->toContain('hostile-access', 'hostile-refresh', 'hostile-code');
    }
})->with([
    'missing' => '',
    'narrower' => 'https://www.googleapis.com/auth/gmail.readonly',
    'broader full mailbox' => 'https://mail.google.com/',
    'extra scope' => GmailOAuth::SCOPE.' openid',
    'settings scope' => GmailOAuth::SCOPE.' https://www.googleapis.com/auth/gmail.settings.basic',
]);

it('exposes no Gmail mutation, submission, deletion, draft-write, or revocation API', function (): void {
    $methods = array_merge(get_class_methods(GmailOAuth::class), get_class_methods(GmailMailboxReader::class));

    expect($methods)->not->toContain('send', 'submit', 'delete', 'trash', 'modify', 'createDraft', 'updateDraft', 'revoke');
});

it('rejects malformed PKCE values before network and redacts the verifier', function (): void {
    $hostileVerifier = 'hostile-verifier-secret-3206';

    expect(fn () => app(GmailOAuth::class)->authorizationUrl('opaque-state-3206', 'not-a-valid-challenge'))
        ->toThrow(GmailAuthorizationException::class);

    try {
        app(GmailOAuth::class)->exchange('hostile-code-secret-3206', $hostileVerifier);
        throw new RuntimeException('The malformed PKCE verifier was accepted.');
    } catch (GmailAuthorizationException $failure) {
        expect((string) $failure)->not->toContain($hostileVerifier, 'hostile-code-secret-3206');
    }

    Http::assertNothingSent();
});
