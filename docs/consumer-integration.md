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
oversized, or cross-subscription list. Gmail history IDs encoded as either
decimal strings or exact positive JSON integers are normalized to decimal
strings; zero, negative, fractional, and imprecise numeric values are rejected.

### Fastmail EventSource

Each `receive()` call makes at most two authenticated requests: Session
discovery and one finite EventSource request with `closeafter=state`. The package
accepts only the Session-advertised level-1 template with exactly `types`,
`closeafter`, and `ping`, an HTTPS origin in the package's finite Fastmail
allowlist (`api.fastmail.com` or `phl.api.fastmail.com`), no userinfo, fragment,
custom port, static query credential, or redirect. The first accepted origin is
persisted and later Session responses must match it. Stream, event, line,
event-count, and request-time limits come from
`mail-mirror.jmap.event_source`. The timeout is a wall-time limit shared by
Session discovery and the complete finite `closeafter=state` transfer. The
EventSource response is buffered by the HTTP transport rather than returned as a
live PHP stream, so its total timeout remains active during quiet periods and
continuous pings. Transfer and read failures are content-safe and retryable, and
the response body is always closed after a successful transfer. An event is
exposed only after its terminating blank line arrives; a partial final event is
discarded and replayed after reconnect.

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
`MailImportError` but retains the cursor so the page is retried. A message that
disappears between the provider change list and state retrieval instead creates
a `mail_delta_pending_messages` obligation while advancing past that page. A
later deletion resolves it, or the next changed-message sync retries full
retrieval; `caughtUp` remains false while an obligation is open. Each call reads
provider changes before retrying at most 25 obligations, ordered by fewest prior
attempts and then ID, so an unavailable backlog cannot hide a later provider
deletion page or permanently starve newer obligations.

An expired cursor stores `repair_cursor` and binds `repair_scan_id` to a newly
started authoritative inventory, run in `repairPageLimit` chunks. The repair
remains pending when that exact scan reports transient errors, unexplained
missing messages, or an inconclusive exact lookup. For unexpected local
messages absent from a complete inventory, bounded exact provider lookup either
confirms current presence or records deletion evidence for quarantine; it never
purges the message. Each exact outcome is committed under the existing repair
scan before the next lookup, so a large set or exhausted invocation resumes from
its remaining messages instead of restarting the scan. After convergence,
change sync replays from the captured repair cursor before reporting `caughtUp`.
Partial container snapshots and transient or authorization failures never prove
deletion.

### Bound one invocation

Pass a caller-owned `SyncWorkBudget` when a check must stop after a finite
allowance. Omitting it preserves the unlimited package behavior:

```php
use Jkudish\MailMirror\Exceptions\SyncBudgetExhausted;
use Jkudish\MailMirror\Read\SyncWorkBudget;

$budget = new SyncWorkBudget(
    maxHttpRequests: 200,
    maxListedIds: 2000,
    maxFetchedMessages: 100,
    maxDownloadedBytes: 100 * 1024 * 1024,
    maxElapsedSeconds: 15 * 60,
);

try {
    $result = app(MailImportEngine::class)->syncChangesAccount(
        mailAccountId: $account->id,
        ownerType: 'user',
        ownerId: $user->getKey(),
        pageLimit: 10,
        repairPageLimit: 2,
        budget: $budget,
    );
} catch (SyncBudgetExhausted $failure) {
    $safeCode = SyncBudgetExhausted::SAFE_CODE; // budget_exhausted
    $dimension = $failure->dimension;
    $usage = $failure->snapshot;
}

$usage = $budget->snapshot();
```

The default constructor values are the limits shown above. The same mutable
object covers provider change pages, every HTTP retry and Gmail token refresh,
listed message IDs, message retrieval attempts, and any nested expired-cursor
inventory repair and baseline replay. `snapshot()` and the exception snapshot
contain only scalar counters and limits; they contain no account, message, or
credential data. A budget exception does not reset a durable cursor: work
committed before it remains resumable.

HTTP attempts and fetched-message attempts are reserved before work starts. An
exactly consumed HTTP, listed-ID, fetched-message, or downloaded-byte allowance
rejects the next network request. Gmail `maxResults` and JMAP
`limit`/`maxChanges` are clamped to the remaining listed-ID allowance, although
one Gmail history record can still expand into multiple message IDs; every
returned page is therefore validated and charged before persistence.
All HTTP requests needed to retrieve an admitted final message remain allowed;
the fetched-message boundary closes when that retrieval returns or throws.

