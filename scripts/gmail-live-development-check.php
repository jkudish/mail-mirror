<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;
use Jkudish\MailMirror\Read\MailReadService;

$fail = static function (string $message): never {
    fwrite(STDERR, 'gmail-live-development-check: '.$message.PHP_EOL);
    exit(2);
};

if (getenv('MAIL_MIRROR_GMAIL_LIVE_OPT_IN') !== 'I_UNDERSTAND_THIS_CONTACTS_GMAIL') {
    $fail('explicit live-provider opt-in is required');
}

if (getenv('APP_ENV') !== 'local') {
    $fail('APP_ENV must be local');
}

$root = getenv('MAIL_MIRROR_GMAIL_CONSUMER_ROOT');
$accountId = filter_var(getenv('MAIL_MIRROR_GMAIL_ACCOUNT_ID'), FILTER_VALIDATE_INT);
$ownerType = getenv('MAIL_MIRROR_GMAIL_OWNER_TYPE');
$ownerId = getenv('MAIL_MIRROR_GMAIL_OWNER_ID');

if (! is_string($root) || ! is_file($root.'/bootstrap/app.php') || $accountId === false || $accountId < 1
    || ! is_string($ownerType) || $ownerType === '' || ! is_string($ownerId) || $ownerId === '') {
    $fail('a consumer root and complete account/owner tuple are required');
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('app.env') !== 'local' || config('mail-mirror.gmail.enabled') !== true) {
    $fail('the booted consumer must be local with Gmail explicitly enabled');
}

$account = MailAccount::query()->whereKey($accountId)
    ->where('owner_type', $ownerType)->where('owner_id', $ownerId)
    ->where('driver', MailDriver::Gmail->value)->first();

if (! $account instanceof MailAccount) {
    $fail('the account does not match the supplied owner tuple and Gmail driver');
}

$page = $app->make(MailReadService::class)->inventoryPage($account);
$profile = $page->accountProfile;

if ($profile === null) {
    $fail('Gmail did not return an account profile');
}

printf(
    "Live Gmail read check passed: account_hash=%s messages_on_page=%d identities=%d complete=%s\n",
    substr(hash('sha256', $profile->providerAccountId), 0, 12),
    count($page->messages),
    count($page->identities),
    $page->complete ? 'yes' : 'no',
);
