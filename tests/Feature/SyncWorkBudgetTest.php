<?php

declare(strict_types=1);

use Jkudish\MailMirror\Exceptions\SyncBudgetExhausted;
use Jkudish\MailMirror\Read\SyncWorkBudget;

it('exposes the finite default allowance as a content-safe scalar snapshot', function (): void {
    $snapshot = (new SyncWorkBudget)->snapshot();

    expect($snapshot)->toMatchArray([
        'http_requests' => 0,
        'max_http_requests' => 200,
        'listed_ids' => 0,
        'max_listed_ids' => 2000,
        'fetched_messages' => 0,
        'max_fetched_messages' => 100,
        'downloaded_bytes' => 0,
        'max_downloaded_bytes' => 104857600,
        'max_elapsed_milliseconds' => 900000,
    ]);
});

it('rejects work before crossing a pre-request allowance', function (): void {
    $budget = new SyncWorkBudget(maxHttpRequests: 0);

    try {
        $budget->claimHttpRequest();
        throw new RuntimeException('The zero-request allowance was not enforced.');
    } catch (SyncBudgetExhausted $failure) {
        expect(SyncBudgetExhausted::SAFE_CODE)->toBe('budget_exhausted')
            ->and($failure->dimension)->toBe('http_requests')
            ->and($failure->snapshot['http_requests'])->toBe(0);
    }
});

it('enforces elapsed time before more provider work starts', function (): void {
    $budget = new SyncWorkBudget(maxElapsedSeconds: 0);

    try {
        $budget->claimFetchedMessage();
        throw new RuntimeException('The zero-time allowance was not enforced.');
    } catch (SyncBudgetExhausted $failure) {
        expect($failure->dimension)->toBe('elapsed_time')
            ->and($failure->snapshot['fetched_messages'])->toBe(0)
            ->and($failure->snapshot['max_elapsed_milliseconds'])->toBe(0);
    }
});

it('refuses another network attempt when the listed-ID allowance is exactly consumed', function (): void {
    $budget = new SyncWorkBudget(maxListedIds: 1);
    $budget->recordListedIds(1);

    try {
        $budget->claimHttpRequest();
        throw new RuntimeException('A fully consumed response allowance admitted another network request.');
    } catch (SyncBudgetExhausted $failure) {
        expect($failure->dimension)->toBe('listed_ids')
            ->and($failure->snapshot['http_requests'])->toBe(0);
    }
});

it('refuses another network attempt when the downloaded-byte allowance is exactly consumed', function (): void {
    $budget = new SyncWorkBudget(maxDownloadedBytes: 1);
    $budget->recordDownloadedBytes(1);

    try {
        $budget->claimHttpRequest();
        throw new RuntimeException('A fully consumed response allowance admitted another network request.');
    } catch (SyncBudgetExhausted $failure) {
        expect($failure->dimension)->toBe('downloaded_bytes')
            ->and($failure->snapshot['http_requests'])->toBe(0);
    }
});

it('refuses another network attempt when the fetched-message allowance is exactly consumed', function (): void {
    $budget = new SyncWorkBudget(maxFetchedMessages: 1);
    $budget->claimFetchedMessage();
    $budget->finishFetchedMessage();

    try {
        $budget->claimHttpRequest();
        throw new RuntimeException('A fully consumed response allowance admitted another network request.');
    } catch (SyncBudgetExhausted $failure) {
        expect($failure->dimension)->toBe('fetched_messages')
            ->and($failure->snapshot['http_requests'])->toBe(0);
    }
});

it('keeps other network dimensions enforced during an admitted final message fetch', function (): void {
    $budget = new SyncWorkBudget(maxFetchedMessages: 1, maxDownloadedBytes: 0);
    $budget->claimFetchedMessage();

    try {
        $budget->claimHttpRequest();
        throw new RuntimeException('An admitted fetch bypassed its downloaded-byte allowance.');
    } catch (SyncBudgetExhausted $failure) {
        expect($failure->dimension)->toBe('downloaded_bytes')
            ->and($failure->snapshot['fetched_messages'])->toBe(1)
            ->and($failure->snapshot['http_requests'])->toBe(0);
    } finally {
        $budget->finishFetchedMessage();
    }
});
