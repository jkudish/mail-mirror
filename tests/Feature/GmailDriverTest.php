<?php

declare(strict_types=1);

use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\ConnectionStatus;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Enums\MailImportCode;
use Jkudish\MailMirror\Exceptions\MailImportFailure;
use Jkudish\MailMirror\Gmail\GmailMailboxReader;
use Jkudish\MailMirror\Gmail\GmailOAuth;
use Jkudish\MailMirror\Import\MailImportEngine;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAttachment;
use Jkudish\MailMirror\Models\MailIdentity;
use Jkudish\MailMirror\Models\MailInventoryItem;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailProviderDeletionEvidence;
use Jkudish\MailMirror\Models\MailRawObject;
use Jkudish\MailMirror\Models\MailSyncCheckpoint;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Storage\MailObjectStorage;

final class GmailRefreshState
{
    public bool $invalid = false;
}

/** @return array<string, array<string, mixed>> */
function gmailFixture(): array
{
    $payload = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/gmail-driver.json'), true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($payload));

    /** @var array<string, array<string, mixed>> $payload */
    return $payload;
}

function gmailAccount(string $owner = 'owner-one', ?DateTimeImmutable $expiresAt = null): MailAccount
{
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => $owner,
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'mirror@invented.test',
    ]);
    app(MailAccountConnection::class)->store($account, new OAuthTokenSetCredential(
        'synthetic-driver-access-3206',
        'synthetic-driver-refresh-3206',
        $expiresAt ?? new DateTimeImmutable('+1 hour'),
        [GmailOAuth::SCOPE],
    ));

    return $account;
}

function gmailRaw(): string
{
    return rtrim(strtr(base64_encode((string) file_get_contents(__DIR__.'/../Fixtures/synthetic-message.eml')), '+/', '-_'), '=');
}

function gmailPaddedRaw(): string
{
    return strtr(base64_encode((string) file_get_contents(__DIR__.'/../Fixtures/synthetic-message.eml')), '+/', '-_');
}

beforeEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET=synthetic-client-secret-3206');
    config()->set('mail-mirror.gmail', [
        'enabled' => true,
        'client_id' => 'synthetic-client-id.apps.example.test',
        'redirect_uri' => 'https://consumer.example.test/oauth/gmail/callback',
        'page_size' => 2,
        'timeout_seconds' => 5,
        'max_raw_bytes' => 52428800,
    ]);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET');
});