Budgeted credentialed requests do not follow redirects, and each request
timeout is the smaller of provider configuration and the remaining elapsed
allowance. With Guzzle's normal cURL handler, its native `progress` callback
aborts at the first reported downloaded-byte count beyond the remaining
allowance. Guzzle does not specify callback granularity, its stream handler
ignores callback return values, compressed transport bytes can differ from the
decoded body, and Laravel test fakes do not drive transfer progress. The final
buffered body length is therefore still charged as a fallback. This is not a
strict wire-byte guarantee: a response can overshoot until the cURL callback or,
without cURL progress support, until buffering completes. Provider request
timeouts and raw-message decoded-size limits remain independent bounds; JSON
responses have no separate per-response byte cap.

Custom readers keep their existing API and unlimited behavior, but must
implement `BudgetedMailboxReader` (and `BudgetedDeltaMailboxReader` for deltas)
before accepting a finite budget.

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

The package migration creates `mail_delta_checkpoints`,
`mail_delta_pending_messages`, and `mail_source_changes`; existing inventory
APIs and tables remain compatible. Deploy the migration before calling
`syncChangesAccount()` or `SourceChangeService`.

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

## Change mailbox state

Mailbox state such as read, starred, archived, labels, Trash, and Spam is
provider state, so changing it is a provider write. Writes are off by default:
set `mail-mirror.writes.enabled` (`MAIL_MIRROR_WRITES_ENABLED`) to true in each
environment that may write. While it is off, every write throws
`MailWriteFailure` with `writes_disabled` and `writeSent` false before any
database or provider access.

Authorize the owner and approve the action in your application first, then
apply exactly one change to exactly one message with the full owner tuple:

```php
use Jkudish\MailMirror\Enums\MailWriteOutcome;
use Jkudish\MailMirror\Exceptions\MailWriteFailure;
use Jkudish\MailMirror\Write\MailboxChange;
use Jkudish\MailMirror\Write\MailWriteService;
use Jkudish\MailMirror\Write\MailWriteTarget;

$result = app(MailWriteService::class)->apply(new MailWriteTarget(
    mailAccountId: $account->id,
    ownerType: $account->owner_type,
    ownerId: $account->owner_id,
    providerMessageId: $providerMessageId,
), MailboxChange::archive());

$result->outcome;          // Applied or AlreadyApplied
$result->providerEvidence; // provider-native state from the confirming re-read
```

A change never widens to a thread or conversation. To act on a conversation,
call `apply()` once per message and report each result.

| Change | Gmail request | Fastmail JMAP `Email/set` patch |
| --- | --- | --- |
| `markRead()` / `markUnread()` | `modify` removes / adds `UNREAD` | `keywords/$seen` true / null |
| `star()` / `unstar()` | `modify` adds / removes `STARRED` | `keywords/$flagged` true / null |
| `archive()` | `modify` removes `INBOX` | Inbox-role → Archive-role mailbox; requires Inbox |
| `unarchive()` | `modify` adds `INBOX`; refused in Trash or Spam | Archive-role → Inbox-role mailbox; requires Archive |
| `addContainer($id)` | `modify` adds a user label | adds a mailbox without a role |
| `removeContainer($id)` | `modify` removes a user label | removes a mailbox without a role; never the last one |
| `trash()` | `trash` | every current mailbox → Trash-role mailbox |
| `untrash()` | `untrash` (restores prior labels) | Trash-role → Inbox-role mailbox; requires only Trash |
| `spam()` | `modify` adds `SPAM`, removes `INBOX` | every current mailbox → Junk-role mailbox |
| `notSpam()` | `modify` adds `INBOX`, removes `SPAM` | Junk-role → Inbox-role mailbox; requires only Junk |

Gmail requests go to `users.messages.{modify,trash,untrash}` for the target
message only. Gmail evidence contains `label_ids` and `history_id`. JMAP
sends one `Email/set` update with `ifInState` from the pre-write read, and
resolves each role through exactly one mailbox with that role, or fails with
`ambiguous_mailbox_role`. JMAP evidence contains `mailbox_ids`, the
`<role>_mailbox_id` of each role used, `keywords` for keyword changes,
`container_id` for container changes, and `email_state`.

Containers are user labels or role-less mailboxes. Gmail system labels
(upper-case IDs such as `INBOX` or `CATEGORY_UPDATES`) and JMAP role mailboxes
fail with `unsupported_container`; use their dedicated change instead. An
unknown JMAP mailbox fails with `container_not_found`. A JMAP change that would
leave an email in no mailbox, or move it out of a role mailbox it is not only
in, fails with `unsupported_state` before any write.

