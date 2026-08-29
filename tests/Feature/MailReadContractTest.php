<?php

declare(strict_types=1);

use Jkudish\MailMirror\Contracts\MailboxReader;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Read\InventoryPage;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MailReadService;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\ProviderDeletionEvidence;
use Jkudish\MailMirror\Read\RetrievedMessage;

/**
 * @phpstan-type Fixture array{
 *     provider_message_id: string,
 *     provider_thread_id: string,
 *     subject: string,
 *     headers: list<array{name: string, value: string}>,
 *     participants: list<array{role: string, address: string, name: string, provider_metadata?: array<string, mixed>}>,
 *     attachments: list<array{provider_id: string, filename: string, media_type: string, byte_size: int, provider_metadata: array<string, mixed>}>,
 *     containers: list<array{provider_id: string, name: string, provider_metadata: array<string, mixed>}>,
 *     inventory_metadata: array<string, mixed>,
 *     message_metadata: array<string, mixed>
 * }
 */
final class SyntheticFixtureReader implements MailboxReader
{
    /** @param Fixture $fixture */
    public function __construct(private readonly MailDriver $mailDriver, private readonly array $fixture) {}

    /** @return Fixture */
    public static function fixture(string $name): array
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/'.$name.'.json');
        $fixture = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

        assert(is_array($fixture));

        /** @var Fixture $fixture */
        return $fixture;
    }

    public function driver(): MailDriver
    {
        return $this->mailDriver;
    }

    public function inventoryPage(MailAccount $account, ?string $cursor): InventoryPage
    {
        return new InventoryPage([new MessageReference(
            mailAccountId: $account->id,
            driver: $this->mailDriver,
            providerMessageId: $this->fixture['provider_message_id'],
            providerThreadId: $this->fixture['provider_thread_id'],
            providerMetadata: $this->fixture['inventory_metadata'],
        )], null, true);
    }

    public function retrieve(MailAccount $account, MessageReference $message): RetrievedMessage
    {
        return new RetrievedMessage(
            reference: $message,
            subject: $this->fixture['subject'],
            headers: $this->fixture['headers'],
            participants: $this->fixture['participants'],
            attachments: $this->fixture['attachments'],
            containers: $this->fixture['containers'],
            providerMetadata: $this->fixture['message_metadata'],
        );
    }
}

it('uses enum-keyed Gmail and JMAP readers without erasing native semantics', function (MailDriver $driver, string $fixtureName, string $nativeKey): void {
    $account = MailAccount::query()->create([
        'driver' => $driver,
        'provider_account_id' => $fixtureName.'-account-synthetic',
    ]);
    $fixture = SyntheticFixtureReader::fixture($fixtureName);
    $registry = new MailDriverRegistry;
    $registry->register($driver, new SyntheticFixtureReader($driver, $fixture));
    $reads = new MailReadService($registry);

    $reference = $reads->inventoryPage($account)->messages[0];
    $message = $reads->retrieve($account, $reference);
    /** @var array{provider_metadata: array<string, mixed>} $attachment */
    $attachment = $message->attachments[0];
    /** @var array{provider_metadata: array<string, mixed>} $container */
    $container = $message->containers[0];

    expect($reference->driver)->toBe($driver)
        ->and($reference->providerMessageId)->toBe($fixture['provider_message_id'])
        ->and($reference->providerMetadata)->toBe($fixture['inventory_metadata'])
        ->and($message->providerMetadata)->toBe($fixture['message_metadata'])
        ->and($attachment['provider_metadata'])->toHaveKey($nativeKey)
        ->and($container['provider_metadata'])->toBe($fixture['containers'][0]['provider_metadata']);
})->with([
    'Gmail-shaped' => [MailDriver::Gmail, 'gmail', 'attachmentId'],
    'JMAP-shaped' => [MailDriver::Jmap, 'jmap', 'blobId'],
]);

