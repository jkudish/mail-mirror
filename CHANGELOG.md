# Changelog

All notable changes to MailMirror will be documented in this file.

The project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Breaking

- Provider writes are off by default. Set `mail-mirror.writes.enabled`
  (`MAIL_MIRROR_WRITES_ENABLED`) to true to allow them; otherwise every write,
  including `restoreFromTrash()`, throws `writes_disabled` before any database
  or provider access.
- `TrashRestoreDriver` and `TrashState` are replaced by `MailboxMutationDriver`
  and `MessageState`. Custom drivers implement `messageState()` and
  `applyChange()` for a `MailboxChange` instead of `trashState()` and
  `restoreFromTrash()`.
- Gmail `createDraft()` now requires a `DraftUploadSession` as its fourth
  argument; the one-shot form refuses before network access. Call
  `prepareDraftUpload(target, operationKey, content, thread)` and durably persist
  its encrypted `checkpoint()` before authorizing MIME upload. Restore with
  `DraftUploadSession::fromCheckpoint()` and recover only through that SAME
  session. Expiry, absence, ambiguous status, or lost completion never initiate
  another create. No package persistence; consumers own durable attempts.

### Added

- `MailWriteService::apply(MailWriteTarget, MailboxChange)`: exactly one
  reversible change to exactly one provider message — mark read or unread,
  star or unstar, archive or unarchive, add or remove one label or mailbox,
  move to or out of Trash, and move to or out of Spam. Gmail calls only
  `users.messages.modify`, `trash`, or `untrash`, never a threads endpoint.
  Fastmail JMAP sends one `Email/set` patch with `ifInState`; archive and spam
  resolve exactly one Archive- or Junk-role mailbox. `restoreFromTrash()` is
  now `apply()` with `MailboxChange::untrash()` and keeps its behavior,
  evidence, and intent key. Each message has one intent slot naming the last
  change this package may have applied, so a retry returns `AlreadyApplied`
  only when no later write replaced it.
- Provider drafts from caller-built RFC 5322 bytes (`DraftContent`):
  `MailWriteService::createDraft()`, `draft()`, `resolveDraft()` (by provider
  message ID, including drafts started outside the consumer),
  `replaceDraft()`, and `deleteDraft()`. Each returns or takes a
  `DraftRevision` whose opaque `revision` must still match before a replace or
  delete writes anything; a mismatch fails `stale_revision`. JMAP keeps the
  caller Message-ID create idempotency key. Gmail uses resumable draft upload
  preparation, status and one remaining MIME transfer, plus get (`format=raw`),
  update, and delete. Gmail confirms meaningful content while permitting only
  generated top-level Message-ID/added Received, equivalent supplied Date
  instants, and Date delegation when omitted;
  exact provider bytes and revisions remain unchanged. Changed recipients
  (including Bcc), From, threading, MIME headers, body/attachment bytes or Date
  instant when supplied fail `revision_conflict`. JMAP uploads a blob and uses
  `Email/import` into the Drafts role with `$draft` and `$seen`; a replace imports the new email
  and, only after it is created, destroys the old one, each guarded by
  `ifInState`, and a retry finishes an interrupted replace.
- An omitted top-level Date delegates selection to Gmail, including during
  same-session recovery, known-ID/exact-intent replacement retry and later valid
  Date-only edits. Both header maps are validated first; malformed, empty,
  unsupported or duplicate Dates still refuse. Supplied Dates keep exact-instant
  protection, without tolerance or automatic stripping. Omission on replacement
  does not preserve the old Date and cannot distinguish generated Dates from
  later edits. Nested Dates and raw revisions remain exact; consumers must bind
  send approvals to the returned revision, not the delegated comparison.
- Gmail replace recovery confirms the known draft ID under its recorded exact
  attempt intent, not a client Message-ID search. Nondelegated external edits or
  lost intent still fail stale revision; a missing known ID fails draft-not-found
  without adopting another matching draft. JMAP's Message-ID recovery remains.
  The unconditional Gmail update race remains.
- Draft upload sessions store only encrypted URI ciphertext in memory, including
  real property dumps. Raw capability/MIME arguments are sensitive in resumable
  failure traces; URL/Response-bearing transport exceptions are not retained.
  Existing version-1 checkpoints still restore the same session.
  Google's optional nonempty scalar `session_crd` is accepted alongside required
  upload parameters and preserved as part of the sensitive session capability;
  duplicate, unknown, empty and array-valued parameters remain rejected.
