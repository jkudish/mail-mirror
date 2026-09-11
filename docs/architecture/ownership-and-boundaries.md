# MailMirror ownership and boundaries

Status: canonical package contract.

MailMirror is a consumer-neutral Laravel package. It owns provider connections,
provider adapters, mirroring and reconciliation, normalized and provider-native
records, and raw source and attachment persistence. It does not own a consumer's
users, authorization policy, UI, search, AI, MCP tools, approvals, or actions.

This document constrains future schema and driver work without defining those
interfaces ahead of their implementation tasks.

## Ownership roots

A mail account is the root for all provider-derived state. It may optionally
belong to a consumer-defined owner through a polymorphic relation. This allows a
standalone, closed single-owner installation to omit a consumer owner while
allowing jMail or another consumer to attach its own owner model without making
MailMirror depend on that model.

The owner association is identified by both morph type and owner ID. Consumers
register a stable morph alias and constrain both values; a null owner means no
consumer owner and never satisfies a consumer-owner scope. Once attached, the
owner association is immutable outside a future explicit transfer workflow.

Every provider-derived identity, thread, message, address, header, container,
attachment, raw-source reference, inventory item, deletion proof, import error,
checkpoint, and reconciliation report must carry a direct `mail_account_id` or have an
unambiguous account-scoped parent path. Provider IDs are never globally unique
or sufficient authorization by themselves. Records that rely on a parent path
are queried through that path so the account constraint is applied in the query.
The account association and any account-scoped parent association are immutable
after creation.

When a consumer owner is present:

- MailMirror preserves the account-to-owner association but does not decide who
  may act as that owner.
- Consumer code authorizes its owner before invoking MailMirror and passes the
  authorized account or scalar identifiers through an explicit contract.
- MailMirror rejects mismatched account/resource tuples rather than relying on
  post-query filtering.

## Boundary rules

| Concern | Package rule |
| --- | --- |
| Queries and uniqueness | Provider records, lookup keys, and uniqueness constraints are qualified by mail account. |
| Jobs and events | Carry scalar account and resource identifiers. Never serialize credentials into a job or event payload. Serialized consumer owner models or authenticated users are never authority. |
| Cache keys and locks | Begin with a mail-account namespace before any provider or resource identifier. |
| Object storage | Namespace raw sources and attachments by account. MailMirror resolves an object only for an explicitly supplied mail account and rejects a key belonging to another account; the consumer separately authorizes its owner. Key secrecy is not authorization. |
| Credentials | Credentials remain behind the provider connection boundary and never enter consumer models, job/event payloads, caches, logs, exceptions, or browser/MCP payloads. |
| Raw sources | Preserve the original account-scoped source as immutable. Parsing, OCR, indexes, and consumer projections are separate replaceable derivations. |
| Import state | One account-qualified checkpoint owns the current stable scan ID, opaque cursor, version, and progress. Complete provider-neutral hydration and object work commit before its cursor advances. Completed proof is a database-protected immutable metadata-only report with SQL counts and bounded opaque samples. |
| Failures and deletion | Successful imports create no error row and resolve the prior episode, including a waiver. A later episode reopens without inheriting that waiver. Provider deletion evidence is scan-qualified, invalidated by reappearance, and emits a scalar committed-state hook; it does not authorize retention, quarantine, or purge behavior. |
| Local purge | A consumer chooses eligibility and timing. The package operation requires the account/owner tuple and current checkpoint version, locks the account and occurrence, preserves references through filesystem failure, removes package-owned normalized occurrence data, and fences stale import/materialization with a minimal tombstone. Shared records remain while referenced. It never runs automatically or writes to a provider. |
| Consumer integration | Expose package contracts and events without importing `App` or another consumer namespace. Consumer class names do not appear as strings, config defaults, or stored morph values; consumers register aliases instead. |
| UI, MCP, AI, policy, and approvals | Remain consumer concerns and do not enter the package. |

Persistence, filesystem, HTTP, mail, and queue dependencies are valid package
implementation tools when an approved task requires them. Architecture rules
must prohibit ownership violations, not prohibit MailMirror from implementing
its accepted responsibilities.

## Required downstream tests

The task that introduces each account-scoped record or boundary must add real
tests against its concrete implementation. Do not add tests that pass because a
planned model, table, adapter, job, event, cache, or object store is absent.

Using only synthetic fixtures, prove every applicable case:

1. A same-account operation succeeds through the public package boundary.
2. A second account cannot read, mutate, reconcile, or retrieve the first
   account's resource or object key, even when both accounts share the same
   provider ID.
3. Database uniqueness, cache/lock names, and object keys do not collide across
   accounts.
4. A job or event with a mismatched account/resource tuple fails without side
   effects.
5. Credentials do not escape the connection boundary through serialization,
   logs, exceptions, events, or consumer-facing payloads.
6. Raw source is immutable and derivations can be replaced without changing it.
7. Consumer owner scope matches both morph type and owner ID, and an ownerless
   account never matches that scope.
8. Attempts to change a record's mail account, account-scoped parent, or attached
   consumer owner fail without side effects.

Each implementation task records which cases apply and why any remaining case
does not. The owner-relation task also proves that package code and configuration
do not hardcode a consumer class name.

The schema and driver-contract task owns the first account and provider-record
tests. Storage, credentials, import, reconciliation, and provider tasks own the
additional proofs introduced by their behavior.

## Current executable guards

The architecture suite rejects concrete class dependencies from package code to
consumer application classes and consumer-owned UI, MCP, AI, authorization, and
approval layers. It cannot detect consumer class names hidden in strings or
configuration, so the owner-relation task owns that executable source and
configuration check when those artifacts exist.

## Non-goals

This contract does not add schema, models, provider SDKs, credentials, mailbox
access, imports, storage behavior, queues, UI, or outbound actions. It does not
require public tenancy or consumer owner administration. Those remain separate,
explicitly authorized implementation tasks.