`restoreFromTrash($target)` is `apply($target, MailboxChange::untrash())`.

Every write follows the same sequence:

1. Refuse with `writes_disabled` unless writes are enabled.
2. Resolve the account through the full owner tuple. A mismatch throws
   `AccountResourceMismatch` before any provider request.
3. Acquire a cache lock for the target message. The lock covers every write to
   that message in that account. If another write holds it, the call fails
   immediately with `target_busy` and `writeSent` false, before any provider
   request. The lock is held for at most `mail-mirror.writes.lock_seconds`.
4. Read the provider state. A message already in the change's destination
   state returns `AlreadyApplied` without writing, but only when the last
   write this package may have applied to that account's message was this
   exact change. Each message has one intent slot, so any later write,
   including the inverse change, replaces it. Otherwise it
   fails with `already_in_state` (`not_in_trash` for `untrash()`, as before).
5. Send one write with transport retries off. The package records the intent
   only when the write may have applied, and clears it when the provider
   definitively did not apply it.
6. Re-read the provider state. Only a re-read in the destination state
   returns `Applied`. A re-read that fails for any reason throws `unconfirmed`.

Any other outcome throws `MailWriteFailure`. Its `safeCode` is one of
`writes_disabled`, `message_not_found`, `already_in_state`, `not_in_trash`,
`ambiguous_mailbox_role`, `unsupported_container`, `container_not_found`,
`unsupported_state`, `provider_failed`, `target_busy`, `lock_expired`,
`unconfirmed`, or `unsupported_driver`. `providerCode` carries the underlying
provider classification when one exists.

`writeSent` is true when the provider may have applied the write: the request
was sent and the provider did not answer with a 4xx status or a JMAP
method-level rejection. Call the same change again to confirm it without a
second write. `writeSent` is false when nothing was sent or the provider
definitively rejected the write. A Gmail 401 on the write refreshes the token
but does not re-send the write, and reports `writeSent` false.

## Manage provider drafts

MailMirror stores drafts at the provider from RFC 5322 bytes your application
builds; it does not build MIME. Draft writes use the same write switch,
owner-tuple check, lock, single write, and confirming re-read as mailbox
changes. Draft reads (`draft()`, `resolveDraft()`) need no write switch.

```php
use Jkudish\MailMirror\Write\DraftContent;
use Jkudish\MailMirror\Write\DraftTarget;
use Jkudish\MailMirror\Write\DraftUploadSession;
use Jkudish\MailMirror\Write\MailAccountTarget;

$content = new DraftContent($rfc5322Bytes); // throws invalid_draft
$target = new MailAccountTarget($account->id, $account->owner_type, $account->owner_id);

// Gmail only: prepare metadata, not MIME. Persist the checkpoint and immutable
// input hash/thread under your durable operation key BEFORE authorizing upload.
$session = $writes->prepareDraftUpload($target, $operationKey, $content, $providerThreadId);
$checkpoint = $session->checkpoint(); // encrypted sensitive recovery state
// Your draft aggregate durably stores $checkpoint here; outside provider calls.
$restoredSession = DraftUploadSession::fromCheckpoint($checkpoint);
$created = $writes->createDraft($target, $content, $providerThreadId, $restoredSession);
// Retry/recover with that SAME checkpoint, input and thread, never prepare again.

// JMAP only: existing Message-ID recovery, no upload-session checkpoint needed.
// $created = $writes->createDraft($target, $content);
$draft = $created->draft; // DraftRevision: draftId, providerMessageId, messageId, threadId, rawSha256, revision

$replaced = $writes->replaceDraft(new DraftTarget($account->id, $account->owner_type, $account->owner_id, $draft->draftId), $draft->revision, $newContent);
$writes->deleteDraft(new DraftTarget(/* ... */ $replaced->draft->draftId), $replaced->draft->revision);
```

`DraftContent` requires a CRLF-delimited header section with well-formed
fields, at most one of each RFC 5322 single-instance field, and exactly one
`Message-ID: <local@domain>`. Bytes larger than
`mail-mirror.writes.max_draft_bytes` fail `draft_too_large`. Both failures
happen before any provider request.

Gmail assigns its own Message-ID, so an empty client-Message-ID lookup never
authorizes another create. Gmail creation without a checkpoint fails
`draft_upload_required` before any provider request. Preparation initiates a
resumable session with metadata only. `createDraft()` always queries that same
session first, returns its original draft when completed, or uploads only the
missing suffix positively reported by a 308/Range response. It makes no fresh
create or transparent transport retry. `Applied` means the session's draft was
confirmed, not necessarily that this call uploaded MIME.