it('rejects cross-account and cross-driver message references before adapter retrieval', function (): void {
    $first = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => 'owner-one',
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'gmail-account-one',
    ]);
    $second = MailAccount::query()->create([
        'owner_type' => 'synthetic-workspace',
        'owner_id' => 'owner-two',
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'gmail-account-two',
    ]);
    $registry = new MailDriverRegistry;
    $registry->register(MailDriver::Gmail, new SyntheticFixtureReader(MailDriver::Gmail, SyntheticFixtureReader::fixture('gmail')));
    $reads = new MailReadService($registry);
    $reference = $reads->inventoryPage($first)->messages[0];

    expect(fn () => $reads->retrieve($second, $reference))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reads->retrieve($first, new MessageReference($first->id, MailDriver::Jmap, 'id')))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects oversized pages before retrieval and contradictory completion cursors', function (): void {
    $account = MailAccount::query()->create([
        'driver' => MailDriver::Gmail,
        'provider_account_id' => 'oversized-page-account',
    ]);
    config()->set('mail-mirror.inventory_page_max_messages', 1);
    $reader = new class implements MailboxReader
    {
        public function driver(): MailDriver
        {
            return MailDriver::Gmail;
        }

        public function inventoryPage(MailAccount $account, ?string $cursor): InventoryPage
        {
            return new InventoryPage([
                new MessageReference($account->id, MailDriver::Gmail, 'first'),
                new MessageReference($account->id, MailDriver::Gmail, 'second'),
            ], null, true);
        }

        public function retrieve(MailAccount $account, MessageReference $message): RetrievedMessage
        {
            throw new RuntimeException('Retrieval must not be reached for an oversized page.');
        }
    };
    $registry = new MailDriverRegistry;
    $registry->register(MailDriver::Gmail, $reader);

    expect(fn () => (new MailReadService($registry))->inventoryPage($account))
        ->toThrow(InvalidArgumentException::class, 'exceeds the configured resource limit')
        ->and(fn () => new InventoryPage([], 'contradictory-cursor', true))
        ->toThrow(InvalidArgumentException::class, 'complete inventory page cannot include');
});

it('validates account-qualified opaque references and deletion evidence at construction', function (): void {
    expect(fn () => new MessageReference(0, MailDriver::Gmail, 'message'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MessageReference(1, MailDriver::Gmail, ''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MessageReference(1, MailDriver::Gmail, str_repeat('x', 256)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ProviderDeletionEvidence(0, 'message', 'provider_tombstone', 'opaque-audit'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ProviderDeletionEvidence(1, str_repeat('x', 256), 'provider_tombstone', 'opaque-audit'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ProviderDeletionEvidence(1, 'message', 'INVALID CODE', 'opaque-audit'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ProviderDeletionEvidence(1, 'message', 'provider_tombstone', ''))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects duplicate provider child identities before persistence', function (): void {
    $reference = new MessageReference(1, MailDriver::Gmail, 'duplicate-children');

    expect(fn () => new RetrievedMessage(
        $reference,
        null,
        attachments: [
            ['provider_id' => 'duplicate'],
            ['provider_id' => 'duplicate'],
        ],
    ))->toThrow(InvalidArgumentException::class, 'attachment identities must be unique')
        ->and(fn () => new RetrievedMessage(
            $reference,
            null,
            containers: [
                ['provider_id' => 'duplicate'],
                ['provider_id' => 'duplicate'],
            ],
        ))->toThrow(InvalidArgumentException::class, 'container identities must be unique');
});

it('rejects a reader registered under a different enum key', function (): void {
    $registry = new MailDriverRegistry;

    expect(fn () => $registry->register(MailDriver::Gmail, new SyntheticFixtureReader(MailDriver::Jmap, SyntheticFixtureReader::fixture('jmap'))))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects duplicate reader registration for an enum key', function (): void {
    $registry = new MailDriverRegistry;
    $reader = new SyntheticFixtureReader(MailDriver::Gmail, SyntheticFixtureReader::fixture('gmail'));
    $registry->register(MailDriver::Gmail, $reader);

    expect(fn () => $registry->register(MailDriver::Gmail, $reader))
        ->toThrow(LogicException::class, 'already registered');
});
