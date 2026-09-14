# MailMirror package guidance

MailMirror is a standalone Laravel package under the `Jkudish\\MailMirror`
namespace. Keep the package independent of consumer applications.

## Boundaries

- Put package code in `src/` and package tests in `tests/`.
- Follow the package [ownership and boundary contract](docs/architecture/ownership-and-boundaries.md).
  Provider-derived records are scoped through a mail account; consumer
  applications remain responsible for authorizing their own owners.
- Add concrete same-account and cross-account tests, plus cross-owner tests when
  a consumer owner applies, in the task that introduces each account-scoped record
  or boundary. Do not add passing architecture tests for classes or tables that
  do not exist.
- Do not add provider credentials, mailbox access, schema, storage, imports,
  reconciliation, queues, HTTP integrations, or UI without an approved design.
- Never use real mailbox data or secrets in fixtures or tests.
- Keep Laravel 12 and 13 compatibility unless a deliberate change is approved.

## Verification

- `composer test`: Pest tests and architecture rules.
- `composer analyse`: Larastan/PHPStan at maximum level.
- `composer lint:check`: Pint formatting check.
- `composer consumer:check`: clean Laravel 13 consumer migration and synthetic import proof.
- `composer verify`: all package checks.
- `composer pr:check`: exact-commit orb verification and local receipt.
- `composer pr:signoff -- --approved-sha <sha>`: approved exact-SHA signoff.

Before creating, updating, or signing off a pull request, load the project-local
`verifying-pull-requests` skill. GitHub Actions and enforced rulesets are
intentionally absent. Signoff requires either explicit human approval of the
full verified SHA or explicit prospective delegation for the defined work and
consequence. Under prospective delegation, the lead agent may approve only the
exact SHA it reviewed and verified after all delegated gates pass. A commit
change invalidates the evidence and requires review and verification of
the new SHA; a changed objective, scope, gate, or consequence invalidates the
delegation. Always read the GitHub status back at the exact SHA. Verification
and signoff do not authorize merge, publication, release, deployment, or
provider access; each consequence needs its own explicit authority, which may
also be delegated prospectively.
