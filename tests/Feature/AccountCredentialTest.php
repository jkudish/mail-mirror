<?php

declare(strict_types=1);

use DateTimeImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\ConnectionStatus;
use Jkudish\MailMirror\Enums\ConnectionTransition;
use Jkudish\MailMirror\Enums\CredentialType;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Exceptions\ConnectionCredentialException;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAccountCredential;
use Jkudish\MailMirror\Models\MailAccountCredentialHistory;
use PHPUnit\Framework\SkippedWithMessageException;

/** @property int $id */
final class CredentialSyntheticOwner extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('credential_synthetic_owners', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Relation::morphMap(['credential-synthetic-owner' => CredentialSyntheticOwner::class]);
});

function credentialAccount(string $suffix, ?CredentialSyntheticOwner $owner = null): MailAccount
{
    return MailAccount::query()->create([
        'owner_type' => $owner === null ? null : 'credential-synthetic-owner',
        'owner_id' => $owner?->id,
        'driver' => MailDriver::Gmail,
        'provider_account_id' => "credential-account-{$suffix}",
    ]);
}

function apiCredential(string $suffix): ApiTokenCredential
{
    return new ApiTokenCredential("synthetic-3097-api-{$suffix}");
}

function oauthCredential(string $suffix): OAuthTokenSetCredential
{
    return new OAuthTokenSetCredential(
        "synthetic-3097-access-{$suffix}",
        "synthetic-3097-refresh-{$suffix}",
        new DateTimeImmutable('2039-01-02T03:04:05+00:00'),
        ['mail.read', 'profile.synthetic'],
    );
}

it('stores a typed OAuth token set only as a Laravel-encrypted nested payload', function (): void {
    $account = credentialAccount('oauth-at-rest');
    $input = oauthCredential('oauth-at-rest');
    $stored = app(MailAccountConnection::class)->store($account, $input);
    $database = DB::table('mail_account_credentials')->where('id', $stored->id)->first();
    assert($database !== null);
    $ciphertext = $database->encrypted_payload;
    assert(is_string($ciphertext));

    expect($database->credential_type)->toBe(CredentialType::OAuthTokenSet->value)
        ->and($database->schema_version)->toBe(1)
        ->and($database->status)->toBe(ConnectionStatus::Ready->value)
        ->and($database->version)->toBe(1)
        ->and($ciphertext)->not->toContain('synthetic-3097-access-oauth-at-rest')
        ->and($ciphertext)->not->toContain('synthetic-3097-refresh-oauth-at-rest');

    $decrypted = decrypt($ciphertext, false);
    assert(is_string($decrypted));
    $decryptedPayload = json_decode($decrypted, true, 16, JSON_THROW_ON_ERROR);
    expect($decryptedPayload)->toBe([
        'tokens' => [
            'access' => 'synthetic-3097-access-oauth-at-rest',
            'refresh' => 'synthetic-3097-refresh-oauth-at-rest',
        ],
        'expires_at' => '2039-01-02T03:04:05+00:00',
        'scopes' => ['mail.read', 'profile.synthetic'],
    ]);

    $read = app(MailAccountConnection::class)->credentials($account, $stored);
    expect($read)->toBeInstanceOf(OAuthTokenSetCredential::class);
    assert($read instanceof OAuthTokenSetCredential);
    expect($read->accessToken())->toBe('synthetic-3097-access-oauth-at-rest')
        ->and($read->refreshToken())->toBe('synthetic-3097-refresh-oauth-at-rest')
        ->and($read->expiresAt()?->format(DATE_ATOM))->toBe('2039-01-02T03:04:05+00:00')
        ->and($read->scopes())->toBe(['mail.read', 'profile.synthetic']);
});

it('stores and restores the closed API token representation', function (): void {
    $account = credentialAccount('api-type');
    $stored = app(MailAccountConnection::class)->store($account, apiCredential('api-type'));
    $read = app(MailAccountConnection::class)->credentials($account, $stored);

    expect($stored->credential_type)->toBe(CredentialType::ApiToken)
        ->and($stored->schema_version)->toBe(1)
        ->and($read)->toBeInstanceOf(ApiTokenCredential::class);
    assert($read instanceof ApiTokenCredential);
    expect($read->token())->toBe('synthetic-3097-api-api-type');
});

