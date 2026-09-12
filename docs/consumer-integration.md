# Integrate MailMirror into a Laravel application

MailMirror is a private Laravel 12–13 package for mirroring Gmail and Fastmail
JMAP accounts into account-scoped database records and private object storage.
This guide is for an application that will own authorization and call the
package directly. MailMirror does not provide UI, search, MCP, AI, approval, or
provider-write behavior.

## Install and migrate

Configure access to the private repository, then require the package version
your application has selected:

```bash
composer config repositories.mail-mirror vcs git@github.com:jkudish/mail-mirror.git
composer require jkudish/mail-mirror:dev-main
php artisan migrate
```

`dev-main` is the current pre-release installation target. After the first
tagged release, use a compatible tagged constraint instead of following the
branch. Laravel package discovery registers `MailMirrorServiceProvider`, its
configuration, migrations, built-in readers, and import/storage services.

Verify that `mail_accounts` and `mail_reconciliation_reports` exist. MailMirror
loads migrations from the package and does not publish migration copies, which
prevents the same migration from running twice.

## Configure the package

Publish the configuration only when the application needs to override defaults:

```bash
php artisan vendor:publish --tag=mail-mirror-config
```

The important settings are:

| Setting | Default | Contract |
| --- | --- | --- |
| `database_connection` | application default | Optional configured Laravel database connection used by package models. PostgreSQL is the production database; SQLite is supported for tests and local consumers. |
| `storage_disk` | `local` | Private Laravel Filesystem disk for immutable RFC 822 sources and materialized attachments. |
| `import_max_attempts` | `3` | Maximum attempts for explicitly retryable content-safe retrieval failures. |
| `import_max_scan_restarts` | `1` | Maximum fresh-scan restart after an invalid provider cursor. |
| `inventory_page_max_messages` | `500` | Hard limit applied before retaining page resources. |
| `reconciliation_sample_limit` | `20` | Maximum opaque IDs retained per reconciliation category. |
| `gmail.enabled` / `jmap.enabled` | `false` | Provider network kill switches. Ordinary tests and verification keep both disabled. |

Use a private disk in every environment containing real mail. Applications
must authorize the consumer owner before passing an account to MailMirror;
storage keys and provider IDs are never authorization.

## Create an owned account

`MailAccount` is the isolation root. A closed single-owner application may use
an ownerless account. Applications with users or tenants should register a
stable morph alias and persist both owner values:

```php
use Illuminate\Database\Eloquent\Relations\Relation;
use Jkudish\MailMirror\Enums\MailDriver;
use Jkudish\MailMirror\Models\MailAccount;

Relation::enforceMorphMap(['user' => App\Models\User::class]);

$account = MailAccount::query()->create([
    'owner_type' => 'user',
    'owner_id' => (string) $user->getKey(),
    'driver' => MailDriver::Gmail,
    'provider_account_id' => $providerAccountId,
]);
```

Owner type and ID must both be present or both be null. An attached owner,
driver, and provider account identity are immutable. The consumer remains
responsible for authenticating and authorizing `$user`; package operations
recheck the complete account/owner tuple.

See [MailMirror ownership and boundaries](architecture/ownership-and-boundaries.md)
for the schema ownership and trust-boundary contract.

## Import and reconcile

After storing a provider credential through `MailAccountConnection`, import a
bounded number of pages or continue until the scan completes:

```php
use Jkudish\MailMirror\Import\MailImportEngine;

$report = app(MailImportEngine::class)->syncAccount(
    mailAccountId: $account->id,
    ownerType: 'user',
    ownerId: $user->getKey(),
    pageLimit: 10,
);
```

A `null` result means the bounded call stopped before completing the current
scan. Call it again with the same owner tuple to resume from the durable opaque
cursor. A completed scan returns an immutable `MailReconciliationReport`.
Completion is healthy only when open errors and unexplained missing or
unexpected active occurrences are understood; do not infer success from the
presence of a report alone.

Each page commits normalized records, raw/object work, inventory, errors,
deletion evidence, and the checkpoint together. Repeating an import is safe:
provider identities are account-qualified, hydration is idempotent, and a
second unchanged scan converges without duplicate messages.

### Retry open failures

Retry sparse failures only while their scan is incomplete:

```php
$retried = app(MailImportEngine::class)->retryOpenFailures(
    mailAccountId: $account->id,
    ownerType: 'user',
    ownerId: $user->getKey(),
    limit: 100,
);
```

