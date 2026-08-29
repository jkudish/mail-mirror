# MailMirror

MailMirror is a private, standalone Laravel package foundation. It currently
registers only its service provider. It contains no provider integrations,
credentials, import logic, reconciliation, schema, or storage behavior.

## Development

```bash
./.agents/setup
composer verify
```

The package supports PHP 8.4–8.5 and Laravel 12–13.

The package [ownership and boundary contract](docs/architecture/ownership-and-boundaries.md)
defines mail accounts as the roots for provider-derived records while keeping
consumer ownership optional and consumer-neutral. Future schema, storage,
credential, and driver work must add its concrete isolation tests alongside the
implementation.

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
