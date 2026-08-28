# MailMirror package guidance

MailMirror is a standalone Laravel package under the `Jkudish\\MailMirror`
namespace. Keep the package independent of jMail and consumer applications.

## Boundaries

- Put package code in `src/` and package tests in `tests/`.
- Do not add provider credentials, mailbox access, schema, storage, imports,
  reconciliation, queues, HTTP integrations, or UI without an approved design.
- Never use real mailbox data or secrets in fixtures or tests.
- Keep Laravel 12 and 13 compatibility unless a deliberate change is approved.

## Verification

- `composer test` — Pest tests and architecture rules.
- `composer analyse` — Larastan/PHPStan at maximum level.
- `composer lint:check` — Pint formatting check.
- `composer consumer:check` — clean Laravel 13 consumer install proof.
- `composer verify` — all package checks.
- `composer pr:check` — exact-commit orb verification and local receipt.
- `composer pr:signoff -- --approved-sha <sha>` — approved exact-SHA signoff.

Before creating, updating, or signing off a pull request, load the project-local
`verifying-pull-requests` skill. GitHub Actions and enforced rulesets are
intentionally absent. Signoff requires explicit human approval of the full
verified SHA and a GitHub status read-back. Verification and signoff do not
authorize merge, publication, release, deployment, or provider access.
