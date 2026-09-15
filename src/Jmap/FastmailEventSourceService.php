<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Jmap;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Exceptions\ProviderNotificationException;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAccountCredential;
use Jkudish\MailMirror\Models\MailJmapEventSource;
use Throwable;

final readonly class FastmailEventSourceService
{
    private const SESSION_URL = 'https://api.fastmail.com/jmap/session';

    private const TYPES = ['Email', 'EmailDelivery', 'Mailbox'];

    public function __construct(
        private Factory $http,
        private MailAccountConnection $connections,
    ) {}

    public function receive(MailAccount $account): FastmailEventBatch
    {
        $account = $this->matchedAccount($account);
        $credential = $this->credential($account);
        $session = $this->request($credential, self::SESSION_URL, true);
        $template = $session['eventSourceUrl'] ?? null;
        $accounts = $session['accounts'] ?? null;

        if (! is_string($template) || strlen($template) > 2048 || ! is_array($accounts)
            || ! is_array($accounts[$account->provider_account_id] ?? null)) {
            throw new ProviderNotificationException('malformed_payload');
        }

        [$url, $origin] = $this->eventSourceUrl($template);
        $state = MailJmapEventSource::query()->where('mail_account_id', $account->id)->first();

        if ($state !== null && $state->event_source_origin !== $origin) {
            throw new ProviderNotificationException('resource_mismatch');
        }

        $response = $this->streamRequest($credential, $url, $state?->last_event_id);
        $batch = $this->parse($account, $this->readBounded($response));

        MailJmapEventSource::query()->firstOrCreate(
            ['mail_account_id' => $account->id],
            ['event_source_origin' => $origin, 'last_event_id' => null],
        );

        return $batch;
    }

    public function advanceLastEventId(
        MailAccount $account,
        ?string $expectedLastEventId,
        FastmailEventBatch $batch,
    ): void {
        $account = $this->matchedAccount($account);
        $next = $batch->lastEventId;

        if ($next === null || ! $this->validEventId($next)
            || ($expectedLastEventId !== null && ! $this->validEventId($expectedLastEventId))) {
            throw new ProviderNotificationException('resource_mismatch');
        }

        $query = MailJmapEventSource::query()->where('mail_account_id', $account->id);
        $expectedLastEventId === null
            ? $query->whereNull('last_event_id')
            : $query->where('last_event_id', $expectedLastEventId);

        if ($query->update(['last_event_id' => $next, 'updated_at' => now()]) !== 1) {
            throw new ProviderNotificationException('resource_mismatch');
        }
    }

    /** @return array<string, mixed> */
    private function request(ApiTokenCredential $credential, string $url, bool $json): array
    {
        try {
            $response = $this->http->withToken($credential->token())->acceptJson()
                ->withOptions(['allow_redirects' => false])->timeout($this->timeout())->get($url);
        } catch (Throwable) {
            throw new ProviderNotificationException('provider_unavailable', true);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $payload = $json ? $response->json() : null;

        if (! is_array($payload)) {
            throw new ProviderNotificationException('malformed_payload');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function streamRequest(ApiTokenCredential $credential, string $url, ?string $lastEventId): Response
    {
        if ($lastEventId !== null && ! $this->validEventId($lastEventId)) {
            throw new ProviderNotificationException('resource_mismatch');
        }

        try {
            $request = $this->http->withToken($credential->token())
                ->withHeaders(['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache'])
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->timeout($this->timeout());

            if ($lastEventId !== null) {
                $request = $request->withHeaders(['Last-Event-ID' => $lastEventId]);
            }

            $response = $request->get($url);
        } catch (Throwable) {
            throw new ProviderNotificationException('provider_unavailable', true);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $contentType = strtolower($response->header('Content-Type'));

        if (! str_starts_with($contentType, 'text/event-stream')) {
            throw new ProviderNotificationException('malformed_payload');
        }

        return $response;
    }

    /** @return array{string, string} */
    private function eventSourceUrl(string $template): array
    {
        foreach (['types', 'closeafter', 'ping'] as $variable) {
            if (substr_count($template, '{'.$variable.'}') !== 1) {
                throw new ProviderNotificationException('malformed_payload');
            }
        }

        $withoutExpected = str_replace(['{types}', '{closeafter}', '{ping}'], '', $template);

        if (str_contains($withoutExpected, '{') || str_contains($withoutExpected, '}')) {
            throw new ProviderNotificationException('malformed_payload');
        }

        $parts = parse_url($template);
        $query = is_array($parts) ? ($parts['query'] ?? null) : null;

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'api.fastmail.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['port'])
            || ! is_string($query)) {
            throw new ProviderNotificationException('resource_mismatch');
        }

        $parameters = explode('&', $query);
        sort($parameters);

        if ($parameters !== ['closeafter={closeafter}', 'ping={ping}', 'types={types}']) {
            throw new ProviderNotificationException('resource_mismatch');
        }

        $url = str_replace(
            ['{types}', '{closeafter}', '{ping}'],
            [rawurlencode(implode(',', self::TYPES)), 'state', (string) $this->pingSeconds()],
            $template,
        );

        return [$url, 'https://api.fastmail.com'];
    }

    private function readBounded(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $maximum = $this->maximumStreamBytes();
        $body = '';

        while (! $stream->eof()) {
            $remaining = $maximum - strlen($body);

            if ($remaining < 1) {
                throw new ProviderNotificationException('malformed_payload');
            }

            $body .= $stream->read(min(8192, $remaining + 1));

            if (strlen($body) > $maximum) {
                throw new ProviderNotificationException('malformed_payload');
            }
        }

        return $body;
    }

    private function parse(MailAccount $account, string $body): FastmailEventBatch
    {
        if (strlen($body) > $this->maximumStreamBytes() || str_contains($body, "\0")) {
            throw new ProviderNotificationException('malformed_payload');
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $blocks = preg_split('/\n\n+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($blocks) || count($blocks) > $this->maximumEvents()) {
            throw new ProviderNotificationException('malformed_payload');
        }

        $changes = [];
        $lastEventId = null;
        $pingInterval = null;

        foreach ($blocks as $block) {
            [$event, $data, $eventId] = $this->event($block);

            if ($eventId !== null) {
                $lastEventId = $eventId;
            }

            if ($event === 'ping') {
                if ($eventId !== null) {
                    throw new ProviderNotificationException('malformed_payload');
                }

                $payload = json_decode($data, true, 4);
                $interval = is_array($payload) ? ($payload['interval'] ?? null) : null;

                if (! is_int($interval) || $interval < 1 || $interval > 3600) {
                    throw new ProviderNotificationException('malformed_payload');
                }

                $pingInterval = $interval;

                continue;
            }

            if ($event !== 'state') {
                continue;
            }

            $payload = json_decode($data, true, 8);
            if (! is_array($payload)) {
                throw new ProviderNotificationException('malformed_payload');
            }

            $changed = $payload['changed'] ?? null;
            $accountChanges = is_array($changed) ? ($changed[$account->provider_account_id] ?? null) : null;

            if (($payload['@type'] ?? null) !== 'StateChange' || ! is_array($changed)) {
                throw new ProviderNotificationException('malformed_payload');
            }

            if ($accountChanges === null) {
                continue;
            }

            if (! is_array($accountChanges)) {
                throw new ProviderNotificationException('resource_mismatch');
            }

            $safeChanges = [];

            foreach ($accountChanges as $type => $state) {
                if (! in_array($type, self::TYPES, true) || ! is_string($state)
                    || $state === '' || strlen($state) > 255) {
                    throw new ProviderNotificationException('malformed_payload');
                }

                $safeChanges[$type] = $state;
            }

            if ($safeChanges === []) {
                throw new ProviderNotificationException('malformed_payload');
            }

            $changes[] = new FastmailEventHint($eventId ?? $lastEventId, $safeChanges);
        }

        return new FastmailEventBatch($changes, $lastEventId, $pingInterval);
    }

    /** @return array{string, string, string|null} */
    private function event(string $block): array
    {
        $event = 'message';
        $data = [];
        $eventId = null;

        foreach (explode("\n", $block) as $line) {
            if (strlen($line) > $this->maximumLineBytes()) {
                throw new ProviderNotificationException('malformed_payload');
            }

            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;

            if ($field === 'event') {
                $event = $value;
            } elseif ($field === 'data') {
                $data[] = $value;
            } elseif ($field === 'id') {
                if (! $this->validEventId($value)) {
                    throw new ProviderNotificationException('malformed_payload');
                }

                $eventId = $value;
            }
        }

        $joined = implode("\n", $data);

        if (strlen($joined) > $this->maximumEventBytes()) {
            throw new ProviderNotificationException('malformed_payload');
        }

        return [$event, $joined, $eventId];
    }

    private function credential(MailAccount $account): ApiTokenCredential
    {
        $stored = $account->credential()->first();

        try {
            $credential = $stored instanceof MailAccountCredential
                ? $this->connections->credentials($account, $stored)
                : null;
        } catch (Throwable) {
            $credential = null;
        }

        return $credential instanceof ApiTokenCredential
            ? $credential
            : throw new ProviderNotificationException('authentication_failed');
    }

    private function matchedAccount(MailAccount $account): MailAccount
    {
        if (config('mail-mirror.jmap.enabled') !== true || config('mail-mirror.jmap.event_source.enabled') !== true) {
            throw new ProviderNotificationException('configuration_invalid');
        }

        $matched = MailAccount::query()->whereKey($account->id)
            ->where('driver', MailDriver::Jmap->value)
            ->where('provider_account_id', $account->provider_account_id)
            ->where('owner_type', $account->owner_type)->where('owner_id', $account->owner_id)->first();

        return $matched ?? throw new ProviderNotificationException('resource_mismatch');
    }

    private function validEventId(string $value): bool
    {
        return $value !== '' && strlen($value) <= 1024
            && ! str_contains($value, "\0") && ! str_contains($value, "\r") && ! str_contains($value, "\n");
    }

    private function timeout(): int
    {
        $value = config('mail-mirror.jmap.event_source.timeout_seconds', 35);

        return is_int($value) && $value >= 1 && $value <= 120 ? $value : 35;
    }

    private function pingSeconds(): int
    {
        $value = config('mail-mirror.jmap.event_source.ping_seconds', 30);

        return is_int($value) && $value >= 1 && $value <= 300 ? $value : 30;
    }

    private function maximumStreamBytes(): int
    {
        $value = config('mail-mirror.jmap.event_source.max_stream_bytes', 262144);

        return is_int($value) && $value >= 1024 && $value <= 1048576 ? $value : 262144;
    }

    private function maximumEventBytes(): int
    {
        $value = config('mail-mirror.jmap.event_source.max_event_bytes', 32768);

        return is_int($value) && $value >= 256 && $value <= 131072 ? $value : 32768;
    }

    private function maximumLineBytes(): int
    {
        return min(16384, $this->maximumEventBytes());
    }

    private function maximumEvents(): int
    {
        $value = config('mail-mirror.jmap.event_source.max_events', 20);

        return is_int($value) && $value >= 1 && $value <= 100 ? $value : 20;
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
