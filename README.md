# MailMirror

MailMirror is a private, standalone Laravel package foundation. It provides an
account-rooted, provider-neutral persistence schema and read-only driver
contracts without provider integrations, credentials, import orchestration,
object bytes, search, or mutation APIs.

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

The package supports PHP 8.4–8.5 and Laravel 12–13.

The package [ownership and boundary contract](docs/architecture/ownership-and-boundaries.md)
defines mail accounts as the roots for provider-derived records while keeping
consumer ownership optional and consumer-neutral. Future storage, credential,
import, reconciliation, and provider work must add its concrete isolation tests
alongside the implementation.

The package automatically registers its config and migration. Applications can
publish them with Laravel's conventional `vendor:publish` commands. Models use
the application's default database connection unless
`mail-mirror.database_connection` selects another configured connection.

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
