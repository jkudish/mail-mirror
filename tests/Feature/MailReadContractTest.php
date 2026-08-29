<?php

declare(strict_types=1);

use Jkudish\MailMirror\Contracts\MailboxReader;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MailReadService;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\RetrievedMessage;

/**
 * @phpstan-type Fixture array{
 *     provider_message_id: string,
 *     provider_occurrence_id: string,
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

    public function inventory(MailAccount $account): iterable
    {
        yield new MessageReference(
            mailAccountId: $account->id,
            driver: $this->mailDriver,
            providerMessageId: $this->fixture['provider_message_id'],
            providerOccurrenceId: $this->fixture['provider_occurrence_id'],
            providerThreadId: $this->fixture['provider_thread_id'],
            providerMetadata: $this->fixture['inventory_metadata'],
        );
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

    $reference = $reads->inventory($account)[0];
    $message = $reads->retrieve($account, $reference);

    expect($reference->driver)->toBe($driver)
        ->and($reference->providerMessageId)->toBe($fixture['provider_message_id'])
        ->and($reference->providerMetadata)->toBe($fixture['inventory_metadata'])
        ->and($message->providerMetadata)->toBe($fixture['message_metadata'])
        ->and($message->attachments[0]['provider_metadata'])->toHaveKey($nativeKey)
        ->and($message->containers[0]['provider_metadata'])->toBe($fixture['containers'][0]['provider_metadata']);
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
    $reference = $reads->inventory($first)[0];

    expect(fn () => $reads->retrieve($second, $reference))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reads->retrieve($first, new MessageReference($first->id, MailDriver::Jmap, 'id', 'occurrence')))
        ->toThrow(InvalidArgumentException::class);
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