it('hides encrypted and decrypted secrets from ordinary serialization and debugging', function (): void {
    $account = credentialAccount('serialization');
    $stored = app(MailAccountConnection::class)->store($account, oauthCredential('serialization'));
    $ciphertext = $stored->getRawOriginal('encrypted_payload');
    assert(is_string($ciphertext));
    $account->load(['credential', 'credentialHistory']);
    $modelRepresentations = json_encode([
        'account' => $account->toArray(),
        'credential' => $stored->toArray(),
        'history' => $stored->history()->get()->toArray(),
    ], JSON_THROW_ON_ERROR);

    expect($stored->toArray())->not->toHaveKey('encrypted_payload')
        ->and($modelRepresentations)->not->toContain($ciphertext)
        ->and($modelRepresentations)->not->toContain('synthetic-3097-access-serialization')
        ->and($modelRepresentations)->not->toContain('synthetic-3097-refresh-serialization');

    $read = app(MailAccountConnection::class)->credentials($account, $stored);
    assert($read instanceof OAuthTokenSetCredential);
    expect(json_encode($read, JSON_THROW_ON_ERROR))->toBe('{}')
        ->and($read->__debugInfo())->toBe([])
        ->and(fn () => serialize($read))->toThrow(ConnectionCredentialException::class, 'cannot be serialized');
});

it('records store rotate disable reconnect and revoke without credential material', function (): void {
    Http::preventStrayRequests();
    $cacheSpy = Cache::spy();
    $account = credentialAccount('lifecycle');
    $connection = app(MailAccountConnection::class);
    $stored = $connection->store($account, apiCredential('lifecycle-initial'));
    $stored = $connection->rotate($account, $stored, oauthCredential('lifecycle-rotated'), 1);
    $stored = $connection->disable($account, $stored, 2);

    expect($stored->status)->toBe(ConnectionStatus::Disabled)
        ->and($stored->disabled_at)->not->toBeNull()
        ->and(fn () => $connection->credentials($account, $stored))
        ->toThrow(ConnectionCredentialException::class, 'unavailable');

    $stored = $connection->reconnect($account, $stored, apiCredential('lifecycle-reconnected'), 3);
    $stored = $connection->disable($account, $stored, 4);
    $stored = $connection->revoke($account, $stored, 5);
    $revokedPayload = DB::table('mail_account_credentials')->where('id', $stored->id)->value('encrypted_payload');
    assert(is_string($revokedPayload));
    $history = MailAccountCredentialHistory::query()->forAccount($account)->orderBy('version')->get();
    $historyJson = $history->toJson();

    expect($stored->status)->toBe(ConnectionStatus::Revoked)
        ->and($stored->version)->toBe(6)
        ->and($stored->revoked_at)->not->toBeNull()
        ->and(decrypt($revokedPayload, false))->toBe('{"revoked":true}')
        ->and($history->pluck('transition')->all())->toBe([
            ConnectionTransition::Stored,
            ConnectionTransition::Rotated,
            ConnectionTransition::Disabled,
            ConnectionTransition::Reconnected,
            ConnectionTransition::Disabled,
            ConnectionTransition::Revoked,
        ])
        ->and($history->pluck('from_status')->all())->toBe([
            null,
            ConnectionStatus::Ready,
            ConnectionStatus::Ready,
            ConnectionStatus::Disabled,
            ConnectionStatus::Ready,
            ConnectionStatus::Disabled,
        ])
        ->and($historyJson)->not->toContain('synthetic-3097-')
        ->and(Http::recorded())->toHaveCount(0);

    $cacheSpy->shouldNotHaveReceived('get');
    $cacheSpy->shouldNotHaveReceived('put');
    $cacheSpy->shouldNotHaveReceived('remember');
    $cacheSpy->shouldNotHaveReceived('forever');
});