Retryable provider failures are bounded automatically. Non-retryable or
exhausted failures remain as content-safe `MailImportError` records. A later
successful retrieval resolves the episode. Application code may explicitly
waive an understood occurrence; a later error episode does not inherit the old
waiver.

## Read and verify stored objects

Use `MailObjectStorage`; do not read configured disks or persisted object keys
directly. Every call requires a matching account and record. Reads verify the
stored SHA-256 and byte count before exposing a rewindable stream.

For local tests:

```php
use Illuminate\Support\Facades\Storage;

Storage::fake('mail-mirror-test');
config()->set('mail-mirror.storage_disk', 'mail-mirror-test');
```

`integrityReport()` returns only IDs, MIME metadata, checksums, sizes, and
statuses. Missing materialized attachments can be regenerated from an
unchanged, verified raw source. Identical writes and interrupted-write retries
converge; conflicting immutable bytes fail.

## Handle lifecycle events

MailMirror emits scalar, account-qualified events after the importing
transaction commits:

- `MailContainerStateChanged(mailAccountId, mailContainerId)` tells the
  consumer to refresh projections affected by shared container state.
- `ProviderDeletionStateChanged(mailAccountId, providerMessageId, hasEvidence)`
  tells the consumer that completed-scan deletion evidence appeared or was
  invalidated by reappearance.

Listeners must reacquire and authorize the account from the scalar ID and be
idempotent. Event payloads are notification references, not authority, and do
not contain credentials or message content. MailMirror does not decide a
consumer's retention period or automatically purge provider-deleted mail.

## Recover from failures

| Failure | Safe response |
| --- | --- |
| Process stops after a durable page | Call `syncAccount()` again; it resumes from the committed cursor. |
| Provider cursor is stale | The reader may perform one bounded fresh-scan restart. Repeated mismatch fails and remains visible. |
| Retrieval fails | Inspect the sparse error code, fix credentials/provider conditions, then call `retryOpenFailures()` while the scan remains incomplete. |
| Raw or attachment integrity fails | Do not expose the bytes. Repair from the authoritative provider or regenerate a materialized attachment from the verified raw source. |
| Upgrade sees populated legacy sync state | The migration fails before changing schema. Resolve or explicitly migrate that state before retrying. |
| Local retention purge loses a filesystem operation | Retry `purgeMessage()` with the same owner tuple and current checkpoint version; references remain durable until object deletion succeeds. |

Provider access is always explicit. Follow the separate
[Gmail](gmail-live-development.md) or
[Fastmail JMAP](fastmail-jmap-live-development.md) lane before enabling a
network kill switch.

## Driver and release evolution

`MailDriver` is deliberately closed to `gmail` and `jmap`. A consumer cannot
register an arbitrary persisted driver name. Adding a provider is a package
change that must update the enum, database constraint/migration, reader
registration, credentials, provider-neutral contract fixtures, and supported
compatibility tests together. The `MailboxReader` contract remains the common
read/import boundary; provider-specific facts stay in bounded native metadata.

MailMirror follows Semantic Versioning after its first tag:

- patch releases fix behavior without changing public contracts or required
  schema;
- minor releases add backward-compatible APIs and additive migrations;
- major releases may change public PHP contracts, persisted meanings, required
  configuration, or supported platform versions.

Every release tag must come from a reviewed commit that passes `composer
pr:check`. Migrations are forward-only in normal deployment. An upgrade must
preserve existing data or fail before mutation with an explicit migration path;
rollback methods support development and deployment rollback only where the
current migration documents that guarantee. Release notes must identify new
migrations, required consumer action, deprecations, and any provider/schema
contract change. A tag or package publication remains a separately authorized
release action.

## Verify a consumer integration

From the package checkout, these commands create disposable Laravel
applications, install MailMirror, run its migrations, configure a fake disk and
reader, and prove an idempotent synthetic import without provider access:

```bash
bash scripts/test-consumer-install.sh 12
bash scripts/test-consumer-install.sh 13
```

Package pull-request verification additionally runs Laravel 12 and 13 against
stable and prefer-lowest dependency graphs. Every graph gets Composer
validation and audit, PHPStan, and the complete package suite; source formatting
is checked once with Pint. Ordinary verification uses invented `.test` data and
blocks provider requests.
