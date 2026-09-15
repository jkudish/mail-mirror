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

## Wake synchronization from provider notifications

MailMirror exposes provider-specific, finite notification primitives. The host
owns process supervision, repeated invocation, account locking, durable job or
wake-up creation, and fallback polling. A hint never changes
`mail_delta_checkpoints.provider_cursor`; it only tells the host to call
`MailImportEngine::syncChangesAccount()` from the package's applied cursor.

Run the package migration before configuring either adapter. Both integrations
are disabled by default.

### Gmail watch and outbound Pub/Sub pull

Configure one exact project/topic/subscription tuple under
`mail-mirror.gmail.pubsub`. The default `PubSubAccessTokenProvider` reads a
short-lived token at call time from the environment variable named by
`access_token_environment`; the token is not copied into cached Laravel config.
Consumers may bind another `PubSubAccessTokenProvider` implementation for their
credential runtime. Pub/Sub credentials are separate from mailbox OAuth.

Register a watch with an explicit setup choice:

```php
$watch = app(GmailWatchService::class)->register(
    $account,
    GmailWatchSetup::Create,
);

// Renewal requires the exact identity returned by the prior setup.
$watch = app(GmailWatchService::class)->register(
    $account,
    GmailWatchSetup::Renew,
    $watch->identity,
);
```

`Create` rejects existing metadata. `Renew` requires stored and configured
identities to match. `Replace` requires the exact prior identity and a different
configured identity, making resource takeover explicit. The returned
`historyIdHint` and persisted `mail_gmail_watches.history_id_hint` are diagnostic
hints only; neither advances applied Gmail history.

Pull and acknowledge are separate host calls:

```php
$batch = app(GmailPubSubService::class)->pull($gmailAccounts, maximumMessages: 20);

foreach ($batch->notifications as $notification) {
    if ($notification->accepted()) {
        // Durably record/coalesce a wake-up for $notification->mailAccountId.
        // historyIdHint is diagnostic only; reconcile from the applied cursor.
    } else {
        // Durably record a safe malformed_or_misrouted disposition.
    }
}

// Call only after every envelope has durable host intent or disposition.
app(GmailPubSubService::class)->acknowledge($batch->notifications);
```

`pull()` makes one unary REST Pull request and accepts 1 through the configured
`max_messages` (at most 100). Every supplied account must exactly match its
persisted account/owner identity and stored watch project/topic/subscription.
Each envelope is size bounded and yields only account ID, provider message ID,
publish time, optional history hint, and a safe rejection reason. Ack IDs remain
inside non-serializable envelope capabilities. `acknowledge()` rejects an empty,
oversized, or cross-subscription list.

### Fastmail EventSource

Each `receive()` call makes at most two authenticated requests: Session
discovery and one finite EventSource request with `closeafter=state`. The package
accepts only the Session-advertised level-1 template with exactly `types`,
`closeafter`, and `ping`, an HTTPS `api.fastmail.com` origin, no userinfo,
fragment, custom port, static query credential, or redirect. Stream, event,
line, event-count, and request-time limits come from
`mail-mirror.jmap.event_source`. The timeout is a wall-time limit shared by
Session discovery, EventSource connection, and bounded reads; each blocking read
is independently limited, read failures are content-safe and retryable, and the
stream is always closed. An event is exposed only after its terminating blank
line arrives; a partial final event is discarded and replayed after reconnect.

```php
$batch = app(FastmailEventSourceService::class)->receive($account);

foreach ($batch->stateChanges as $hint) {
    // Durably coalesce a wake-up. hint->changed contains only bounded
    // Email, EmailDelivery, and/or Mailbox state strings for this account.
}

// After durable host intent, checkpoint SSE replay position separately.
if ($batch->lastEventId !== null) {
    app(FastmailEventSourceService::class)->advanceLastEventId(
        $account,
        expectedLastEventId: $previousLastEventId,
        batch: $batch,
    );
}
```

`advanceLastEventId()` is compare-and-set. Event IDs live only in
`mail_jmap_event_sources`; they never replace applied JMAP state in account
metadata or the delta checkpoint. The next `receive()` sends the persisted
value as `Last-Event-ID`. The host reconnects by invoking `receive()` again and
uses ordinary delta reconciliation and fallback when hints are duplicate,
missing, delayed, or interrupted.

## Apply provider changes

After an inventory has established provider state, run a bounded changed-message
sync independently of push hints:

```php
$result = app(MailImportEngine::class)->syncChangesAccount(
    mailAccountId: $account->id,
    ownerType: 'user',
    ownerId: $user->getKey(),
    pageLimit: 10,
    repairPageLimit: 2,
);
```

`DeltaSyncResult` contains `processedCount`, `caughtUp`, and `repairPending`.
Call the method again when `caughtUp` is false. Gmail history and JMAP
`Email/changes` update existing message state without downloading RFC 822 data.
New or reappeared messages still pass through complete normalized and raw
persistence.

`mail_delta_checkpoints.provider_cursor` is the last applied provider cursor,
not the last push hint received. A page commits its source records, deletion
evidence, normalized container lifecycle, replayable source changes, and cursor
in one database transaction. A failed new-message retrieval records a
`MailImportError` but retains the cursor so the page is retried. An expired
cursor stores `repair_cursor` and runs the existing authoritative inventory in
`repairPageLimit` chunks; the applied cursor changes only after that inventory
completes. Partial container snapshots never prove deletion.

### Project source changes

Every package persistence path, including the historical `syncAccount()` path,
inserts `MailSourceChange` rows in the same transaction as the source change.
This closes the crash gap that after-commit events alone cannot close. The
append-only rows contain only scalar references:

| `kind` | Populated values |
| --- | --- |
| `message_changed` | `mail_message_id`, `provider_message_id` |
| `message_deleted` | deleted `mail_message_id`, `provider_message_id` |
| `raw_changed` | `mail_message_id`, `mail_raw_object_id`, `provider_message_id` |
| `container_changed` | `mail_container_id`, `provider_container_id` |
| `container_deleted` | deleted `mail_container_id`, `provider_container_id` |
| `provider_deletion_changed` | `provider_message_id`, `provider_deleted` |

All rows also contain `id`, `mail_account_id`, `acknowledged_at`, and timestamps.
IDs are account-qualified; message, raw object, and container IDs deliberately
have no foreign key because deletion records must remain replayable.

Read and acknowledge rows through the owner-scoped service:

```php
$changes = app(SourceChangeService::class)->pendingAccount(
    $account->id,
    'user',
    $user->getKey(),
    limit: 100,
);

DB::transaction(function () use ($changes, $account, $user): void {
    // Apply each change idempotently to the host projection first.

    app(SourceChangeService::class)->acknowledgeAccount(
        $account->id,
        'user',
        $user->getKey(),
        $changes->modelKeys(),
    );
});
```

`pendingAccount()` returns unacknowledged rows ordered by `id` (limit 1–500).
`acknowledgeAccount()` accepts 1–500 positive integer IDs and updates only rows
on the supplied account and owner path. When the host projection uses the same
database connection, acknowledge inside the host projection transaction as
shown. Otherwise apply idempotently and acknowledge afterward; a crash may
replay a row but cannot lose it.

The package migration creates `mail_delta_checkpoints` and
`mail_source_changes`; existing inventory APIs and tables remain compatible.
Deploy the migration before calling `syncChangesAccount()` or
`SourceChangeService`.

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