it('fails closed for stale versions and invalid lifecycle transitions', function (): void {
    $account = credentialAccount('stale');
    $connection = app(MailAccountConnection::class);
    $stored = $connection->store($account, apiCredential('stale'));
    $staleCopy = $stored->replicate()->setRawAttributes($stored->getAttributes(), true);
    $staleCopy->setAttribute($staleCopy->getKeyName(), $stored->getKey());
    $current = $connection->rotate($account, $stored, apiCredential('current'), 1);

    expect(fn () => $connection->disable($account, $staleCopy, 1))
        ->toThrow(ConnectionCredentialException::class, 'stale or mismatched')
        ->and(fn () => $connection->reconnect($account, $current, apiCredential('invalid-reconnect'), 2))
        ->toThrow(ConnectionCredentialException::class, 'not allowed')
        ->and($current->refresh()->status)->toBe(ConnectionStatus::Ready)
        ->and($current->version)->toBe(2)
        ->and(MailAccountCredentialHistory::query()->count())->toBe(2);
});

it('denies cross-account and cross-owner credential reads and mutations', function (): void {
    $firstOwner = CredentialSyntheticOwner::query()->create(['name' => 'First synthetic credential owner']);
    $secondOwner = CredentialSyntheticOwner::query()->create(['name' => 'Second synthetic credential owner']);
    $first = credentialAccount('cross-owner-first', $firstOwner);
    $second = credentialAccount('cross-owner-second', $secondOwner);
    $connection = app(MailAccountConnection::class);
    $firstStored = $connection->store($first, oauthCredential('cross-owner'));
    $secondStored = $connection->store($second, apiCredential('cross-owner-second'));

    expect(fn () => $connection->credentials($second, $firstStored))
        ->toThrow(ConnectionCredentialException::class)
        ->and(fn () => $connection->rotate($second, $firstStored, apiCredential('cross-owner-rotate'), 1))
        ->toThrow(ConnectionCredentialException::class, 'stale or mismatched')
        ->and(fn () => $connection->disable($first, $secondStored, 1))
        ->toThrow(ConnectionCredentialException::class, 'stale or mismatched');

    $forgedOwner = $first->replicate()->setRawAttributes($first->getAttributes(), true);
    $forgedOwner->setAttribute($forgedOwner->getKeyName(), $first->getKey());
    $forgedOwner->owner_id = (string) $secondOwner->id;

    expect(fn () => $connection->credentials($forgedOwner, $firstStored))
        ->toThrow(ConnectionCredentialException::class)
        ->and(fn () => $connection->revoke($forgedOwner, $firstStored, 1))
        ->toThrow(ConnectionCredentialException::class, 'identity does not match')
        ->and($firstStored->refresh()->status)->toBe(ConnectionStatus::Ready)
        ->and($secondStored->refresh()->status)->toBe(ConnectionStatus::Ready);
});