it('registers the production Gmail reader by its closed enum key and fails closed before any network call', function (): void {
    expect(app(MailDriverRegistry::class)->reader(MailDriver::Gmail))->toBeInstanceOf(GmailMailboxReader::class);

    config()->set('mail-mirror.gmail.enabled', false);
    $account = gmailAccount();

    try {
        app(GmailMailboxReader::class)->inventoryPage($account, null);
        throw new RuntimeException('The network-disabled reader performed an inventory.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::ProviderUnavailable);
    }

    Http::assertNothingSent();
});

it('keeps the separately named live lane unreachable without its explicit opt-in', function (): void {
    $command = sprintf('env -u MAIL_MIRROR_GMAIL_LIVE_OPT_IN %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__.'/../../scripts/gmail-live-development-check.php'),
    );
    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(2)
        ->and(implode("\n", $output))->toContain('explicit live-provider opt-in is required');
});

it('drives paginated duplicate Gmail inventory and complete retrieval through the import engine', function (): void {
    $fixture = gmailFixture();
    $account = gmailAccount();
    $retrievalAttempts = [];
    Sleep::fake();

    Http::fake(function (Request $request) use ($fixture, &$retrievalAttempts) {
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/profile') => Http::response($fixture['profile']),
            str_ends_with($url, '/labels') => Http::response($fixture['labels']),
            str_ends_with($url, '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($url, '/messages/gmail-message-a') && str_contains($url, 'format=full') => (function () use ($fixture, &$retrievalAttempts) {
                $retrievalAttempts['a'] = ($retrievalAttempts['a'] ?? 0) + 1;

                return $retrievalAttempts['a'] === 1 ? Http::response([], 429, ['Retry-After' => '1']) : Http::response($fixture['message_a']);
            })(),
            str_contains($url, '/messages/gmail-message-b') && str_contains($url, 'format=full') => Http::response($fixture['message_b']),
            str_contains($url, '/messages/') && str_contains($url, 'format=raw') => Http::response(['raw' => gmailRaw()]),
            str_contains($url, '/messages?') => Http::response(($request->data()['pageToken'] ?? null) === 'page-two' ? $fixture['inventory_page_2'] : $fixture['inventory_page_1']),
            default => Http::response(['synthetic' => 'unexpected'], 500),
        };
    });

    $first = app(MailImportEngine::class)->sync($account, 1);
    $checkpoint = MailSyncCheckpoint::query()->forAccount($account)->firstOrFail();

    expect($first)->toBeNull()
        ->and($checkpoint->processed_count)->toBe(1)
        ->and($checkpoint->provider_cursor)->not->toBeNull()
        ->and(MailMessage::query()->forAccount($account)->count())->toBe(1)
        ->and(MailIdentity::query()->forAccount($account)->count())->toBe(2)
        ->and($account->refresh()->provider_metadata)->toMatchArray(['history_id' => 'history-100']);

    $report = app(MailImportEngine::class)->sync($account);
    $draftMetadata = MailMessage::query()->forAccount($account)
        ->where('provider_message_id', 'gmail-message-b')->value('provider_metadata');
    assert(is_array($draftMetadata));
    $mailboxState = $draftMetadata['mailbox_state'] ?? null;
    assert(is_array($mailboxState));

    expect($report?->inventory_count)->toBe(2)
        ->and($report?->mirrored_count)->toBe(2)
        ->and(MailMessage::query()->forAccount($account)->count())->toBe(2)
        ->and($draftMetadata['draft'] ?? null)->toBeTrue()
        ->and($mailboxState)->toBe([
            'unread' => false,
            'flagged' => false,
            'draft' => true,
            'sent' => false,
            'spam' => true,
            'trash' => true,
        ])
        ->and(MailAttachment::query()->forAccount($account)->where('provider_attachment_id', 'attachment-a')->exists())->toBeTrue()
        ->and(MailRawObject::query()->forAccount($account)->count())->toBe(2)
        ->and($retrievalAttempts['a'])->toBeGreaterThanOrEqual(2);
    Sleep::assertSlept(fn (DateInterval $duration): bool => $duration->s === 1);

    $raw = MailRawObject::query()->forAccount($account)->whereHas('message', fn ($query) => $query->where('provider_message_id', 'gmail-message-a'))->firstOrFail();
    $materialized = app(MailObjectStorage::class)->regenerateAttachments($account, $raw);
    $bytes = stream_get_contents(app(MailObjectStorage::class)->readAttachment($account, $materialized[0]));

    expect($bytes)->toBe("Synthetic attachment bytes.\n")
        ->and($materialized[0]->storage_disk)->toBe('local');

    $secondPass = app(MailImportEngine::class)->sync($account);
    expect($secondPass?->mirrored_count)->toBe(2)
        ->and(MailMessage::query()->forAccount($account)->count())->toBe(2)
        ->and(MailIdentity::query()->forAccount($account)->count())->toBe(2);
});

it('uses Gmail history for changes and deletion evidence then performs an authoritative full scan', function (): void {
    $fixture = gmailFixture();
    $account = gmailAccount();
    $account->forceFill(['provider_metadata' => ['history_id' => 'history-100']])->save();
    MailMessage::query()->create(['mail_account_id' => $account->id, 'provider_message_id' => 'gmail-message-b']);
    $profile = $fixture['profile'];
    $profile['historyId'] = 'history-200';

    Http::fake(function (Request $request) use ($fixture, $profile) {
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/profile') => Http::response($profile),
            str_ends_with($url, '/labels') => Http::response($fixture['labels']),
            str_ends_with($url, '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($url, '/history') => Http::response($fixture['history']),
            str_contains($url, '/messages?') => Http::response(['messages' => [['id' => 'gmail-message-a', 'threadId' => 'gmail-thread-1']]]),
            str_contains($url, 'format=full') => Http::response($fixture['message_a']),
            str_contains($url, 'format=raw') => Http::response(['raw' => gmailRaw()]),
            default => Http::response([], 500),
        };
    });

    $report = app(MailImportEngine::class)->sync($account);

    expect($report?->provider_deleted_count)->toBe(1)
        ->and($report?->mirrored_count)->toBe(1)
        ->and(MailProviderDeletionEvidence::query()->forAccount($account)->where('provider_message_id', 'gmail-message-b')->value('proof_code'))
        ->toBe('gmail_history_deleted')
        ->and($account->refresh()->provider_metadata)->toMatchArray(['history_id' => 'history-200']);
});

it('falls back to a full scan when Gmail history has expired', function (): void {
    $fixture = gmailFixture();
    $account = gmailAccount();
    $account->forceFill(['provider_metadata' => ['history_id' => 'history-expired']])->save();
    $profile = $fixture['profile'];
    $profile['historyId'] = 'history-300';

    Http::fake(function (Request $request) use ($fixture, $profile) {
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/profile') => Http::response($profile),
            str_ends_with($url, '/labels') => Http::response($fixture['labels']),
            str_ends_with($url, '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($url, '/history') => Http::response(['error' => ['message' => 'synthetic expired history marker']], 404),
            str_contains($url, '/messages?') => Http::response([]),
            default => Http::response([], 500),
        };
    });

    $report = app(MailImportEngine::class)->sync($account);

    expect($report?->inventory_count)->toBe(0)
        ->and(MailSyncCheckpoint::query()->forAccount($account)->value('scan_completed_at'))->not->toBeNull();
});

