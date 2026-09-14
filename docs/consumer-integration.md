# Integrate MailMirror into a Laravel application

MailMirror reads Gmail and Fastmail JMAP accounts into account-scoped database
records and private object storage. Your application owns authentication,
authorization, retention policy, and any UI or search layer.

## Install and migrate

```bash
composer require jkudish/mail-mirror
php artisan migrate
```

Laravel package discovery registers `MailMirrorServiceProvider`, the built-in
readers, configuration, migrations, and import and storage services. MailMirror
loads migrations directly from the package; it does not publish migration
copies.

## Configure MailMirror

Publish the configuration only when you need to override a default:

```bash
php artisan vendor:publish --tag=mail-mirror-config
```

| Setting | Default | Purpose |
| --- | --- | --- |
| `database_connection` | application default | The configured Laravel database connection used by package models. PostgreSQL is required in production. |
| `storage_disk` | `local` | A private Laravel Filesystem disk for RFC 822 sources and materialized attachments. |
| `import_max_attempts` | `3` | Attempts for retryable retrieval failures. |
| `import_max_scan_restarts` | `1` | Fresh-scan restarts after an invalid provider cursor. |
| `inventory_page_max_messages` | `500` | Maximum messages accepted in one provider page. |
| `reconciliation_sample_limit` | `20` | Opaque provider IDs retained per report category. |
| `gmail.enabled` / `jmap.enabled` | `false` | Provider network kill switches. |

Use a private disk anywhere real mail is stored. Authorize the account owner
before calling MailMirror; provider IDs and storage keys are identifiers, not
authorization.

## Create an owned account

`MailAccount` is the isolation root. Applications with users or tenants should
register a stable morph alias and store both owner values:

```php
use Illuminate\Database\Eloquent\Relations\Relation;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;

Relation::enforceMorphMap(['user' => App\Models\User::class]);

$account = MailAccount::query()->create([
    'owner_type' => 'user',
    'owner_id' => (string) $user->getKey(),
    'driver' => MailDriver::Jmap,
    'provider_account_id' => $providerAccountId,
]);
```

Owner type and ID must both be present or both be `null`. An attached owner,
driver, and provider account ID cannot be changed. A closed, single-owner
application may use an ownerless account.

See [ownership and boundaries](architecture/ownership-and-boundaries.md) for the
full isolation contract.

## Store credentials

Store Fastmail API tokens behind `MailAccountConnection`:

```php
use Jkudish\MailMirror\Credentials\ApiTokenCredential;
use Jkudish\MailMirror\Credentials\MailAccountConnection;

app(MailAccountConnection::class)->store(
    $account,
    new ApiTokenCredential($apiToken),
);
```

For Gmail, use `GmailOAuth::authorizationUrl()` and `GmailOAuth::exchange()`.
Keep OAuth state and the PKCE verifier in a short-lived server-side session,
then store the returned `OAuthTokenSetCredential` with
`MailAccountConnection::store()`.

Inject tokens and OAuth secrets through your secret manager. Never put them in
configuration files, commands, logs, jobs, events, or browser payloads.

## Import and reconcile

Enable the matching provider, then import a bounded number of pages:

```php
use Jkudish\MailMirror\Import\MailImportEngine;

$report = app(MailImportEngine::class)->syncAccount(
    mailAccountId: $account->id,
    ownerType: 'user',
    ownerId: $user->getKey(),
    pageLimit: 10,
);
```

`null` means more pages remain. Call the method again with the same owner tuple
to resume from the durable cursor. A completed scan returns an immutable
`MailReconciliationReport`. Review its transient errors, waived errors,
unexplained missing records, and unexpected active records before treating the
scan as healthy.

Each page commits its normalized records, objects, inventory, errors, deletion
evidence, and checkpoint together. Repeating a scan does not duplicate messages.

Retry open failures only while their scan is incomplete:

```php
$retried = app(MailImportEngine::class)->retryOpenFailures(
    mailAccountId: $account->id,
    ownerType: 'user',
    ownerId: $user->getKey(),
    limit: 100,
);
```

A successful retry resolves the error episode. You may waive an understood
error with `MailImportError::waive()`. A later episode does not inherit the old
waiver.

## Read stored objects

Use `MailObjectStorage` instead of reading its configured disk or persisted
keys directly. Each call requires the matching account and record. Reads verify
SHA-256 and byte count before returning a rewindable stream.

For tests:

```php
use Illuminate\Support\Facades\Storage;

Storage::fake('mail-mirror-test');
config()->set('mail-mirror.storage_disk', 'mail-mirror-test');
```

`integrityReport()` returns IDs, MIME metadata, checksums, sizes, and statuses.
It does not return content or object keys. Missing attachments can be rebuilt
from an unchanged, verified raw message.

## Handle events

MailMirror dispatches two account-qualified events after commit:

- `MailContainerStateChanged(mailAccountId, mailContainerId)` signals changed
  container state.
- `ProviderDeletionStateChanged(mailAccountId, providerMessageId, hasEvidence)`
  signals new or invalidated provider-deletion evidence.

Listeners must reacquire and authorize the account from the scalar ID. Make
listeners idempotent. Event payloads contain no credentials or message content.

## Recover from failures

| Failure | Response |
| --- | --- |
| A process stops after a committed page | Call `syncAccount()` again. |
| A provider cursor expires | Allow the bounded fresh-scan restart. Investigate repeated mismatches. |
| Retrieval fails | Fix the credential or provider condition, then call `retryOpenFailures()` while the scan remains incomplete. |
| Object integrity fails | Do not expose the bytes. Retrieve the source again, or rebuild an attachment from a verified raw message. |
| An upgrade finds populated legacy sync state | Resolve or migrate that state before running the migration again. No schema change has occurred. |
| A local purge loses a filesystem operation | Retry `purgeMessage()` with the same owner tuple and checkpoint version. References remain until object deletion succeeds. |

Follow the separate [Gmail](gmail-live-development.md) or
[Fastmail JMAP](fastmail-jmap-live-development.md) procedure before using a
provider account for live development.

## Extend a driver

`MailDriver` accepts only `gmail` and `jmap`. Adding a provider requires a new
enum case, database constraint and migration, reader registration, credential
handling, provider-neutral fixtures, and compatibility tests. Keep
provider-specific facts in bounded native metadata behind the `MailboxReader`
contract.
