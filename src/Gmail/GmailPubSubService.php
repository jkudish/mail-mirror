<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Gmail;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Jkudish\MailMirror\Contracts\PubSubAccessTokenProvider;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Exceptions\ProviderNotificationException;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailGmailWatch;
use Throwable;

final readonly class GmailPubSubService
{
    private const API = 'https://pubsub.googleapis.com/v1/';

    public function __construct(
        private Factory $http,
        private PubSubAccessTokenProvider $tokens,
    ) {}

    /** @param list<MailAccount> $accounts */
    public function pull(array $accounts, int $maximumMessages = 10): GmailPullBatch
    {
        if ($maximumMessages < 1 || $maximumMessages > $this->maximumBatchSize()) {
            throw new ProviderNotificationException('configuration_invalid');
        }

        $identity = $this->configuredIdentity();
        $accountsByEmail = $this->validatedAccounts($accounts);
        $response = $this->request($identity, 'pull', ['maxMessages' => $maximumMessages]);
        $received = $response['receivedMessages'] ?? [];

        if (! is_array($received) || count($received) > $maximumMessages) {
            throw new ProviderNotificationException('malformed_payload');
        }

        $notifications = [];

        foreach ($received as $envelope) {
            $notifications[] = $this->notification($envelope, $identity, $accountsByEmail);
        }

        return new GmailPullBatch($notifications);
    }

    /** @param list<GmailPulledNotification> $notifications */
    public function acknowledge(array $notifications): void
    {
        if ($notifications === [] || count($notifications) > $this->maximumBatchSize()) {
            throw new ProviderNotificationException('resource_mismatch');
        }

        $identity = $this->configuredIdentity();
        $ackIds = [];

        foreach ($notifications as $notification) {
            if ($notification->subscription() !== $identity->subscription) {
                throw new ProviderNotificationException('resource_mismatch');
            }

            $ackIds[] = $notification->ackId();
        }

        $this->request($identity, 'acknowledge', ['ackIds' => array_values(array_unique($ackIds))]);
    }

    /**
     * @param  array<string, MailAccount>  $accountsByEmail
     */
    private function notification(mixed $envelope, GmailWatchIdentity $identity, array $accountsByEmail): GmailPulledNotification
    {
        $message = is_array($envelope) ? ($envelope['message'] ?? null) : null;
        $ackId = is_array($envelope) ? ($envelope['ackId'] ?? null) : null;
        $messageId = is_array($message) ? ($message['messageId'] ?? null) : null;
        $publishedAt = is_array($message) ? ($message['publishTime'] ?? null) : null;
        $encoded = is_array($message) ? ($message['data'] ?? null) : null;

        if (! is_string($ackId) || $ackId === '' || strlen($ackId) > 4096
            || ! is_string($messageId) || $messageId === '' || strlen($messageId) > 1024
            || ! is_string($publishedAt) || strlen($publishedAt) > 64
            || ! is_string($encoded) || strlen($encoded) > 16384) {
            throw new ProviderNotificationException('malformed_payload');
        }

        try {
            $time = new DateTimeImmutable($publishedAt);
        } catch (Throwable) {
            throw new ProviderNotificationException('malformed_payload');
        }

        $decoded = base64_decode($encoded, true);
        $payload = is_string($decoded) && strlen($decoded) <= 8192
            ? json_decode($decoded, true, 8)
            : null;
        $email = is_array($payload) ? ($payload['emailAddress'] ?? null) : null;
        $historyId = is_array($payload) ? ($payload['historyId'] ?? null) : null;
        $account = is_string($email) ? ($accountsByEmail[strtolower($email)] ?? null) : null;
        $accepted = $account instanceof MailAccount
            && is_string($historyId) && $historyId !== '' && strlen($historyId) <= 255 && ctype_digit($historyId);

        return new GmailPulledNotification(
            $account instanceof MailAccount ? $account->id : 0,
            $identity->subscription,
            $ackId,
            $messageId,
            $time,
            $accepted ? $historyId : null,
            $accepted ? null : 'malformed_or_misrouted',
        );
    }

    /**
     * @param  list<MailAccount>  $accounts
     * @return array<string, MailAccount>
     */
    private function validatedAccounts(array $accounts): array
    {
        if ($accounts === [] || count($accounts) > 500) {
            throw new ProviderNotificationException('resource_mismatch');
        }

        $map = [];
        $identity = $this->configuredIdentity();

        foreach ($accounts as $account) {
            if ($account->driver !== MailDriver::Gmail
                || $account->provider_account_id === '' || strlen($account->provider_account_id) > 320
                || ! MailAccount::query()->whereKey($account->id)->where('driver', MailDriver::Gmail->value)
                    ->where('provider_account_id', $account->provider_account_id)
                    ->where('owner_type', $account->owner_type)->where('owner_id', $account->owner_id)->exists()
                || ! MailGmailWatch::query()->where('mail_account_id', $account->id)
                    ->where('project_id', $identity->projectId)->where('topic', $identity->topic)
                    ->where('subscription', $identity->subscription)->exists()) {
                throw new ProviderNotificationException('resource_mismatch');
            }

            $key = strtolower($account->provider_account_id);

            if (isset($map[$key])) {
                throw new ProviderNotificationException('resource_mismatch');
            }

            $map[$key] = $account;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(GmailWatchIdentity $identity, string $operation, array $body): array
    {
        try {
            $response = $this->http->withToken($this->tokens->accessToken())->acceptJson()
                ->timeout($this->timeout())
                ->post(self::API.$identity->subscription.':'.$operation, $body);
        } catch (ProviderNotificationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ProviderNotificationException('provider_unavailable', true);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        if ($response->body() === '') {
            return [];
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ProviderNotificationException('malformed_payload');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function configuredIdentity(): GmailWatchIdentity
    {
        if (config('mail-mirror.gmail.pubsub.enabled') !== true) {
            throw new ProviderNotificationException('configuration_invalid');
        }

        try {
            return GmailWatchIdentity::fromConfiguration();
        } catch (Throwable) {
            throw new ProviderNotificationException('configuration_invalid');
        }
    }

    private function maximumBatchSize(): int
    {
        $value = config('mail-mirror.gmail.pubsub.max_messages', 20);

        return is_int($value) && $value >= 1 && $value <= 100 ? $value : 20;
    }

    private function timeout(): int
    {
        $value = config('mail-mirror.gmail.pubsub.timeout_seconds', 10);

        return is_int($value) && $value >= 1 && $value <= 60 ? $value : 10;
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
