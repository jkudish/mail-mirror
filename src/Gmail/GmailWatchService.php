<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Credentials\OAuthTokenSetCredential;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Exceptions\GmailAuthorizationException;
use Jkudish\MailMirror\Exceptions\ProviderNotificationException;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAccountCredential;
use Jkudish\MailMirror\Models\MailGmailWatch;
use Throwable;

final readonly class GmailWatchService
{
    private const WATCH_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/watch';

    public function __construct(
        private Factory $http,
        private MailAccountConnection $connections,
        private GmailOAuth $oauth,
    ) {}

    public function register(
        MailAccount $account,
        GmailWatchSetup $setup,
        ?GmailWatchIdentity $expectedCurrentIdentity = null,
    ): GmailWatchRegistration {
        $identity = $this->configuredIdentity();

        return $account->getConnection()->transaction(function () use ($account, $setup, $expectedCurrentIdentity, $identity): GmailWatchRegistration {
            $account = $this->lockAccount($account);
            $current = MailGmailWatch::query()->where('mail_account_id', $account->id)->lockForUpdate()->first();
            $this->assertSetup($setup, $current, $expectedCurrentIdentity, $identity);
            $payload = $this->watch($account, $identity);
            $historyId = $this->boundedDigits($payload['historyId'] ?? null, 255);
            $expiration = $this->boundedDigits($payload['expiration'] ?? null, 20);
            $expiresAt = (new DateTimeImmutable)->setTimestamp((int) floor(((int) $expiration) / 1000));

            if ($expiresAt <= new DateTimeImmutable || $expiresAt > new DateTimeImmutable('+8 days')) {
                throw new ProviderNotificationException('malformed_payload');
            }

            MailGmailWatch::query()->updateOrCreate(
                ['mail_account_id' => $account->id],
                [
                    'project_id' => $identity->projectId,
                    'topic' => $identity->topic,
                    'subscription' => $identity->subscription,
                    'history_id_hint' => $historyId,
                    'expires_at' => $expiresAt,
                ],
            );

            return new GmailWatchRegistration($account->id, $identity, $historyId, $expiresAt);
        }, 3);
    }

    private function assertSetup(
        GmailWatchSetup $setup,
        ?MailGmailWatch $current,
        ?GmailWatchIdentity $expected,
        GmailWatchIdentity $configured,
    ): void {
        if ($setup === GmailWatchSetup::Create && ($current !== null || $expected !== null)) {
            throw new ProviderNotificationException('setup_conflict');
        }

        if ($setup !== GmailWatchSetup::Create && ($current === null || $expected === null)) {
            throw new ProviderNotificationException('setup_conflict');
        }

        if ($current === null || $expected === null) {
            return;
        }

        $stored = new GmailWatchIdentity(
            $current->project_id,
            $this->resourceId($current->topic, 'topics'),
            $this->resourceId($current->subscription, 'subscriptions'),
        );

        if ($stored->topic !== $current->topic || $stored->subscription !== $current->subscription
            || ! $stored->equals($expected)
            || ($setup === GmailWatchSetup::Renew && ! $stored->equals($configured))
            || ($setup === GmailWatchSetup::Replace && $stored->equals($configured))) {
            throw new ProviderNotificationException('setup_conflict');
        }
    }

    /** @return array<string, mixed> */
    private function watch(MailAccount $account, GmailWatchIdentity $identity): array
    {
        [$credential, $stored] = $this->credential($account);
        $response = $this->send($credential, $identity);

        if ($response->status() === 401) {
            $credential = $this->refresh($account, $stored, $credential);
            $response = $this->send($credential, $identity);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ProviderNotificationException('malformed_payload');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function send(OAuthTokenSetCredential $credential, GmailWatchIdentity $identity): Response
    {
        try {
            return $this->http->withToken($credential->accessToken())->acceptJson()
                ->withoutRedirecting()->timeout($this->timeout())
                ->post(self::WATCH_URL, ['topicName' => $identity->topic]);
        } catch (Throwable) {
            throw new ProviderNotificationException('provider_unavailable', true);
        }
    }

    /** @return array{OAuthTokenSetCredential, MailAccountCredential} */
    private function credential(MailAccount $account): array
    {
        $stored = $account->credential()->first();

        try {
            $credential = $stored instanceof MailAccountCredential
                ? $this->connections->credentials($account, $stored)
                : null;
        } catch (Throwable) {
            $credential = null;
        }

        if (! $stored instanceof MailAccountCredential || ! $credential instanceof OAuthTokenSetCredential
            || $credential->scopes() !== [GmailOAuth::SCOPE]) {
            throw new ProviderNotificationException('authentication_failed');
        }

        if ($credential->expiresAt() !== null && $credential->expiresAt() <= new DateTimeImmutable('+30 seconds')) {
            $credential = $this->refresh($account, $stored, $credential);
        }

        return [$credential, $stored];
    }

    private function refresh(MailAccount $account, MailAccountCredential $stored, OAuthTokenSetCredential $credential): OAuthTokenSetCredential
    {
        try {
            return $this->oauth->refresh($account, $stored, $credential);
        } catch (GmailAuthorizationException $exception) {
            throw new ProviderNotificationException(
                $exception->grantInvalid ? 'authentication_failed' : 'provider_unavailable',
                ! $exception->grantInvalid,
            );
        }
    }

    private function lockAccount(MailAccount $account): MailAccount
    {
        $matched = MailAccount::query()->whereKey($account->id)
            ->where('driver', MailDriver::Gmail->value)
            ->where('provider_account_id', $account->provider_account_id)
            ->where('owner_type', $account->owner_type)
            ->where('owner_id', $account->owner_id)
            ->lockForUpdate()->first();

        return $matched ?? throw new ProviderNotificationException('resource_mismatch');
    }

    private function configuredIdentity(): GmailWatchIdentity
    {
        if (config('mail-mirror.gmail.enabled') !== true || config('mail-mirror.gmail.pubsub.enabled') !== true) {
            throw new ProviderNotificationException('configuration_invalid');
        }

        try {
            return GmailWatchIdentity::fromConfiguration();
        } catch (Throwable) {
            throw new ProviderNotificationException('configuration_invalid');
        }
    }

    private function resourceId(string $resource, string $kind): string
    {
        $prefix = '/'.$kind.'/';
        $position = strpos($resource, $prefix);

        return $position === false ? '' : substr($resource, $position + strlen($prefix));
    }

    private function boundedDigits(mixed $value, int $maximum): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > $maximum || ! ctype_digit($value)) {
            throw new ProviderNotificationException('malformed_payload');
        }

        return $value;
    }

    private function timeout(): int
    {
        $value = config('mail-mirror.gmail.timeout_seconds', 30);

        return is_int($value) && $value >= 1 && $value <= 120 ? $value : 30;
    }

    private function failure(Response $response): ProviderNotificationException
    {
        return match ($response->status()) {
            401 => new ProviderNotificationException('authentication_failed'),
            400, 403, 404 => new ProviderNotificationException('resource_mismatch'),
            408, 425, 429, 500, 502, 503, 504 => new ProviderNotificationException('provider_unavailable', true),
            default => new ProviderNotificationException('provider_unavailable'),
        };
    }
}
