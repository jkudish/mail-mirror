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
        ->and($mailboxState['spam'] ?? null)->toBeTrue()
        ->and($mailboxState['trash'] ?? null)->toBeTrue()
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