The checkpoint is encrypted/authenticated with your Laravel encryption key and
binds the account/owner, operation key, exact input SHA-256/size, and requested
thread. Mismatches and invalid destinations fail `invalid_draft_upload` before
network access. Do not log the checkpoint or session URL, or serialize the
session into audits/jobs; PHP serialization refuses. Use `checkpoint()` and
`fromCheckpoint()` explicitly in authorized durable storage. Session URLs are
restricted to Google's HTTPS Gmail draft upload endpoint; redirects are off.

An expired/missing session (404/410), ambiguous Range, malformed completion or
failed upload/status request fails `draft_upload_unknown`, with `writeSent`
true because an earlier upload may have completed. Keep the checkpoint; never
prepare another session as recovery. If a draft ID is known but confirmation
fails, `MailWriteFailure::$draftId` preserves it for recovery or cleanup, not
success. Retrying the same completed session still re-reads meaningful content.

Consumers must durably bind one session to each operation and prevent a second
preparation after upload was authorized. The package stores no attempts or
tables and cannot prove that your checkpoint was persisted. Preparation lost
before checkpoint can be repeated only while no MIME upload was authorized.
jMail production draft wiring remains blocked on #3125's durable draft owner;
the approval engine is not a draft-create checkpoint.

JMAP retains the caller Message-ID idempotency key: the same bytes return
`AlreadyApplied` without a write, and different bytes fail `message_id_conflict`.
A replace rejects another draft using its Message-ID with different content.

Pass back the `revision` you read. A replace or delete of a draft whose
current revision differs fails `stale_revision` without writing. A
`DraftRevision` from a JMAP replace has a new `draftId`, because JMAP emails
are immutable; Gmail keeps the draft ID and changes `providerMessageId`.

`DraftRevision::$rawBytes` contains the exact MIME snapshot read from the
provider, including sensitive headers, message bodies, and attachments. Do not
serialize the whole revision into logs, audits, API responses, or jobs. Select
explicit fields for each destination; treat the raw bytes as sensitive mail
content and keep them within your application's authorized content boundary.

| Operation | Gmail | Fastmail JMAP |
| --- | --- | --- |
| create | resumable `drafts.create`: metadata preparation (optional `threadId`), checkpoint, status query, one remaining MIME upload | blob upload, then `Email/import` into the Drafts role with `$draft` and `$seen` |
| read | `drafts.get` with `format=raw` | `Email/get` plus blob download; must be in Drafts with `$draft` |
| resolve by provider message ID | `drafts.list` pages, then `drafts.get` | the email ID is the draft ID |
| replace | `drafts.update`, unconditional | `Email/import` with `ifInState`; after it is created, `Email/set destroy` of the old email with `ifInState` set to the import's `newState` |
| delete | `drafts.delete` | `Email/set destroy` with `ifInState` |

Gmail has no update precondition. MailMirror compares the revision under its
lock and sends one update; an edit made elsewhere between that read and the
update is detected by the confirming re-read and fails `revision_conflict`
with `writeSent` true. Such an edit can be lost; re-read the draft before
retrying.

A create and a replace that use the same Message-ID serialize on a
Message-ID lock (a replace takes it before its draft lock); the second fails
`target_busy`.

Content identity is provider-specific. JMAP stores an imported blob unchanged,
so the raw SHA-256 and Message-ID of a re-read identify the bytes written.
Gmail confirmation permits a generated top-level Message-ID, added top-level
Received fields, field-name case/order and outer field whitespace, and equivalent
Date formatting. Date supports an optional weekday, day/month/four-digit year,
time with seconds, and numeric timezone offset or GMT/UT; it validates the date
and compares the instant. Unsupported or invalid Dates fail confirmation, never
get dropped. From, recipients (including Bcc), Reply-To, Subject, threading,
arbitrary X-* and MIME headers remain significant. Ambiguous duplicate fields
fail; body bytes (including every attachment and nested message header) must
match exactly. Transfer-encoding transformations are unsupported, not decoded
or normalized to make them pass.

These comparison allowances never change exact provider revisions: returned
Message-ID, raw bytes, raw SHA-256 and revision are the actual readback. A retry
of a possibly applied Gmail replace confirms the known draft ID's meaningful
content only with the recorded exact attempt intent, without Message-ID search
or another update. Changed content or lost intent fails stale revision. Gmail's
accepted unconditional update race is unchanged.

