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
