<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Jkudish\MailMirror\Contracts\MailboxReader;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Import\MailImportEngine;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Models\MailMessage;
use Jkudish\MailMirror\Models\MailRawObject;
use Jkudish\MailMirror\Read\InventoryPage;
use Jkudish\MailMirror\Read\MailDriverRegistry;
use Jkudish\MailMirror\Read\MessageReference;
use Jkudish\MailMirror\Read\RawMessageSource;
use Jkudish\MailMirror\Read\RetrievedMessage;

$arguments = $_SERVER['argv'] ?? null;

if (! is_array($arguments)
    || count($arguments) !== 2
    || ! array_key_exists(1, $arguments)
    || ! is_string($arguments[1])
    || ! is_dir($arguments[1])) {
    fwrite(STDERR, "Usage: php consumer-smoke.php CONSUMER_ROOT\n");
    exit(2);
}

$consumerRoot = realpath($arguments[1]);

if (! is_string($consumerRoot)) {
    throw new RuntimeException('The consumer root could not be resolved.');
}

require $consumerRoot.'/vendor/autoload.php';
/** @var Application $app */
$app = require $consumerRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! Schema::hasTable('mail_accounts') || ! Schema::hasTable('mail_reconciliation_reports')) {
    throw new RuntimeException('MailMirror migrations did not run in the clean consumer.');
}

Storage::fake('mail-mirror-consumer-smoke');
config()->set('mail-mirror.storage_disk', 'mail-mirror-consumer-smoke');

$account = MailAccount::query()->create([
    'driver' => MailDriver::Gmail,
    'provider_account_id' => 'synthetic-consumer-account',
]);
$reader = new class implements MailboxReader
{
    public function driver(): MailDriver
    {
        return MailDriver::Gmail;
    }

    public function inventoryPage(MailAccount $account, ?string $cursor): InventoryPage
    {
        return new InventoryPage([
            new MessageReference($account->id, MailDriver::Gmail, 'synthetic-consumer-message'),
        ], null, true);
    }

    public function retrieve(MailAccount $account, MessageReference $message): RetrievedMessage
    {
        $stream = fopen('php://temp', 'w+b');

        if (! is_resource($stream)) {
            throw new RuntimeException('Could not create the synthetic raw source.');
        }

        fwrite($stream, "From: sender@invented.test\r\nMessage-ID: <consumer@invented.test>\r\n\r\nSynthetic body.\r\n");
        rewind($stream);

        return new RetrievedMessage(
            reference: $message,
            subject: 'Synthetic consumer message',
            internetMessageId: '<consumer@invented.test>',
            rawSource: new RawMessageSource($stream, 'synthetic-consumer-raw'),
        );
    }
};
$registry = new MailDriverRegistry;
$registry->register(MailDriver::Gmail, $reader);
$app->instance(MailDriverRegistry::class, $registry);
$engine = $app->make(MailImportEngine::class);

$first = $engine->syncAccount($account->id, null, null);
$second = $engine->syncAccount($account->id, null, null);
$raw = MailRawObject::query()->forAccount($account)->first();

if ($first?->mirrored_count !== 1
    || $second?->mirrored_count !== 1
    || $second->unexplained_missing_count !== 0
    || $second->unexpected_active_count !== 0
    || MailMessage::query()->forAccount($account)->count() !== 1
    || ! $raw instanceof MailRawObject) {
    throw new RuntimeException('The representative synthetic import did not converge.');
}

Storage::disk('mail-mirror-consumer-smoke')->assertExists((string) $raw->object_key);

fwrite(STDOUT, "Representative synthetic import converged.\n");
