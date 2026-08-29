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

Every provider-derived identity, thread, occurrence, address, header,
container, attachment, raw-source reference, sync cursor, checkpoint, and
provider-native record must carry a direct `mail_account_id` or have an
unambiguous account-scoped parent path. Provider IDs are never globally unique
or sufficient authorization by themselves.

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
| Jobs and events | Carry scalar account and resource identifiers. Never serialize consumer owner models, authenticated users, or credentials as authority. |
| Cache keys and locks | Begin with a mail-account namespace before any provider or resource identifier. |
| Object storage | Namespace raw sources and attachments by account. Authorize before resolving a key; key secrecy is not authorization. |
| Credentials | Credentials remain behind the provider connection boundary and never enter consumer models, events, logs, exceptions, or browser/MCP payloads. |
| Raw sources | Preserve the original account-scoped source as immutable. Parsing, OCR, indexes, and consumer projections are separate replaceable derivations. |
| Consumer integration | Expose package contracts and events without importing `App` or another consumer namespace. |
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
   account's resource, even when both accounts share the same provider ID.
3. Database uniqueness, cache/lock names, and object keys do not collide across
   accounts.
4. A job or event with a mismatched account/resource tuple fails without side
   effects.
5. Credentials do not escape the connection boundary through serialization,
   logs, exceptions, events, or consumer-facing payloads.
6. Raw source is immutable and derivations can be replaced without changing it.

The schema and driver-contract task owns the first account and provider-record
tests. Storage, credentials, import, reconciliation, and provider tasks own the
additional proofs introduced by their behavior.

## Non-goals

This contract does not add schema, models, provider SDKs, credentials, mailbox
access, imports, storage behavior, queues, UI, or outbound actions. It does not
require public tenancy or consumer owner administration. Those remain separate,
explicitly authorized implementation tasks.
