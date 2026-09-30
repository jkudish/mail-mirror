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
  delete writes anything; a mismatch fails `stale_revision`. The Message-ID is
  the create idempotency key: a retry finds the existing draft and never
  creates a second one. Gmail uses `users.drafts` create, get (`format=raw`),
  update, and delete; a confirming read with other bytes than those written
  fails `revision_conflict`. JMAP uploads a blob and uses `Email/import` into
  the Drafts role with `$draft` and `$seen`; a replace imports the new email
  and, only after it is created, destroys the old one, each guarded by
  `ifInState`, and a retry finishes an interrupted replace.
- `mail-mirror.writes.max_draft_bytes` (25 MiB) and draft codes
  `invalid_draft`, `draft_too_large`, `draft_not_found`, `stale_revision`,
  `revision_conflict`, and `message_id_conflict`.
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