it('rotates an expired token through the credential lifecycle and revokes invalid grants', function (): void {
    $fixture = gmailFixture();
    $account = gmailAccount(expiresAt: new DateTimeImmutable('-1 minute'));
    $state = new GmailRefreshState;

    Http::fake(function (Request $request) use ($fixture, $state) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            return $state->invalid
                ? Http::response(['error' => 'invalid_grant', 'error_description' => 'hostile-refresh-secret-3206'], 400)
                : Http::response(['access_token' => 'rotated-access-3206', 'expires_in' => 3600, 'scope' => GmailOAuth::SCOPE]);
        }

        return match (true) {
            str_ends_with($request->url(), '/profile') => Http::response($fixture['profile']),
            str_ends_with($request->url(), '/labels') => Http::response($fixture['labels']),
            str_ends_with($request->url(), '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($request->url(), '/messages?') => Http::response([]),
            default => Http::response([], 500),
        };
    });

    app(MailImportEngine::class)->sync($account);
    $stored = $account->credential()->firstOrFail();
    expect($stored->version)->toBe(2)->and($stored->status)->toBe(ConnectionStatus::Ready);

    $storedCredential = app(MailAccountConnection::class)->credentials($account, $stored);
    assert($storedCredential instanceof OAuthTokenSetCredential);
    $expiredAgain = new OAuthTokenSetCredential('expired-again-3206', $storedCredential->refreshToken(), new DateTimeImmutable('-1 minute'), [GmailOAuth::SCOPE]);
    app(MailAccountConnection::class)->rotate($account, $stored, $expiredAgain, 2);
    $state->invalid = true;

    try {
        $account->refresh();
        app(GmailMailboxReader::class)->inventoryPage($account, null);
        throw new RuntimeException('The invalid grant was accepted.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::AuthenticationFailed)
            ->and((string) $failure)->not->toContain('hostile-refresh', 'expired-again');
    }

    expect($account->credential()->firstOrFail()->status)->toBe(ConnectionStatus::Revoked);
});

it('refreshes a rejected access token once and revokes a grant rejected again', function (): void {
    $account = gmailAccount();
    $profileAttempts = 0;

    Http::fake(function (Request $request) use (&$profileAttempts) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            return Http::response([
                'access_token' => 'synthetic-retried-access-3206',
                'expires_in' => 3600,
                'scope' => GmailOAuth::SCOPE,
            ]);
        }

        if (str_ends_with($request->url(), '/profile')) {
            $profileAttempts++;

            return Http::response(['error' => ['message' => 'synthetic rejected access']], 401);
        }

        return Http::response([], 500);
    });

    try {
        app(GmailMailboxReader::class)->inventoryPage($account, null);
        throw new RuntimeException('A repeatedly rejected access token was accepted.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::AuthenticationFailed)
            ->and((string) $failure)->not->toContain('synthetic-retried-access');
    }

    $stored = $account->credential()->firstOrFail();
    expect($profileAttempts)->toBe(2)
        ->and($stored->version)->toBe(3)
        ->and($stored->status)->toBe(ConnectionStatus::Revoked);
});

it('preserves a ready refresh credential when the token endpoint rejects the client or request', function (int $status, string $error): void {
    $account = gmailAccount(expiresAt: new DateTimeImmutable('-1 minute'));

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'error' => $error,
            'error_description' => 'hostile-token-endpoint-secret-3206',
        ], $status),
    ]);

    try {
        app(GmailMailboxReader::class)->inventoryPage($account, null);
        throw new RuntimeException('The rejected token request unexpectedly continued.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::ProviderUnavailable)
            ->and($failure->retryable)->toBeTrue()
            ->and((string) $failure)->not->toContain('hostile-token-endpoint-secret', $error);
    }

    $stored = $account->credential()->firstOrFail();
    $credential = app(MailAccountConnection::class)->credentials($account, $stored);
    assert($credential instanceof OAuthTokenSetCredential);

    expect($stored->status)->toBe(ConnectionStatus::Ready)
        ->and($stored->version)->toBe(1)
        ->and($credential->refreshToken())->toBe('synthetic-driver-refresh-3206');
})->with([
    'invalid client' => [400, 'invalid_client'],
    'invalid request' => [400, 'invalid_request'],
    'generic unauthorized token response' => [401, 'temporarily_unavailable'],
]);

