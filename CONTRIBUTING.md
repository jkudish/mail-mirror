# Contributing to MailMirror

MailMirror supports PHP 8.4 and 8.5 with Laravel 12 and 13.

## Set up the repository

Install PHP, Composer 2, and Node.js 24 or newer, then run:

```bash
./.agents/setup
```

The setup script installs Composer dependencies and the repository's pinned
pull-request tooling. It is safe to run again.

## Make a change

- Keep package code in `src/` and tests in `tests/`.
- Preserve account and owner isolation described in the
  [ownership contract](docs/architecture/ownership-and-boundaries.md).
- Add same-account and cross-account tests for each account-scoped boundary.
- Keep Laravel 12 and 13 compatibility unless the change says otherwise.
- Use synthetic `.test` data. Do not add mailbox data, provider responses,
  credentials, tokens, or personal information.
- Keep provider access disabled during normal development and verification.

Run the smallest relevant test while working. Before opening a pull request,
run:

```bash
composer verify
```

See [verification](docs/verification.md) for focused commands, database options,
and the full compatibility matrix.

## Live provider checks

The Gmail and Fastmail scripts contact external providers. They require
explicit approval and a dedicated development account. Do not run them as part
of a normal test or pull request.

- [Gmail development-account check](docs/gmail-live-development.md)
- [Fastmail JMAP development-account check](docs/fastmail-jmap-live-development.md)

## Pull requests

Keep each pull request focused. Explain contract or migration changes and any
action a consumer must take. Maintainers run `composer pr:check` against the
exact commit before signoff. See [verification](docs/verification.md).

Report vulnerabilities through [SECURITY.md](SECURITY.md), not a public issue.
