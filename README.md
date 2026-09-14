# MailMirror

Mirror Gmail and Fastmail accounts into account-scoped Laravel models and
private object storage.

MailMirror provides read-only Gmail and JMAP adapters, resumable imports,
reconciliation reports, encrypted credential storage, and integrity-checked
RFC 822 and attachment storage. It does not provide a UI, search, or provider
write operations.

## Requirements

- PHP 8.4 or 8.5
- Laravel 12 or 13
- PostgreSQL in production

SQLite is supported for tests and local development.

## Installation

Install the package with Composer, then run its migrations:

```bash
composer require jkudish/mail-mirror
php artisan migrate
```

Laravel package discovery registers the service provider, configuration,
migrations, and built-in Gmail and Fastmail JMAP readers.

Publish the configuration if you need to change the database connection,
storage disk, import limits, or provider settings:

```bash
php artisan vendor:publish --tag=mail-mirror-config
```

Set `MAIL_MIRROR_STORAGE_DISK` to a private Laravel Filesystem disk before
mirroring real mail. Gmail and JMAP network access remain disabled until you
enable the matching provider.

## Mirror an account

Create a `MailAccount`, store its credential, then call `syncAccount()`. This
Fastmail example assumes `$apiToken` came from your application's secret
manager:

```php
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Import\MailImportEngine;
use Jkudish\MailMirror\Models\MailAccount;

$account = MailAccount::query()->create([
    'owner_type' => 'user',
    'owner_id' => (string) $user->getKey(),
    'driver' => MailDriver::Jmap,
    'provider_account_id' => $providerAccountId,
]);

app(MailAccountConnection::class)->store(
    $account,
    new ApiTokenCredential($apiToken),
);

$report = app(MailImportEngine::class)->syncAccount(
    mailAccountId: $account->id,
    ownerType: 'user',
    ownerId: $user->getKey(),
    pageLimit: 10,
);
```

Set `MAIL_MIRROR_JMAP_ENABLED=true` before the import. For Gmail, complete the
OAuth flow with `GmailOAuth`, store the returned `OAuthTokenSetCredential`, and
set `MAIL_MIRROR_GMAIL_ENABLED=true`.

A bounded import returns `null` when more pages remain. Call `syncAccount()`
again with the same account and owner tuple to resume. A completed scan returns
an immutable `MailReconciliationReport` with mirrored, provider-deleted,
transient-error, waived-error, unexplained-missing, and unexpected-active
counts.

Imports are idempotent. MailMirror qualifies provider identities by account,
commits each page with its checkpoint, and verifies stored object checksums and
byte counts before returning content.

## Application responsibilities

Your application must:

- authenticate and authorize the owner before every package call;
- keep credentials outside logs, queues, events, and browser payloads;
- use a private storage disk for real mail;
- decide retention and deletion policy;
- retry bounded imports and investigate reconciliation errors;
- treat package events as notification references, not authorization.

MailMirror never sends mail, changes mailbox state, or purges local mail on its
own.

## Documentation

- [Consumer integration](docs/consumer-integration.md)
- [Ownership and trust boundaries](docs/architecture/ownership-and-boundaries.md)
- [Gmail development-account check](docs/gmail-live-development.md)
- [Fastmail JMAP development-account check](docs/fastmail-jmap-live-development.md)
- [Verification](docs/verification.md)
- [Releasing](docs/releasing.md)

See [CONTRIBUTING.md](CONTRIBUTING.md) for local development and
[SECURITY.md](SECURITY.md) for reporting vulnerabilities.

## License

MailMirror is open source software licensed under the
[MIT License](LICENSE.md).