it('keeps credentials ready when Gmail profile access is forbidden', function (): void {
    $account = gmailAccount();

    Http::fake([
        '*users/me/profile' => Http::response([
            'error' => ['message' => 'hostile quota or API-disabled secret 3206'],
        ], 403),
    ]);

    try {
        app(GmailMailboxReader::class)->inventoryPage($account, null);
        throw new RuntimeException('The forbidden profile request unexpectedly continued.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::PermissionDenied)
            ->and($failure->retryable)->toBeFalse()
            ->and((string) $failure)->not->toContain('hostile quota', 'API-disabled');
    }

    expect($account->credential()->firstOrFail()->status)->toBe(ConnectionStatus::Ready)
        ->and($account->credential()->firstOrFail()->version)->toBe(1);
});

it('keeps credentials ready for a malformed profile request response', function (): void {
    $account = gmailAccount();

    Http::fake([
        '*users/me/profile' => Http::response(['error' => 'hostile-profile-request-secret-3206'], 400),
    ]);

    try {
        app(GmailMailboxReader::class)->inventoryPage($account, null);
        throw new RuntimeException('The malformed profile response unexpectedly continued.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::ProviderUnavailable)
            ->and((string) $failure)->not->toContain('hostile-profile-request-secret');
    }

    expect($account->credential()->firstOrFail()->status)->toBe(ConnectionStatus::Ready);
});

it('keeps credentials ready when refresh client configuration is unavailable', function (): void {
    $account = gmailAccount(expiresAt: new DateTimeImmutable('-1 minute'));
    putenv('MAIL_MIRROR_GMAIL_CLIENT_SECRET');

    try {
        app(GmailMailboxReader::class)->inventoryPage($account, null);
        throw new RuntimeException('Refresh continued without client configuration.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::ProviderUnavailable);
    }

    expect($account->credential()->firstOrFail()->status)->toBe(ConnectionStatus::Ready)
        ->and($account->credential()->firstOrFail()->version)->toBe(1);
    Http::assertNothingSent();
});

it('adopts the valid winner when two refreshes race', function (): void {
    $fixture = gmailFixture();
    $account = gmailAccount(expiresAt: new DateTimeImmutable('-1 minute'));
    $winnerRotated = false;

    Http::fake(function (Request $request) use ($account, $fixture, &$winnerRotated) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            $winnerStored = $account->credential()->firstOrFail();
            app(MailAccountConnection::class)->rotate($account, $winnerStored, new OAuthTokenSetCredential(
                'concurrent-winner-access-3206',
                'concurrent-winner-refresh-3206',
                new DateTimeImmutable('+1 hour'),
                [GmailOAuth::SCOPE],
            ), $winnerStored->version);
            $winnerRotated = true;

            return Http::response([
                'access_token' => 'concurrent-loser-access-3206',
                'refresh_token' => 'concurrent-loser-refresh-3206',
                'expires_in' => 3600,
                'scope' => GmailOAuth::SCOPE,
            ]);
        }

        return match (true) {
            str_ends_with($request->url(), '/profile') => Http::response($fixture['profile']),
            str_ends_with($request->url(), '/labels') => Http::response($fixture['labels']),
            str_ends_with($request->url(), '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($request->url(), '/messages?') => Http::response([]),
            default => Http::response([], 500),
        };
    });

    app(MailImportEngine::class)->sync($account);
    $stored = $account->credential()->firstOrFail();
    $winner = app(MailAccountConnection::class)->credentials($account, $stored);
    assert($winner instanceof OAuthTokenSetCredential);

    expect($winnerRotated)->toBeTrue()
        ->and($stored->status)->toBe(ConnectionStatus::Ready)
        ->and($stored->version)->toBe(2)
        ->and($winner->accessToken())->toBe('concurrent-winner-access-3206')
        ->and($winner->refreshToken())->toBe('concurrent-winner-refresh-3206');
});

it('never revokes a concurrent refresh winner when the losing request receives invalid_grant', function (): void {
    $fixture = gmailFixture();
    $account = gmailAccount(expiresAt: new DateTimeImmutable('-1 minute'));

    Http::fake(function (Request $request) use ($account, $fixture) {
        if ($request->url() === 'https://oauth2.googleapis.com/token') {
            $winnerStored = $account->credential()->firstOrFail();
            app(MailAccountConnection::class)->rotate($account, $winnerStored, new OAuthTokenSetCredential(
                'invalid-grant-winner-access-3206',
                'invalid-grant-winner-refresh-3206',
                new DateTimeImmutable('+1 hour'),
                [GmailOAuth::SCOPE],
            ), $winnerStored->version);

            return Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'hostile losing refresh secret 3206',
            ], 400);
        }

        return match (true) {
            str_ends_with($request->url(), '/profile') => Http::response($fixture['profile']),
            str_ends_with($request->url(), '/labels') => Http::response($fixture['labels']),
            str_ends_with($request->url(), '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($request->url(), '/messages?') => Http::response([]),
            default => Http::response([], 500),
        };
    });

    app(MailImportEngine::class)->sync($account);
    $stored = $account->credential()->firstOrFail();
    $winner = app(MailAccountConnection::class)->credentials($account, $stored);
    assert($winner instanceof OAuthTokenSetCredential);

    expect($stored->status)->toBe(ConnectionStatus::Ready)
        ->and($stored->version)->toBe(2)
        ->and($winner->refreshToken())->toBe('invalid-grant-winner-refresh-3206');
});

it('keeps the original history start across pages and applies only each ID final history state', function (): void {
    $fixture = gmailFixture();
    $account = gmailAccount();
    $account->forceFill(['provider_metadata' => ['history_id' => 'history-original-3206']])->save();
    foreach (['history-add-delete', 'history-delete-reappear', 'history-cross-page'] as $id) {
        MailMessage::query()->create(['mail_account_id' => $account->id, 'provider_message_id' => $id]);
    }
    MailProviderDeletionEvidence::query()->create([
        'mail_account_id' => $account->id,
        'scan_id' => '00000000-0000-0000-0000-000000003206',
        'provider_message_id' => 'history-delete-reappear',
        'proof_code' => 'gmail_history_deleted',
        'audit_reference' => 'synthetic-prior-proof-3206',
    ]);
    $profile = $fixture['profile'];
    $profile['historyId'] = 'history-current-3206';
    $historyRequests = [];

    Http::fake(function (Request $request) use ($fixture, $profile, &$historyRequests) {
        $url = $request->url();

        if (str_contains($url, '/history')) {
            $historyRequests[] = $request->data();

            if (($request->data()['pageToken'] ?? null) === 'history-page-two') {
                return Http::response(['history' => [[
                    'id' => 'history-105',
                    'messagesDeleted' => [['message' => ['id' => 'history-cross-page', 'threadId' => 'thread-c']]],
                ]], 'historyId' => 'history-current-3206']);
            }

            return Http::response(['history' => [
                [
                    'id' => 'history-101',
                    'messagesAdded' => [['message' => ['id' => 'history-add-delete', 'threadId' => 'thread-a']]],
                    'messagesDeleted' => [['message' => ['id' => 'history-add-delete', 'threadId' => 'thread-a']]],
                ],
                [
                    'id' => 'history-102',
                    'messagesDeleted' => [['message' => ['id' => 'history-delete-reappear', 'threadId' => 'thread-b']]],
                ],
                [
                    'id' => 'history-103',
                    'labelsAdded' => [['message' => ['id' => 'history-delete-reappear', 'threadId' => 'thread-b']]],
                ],
                [
                    'id' => 'history-104',
                    'labelsRemoved' => [['message' => ['id' => 'history-cross-page', 'threadId' => 'thread-c']]],
                ],
            ], 'nextPageToken' => 'history-page-two', 'historyId' => 'history-mid-window-3206']);
        }

        return match (true) {
            str_ends_with($url, '/profile') => Http::response($profile),
            str_ends_with($url, '/labels') => Http::response($fixture['labels']),
            str_ends_with($url, '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($url, '/messages?') => Http::response([]),
            default => Http::response([], 500),
        };
    });

    $report = app(MailImportEngine::class)->sync($account);
    $evidenceIds = MailProviderDeletionEvidence::query()->forAccount($account)
        ->orderBy('provider_message_id')->pluck('provider_message_id')->all();

    expect($historyRequests)->toHaveCount(2)
        ->and($historyRequests[0]['startHistoryId'] ?? null)->toBe('history-original-3206')
        ->and($historyRequests[1]['startHistoryId'] ?? null)->toBe('history-original-3206')
        ->and($historyRequests[1]['pageToken'] ?? null)->toBe('history-page-two')
        ->and($evidenceIds)->toBe(['history-add-delete', 'history-cross-page'])
        ->and(MailInventoryItem::query()->forAccount($account)->count())->toBe(0)
        ->and($report?->provider_deleted_count)->toBe(2)
        ->and($report?->unexpected_active_count)->toBe(1);
});

it('atomically restarts a full scan after an invalid page token and resumes after a crash', function (): void {
    $fixture = gmailFixture();
    $account = gmailAccount();
    $unpagedRequests = 0;

    Http::fake(function (Request $request) use ($fixture, &$unpagedRequests) {
        $url = $request->url();

        if (str_contains($url, '/messages?') && ($request->data()['pageToken'] ?? null) === 'rejected-full-token') {
            return Http::response(['error' => ['message' => 'hostile rejected full cursor']], 400);
        }

        if (str_contains($url, '/messages?')) {
            $unpagedRequests++;

            return $unpagedRequests === 1
                ? Http::response(['messages' => [['id' => 'gmail-message-a', 'threadId' => 'gmail-thread-1']], 'nextPageToken' => 'rejected-full-token'])
                : Http::response(['messages' => [['id' => 'gmail-message-b', 'threadId' => 'gmail-thread-1']]]);
        }

        return match (true) {
            str_ends_with($url, '/profile') => Http::response($fixture['profile']),
            str_ends_with($url, '/labels') => Http::response($fixture['labels']),
            str_ends_with($url, '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($url, '/messages/gmail-message-a') && str_contains($url, 'format=full') => Http::response($fixture['message_a']),
            str_contains($url, '/messages/gmail-message-b') && str_contains($url, 'format=full') => Http::response($fixture['message_b']),
            str_contains($url, 'format=raw') => Http::response(['raw' => gmailRaw()]),
            default => Http::response([], 500),
        };
    });

    app(MailImportEngine::class)->sync($account, 1);
    $partial = MailSyncCheckpoint::query()->forAccount($account)->firstOrFail();
    $partialScanId = $partial->scan_id;

    $report = app(MailImportEngine::class)->sync($account);
    $restarted = MailSyncCheckpoint::query()->forAccount($account)->firstOrFail();

    expect($restarted->scan_id)->not->toBe($partialScanId)
        ->and($restarted->scan_completed_at)->not->toBeNull()
        ->and($report?->inventory_count)->toBe(1)
        ->and(MailInventoryItem::query()->forAccount($account)->where('scan_id', $restarted->scan_id)
            ->pluck('provider_message_id')->all())->toBe(['gmail-message-b']);
});

it('restarts from a full scan after an invalid history page token', function (): void {
    $fixture = gmailFixture();
    $account = gmailAccount();
    $account->forceFill(['provider_metadata' => ['history_id' => 'history-original-3206']])->save();
    $profile = $fixture['profile'];
    $profile['historyId'] = 'history-current-3206';

    Http::fake(function (Request $request) use ($fixture, $profile) {
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/profile') => Http::response($profile),
            str_ends_with($url, '/labels') => Http::response($fixture['labels']),
            str_ends_with($url, '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($url, '/history') && ($request->data()['pageToken'] ?? null) === 'rejected-history-token' => Http::response([], 400),
            str_contains($url, '/history') => Http::response(['nextPageToken' => 'rejected-history-token']),
            str_contains($url, '/messages?') => Http::response([]),
            default => Http::response([], 500),
        };
    });

    app(MailImportEngine::class)->sync($account, 1);
    $partialScanId = MailSyncCheckpoint::query()->forAccount($account)->value('scan_id');
    $report = app(MailImportEngine::class)->sync($account);

    expect(MailSyncCheckpoint::query()->forAccount($account)->value('scan_id'))->not->toBe($partialScanId)
        ->and($report?->inventory_count)->toBe(0);
});

it('deduplicates a high-cardinality repeated history event page by unique output ID', function (): void {
    config()->set('mail-mirror.inventory_page_max_messages', 3);
    $fixture = gmailFixture();
    $account = gmailAccount();
    $account->forceFill(['provider_metadata' => ['history_id' => 'history-original-3206']])->save();
    $profile = $fixture['profile'];
    $profile['historyId'] = 'history-current-3206';
    $duplicates = array_fill(0, 2000, ['message' => ['id' => 'duplicate-history-id', 'threadId' => 'duplicate-thread']]);

    Http::fake(function (Request $request) use ($fixture, $profile, $duplicates) {
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/profile') => Http::response($profile),
            str_ends_with($url, '/labels') => Http::response($fixture['labels']),
            str_ends_with($url, '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($url, '/history') => Http::response(['history' => [[
                'id' => 'history-duplicate-flood',
                'labelsAdded' => $duplicates,
            ]]]),
            str_contains($url, '/messages?') => Http::response([]),
            default => Http::response([], 500),
        };
    });

    $report = app(MailImportEngine::class)->sync($account);

    expect($report?->inventory_count)->toBe(0)
        ->and(MailProviderDeletionEvidence::query()->forAccount($account)->count())->toBe(0);
});

