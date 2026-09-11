<?php

declare(strict_types=1);

use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailContainer;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailMessageContainerMembership;
use Jkudish\MailMirror\Read\MailMessageStateReader;

it('normalizes equivalent Gmail and JMAP state while preserving bounded native facts', function (): void {
    $gmailAccount = MailAccount::query()->create([
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'gmail-state-account',
    ]);
    $gmailMessage = MailMessage::query()->create([
        'mail_account_id' => $gmailAccount->id,
        'provider_message_id' => 'gmail-state-message',
        'provider_metadata' => [
            'mailbox_state' => [
                'unread' => true,
                'flagged' => true,
                'draft' => true,
                'sent' => false,
                'spam' => false,
                'trash' => false,
            ],
        ],
    ]);
    $gmailContainer = MailContainer::query()->create([
        'mail_account_id' => $gmailAccount->id,
        'provider_container_id' => 'DRAFT',
        'name' => 'Drafts',
        'kind' => 'system',
    ]);
    MailMessageContainerMembership::query()->create([
        'mail_account_id' => $gmailAccount->id,
        'mail_message_id' => $gmailMessage->id,
        'mail_container_id' => $gmailContainer->id,
        'provider_membership_id' => 'gmail-state-message:DRAFT',
    ]);

    $jmapAccount = MailAccount::query()->create([
        'driver' => MailDriver::Jmap,
        'provider_account_id' => 'jmap-state-account',
    ]);
    $jmapMessage = MailMessage::query()->create([
        'mail_account_id' => $jmapAccount->id,
        'provider_message_id' => 'jmap-state-message',
        'provider_metadata' => [
            'keywords' => ['$draft' => true, '$flagged' => true],
            'mailbox_state' => [
                'unread' => true,
                'flagged' => true,
                'draft' => true,
                'sent' => false,
                'spam' => false,
                'trash' => false,
            ],
        ],
    ]);
    $jmapContainer = MailContainer::query()->create([
        'mail_account_id' => $jmapAccount->id,
        'provider_container_id' => 'jmap-drafts',
        'name' => 'Drafts',
        'kind' => 'drafts',
    ]);
    MailMessageContainerMembership::query()->create([
        'mail_account_id' => $jmapAccount->id,
        'mail_message_id' => $jmapMessage->id,
        'mail_container_id' => $jmapContainer->id,
    ]);

    $reader = app(MailMessageStateReader::class);
    $gmail = $reader->read($gmailAccount, $gmailMessage);
    $jmap = $reader->read($jmapAccount, $jmapMessage);

    expect($gmail->flags())->toBe([
        'unread' => true,
        'flagged' => true,
        'draft' => true,
        'sent' => false,
        'spam' => false,
        'trash' => false,
    ])->and($jmap->flags())->toBe($gmail->flags())
        ->and($gmail->keywords)->toBe([])
        ->and($jmap->keywords)->toBe(['$draft', '$flagged'])
        ->and($gmail->containers)->toBe([[
            'id' => $gmailContainer->id,
            'provider_id' => 'DRAFT',
            'name' => 'Drafts',
            'kind' => 'system',
        ]])
        ->and($jmap->containers)->toBe([[
            'id' => $jmapContainer->id,
            'provider_id' => 'jmap-drafts',
            'name' => 'Drafts',
            'kind' => 'drafts',
        ]]);

    expect(fn () => $reader->read($gmailAccount, $jmapMessage))
        ->toThrow(InvalidArgumentException::class, 'does not belong');
});

it('rejects malformed normalized provider state instead of inventing defaults', function (): void {
    $account = MailAccount::query()->create([
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'malformed-state-account',
    ]);
    $message = MailMessage::query()->create([
        'mail_account_id' => $account->id,
        'provider_message_id' => 'malformed-state-message',
        'provider_metadata' => [
            'mailbox_state' => ['unread' => 'yes'],
        ],
    ]);

    expect(fn () => app(MailMessageStateReader::class)->read($account, $message))
        ->toThrow(UnexpectedValueException::class, 'normalized mailbox state');
});

it('derives legacy JMAP unread state from an explicitly empty keyword map', function (): void {
    $account = MailAccount::query()->create([
        'driver' => MailDriver::Jmap,
        'provider_account_id' => 'legacy-jmap-state-account',
    ]);
    $message = MailMessage::query()->create([
        'mail_account_id' => $account->id,
        'provider_message_id' => 'legacy-jmap-state-message',
        'provider_metadata' => ['keywords' => []],
    ]);

    expect(app(MailMessageStateReader::class)->read($account, $message)->flags())->toBe([
        'unread' => true,
        'flagged' => false,
        'draft' => false,
        'sent' => false,
        'spam' => false,
        'trash' => false,
    ]);
});

it('derives the flag added after legacy Gmail state from its durable container', function (): void {
    $account = MailAccount::query()->create([
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'legacy-gmail-state-account',
    ]);
    $message = MailMessage::query()->create([
        'mail_account_id' => $account->id,
        'provider_message_id' => 'legacy-gmail-state-message',
        'provider_metadata' => ['mailbox_state' => [
            'unread' => false,
            'draft' => false,
            'sent' => false,
            'spam' => false,
            'trash' => false,
        ]],
    ]);
    $starred = MailContainer::query()->create([
        'mail_account_id' => $account->id,
        'provider_container_id' => 'STARRED',
        'name' => 'Starred',
        'kind' => 'system',
    ]);
    MailMessageContainerMembership::query()->create([
        'mail_account_id' => $account->id,
        'mail_message_id' => $message->id,
        'mail_container_id' => $starred->id,
        'provider_membership_id' => 'legacy-gmail-state-message:STARRED',
    ]);

    expect(app(MailMessageStateReader::class)->read($account, $message)->flags())->toBe([
        'unread' => false,
        'flagged' => true,
        'draft' => false,
        'sent' => false,
        'spam' => false,
        'trash' => false,
    ]);
});
