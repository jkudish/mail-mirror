<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailAddress;
use Jkudish\MailMirror\Models\MailAttachment;
use Jkudish\MailMirror\Models\MailContainer;
use Jkudish\MailMirror\Models\MailIdentity;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailMessageContainerMembership;
use Jkudish\MailMirror\Models\MailMessageHeader;
use Jkudish\MailMirror\Models\MailMessageParticipant;
use Jkudish\MailMirror\Models\MailRawObject;
use Jkudish\MailMirror\Models\MailSyncCheckpoint;
use Jkudish\MailMirror\Models\MailThread;

/** @property int $id */
final class SyntheticOwner extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('synthetic_owners', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Relation::enforceMorphMap(['synthetic-owner' => SyntheticOwner::class]);
});

function account(string $providerId, MailDriver $driver = MailDriver::Gmail): MailAccount
{
    return MailAccount::query()->create([
        'driver' => $driver,
        'provider_account_id' => $providerId,
        'provider_metadata' => ['synthetic' => true],
    ]);
}

it('persists the minimal account-rooted record graph and provider metadata', function (): void {
    $account = account('account-synthetic-001');
    $identity = MailIdentity::query()->create([
        'mail_account_id' => $account->id,
        'provider_identity_id' => 'identity-synthetic-001',
        'email_address' => 'mirror@invented.test',
        'provider_metadata' => ['gmail_profile' => 'synthetic'],
    ]);
    $thread = MailThread::query()->create([
        'mail_account_id' => $account->id,
        'provider_thread_id' => 'thread-synthetic-001',
        'subject' => 'Synthetic thread',
    ]);
    $message = MailMessage::query()->create([
        'mail_account_id' => $account->id,
        'mail_thread_id' => $thread->id,
        'provider_message_id' => 'message-synthetic-001',
        'internet_message_id' => '<synthetic-001@invented.test>',
        'provider_metadata' => ['historyId' => 'synthetic-history-1'],
    ]);
    $address = MailAddress::query()->create([
        'mail_account_id' => $account->id,
        'provider_address_id' => 'address-synthetic-001',
        'address' => 'sender@invented.test',
    ]);
    $participant = MailMessageParticipant::query()->create([
        'mail_account_id' => $account->id,
        'mail_message_id' => $message->id,
        'mail_address_id' => $address->id,
        'mail_identity_id' => $identity->id,
        'role' => 'from',
    ]);
    $header = MailMessageHeader::query()->create([
        'mail_account_id' => $account->id,
        'mail_message_id' => $message->id,
        'name' => 'X-Synthetic',
        'value' => 'true',
    ]);
    $container = MailContainer::query()->create([
        'mail_account_id' => $account->id,
        'provider_container_id' => 'container-synthetic-001',
        'name' => 'Synthetic inbox',
        'provider_metadata' => ['native_role' => 'inbox'],
    ]);
    $membership = MailMessageContainerMembership::query()->create([
        'mail_account_id' => $account->id,
        'mail_message_id' => $message->id,
        'mail_container_id' => $container->id,
        'provider_membership_id' => 'membership-synthetic-001',
    ]);
    $attachment = MailAttachment::query()->create([
        'mail_account_id' => $account->id,
        'mail_message_id' => $message->id,
        'provider_attachment_id' => 'attachment-synthetic-001',
        'filename' => 'synthetic.txt',
        'byte_size' => 12,
        'provider_metadata' => ['blobId' => 'synthetic-blob-1'],
    ]);
    $raw = MailRawObject::query()->create([
        'mail_account_id' => $account->id,
        'mail_message_id' => $message->id,
        'provider_object_id' => 'raw-synthetic-001',
        'kind' => 'rfc822',
        'byte_size' => 128,
        'checksum' => 'synthetic-checksum-not-content',
    ]);
    $checkpoint = MailSyncCheckpoint::query()->create([
        'mail_account_id' => $account->id,
        'scan_id' => '10000000-0000-4000-8000-000000000001',
        'provider_cursor' => 'synthetic-cursor-001',
        'scan_started_at' => now(),
    ]);

    expect($account->refresh()->driver)->toBe(MailDriver::Gmail)
        ->and($identity->refresh()->provider_metadata)->toBe(['gmail_profile' => 'synthetic'])
        ->and($message->refresh()->provider_metadata)->toBe(['historyId' => 'synthetic-history-1'])
        ->and($container->refresh()->provider_metadata)->toBe(['native_role' => 'inbox'])
        ->and($attachment->refresh()->provider_metadata)->toBe(['blobId' => 'synthetic-blob-1'])
        ->and([$participant->exists, $header->exists, $membership->exists, $raw->exists, $checkpoint->exists])
        ->each->toBeTrue();

    $account->delete();

    expect([
        MailAccount::query()->count(),
        MailIdentity::query()->count(),
        MailThread::query()->count(),
        MailMessage::query()->count(),
        MailAddress::query()->count(),
        MailMessageParticipant::query()->count(),
        MailMessageHeader::query()->count(),
        MailContainer::query()->count(),
        MailMessageContainerMembership::query()->count(),
        MailAttachment::query()->count(),
        MailRawObject::query()->count(),
        MailSyncCheckpoint::query()->count(),
    ])->each->toBe(0);
});