it('clamps a legal full page around first-page identities', function (): void {
    config()->set('mail-mirror.inventory_page_max_messages', 4);
    config()->set('mail-mirror.gmail.page_size', 500);
    $fixture = gmailFixture();
    $account = gmailAccount();
    $observedMaxResults = null;

    Http::fake(function (Request $request) use ($fixture, &$observedMaxResults) {
        $url = $request->url();

        if (str_contains($url, '/messages?')) {
            $observedMaxResults = $request->data()['maxResults'] ?? null;

            return Http::response(['messages' => [
                ['id' => 'gmail-message-a', 'threadId' => 'gmail-thread-1'],
                ['id' => 'gmail-message-b', 'threadId' => 'gmail-thread-1'],
            ]]);
        }

        return match (true) {
            str_ends_with($url, '/profile') => Http::response($fixture['profile']),
            str_ends_with($url, '/labels') => Http::response($fixture['labels']),
            str_ends_with($url, '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($url, '/messages/gmail-message-a') && str_contains($url, 'format=full') => Http::response($fixture['message_a']),
            str_contains($url, '/messages/gmail-message-b') && str_contains($url, 'format=full') => Http::response($fixture['message_b']),
            str_contains($url, 'format=raw') => Http::response(['raw' => gmailRaw()]),
            default => Http::response([], 500),
        };
    });

    $report = app(MailImportEngine::class)->sync($account);

    expect($observedMaxResults)->toBe(2)
        ->and($report?->inventory_count)->toBe(2)
        ->and(MailIdentity::query()->forAccount($account)->count())->toBe(2);
});

it('abandons an over-limit unique history page for a bounded authoritative full scan', function (): void {
    config()->set('mail-mirror.inventory_page_max_messages', 4);
    $fixture = gmailFixture();
    $account = gmailAccount();
    $account->forceFill(['provider_metadata' => ['history_id' => 'history-original-3206']])->save();
    $profile = $fixture['profile'];
    $profile['historyId'] = 'history-current-3206';
    $historyRequests = 0;

    Http::fake(function (Request $request) use ($fixture, $profile, &$historyRequests) {
        $url = $request->url();

        if (str_contains($url, '/history')) {
            $historyRequests++;

            return Http::response(['history' => [[
                'id' => 'history-over-limit',
                'labelsAdded' => [
                    ['message' => ['id' => 'unique-a', 'threadId' => 'thread-a']],
                    ['message' => ['id' => 'unique-b', 'threadId' => 'thread-b']],
                    ['message' => ['id' => 'unique-c', 'threadId' => 'thread-c']],
                ],
            ]]]);
        }

        return match (true) {
            str_ends_with($url, '/profile') => Http::response($profile),
            str_ends_with($url, '/labels') => Http::response($fixture['labels']),
            str_ends_with($url, '/settings/sendAs') => Http::response($fixture['identities']),
            str_contains($url, '/messages?') => Http::response([]),
            default => Http::response([], 500),
        };
    });

    $report = app(MailImportEngine::class)->sync($account);

    expect($historyRequests)->toBe(1)
        ->and($report?->inventory_count)->toBe(0)
        ->and(MailSyncCheckpoint::query()->forAccount($account)->value('version'))->toBeGreaterThanOrEqual(2);
});

it('rejects oversized encoded raw data before base64 decoding', function (): void {
    config()->set('mail-mirror.gmail.max_raw_bytes', 1048576);
    $fixture = gmailFixture();
    $account = gmailAccount();
    $encoded = str_repeat('A', 1398104);

    Http::fake(function (Request $request) use ($fixture, $encoded) {
        return str_contains($request->url(), 'format=raw')
            ? Http::response(['raw' => $encoded])
            : Http::response($fixture['message_a']);
    });

    $reference = new MessageReference($account->id, MailDriver::Gmail, 'gmail-message-a', 'gmail-thread-1');

    try {
        app(GmailMailboxReader::class)->retrieve($account, $reference);
        throw new RuntimeException('The oversized encoded raw source was decoded.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::MalformedPayload);
    }
});

it('accepts Gmail raw data with valid trailing base64url padding', function (): void {
    $fixture = gmailFixture();
    $account = gmailAccount();

    Http::fake(function (Request $request) use ($fixture) {
        return str_contains($request->url(), 'format=raw')
            ? Http::response(['raw' => gmailPaddedRaw()])
            : Http::response($fixture['message_a']);
    });

    $reference = new MessageReference($account->id, MailDriver::Gmail, 'gmail-message-a', 'gmail-thread-1');
    $message = app(GmailMailboxReader::class)->retrieve($account, $reference);
    $source = $message->rawSource?->stream;
    assert(is_resource($source));

    expect($source)->toBeResource()
        ->and(stream_get_contents($source))->toBe(file_get_contents(__DIR__.'/../Fixtures/synthetic-message.eml'));

    fclose($source);
});

it('accepts bounded native Gmail attachment IDs longer than 255 characters', function (): void {
    $fixture = gmailFixture();
    $account = gmailAccount();
    $nativeAttachmentId = str_repeat('a', 404);
    $nativeMessage = $fixture['message_a'];
    $payload = $nativeMessage['payload'] ?? null;
    assert(is_array($payload));
    $parts = $payload['parts'] ?? null;
    assert(is_array($parts));
    $part = $parts[0] ?? null;
    assert(is_array($part));
    $body = $part['body'] ?? null;
    assert(is_array($body));
    $body['attachmentId'] = $nativeAttachmentId;
    $part['body'] = $body;
    $parts[0] = $part;
    $payload['parts'] = $parts;
    $nativeMessage['payload'] = $payload;
    $fixture['message_a'] = $nativeMessage;

    Http::fake(function (Request $request) use ($fixture) {
        return str_contains($request->url(), 'format=raw')
            ? Http::response(['raw' => gmailPaddedRaw()])
            : Http::response($fixture['message_a']);
    });

    $reference = new MessageReference($account->id, MailDriver::Gmail, 'gmail-message-a', 'gmail-thread-1');
    $message = app(GmailMailboxReader::class)->retrieve($account, $reference);
    $attachment = $message->attachments[0] ?? null;
    assert(is_array($attachment));
    $source = $message->rawSource?->stream;
    assert(is_resource($source));

    expect($message->attachments)->toHaveCount(1)
        ->and($attachment['provider_id'])->toBe($nativeAttachmentId);

    fclose($source);
});

it('accepts padded Gmail raw data at the configured decoded-byte limit', function (): void {
    config()->set('mail-mirror.gmail.max_raw_bytes', 1048576);
    $fixture = gmailFixture();
    $account = gmailAccount();
    $prefix = "From: sender@invented.test\r\nTo: recipient@invented.test\r\nSubject: Exact limit\r\n\r\n";
    $rawBytes = $prefix.str_repeat('A', 1048576 - strlen($prefix));
    $encoded = strtr(base64_encode($rawBytes), '+/', '-_');

    Http::fake(function (Request $request) use ($fixture, $encoded) {
        return str_contains($request->url(), 'format=raw')
            ? Http::response(['raw' => $encoded])
            : Http::response($fixture['message_a']);
    });

    $reference = new MessageReference($account->id, MailDriver::Gmail, 'gmail-message-a', 'gmail-thread-1');
    $message = app(GmailMailboxReader::class)->retrieve($account, $reference);
    $source = $message->rawSource?->stream;
    assert(is_resource($source));

    expect(strlen((string) stream_get_contents($source)))->toBe(1048576);

    fclose($source);
});

it('rejects non-canonical Gmail raw base64url padding', function (string $encoded): void {
    $fixture = gmailFixture();
    $account = gmailAccount();

    Http::fake(function (Request $request) use ($fixture, $encoded) {
        return str_contains($request->url(), 'format=raw')
            ? Http::response(['raw' => $encoded])
            : Http::response($fixture['message_a']);
    });

    $reference = new MessageReference($account->id, MailDriver::Gmail, 'gmail-message-a', 'gmail-thread-1');

    try {
        app(GmailMailboxReader::class)->retrieve($account, $reference);
        throw new RuntimeException('The non-canonical raw encoding was accepted.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::MalformedPayload);
    }
})->with(['YQ=', 'YQ===', 'Y=Q=', 'AB==']);

it('normalizes oversized send-as identity fields to a sparse malformed-payload failure', function (string $field): void {
    $fixture = gmailFixture();
    $account = gmailAccount();
    $identities = $fixture['identities'];
    assert(is_array($identities['sendAs'] ?? null));
    assert(is_array($identities['sendAs'][0] ?? null));
    $identities['sendAs'][0][$field] = str_repeat('x', 256);

    Http::fake(function (Request $request) use ($fixture, $identities) {
        return match (true) {
            str_ends_with($request->url(), '/profile') => Http::response($fixture['profile']),
            str_ends_with($request->url(), '/labels') => Http::response($fixture['labels']),
            str_ends_with($request->url(), '/settings/sendAs') => Http::response($identities),
            default => Http::response([], 500),
        };
    });

    try {
        app(GmailMailboxReader::class)->inventoryPage($account, null);
        throw new RuntimeException('The oversized send-as identity was accepted.');
    } catch (MailImportFailure $failure) {
        expect($failure->safeCode)->toBe(MailImportCode::MalformedPayload)
            ->and((string) $failure)->not->toContain(str_repeat('x', 64));
    }
})->with(['sendAsEmail', 'displayName']);

it('rejects malformed and cross-account payloads without stale mirror masking', function (): void {
    $first = gmailAccount('first-owner');
    $second = gmailAccount('second-owner');
    $fixture = gmailFixture();
    $reader = app(GmailMailboxReader::class);

    Http::fake([
        '*messages/gmail-message-a*' => Http::response(['id' => 'gmail-message-a', 'labelIds' => 'hostile-secret-payload']),
    ]);

    $reference = new MessageReference($first->id, MailDriver::Gmail, 'gmail-message-a', 'gmail-thread-1');

    expect(fn () => $reader->retrieve($second, $reference))->toThrow(MailImportFailure::class)
        ->and(fn () => $reader->retrieve($first, $reference))->toThrow(MailImportFailure::class);

    expect(MailMessage::query()->count())->toBe(0)
        ->and($fixture)->toHaveKey('message_a');
});
