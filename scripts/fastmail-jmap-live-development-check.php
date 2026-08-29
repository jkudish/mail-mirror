<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Read\MailReadService;

$fail = static function (string $message): never {
    fwrite(STDERR, 'fastmail-jmap-live-development-check: '.$message.PHP_EOL);
    exit(2);
};

if (getenv('MAIL_MIRROR_JMAP_LIVE_OPT_IN') !== 'I_UNDERSTAND_THIS_CONTACTS_FASTMAIL') {
    $fail('explicit live-provider opt-in is required');
}

if (getenv('APP_ENV') !== 'local') {
    $fail('APP_ENV must be local');
}

$root = getenv('MAIL_MIRROR_JMAP_CONSUMER_ROOT');
$accountId = filter_var(getenv('MAIL_MIRROR_JMAP_ACCOUNT_ID'), FILTER_VALIDATE_INT);
$ownerType = getenv('MAIL_MIRROR_JMAP_OWNER_TYPE');
$ownerId = getenv('MAIL_MIRROR_JMAP_OWNER_ID');

if (! is_string($root) || ! is_file($root.'/bootstrap/app.php') || $accountId === false || $accountId < 1
    || ! is_string($ownerType) || $ownerType === '' || ! is_string($ownerId) || $ownerId === '') {
    $fail('a consumer root and complete account/owner tuple are required');
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('app.env') !== 'local' || config('mail-mirror.jmap.enabled') !== true) {
    $fail('the booted consumer must be local with Fastmail JMAP explicitly enabled');
}

$account = MailAccount::query()->whereKey($accountId)
    ->where('owner_type', $ownerType)->where('owner_id', $ownerId)
    ->where('driver', MailDriver::Jmap->value)->first();

if (! $account instanceof MailAccount) {
    $fail('the account does not match the supplied owner tuple and JMAP driver');
}

$reads = $app->make(MailReadService::class);
$cursor = null;
$profile = null;
$messageCount = 0;
$identityCount = 0;
$complete = false;

for ($pageNumber = 1; $pageNumber <= 10000; $pageNumber++) {
    $page = $reads->inventoryPage($account, $cursor);
    $profile ??= $page->accountProfile;
    $messageCount += count($page->messages);
    $identityCount += count($page->identities);

    if ($page->complete) {
        $complete = true;
        break;
    }

    if ($page->nextCursor === null) {
        $fail('Fastmail JMAP returned an incomplete inventory without a cursor');
    }

    $cursor = $page->nextCursor;
}

if ($profile === null || ! $complete) {
    $fail('Fastmail JMAP did not complete a bounded account inventory');
}

printf(
    "Live Fastmail JMAP read check passed: account_hash=%s messages=%d identities=%d complete=yes\n",
    substr(hash('sha256', $profile->providerAccountId), 0, 12),
    $messageCount,
    $identityCount,
);