it('scopes owners by both morph values and excludes ownerless accounts', function (): void {
    $firstOwner = SyntheticOwner::query()->create(['name' => 'First synthetic owner']);
    $secondOwner = SyntheticOwner::query()->create(['name' => 'Second synthetic owner']);
    $first = MailAccount::query()->create([
        'owner_type' => 'synthetic-owner',
        'owner_id' => (string) $firstOwner->id,
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'shared-provider-account',
    ]);
    MailAccount::query()->create([
        'owner_type' => 'synthetic-owner',
        'owner_id' => (string) $secondOwner->id,
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'shared-provider-account',
    ]);
    account('ownerless-provider-account');

    expect(MailAccount::query()->ownedBy('synthetic-owner', $firstOwner->id)->pluck('id')->all())
        ->toBe([$first->id])
        ->and($first->owner()->getResults()?->is($firstOwner))->toBeTrue()
        ->and(MailAccount::query()->ownedBy('different-owner-type', $firstOwner->id)->exists())->toBeFalse();
});

it('rejects invalid owner tuples and changes to attached owners or provider identity', function (): void {
    expect(fn () => MailAccount::query()->create([
        'owner_type' => 'synthetic-owner',
        'owner_id' => null,
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'invalid-owner-path',
    ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DB::table('mail_accounts')->insert([
            'owner_type' => 'synthetic-owner',
            'owner_id' => null,
            'driver' => MailDriver::Gmail->value,
            'provider_account_id' => 'invalid-owner-path-through-query-builder',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

    $owner = SyntheticOwner::query()->create(['name' => 'Synthetic owner']);
    $account = MailAccount::query()->create([
        'owner_type' => 'synthetic-owner',
        'owner_id' => (string) $owner->id,
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'immutable-provider-account',
    ]);

    expect(fn () => DB::table('mail_accounts')->where('id', $account->id)->update([
        'owner_type' => null,
    ]))->toThrow(QueryException::class);

    $account->owner_id = '999';
    expect(fn () => $account->save())->toThrow(LogicException::class);

    $account->refresh()->provider_account_id = 'changed';
    expect(fn () => $account->save())->toThrow(LogicException::class);
});

it('qualifies provider message identifiers by account without treating RFC Message-ID as identity', function (): void {
    $first = account('first-account');
    $second = account('second-account');

    foreach ([$first, $second] as $account) {
        MailIdentity::query()->create(['mail_account_id' => $account->id, 'provider_identity_id' => 'shared-identity']);
        MailThread::query()->create(['mail_account_id' => $account->id, 'provider_thread_id' => 'shared-thread']);
        $message = MailMessage::query()->create([
            'mail_account_id' => $account->id,
            'provider_message_id' => 'shared-message',
            'internet_message_id' => '<shared@invented.test>',
        ]);
        MailContainer::query()->create(['mail_account_id' => $account->id, 'provider_container_id' => 'shared-container', 'name' => 'Synthetic']);
        MailAttachment::query()->create(['mail_account_id' => $account->id, 'mail_message_id' => $message->id, 'provider_attachment_id' => 'shared-attachment']);
        MailRawObject::query()->create(['mail_account_id' => $account->id, 'mail_message_id' => $message->id, 'provider_object_id' => 'shared-raw', 'kind' => 'rfc822']);
    }

    expect(MailMessage::query()->where('internet_message_id', '<shared@invented.test>')->count())->toBe(2)
        ->and(MailMessage::query()->forAccount($first)->count())->toBe(1)
        ->and(fn () => MailMessage::query()->create([
            'mail_account_id' => $first->id,
            'provider_message_id' => 'shared-message',
            'internet_message_id' => '<different@invented.test>',
        ]))->toThrow(QueryException::class);
});

it('qualifies attachment and raw object identifiers by provider message', function (): void {
    $account = account('reused-child-provider-identifiers');

    foreach (['first', 'second'] as $position) {
        $message = MailMessage::query()->create([
            'mail_account_id' => $account->id,
            'provider_message_id' => $position.'-message',
        ]);

        MailAttachment::query()->create([
            'mail_account_id' => $account->id,
            'mail_message_id' => $message->id,
            'provider_attachment_id' => 'reused-attachment-id',
        ]);
        MailRawObject::query()->create([
            'mail_account_id' => $account->id,
            'mail_message_id' => $message->id,
            'provider_object_id' => 'reused-raw-id',
            'kind' => 'rfc822',
        ]);
    }

    expect(MailAttachment::query()->count())->toBe(2)
        ->and(MailRawObject::query()->count())->toBe(2)
        ->and(fn () => MailRawObject::query()->create([
            'mail_account_id' => $account->id,
            'provider_object_id' => 'account-level-raw-id',
            'kind' => 'rfc822',
        ]))->toThrow(QueryException::class)
        ->and(fn () => MailAttachment::query()->create([
            'mail_account_id' => $account->id,
            'mail_message_id' => $message->id,
            'provider_attachment_id' => 'reused-attachment-id',
        ]))->toThrow(QueryException::class)
        ->and(fn () => MailRawObject::query()->create([
            'mail_account_id' => $account->id,
            'mail_message_id' => $message->id,
            'provider_object_id' => 'reused-raw-id',
            'kind' => 'rfc822',
        ]))->toThrow(QueryException::class);
});

it('rejects every mismatched account and account-scoped parent tuple', function (): void {
    $first = account('first-parent-account');
    $second = account('second-parent-account');
    $firstThread = MailThread::query()->create(['mail_account_id' => $first->id, 'provider_thread_id' => 'first-thread']);
    $firstMessage = MailMessage::query()->create(['mail_account_id' => $first->id, 'provider_message_id' => 'first-message']);
    $firstAddress = MailAddress::query()->create(['mail_account_id' => $first->id, 'address' => 'first@invented.test']);
    $secondAddress = MailAddress::query()->create(['mail_account_id' => $second->id, 'address' => 'second@invented.test']);
    $secondIdentity = MailIdentity::query()->create(['mail_account_id' => $second->id, 'provider_identity_id' => 'second-identity']);
    $secondContainer = MailContainer::query()->create(['mail_account_id' => $second->id, 'provider_container_id' => 'second-container', 'name' => 'Second']);
    $invalidCreates = [
        fn () => MailMessage::query()->create(['mail_account_id' => $second->id, 'mail_thread_id' => $firstThread->id, 'provider_message_id' => 'cross-thread']),
        fn () => MailMessageParticipant::query()->create(['mail_account_id' => $first->id, 'mail_message_id' => $firstMessage->id, 'mail_address_id' => $secondAddress->id, 'role' => 'from']),
        fn () => MailMessageParticipant::query()->create(['mail_account_id' => $first->id, 'mail_message_id' => $firstMessage->id, 'mail_address_id' => $firstAddress->id, 'mail_identity_id' => $secondIdentity->id, 'role' => 'to']),
        fn () => MailMessageHeader::query()->create(['mail_account_id' => $second->id, 'mail_message_id' => $firstMessage->id, 'name' => 'X-Cross', 'value' => 'invalid']),
        fn () => MailMessageContainerMembership::query()->create(['mail_account_id' => $first->id, 'mail_message_id' => $firstMessage->id, 'mail_container_id' => $secondContainer->id]),
        fn () => MailAttachment::query()->create(['mail_account_id' => $second->id, 'mail_message_id' => $firstMessage->id, 'provider_attachment_id' => 'cross-attachment']),
        fn () => MailRawObject::query()->create(['mail_account_id' => $second->id, 'mail_message_id' => $firstMessage->id, 'provider_object_id' => 'cross-raw', 'kind' => 'rfc822']),
    ];

    foreach ($invalidCreates as $invalidCreate) {
        expect($invalidCreate)->toThrow(QueryException::class);
    }
});

it('keeps account paths immutable while allowing same-account thread state changes', function (): void {
    $first = account('first-immutable-account');
    $second = account('second-immutable-account');
    $thread = MailThread::query()->create(['mail_account_id' => $first->id, 'provider_thread_id' => 'immutable-thread']);
    $message = MailMessage::query()->create([
        'mail_account_id' => $first->id,
        'mail_thread_id' => $thread->id,
        'provider_message_id' => 'immutable-message',
    ]);

    $thread->mail_account_id = $second->id;
    expect(fn () => $thread->save())->toThrow(LogicException::class);

    $message->mail_thread_id = null;
    $message->save();
    $secondThread = MailThread::query()->create([
        'mail_account_id' => $second->id,
        'provider_thread_id' => 'cross-account-thread',
    ]);
    $message->mail_thread_id = $secondThread->id;

    expect(fn () => $message->save())->toThrow(QueryException::class);
});

it('fails closed for unknown drivers', function (): void {
    expect(fn () => DB::table('mail_accounts')->insert([
        'driver' => 'imap',
        'provider_account_id' => 'unknown-driver-account',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

});
