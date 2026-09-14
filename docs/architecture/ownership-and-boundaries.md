# MailMirror ownership and boundaries

Status: canonical package contract.

MailMirror owns provider connections, read adapters, mirroring,
reconciliation, normalized records, provider-native metadata, raw messages, and
attachments. The consumer owns users, authorization, UI, search, AI, tools,
approvals, retention policy, and provider write operations.

## Ownership roots

A mail account is the root for all provider-derived state. It may belong to a
consumer model through a polymorphic relation. A closed, single-owner
installation may leave the owner unset.

The owner association consists of a morph type and owner ID. Consumers must
register a stable morph alias and constrain both values. A `null` owner does not
match an owner scope. Once attached, the owner cannot change without a future
transfer workflow.

Every provider-derived record must have a direct `mail_account_id` or an
unambiguous account-scoped parent path. This includes identities, threads,
messages, addresses, headers, containers, attachments, object references,
inventory, deletion evidence, errors, checkpoints, and reconciliation reports.

Provider IDs are not globally unique and do not grant access. Queries must
apply the account constraint before returning a record. Account and
account-scoped parent associations cannot change after creation.

When an owner is present:

- The consumer authorizes the owner before calling MailMirror.
- Public operations receive the authorized account or its scalar account and
  owner identifiers.
- MailMirror rejects mismatched account and resource tuples in the query.

## Boundary rules

| Concern | Rule |
| --- | --- |
| Queries and uniqueness | Qualify provider records, lookup keys, and uniqueness constraints by mail account. |
| Jobs and events | Carry scalar account and resource IDs. Serialized owners and authenticated users do not grant authority. Never include credentials. |
| Cache keys and locks | Start with a mail-account namespace before any provider or resource ID. |
| Object storage | Namespace objects by account. Resolve an object only for the supplied account. The consumer separately authorizes the owner. |
| Credentials | Keep credentials behind `MailAccountConnection`. Do not expose them through models, jobs, events, caches, logs, exceptions, or browser payloads. |
| Raw messages | Preserve the original account-scoped source as immutable. Treat parsing, indexes, and consumer projections as replaceable derivations. |
| Import state | Store one resumable checkpoint per account. Commit hydration and object work before advancing its cursor. Record completed proof in an immutable metadata-only report. |
| Failures | Resolve the previous error episode after a successful import. A later episode does not inherit an old waiver. |
| Provider deletion | Qualify evidence by scan and invalidate it when the message reappears. Evidence does not authorize retention, quarantine, or purge. |
| Local purge | Require the account, owner tuple, and checkpoint version. Preserve references after filesystem failure. Fence stale imports with a tombstone. Never write to the provider. |
| Consumer integration | Expose contracts and events without importing `App` or another consumer namespace. Consumers register morph aliases. |

MailMirror may use Laravel persistence, filesystem, HTTP, and queue services to
perform its work. These rules constrain ownership and trust boundaries, not
implementation tools.

## Required tests

The change that introduces an account-scoped record or boundary must add tests
against the concrete implementation. Use synthetic fixtures and cover every
applicable case:

1. A same-account operation succeeds through the public package API.
2. A second account cannot read or change the first account's record, even when
   both accounts share the same provider ID.
3. Database constraints, cache keys, locks, and object keys do not collide
   across accounts.
4. A mismatched account and resource tuple fails without side effects.
5. Credentials do not escape through serialization, logs, exceptions, events,
   or consumer-facing payloads.
6. A raw message remains immutable while its derivations can be replaced.
7. Owner scope matches both morph type and owner ID. An ownerless account never
   matches that scope.
8. Attempts to change an account path, account-scoped parent, or attached owner
   fail without side effects.

Record why an inapplicable case does not apply. Do not add tests that pass only
because a planned model, table, adapter, job, event, cache, or object store does
not exist.

## Executable guards

Architecture tests reject package dependencies on consumer application classes
and consumer-owned UI, AI, authorization, and approval layers. These tests
cannot detect consumer class names hidden in strings or configuration, so each
owner-related change must test those surfaces directly.

## Non-goals

This contract does not add provider write APIs, consumer UI, search, retention
policy, owner administration, or outbound actions. Each remains a separate
consumer responsibility or package change.
