<?php

declare(strict_types=1);

namespace Jkudish\MailMirror\Read;

use InvalidArgumentException;
use Jkudish\MailMirror\Exceptions\SyncBudgetExhausted;
use LogicException;

final class SyncWorkBudget
{
    private int $httpRequests = 0;

    private int $listedIds = 0;

    private int $fetchedMessages = 0;

    private int $activeFetchedMessages = 0;

    private int $downloadedBytes = 0;

    private ?string $exhaustedDimension = null;

    private readonly int $startedAtNanoseconds;

    public function __construct(
        private readonly int $maxHttpRequests = 200,
        private readonly int $maxListedIds = 2000,
        private readonly int $maxFetchedMessages = 100,
        private readonly int $maxDownloadedBytes = 104857600,
        private readonly int $maxElapsedSeconds = 900,
    ) {
        if ($maxHttpRequests < 0 || $maxListedIds < 0 || $maxFetchedMessages < 0
            || $maxDownloadedBytes < 0 || $maxElapsedSeconds < 0) {
            throw new InvalidArgumentException('Sync work budget limits must not be negative.');
        }

        $this->startedAtNanoseconds = hrtime(true);
    }

    public function claimHttpRequest(): void
    {
        $this->assertNetworkAvailable();

        if ($this->httpRequests >= $this->maxHttpRequests) {
            $this->exhausted('http_requests');
        }

        $this->httpRequests++;
    }

    public function claimFetchedMessage(): void
    {
        $this->assertElapsed();

        if ($this->fetchedMessages >= $this->maxFetchedMessages) {
            $this->exhausted('fetched_messages');
        }

        $this->fetchedMessages++;
        $this->activeFetchedMessages++;
    }

    public function finishFetchedMessage(): void
    {
        if ($this->activeFetchedMessages < 1) {
            throw new LogicException('No fetched message claim is active.');
        }

        $this->activeFetchedMessages--;
    }

    public function recordListedIds(int $count): void
    {
        if ($count < 0) {
            throw new InvalidArgumentException('A listed message count must not be negative.');
        }

        $this->listedIds += $count;

        if ($this->listedIds > $this->maxListedIds) {
            $this->exhausted('listed_ids');
        }

        $this->assertElapsed();
    }

    public function recordDownloadedBytes(int $bytes): void
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('A downloaded byte count must not be negative.');
        }

        $this->downloadedBytes += $bytes;

        if ($this->downloadedBytes > $this->maxDownloadedBytes) {
            $this->exhausted('downloaded_bytes');
        }

        $this->assertElapsed();
    }

    public function assertElapsed(): void
    {
        if ($this->exhaustedDimension !== null) {
            $this->exhausted($this->exhaustedDimension);
        }

        if ($this->elapsedMilliseconds() >= $this->maxElapsedSeconds * 1000) {
            $this->exhausted('elapsed_time');
        }
    }

    public function remainingListedIds(): int
    {
        return max(0, $this->maxListedIds - $this->listedIds);
    }

    public function remainingDownloadedBytes(): int
    {
        return max(0, $this->maxDownloadedBytes - $this->downloadedBytes);
    }

    public function remainingElapsedSeconds(): float
    {
        $this->assertElapsed();

        return max(0.001, ($this->maxElapsedSeconds * 1000 - $this->elapsedMilliseconds()) / 1000);
    }

    /**
     * @return array{
     *     http_requests: int,
     *     max_http_requests: int,
     *     listed_ids: int,
     *     max_listed_ids: int,
     *     fetched_messages: int,
     *     max_fetched_messages: int,
     *     downloaded_bytes: int,
     *     max_downloaded_bytes: int,
     *     elapsed_milliseconds: int,
     *     max_elapsed_milliseconds: int
     * }
     */
    public function snapshot(): array
    {
        return [
            'http_requests' => $this->httpRequests,
            'max_http_requests' => $this->maxHttpRequests,
            'listed_ids' => $this->listedIds,
            'max_listed_ids' => $this->maxListedIds,
            'fetched_messages' => $this->fetchedMessages,
            'max_fetched_messages' => $this->maxFetchedMessages,
            'downloaded_bytes' => $this->downloadedBytes,
            'max_downloaded_bytes' => $this->maxDownloadedBytes,
            'elapsed_milliseconds' => $this->elapsedMilliseconds(),
            'max_elapsed_milliseconds' => $this->maxElapsedSeconds * 1000,
        ];
    }

    private function elapsedMilliseconds(): int
    {
        return (int) floor((hrtime(true) - $this->startedAtNanoseconds) / 1_000_000);
    }

    private function assertNetworkAvailable(): void
    {
        $this->assertElapsed();

        foreach ([
            'listed_ids' => [$this->listedIds, $this->maxListedIds],
            'fetched_messages' => [
                $this->activeFetchedMessages === 0 ? $this->fetchedMessages : 0,
                $this->maxFetchedMessages,
            ],
            'downloaded_bytes' => [$this->downloadedBytes, $this->maxDownloadedBytes],
        ] as $dimension => [$used, $maximum]) {
            if ($used >= $maximum) {
                $this->exhausted($dimension);
            }
        }
    }

    private function exhausted(string $dimension): never
    {
        $this->exhaustedDimension = $dimension;

        throw new SyncBudgetExhausted($dimension, $this->snapshot());
    }
}