- Provider writes use a fresh single-execution ext-cURL base handler, one native
  execution and one Laravel attempt, retaining HTTP middleware/fakes. Gmail
  resumable/mailbox/draft/send writes and JMAP upload/import/set/submission writes
  use the fixed verified-TLS (1.2 minimum, 1.3 permitted) HTTP/1.1 profile with a
  monotonic total timeout, forward-only bodies, no redirects/reuse/auth
  negotiation/proxies/early data.
  A seekable body gets one initial rewind after inspection middleware, never
  during native execution/retry; advanced nonseekable bodies refuse. A stats
  callback failure cannot mask an earlier budget abort.
  Writes require ext-cURL with asynchronous DNS; unsupported transport options
  refuse without fallback or replay. Reads retain their existing transport.
- Draft upload codes `draft_upload_required`, `invalid_draft_upload`, and
  `draft_upload_unknown`. Known-ID confirmation failures expose
  `MailWriteFailure::$draftId` as recoverable evidence, never success.
- `MailWriteService::submit()` sends one draft exactly once, only at the
  expected revision and only when its single From address matches exactly one
  mirrored identity (`identity_mismatch` otherwise). Gmail sends one
  `drafts.send` carrying the bytes approved at that revision, so a concurrent
  edit in Gmail cannot change what goes out; JMAP sends one
  `EmailSubmission/set` with `identityId` and `onSuccessUpdateEmail` (Drafts
  to Sent, `$draft` removed). Neither retries transport, and success is
  confirmed by re-reading the sent message.
- `MailWriteService::reconcileSubmission()` answers `submitted` or `unknown`
  (`SubmissionOutcome`) from provider reads only; absence of evidence is never
  reported as not sent. A possibly sent submit blocks further submits of that
  draft with `submission_unknown` until reconciliation answers `submitted`,
  and a provider submission linked to the draft's ID (JMAP, paginated, with a
  bound and `queryState` check that also refuse) blocks it even if that cached
  record is lost. Cache eviction or TTL expiry only removes the temporary
  guard, never authorizes another submission. Durable attempt state and new
  explicit approval for any resend belong to the consumer's approval engine.
- `DraftRevision::$rawBytes`: the exact bytes the read found, containing
  sensitive MIME. Consumers must select explicit fields for serialization,
  audits, and jobs instead of serializing the whole revision.
- `mail-mirror.writes.max_draft_bytes` (25 MiB) and draft codes
  `invalid_draft`, `draft_too_large`, `draft_not_found`, `stale_revision`,
  `revision_conflict`, `message_id_conflict`, `identity_mismatch`, and
  `submission_unknown`.
- Write codes `writes_disabled`, `already_in_state` (the destination state
  already held and this package recorded no intent for it),
  `unsupported_container` (a Gmail system label or JMAP role mailbox passed as
  a container), and `container_not_found` (an unknown JMAP mailbox).

- `MailWriteService::restoreFromTrash()`, the first provider write. Gmail
  untrashes one message; Fastmail JMAP moves one email from the Trash-role to
  the Inbox-role mailbox. Writes resolve the owner tuple first, serialize
  writes to the same message with a cache lock, send once without transport
  retries, and confirm by re-reading the restore destination. A retry returns
  `AlreadyApplied` only when this package may have applied the earlier write.
- `mail-mirror.writes.intent_ttl_seconds` and `mail-mirror.writes.lock_seconds`
  configuration. Writes require a default cache store that supports atomic
  locks. A concurrent write to the same message fails with `target_busy`.
- `MailImportFailure::$httpStatus`, the provider HTTP status when one exists.
- `lock_expired`: a write is sent only while its lock still covers the single
  write request, and a configured lock shorter than twice that step is raised.
- `TrashRestoreDriver::prepareWrite()`: drivers do slow pre-send work, such as
  a Gmail token refresh, before the lock deadline check. The Gmail write no
  longer refreshes the token itself.

### Changed

- A JMAP write whose response reports a new session state drops the cached
  session, so the confirming read rediscovers it.
- An unrecognized JMAP method-error type on a write now counts as possibly
  applied (`writeSent` true). Only RFC 8620's definite rejections count as not
  applied.

## [0.1.0] - 2026-09-14

The first public release.

### Added

- Account-scoped Laravel models and migrations for mirrored accounts,
  messages, threads, containers, attachments, checkpoints, and reconciliation
  reports.
- Read-only Gmail and Fastmail JMAP drivers with bounded, resumable imports and
  provider-state reconciliation.
- Encrypted OAuth and API-token credential storage with explicit connection
  lifecycle transitions.
- Integrity-checked private storage for RFC 822 sources and materialized
  attachments.
- Provider-deletion evidence, recovery notifications, and explicit local purge
  operations.
- Consumer, ownership, provider-development, verification, security, and
  release documentation.

### Compatibility

- PHP 8.4 and 8.5.
- Laravel 12 and 13, tested with stable and lowest-supported dependency graphs.
- PostgreSQL for production; SQLite for tests and local development.

[Unreleased]: https://github.com/jkudish/mail-mirror/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/jkudish/mail-mirror/releases/tag/v0.1.0