A JMAP replace interrupted after the import and before the destroy leaves both
drafts. Retrying the same replace with the same revision and bytes finishes it:
it destroys the old draft without importing again.

## Send a draft

Approve the send in your application first, and keep your own durable record
of the attempt; MailMirror keeps no send history. Then submit the draft at the
revision the approver saw:

```php
$result = $writes->submit(new DraftTarget($account->id, $account->owner_type, $account->owner_id, $draft->draftId), $draft->revision);

$result->outcome;           // SubmissionOutcome::Submitted
$result->providerMessageId; // the sent message (Gmail) or email (JMAP)
$result->threadId;
```

Before sending, MailMirror re-reads the draft under the draft lock. A changed
revision fails `stale_revision`. The draft's single From address must match,
case-insensitively, exactly one identity mirrored for the account by the last
import; otherwise the call fails `identity_mismatch` before any submission
request. Gmail sends one `drafts.send` carrying the exact bytes read at the
approved revision, so the approved content and From go out even if the draft
is edited in Gmail during the submit; that concurrent edit is lost. Gmail
deletes the draft on success. JMAP sends one `EmailSubmission/set` with that
identity's `identityId` and `onSuccessUpdateEmail`, which moves the email from
Drafts to Sent and removes `$draft`; JMAP emails are immutable, so the email
ID pins the approved bytes. Neither request is retried. `Submitted` is returned
only after a re-read shows the message sent.

When a submit fails with `writeSent` true, the provider may have sent it.
MailMirror records that and refuses every later `submit()` of the draft with
`submission_unknown`, without any provider request. The record lives in the
cache; if it is evicted, `submit()` still refuses when the provider links a
submission to the draft's ID (a JMAP `EmailSubmission` that is `pending` or
`final`, searched page by page). A search that reaches its page bound before
it is exhausted, or whose `queryState` changes between pages, also refuses.
Every submit makes that read first.

`reconcileSubmission($target, $revision, $messageId)` only reads, and answers
`submitted` or `unknown`:

| Evidence | Outcome |
| --- | --- |
| JMAP `EmailSubmission` for the email ID, `pending` or `final` | `submitted` (`matchedBy` `provider_id`) |
| Sent message with the Message-ID, draft gone | `submitted` (`matchedBy` `message_id`) |
| Sent message with the Message-ID, draft still exists (any revision) | `unknown` |
| No match, or a submission search that reached its bound or changed `queryState` | `unknown` |

Absence is never proof that nothing was sent: a provider may destroy
submission records after sending (RFC 8621), may rewrite Message-IDs, and a
timed-out request may still be in flight. `submitted` clears the recorded
attempt; `unknown` never does. Durable at-most-once and any decision to send
again after `unknown` belong to your application's approval process, with a
human decision; MailMirror never re-sends during reconciliation. Cache eviction
or expiry under `mail-mirror.writes.intent_ttl_seconds` only removes the
temporary guard; it never authorizes another submission. Keep durable attempt
state in your application and require new explicit approval for any resend,
even if both the cached intent and provider evidence are gone. While the intent
exists, a newly approved resend needs a new draft (new draft ID and Message-ID).

### Concurrency and cache dependencies

Writes use your application's default cache store for both the per-message lock
and the write intent. The store must support atomic locks, as the `redis`,
`database`, `file`, `array`, and `dynamodb` stores do. Otherwise the call throws
`LogicException` before any provider request. Use a store shared by every
process that can write, or the lock cannot serialize them.

Concurrent writes to the same message do not wait. One proceeds and the others
fail with `target_busy`; retry them after the first finishes. Writes to
different messages or accounts do not contend.

Before the write, the driver's `prepareWrite()` does all slow work, such as
refreshing a Gmail token. The write is then sent only while the lock has
enough time left for the single write request, bounded by that driver's
`timeout_seconds`, plus five seconds. When the read and preparation run too
long, the call fails with `lock_expired` and `writeSent` false before sending,
so a writer that later takes the lock always reads state after this write
finished. The effective lock is `lock_seconds`, raised to twice the send step
when configured shorter; retry `lock_expired` like `target_busy`.

If the intent is lost, a retry of an already applied change fails closed
with `already_in_state` (`not_in_trash` for a restore) instead of reporting
success. This happens when the cache
evicts the intent, when it expires under `mail-mirror.writes.intent_ttl_seconds`,
or when a process stops between the provider write and recording the intent.
If a lock outlives a stopped process, the target stays busy until the lock
expires.

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
