# MailMirror

MailMirror is a private, standalone Laravel package foundation. It provides an
account-rooted, provider-neutral persistence schema, resumable inventory/import
and reconciliation orchestration, read-only driver contracts, and immutable
raw-message and materialized-attachment byte storage. It includes a read-only
Gmail integration, but no search or provider mutation API.

Mail accounts may optionally belong to a consumer model through a polymorphic
owner. Provider-derived records are isolated by account-qualified identifiers
and composite account/parent foreign keys. Drivers are selected with the closed
`MailDriver` enum (`gmail` and `jmap`); consumers register read adapters in
`MailDriverRegistry` with enum keys rather than arbitrary strings.

The package registers its Gmail reader under `MailDriver::Gmail`. Gmail OAuth
requests exactly `gmail.modify`, validates the exact returned grant, and keeps
token refresh, rotation, and local invalid-grant revocation inside
`MailAccountConnection`. Provider network access is fail-closed unless
`MAIL_MIRROR_GMAIL_ENABLED=true`; ordinary verification never enables it. See
[the separately authorized live development lane](docs/gmail-live-development.md).

## Development

```bash
./.agents/setup
composer verify
```

The package supports PHP 8.4–8.5 and Laravel 12–13. PostgreSQL is the supported
runtime database; SQLite is supported for package tests and local consumers.
The migration fails closed on other database drivers.

The package suite uses SQLite by default. To exercise the feature tests on a
disposable PostgreSQL database, set `MAIL_MIRROR_TEST_POSTGRES=1` and the
`MAIL_MIRROR_TEST_POSTGRES_*` connection variables before running Pest. The
test harness runs `migrate:fresh` before every test and must never target a
shared or persistent database.

The package [ownership and boundary contract](docs/architecture/ownership-and-boundaries.md)
defines mail accounts as the roots for provider-derived records while keeping
consumer ownership optional and consumer-neutral. Future storage, credential,
import, reconciliation, and provider work must add its concrete isolation tests
alongside the implementation.

## Resumable import and reconciliation

`MailboxReader::inventoryPage()` returns account-qualified message references,
an opaque next cursor, completion status, and any explicit provider deletion
evidence. `MailImportEngine` processes one bounded page at a time. It retrieves
messages sequentially, retries only explicitly retryable content-safe failures
up to `mail-mirror.import_max_attempts`, and commits inventory rows, idempotent
message/thread/header/participant/attachment/container state, sparse errors,
scan-qualified deletion evidence, and the checkpoint in one transaction. Later
provider state replaces stale child rows and memberships while preserving
provider-native metadata. `RetrievedMessage` carries optional immutable sent and
received datetimes into the corresponding message columns. Import failures use
the package-owned `MailImportStage` and `MailImportCode` closed values; unknown
driver strings normalize to generic content-safe metadata and never reach
persistence or exceptions. The optimistic checkpoint version and account row
lock reject concurrent or stale advancement. Inventory pages are rejected above
`mail-mirror.inventory_page_max_messages`; the cursor changes only after all
corresponding database and object work is durable.

`MailMessage` identity is exactly `(mail_account_id, provider_message_id)`.
The nullable, non-unique `internet_message_id` preserves RFC Message-ID when
available but is never provider identity. One `mail_sync_checkpoints` row per
account holds only current resumable state: stable scan ID, opaque cursor,
version, progress, and timestamps. There is no generic run lifecycle.

On completion, `ReconciliationService` writes one immutable metadata-only
report per account/scan. It distinguishes mirrored inventory, provider-proven
deletions, open transient errors, explicitly waived errors, unexplained missing
inventory, and unexpected active mirror records. Reports, errors, and thrown
exceptions contain no message content, credentials, object keys, or provider
error text. For current inventory, an open unwaived error takes precedence over
a prior mirror row, followed by an open waiver; only rows without an open error
are mirrored or unexplained. Each report category contains its indexed SQL count
plus at most `mail-mirror.reconciliation_sample_limit` opaque provider IDs and a
`truncated` flag; it never stores the full provider inventory. Deletion evidence
explains a difference only for the completed scan that observed it and is
invalidated by reappearance. MailMirror does not quarantine, retain, purge, or
otherwise apply deletion lifecycle policy here.

The upgrade migration refuses to replace populated legacy run/checkpoint state
before making any schema change. Its rollback restores compatible legacy run,
checkpoint, and provider identity schema; clean installs use only the
simplified import foundation.

Consumers may call `syncAccount()` with scalar account and owner tuple values;
the engine reacquires the account and rejects mismatches before provider or
database side effects. Work is deliberately sequential (bounded concurrency of
one) until a concrete provider demonstrates a need for wider concurrency.

The package automatically registers its config and migration. Applications can
publish the config with Laravel's conventional `vendor:publish` command; the
migration is loaded directly from the package so it cannot also be published
and accidentally run twice. Models use the application's default database
connection unless `mail-mirror.database_connection` selects another configured
connection.

## Immutable object storage

Set `mail-mirror.storage_disk` to a private Laravel Filesystem disk. Consumers
use the package-owned `MailObjectStorage` boundary and must pass both the
`MailAccount` and matching message, raw object, or attachment record for every
operation. Reads verify SHA-256 and byte count before exposing a rewindable
stream. `integrityReport()` returns IDs, MIME metadata, checksums, sizes, and
statuses only; it never returns object keys, filenames, or content.

Writes first hash a bounded-memory local stream. Inside a database transaction
they lock the message row, reject any different immutable record, create the
uniquely constrained record, stream an account/message/content-addressed object
with private visibility, and verify it. A failed write is removed; a partial
object left by process interruption is repaired by an identical retry. If
object finalization succeeds inside an import page that later rolls back, the
engine calls an account-qualified cleanup boundary after the root rollback. It
deletes the key only when no durable raw-object row references it, preserving
previously committed bytes. Missing materialized attachments can be regenerated
from the verified, unchanged RFC 822 raw source using stable MIME part paths.

No lifecycle events are emitted by this foundation: no concrete downstream
consumer requires one yet. A later import or projection task should add only
the scalar event boundary that its implemented consumer needs.

The setup is independent and idempotent. In the combined Amp project, jMail's
primary setup invokes it from `../repos/mail-mirror` relative to jMail because
Amp automatically runs only the primary repository setup.

Pull requests use repository-owned, credential-free Amp-orb verification:

```bash
composer pr:check
```

The plan verifies the locked Laravel 13 graph, a disposable Laravel 13
prefer-lowest graph, a clean Laravel 12 consumer, quality checks, workflow
safeguards, and a final clean diff. It writes a mode-`0600` exact-commit receipt
in `.git`. GitHub Actions and enforced rulesets are intentionally absent. See
the [pull-request verification skill](.agents/skills/verifying-pull-requests/SKILL.md)
for exact-SHA or prospectively delegated approval, dedicated-token signoff,
required status read-back, and authority boundaries.
