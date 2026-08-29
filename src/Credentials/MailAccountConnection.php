<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Credentials;

use DateTimeImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Jkudish\MailMirror\Enums\ConnectionStatus;
use Jkudish\MailMirror\Enums\ConnectionTransition;
use Jkudish\MailMirror\Enums\CredentialType;
use Jkudish\MailMirror\Exceptions\ConnectionCredentialException;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAccountCredential;
use SensitiveParameter;
use Throwable;

final readonly class MailAccountConnection
{
    public function __construct(private Encrypter $encrypter) {}

    public function store(
        MailAccount $account,
        #[SensitiveParameter] ApiTokenCredential|OAuthTokenSetCredential $credential,
    ): MailAccountCredential {
        $encryptedPayload = $this->encrypt($credential);

        return $this->persist(function () use ($account, $credential, $encryptedPayload): MailAccountCredential {
            $connection = $account->getConnection();

            return $connection->transaction(function () use ($account, $credential, $encryptedPayload, $connection): MailAccountCredential {
                $account = $this->lockMatchedAccount($account);

                if (MailAccountCredential::query()->forAccount($account)->exists()) {
                    throw new ConnectionCredentialException('The account already has credentials.');
                }

                $now = Carbon::now();
                $id = $connection->table('mail_account_credentials')->insertGetId([
                    'mail_account_id' => $account->id,
                    'credential_type' => $credential->type()->value,
                    'schema_version' => $credential->schemaVersion(),
                    'encrypted_payload' => $encryptedPayload,
                    'status' => ConnectionStatus::Ready->value,
                    'version' => 1,
                    'last_activated_at' => $now,
                    'disabled_at' => null,
                    'revoked_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $stored = MailAccountCredential::query()->forAccount($account)->findOrFail($id);
                $this->recordTransition($stored, ConnectionTransition::Stored, null, $now);

                return $stored;
            }, 3);
        });
    }

    public function credentials(
        MailAccount $account,
        MailAccountCredential $stored,
    ): ApiTokenCredential|OAuthTokenSetCredential {
        try {
            $matchedAccount = $this->matchedAccountQuery($account)->first();
            $matched = $matchedAccount === null ? null : MailAccountCredential::query()
                ->forAccount($matchedAccount)
                ->whereKey($stored->getKey())
                ->where('status', ConnectionStatus::Ready->value)
                ->first();

            if ($matched === null) {
                throw new ConnectionCredentialException('Credentials are unavailable for this account.');
            }

            return $this->decrypt($matched);
        } catch (ConnectionCredentialException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ConnectionCredentialException('Credentials could not be read.');
        }
    }

    public function rotate(
        MailAccount $account,
        MailAccountCredential $stored,
        #[SensitiveParameter] ApiTokenCredential|OAuthTokenSetCredential $replacement,
        int $expectedVersion,
    ): MailAccountCredential {
        $encryptedPayload = $this->encrypt($replacement);

        return $this->transition(
            $account,
            $stored,
            $expectedVersion,
            [ConnectionStatus::Ready],
            ConnectionStatus::Ready,
            ConnectionTransition::Rotated,
            $replacement,
            $encryptedPayload,
        );
    }

    public function disable(
        MailAccount $account,
        MailAccountCredential $stored,
        int $expectedVersion,
    ): MailAccountCredential {
        return $this->transition(
            $account,
            $stored,
            $expectedVersion,
            [ConnectionStatus::Ready],
            ConnectionStatus::Disabled,
            ConnectionTransition::Disabled,
        );
    }

    public function revoke(
        MailAccount $account,
        MailAccountCredential $stored,
        int $expectedVersion,
    ): MailAccountCredential {
        $revokedPayload = $this->encryptRevokedMarker();

        return $this->transition(
            $account,
            $stored,
            $expectedVersion,
            [ConnectionStatus::Ready, ConnectionStatus::Disabled],
            ConnectionStatus::Revoked,
            ConnectionTransition::Revoked,
            encryptedPayload: $revokedPayload,
        );
    }

    public function reconnect(
        MailAccount $account,
        MailAccountCredential $stored,
        #[SensitiveParameter] ApiTokenCredential|OAuthTokenSetCredential $replacement,
        int $expectedVersion,
    ): MailAccountCredential {
        $encryptedPayload = $this->encrypt($replacement);

        return $this->transition(
            $account,
            $stored,
            $expectedVersion,
            [ConnectionStatus::Disabled, ConnectionStatus::Revoked],
            ConnectionStatus::Ready,
            ConnectionTransition::Reconnected,
            $replacement,
            $encryptedPayload,
        );
    }

    /**
     * @param  non-empty-list<ConnectionStatus>  $allowedFrom
     */
    private function transition(
        MailAccount $account,
        MailAccountCredential $stored,
        int $expectedVersion,
        array $allowedFrom,
        ConnectionStatus $to,
        ConnectionTransition $transition,
        #[SensitiveParameter] ApiTokenCredential|OAuthTokenSetCredential|null $replacement = null,
        ?string $encryptedPayload = null,
    ): MailAccountCredential {
        return $this->persist(function () use (
            $account,
            $stored,
            $expectedVersion,
            $allowedFrom,
            $to,
            $transition,
            $replacement,
            $encryptedPayload,
        ): MailAccountCredential {
            return $account->getConnection()->transaction(function () use (
                $account,
                $stored,
                $expectedVersion,
                $allowedFrom,
                $to,
                $transition,
                $replacement,
                $encryptedPayload,
            ): MailAccountCredential {
                $account = $this->lockMatchedAccount($account);
                $current = MailAccountCredential::query()
                    ->forAccount($account)
                    ->whereKey($stored->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($current === null || $current->version !== $expectedVersion) {
                    throw new ConnectionCredentialException('The credential lifecycle version is stale or mismatched.');
                }

                if (! in_array($current->status, $allowedFrom, true)) {
                    throw new ConnectionCredentialException('The requested credential lifecycle transition is not allowed.');
                }

                $from = $current->status;
                $now = Carbon::now();
                $attributes = [
                    'status' => $to->value,
                    'version' => $expectedVersion + 1,
                    'updated_at' => $now,
                ];

                if ($encryptedPayload !== null) {
                    $attributes['encrypted_payload'] = $encryptedPayload;
                }

                if ($replacement !== null) {
                    $attributes += [
                        'credential_type' => $replacement->type()->value,
                        'schema_version' => $replacement->schemaVersion(),
                    ];
                }

                if ($to === ConnectionStatus::Ready) {
                    $attributes += ['last_activated_at' => $now, 'disabled_at' => null, 'revoked_at' => null];
                } elseif ($to === ConnectionStatus::Disabled) {
                    $attributes['disabled_at'] = $now;
                } else {
                    $attributes['revoked_at'] = $now;
                }

                $updated = $account->getConnection()->table('mail_account_credentials')
                    ->where('mail_account_id', $account->id)
                    ->where('id', $current->id)
                    ->where('version', $expectedVersion)
                    ->update($attributes);

                if ($updated !== 1) {
                    throw new ConnectionCredentialException('The credential lifecycle version is stale or mismatched.');
                }

                $current->refresh();
                $this->recordTransition($current, $transition, $from, $now);

                return $current;
            }, 3);
        });
    }

    private function lockMatchedAccount(MailAccount $account): MailAccount
    {
        $matched = $this->matchedAccountQuery($account)->lockForUpdate()->first();

        if ($matched === null) {
            throw new ConnectionCredentialException('The account identity does not match.');
        }

        return $matched;
    }

    /** @return Builder<MailAccount> */
    private function matchedAccountQuery(MailAccount $account): Builder
    {
        $query = $account->newQuery()
            ->whereKey($account->getKey())
            ->where('driver', $account->driver->value)
            ->where('provider_account_id', $account->provider_account_id);

        foreach (['owner_type', 'owner_id'] as $ownerAttribute) {
            $value = $account->getAttribute($ownerAttribute);
            $value === null ? $query->whereNull($ownerAttribute) : $query->where($ownerAttribute, $value);
        }

        return $query;
    }

    private function recordTransition(
        MailAccountCredential $credential,
        ConnectionTransition $transition,
        ?ConnectionStatus $from,
        Carbon $occurredAt,
    ): void {
        $credential->getConnection()->table('mail_account_credential_history')->insert([
            'mail_account_id' => $credential->mail_account_id,
            'mail_account_credential_id' => $credential->id,
            'version' => $credential->version,
            'transition' => $transition->value,
            'credential_type' => $credential->credential_type->value,
            'schema_version' => $credential->schema_version,
            'from_status' => $from?->value,
            'to_status' => $credential->status->value,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function encrypt(
        #[SensitiveParameter] ApiTokenCredential|OAuthTokenSetCredential $credential,
    ): string {
        try {
            $payload = match (true) {
                $credential instanceof ApiTokenCredential => [
                    'token' => ['value' => $credential->token()],
                ],
                $credential instanceof OAuthTokenSetCredential => [
                    'tokens' => [
                        'access' => $credential->accessToken(),
                        'refresh' => $credential->refreshToken(),
                    ],
                    'expires_at' => $credential->expiresAt()?->format(DATE_ATOM),
                    'scopes' => $credential->scopes(),
                ],
            };

            $encrypted = $this->encrypter->encrypt(json_encode($payload, JSON_THROW_ON_ERROR), false);

            return $encrypted;
        } catch (Throwable) {
            throw new ConnectionCredentialException('Credentials could not be encrypted.');
        }
    }

    private function encryptRevokedMarker(): string
    {
        try {
            $encrypted = $this->encrypter->encrypt('{"revoked":true}', false);

            return $encrypted;
        } catch (Throwable) {
            throw new ConnectionCredentialException('Credentials could not be encrypted.');
        }
    }

    private function decrypt(MailAccountCredential $stored): ApiTokenCredential|OAuthTokenSetCredential
    {
        try {
            $decrypted = $this->encrypter->decrypt($stored->encrypted_payload, false);

            if (! is_string($decrypted)) {
                throw new ConnectionCredentialException('The stored credential representation is invalid.');
            }

            $payload = json_decode($decrypted, true, 16, JSON_THROW_ON_ERROR);

            if (! is_array($payload) || $stored->schema_version !== 1) {
                throw new ConnectionCredentialException('The stored credential representation is unsupported.');
            }

            return match ($stored->credential_type) {
                CredentialType::ApiToken => $this->decodeApiToken($payload),
                CredentialType::OAuthTokenSet => $this->decodeOAuthTokenSet($payload),
            };
        } catch (ConnectionCredentialException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ConnectionCredentialException('Credentials could not be decrypted.');
        }
    }

    /** @param array<mixed> $payload */
    private function decodeApiToken(#[SensitiveParameter] array $payload): ApiTokenCredential
    {
        $tokenContainer = $payload['token'] ?? null;
        $token = is_array($tokenContainer) ? ($tokenContainer['value'] ?? null) : null;

        if (! is_string($token)) {
            throw new ConnectionCredentialException('The stored credential representation is invalid.');
        }

        return new ApiTokenCredential($token);
    }

    /** @param array<mixed> $payload */
    private function decodeOAuthTokenSet(#[SensitiveParameter] array $payload): OAuthTokenSetCredential
    {
        $tokens = $payload['tokens'] ?? null;
        $accessToken = is_array($tokens) ? ($tokens['access'] ?? null) : null;
        $refreshToken = is_array($tokens) ? ($tokens['refresh'] ?? null) : null;
        $expiresAt = $payload['expires_at'] ?? null;
        $scopes = $payload['scopes'] ?? null;

        if (
            ! is_string($accessToken)
            || (! is_string($refreshToken) && $refreshToken !== null)
            || (! is_string($expiresAt) && $expiresAt !== null)
            || ! is_array($scopes)
            || ! array_is_list($scopes)
            || array_filter($scopes, fn (mixed $scope): bool => ! is_string($scope)) !== []
        ) {
            throw new ConnectionCredentialException('The stored credential representation is invalid.');
        }

        /** @var list<string> $scopes */
        return new OAuthTokenSetCredential(
            $accessToken,
            $refreshToken,
            $expiresAt === null ? null : new DateTimeImmutable($expiresAt),
            $scopes,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function persist(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ConnectionCredentialException $exception) {
            throw $exception;
        } catch (QueryException) {
            throw new ConnectionCredentialException('The credential lifecycle operation could not be persisted.');
        } catch (Throwable) {
            throw new ConnectionCredentialException('The credential lifecycle operation failed.');
        }
    }
}
