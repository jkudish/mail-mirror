# MailMirror

MailMirror is a private, standalone Laravel package foundation. It provides an
account-rooted, provider-neutral persistence schema and read-only driver
contracts plus immutable raw-message and materialized-attachment byte storage,
without provider integrations, credentials, import orchestration, search, or
provider mutation APIs.

Mail accounts may optionally belong to a consumer model through a polymorphic
owner. Provider-derived records are isolated by account-qualified identifiers
and composite account/parent foreign keys. Drivers are selected with the closed
`MailDriver` enum (`gmail` and `jmap`); consumers register read adapters in
`MailDriverRegistry` with enum keys rather than arbitrary strings.

## Development

```bash
./.agents/setup
composer verify
```

The package supports PHP 8.4–8.5 and Laravel 12–13. PostgreSQL is the supported
runtime database; SQLite is supported for package tests and local consumers.
The migration fails closed on other database drivers.

The package [ownership and boundary contract](docs/architecture/ownership-and-boundaries.md)
defines mail accounts as the roots for provider-derived records while keeping
consumer ownership optional and consumer-neutral. Future storage, credential,
import, reconciliation, and provider work must add its concrete isolation tests
alongside the implementation.

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
object finalization succeeds but the database commit fails, the unreferenced
content-addressed object is left for an identical retry to adopt safely; it
cannot collide with different bytes. Missing materialized attachments can be
regenerated from the verified, unchanged RFC 822 raw source using stable MIME
part paths.

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
for approval, dedicated-token signoff, required status read-back, and authority
boundaries.