it('protects credential identity payload state and history from unsafe model updates', function (): void {
    $first = credentialAccount('unsafe-first');
    $second = credentialAccount('unsafe-second');
    $stored = app(MailAccountConnection::class)->store($first, apiCredential('unsafe'));
    $history = $stored->history()->firstOrFail();

    expect(fn () => MailAccountCredential::query()->create([
        'mail_account_id' => $first->id,
        'credential_type' => CredentialType::ApiToken,
    ]))->toThrow(MassAssignmentException::class);

    $stored->encrypted_payload = 'synthetic-3097-unsafe-plaintext';
    expect(fn () => $stored->save())->toThrow(LogicException::class, 'connection lifecycle');

    expect(fn () => MailAccountCredential::query()->whereKey($stored->id)->update([
        'encrypted_payload' => 'synthetic-3097-unsafe-builder-plaintext',
    ]))->toThrow(LogicException::class, 'connection lifecycle');

    $history->to_status = ConnectionStatus::Revoked;
    expect(fn () => $history->save())->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $history->delete())->toThrow(LogicException::class, 'immutable')
        ->and(fn () => DB::table('mail_account_credentials')->where('id', $stored->id)->update([
            'mail_account_id' => $second->id,
        ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('mail_accounts')->where('id', $first->id)->update([
            'provider_account_id' => 'credential-account-unsafe-changed',
        ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('mail_account_credential_history')->where('id', $history->id)->delete())
        ->toThrow(QueryException::class);
});

it('redacts nested secrets from logs and exception chains on corrupt persistence failures', function (): void {
    $logger = Log::spy();
    $accessSecret = 'synthetic-3097-access-redaction';
    $refreshSecret = 'synthetic-3097-refresh-redaction';
    $account = credentialAccount('redaction');
    $connection = app(MailAccountConnection::class);
    $stored = $connection->store($account, new OAuthTokenSetCredential($accessSecret, $refreshSecret));
    DB::table('mail_account_credentials')->where('id', $stored->id)->update([
        'encrypted_payload' => "corrupt-{$accessSecret}-{$refreshSecret}",
    ]);

    try {
        $connection->credentials($account, $stored);
        throw new RuntimeException('Expected credential decryption to fail.');
    } catch (ConnectionCredentialException $exception) {
        expect($exception->getMessage())->toBe('Credentials could not be decrypted.')
            ->and($exception->getMessage())->not->toContain($accessSecret)
            ->and($exception->getMessage())->not->toContain($refreshSecret)
            ->and($exception->getPrevious())->toBeNull();
    }

    $logger->shouldNotHaveReceived('debug');
    $logger->shouldNotHaveReceived('info');
    $logger->shouldNotHaveReceived('notice');
    $logger->shouldNotHaveReceived('warning');
    $logger->shouldNotHaveReceived('error');
    $logger->shouldNotHaveReceived('critical');
    $logger->shouldNotHaveReceived('alert');
    $logger->shouldNotHaveReceived('emergency');
});

it('redacts structured decrypted payloads from representation failure traces', function (): void {
    $accessSecret = 'synthetic-3097-access-invalid-representation';
    $refreshSecret = 'synthetic-3097-refresh-invalid-representation';
    $account = credentialAccount('invalid-representation');
    $connection = app(MailAccountConnection::class);
    $stored = $connection->store($account, oauthCredential('invalid-representation'));
    DB::table('mail_account_credentials')->where('id', $stored->id)->update([
        'encrypted_payload' => encrypt(json_encode([
            'tokens' => ['access' => $accessSecret, 'refresh' => $refreshSecret],
            'expires_at' => null,
            'scopes' => ['valid-scope', ['nested-secret' => $refreshSecret]],
        ], JSON_THROW_ON_ERROR), false),
    ]);
    $previousExceptionArgumentSetting = ini_set('zend.exception_ignore_args', '0');

    try {
        $connection->credentials($account, $stored);
        throw new RuntimeException('Expected credential representation decoding to fail.');
    } catch (ConnectionCredentialException $exception) {
        $decodeFrame = collect($exception->getTrace())
            ->first(fn (array $frame): bool => $frame['function'] === 'decodeOAuthTokenSet');
        assert(is_array($decodeFrame));

        expect($exception->getMessage())->toBe('The stored credential representation is invalid.')
            ->and($exception->getPrevious())->toBeNull()
            ->and($decodeFrame['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class)
            ->and($exception->getMessage())->not->toContain($accessSecret)
            ->and($exception->getMessage())->not->toContain($refreshSecret);
    } finally {
        if (is_string($previousExceptionArgumentSetting)) {
            ini_set('zend.exception_ignore_args', $previousExceptionArgumentSetting);
        }
    }
});

it('uses PostgreSQL row locks for account-qualified lifecycle transitions', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        throw new SkippedWithMessageException(
            'Set MAIL_MIRROR_TEST_POSTGRES=1 for PostgreSQL row-lock coverage.',
        );
    }

    $account = credentialAccount('postgres-lock');
    $stored = app(MailAccountConnection::class)->store($account, apiCredential('postgres-lock'));
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    app(MailAccountConnection::class)->disable($account, $stored, 1);

    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'mail_accounts') && str_contains($sql, 'for update')))
        ->not->toBeEmpty()
        ->and(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'mail_account_credentials') && str_contains($sql, 'for update')))
        ->not->toBeEmpty();

    config()->set('database.connections.credential_lock_contender', config('database.connections.testing'));
    $primary = DB::connection('testing');
    $contender = DB::connection('credential_lock_contender');
    $primary->beginTransaction();

    try {
        $primary->table('mail_accounts')->where('id', $account->id)->lockForUpdate()->first();
        $contender->beginTransaction();
        $contender->statement("SET LOCAL lock_timeout = '100ms'");

        expect(fn () => $contender->table('mail_accounts')
            ->where('id', $account->id)
            ->lockForUpdate()
            ->first())->toThrow(QueryException::class);
    } finally {
        if ($contender->transactionLevel() > 0) {
            $contender->rollBack();
        }

        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }

        DB::purge('credential_lock_contender');
    }
});
