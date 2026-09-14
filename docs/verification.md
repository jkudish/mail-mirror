# Verify MailMirror

Normal verification uses synthetic data, Laravel HTTP fakes, and disabled
provider drivers. It does not contact Gmail, Fastmail, a mailbox, or a paid
service.

## Local checks

Run the complete local suite:

```bash
composer verify
```

This runs:

- strict Composer validation and dependency audit;
- Pint formatting checks;
- Larastan/PHPStan analysis;
- the Pest suite and architecture rules;
- a clean Laravel 13 consumer install, migration, and synthetic import.

Focused commands are also available:

```bash
composer test
composer analyse
composer lint:check
composer consumer:check
```

## PostgreSQL tests

The package suite uses in-memory SQLite by default. To run feature tests against
a disposable PostgreSQL database, set `MAIL_MIRROR_TEST_POSTGRES=1` and the
`MAIL_MIRROR_TEST_POSTGRES_*` connection variables before running Pest.

The harness runs `migrate:fresh` before each test. Never point it at a shared or
persistent database.

## Compatibility checks

These scripts build disposable dependency graphs and consumer applications:

```bash
bash scripts/test-laravel-13-suite.sh
bash scripts/test-prefer-lowest.sh
bash scripts/test-compatibility.sh 12 stable
bash scripts/test-compatibility.sh 12 lowest
bash scripts/test-consumer-install.sh 13
bash scripts/test-consumer-install.sh 12
```

Together they cover Laravel 12 and 13 with stable and lowest-supported
dependencies. Consumer checks install a non-symlinked local package copy, run
migrations, and prove that a repeated synthetic import converges without
provider access.

## Pull-request verification

Maintainers run this from a clean branch based on the current `origin/main`:

```bash
composer pr:check
```

The command runs the full compatibility matrix, quality checks, workflow tests,
shell syntax checks, and a final clean-worktree check. It writes a mode-`0600`
receipt for the exact commit under `.git/mail-mirror/pr-check/`.

Any commit change invalidates the receipt. Signoff requires review and approval
of the full verified SHA. Verification and signoff do not authorize merge,
publication, release, provider access, or deployment.
