# Changelog

All notable changes to MailMirror will be documented in this file.

The project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

## [0.1.0] - 2026-09-14

The first public release.

### Added

- Account-scoped Laravel models and migrations for mirrored accounts,
  messages, threads, containers, attachments, checkpoints, and reconciliation
  reports.
- Read-only Gmail and Fastmail JMAP drivers with bounded, resumable imports and
  provider-state reconciliation.
- Encrypted OAuth and API-token credential storage with explicit connection
  lifecycle transitions.
- Integrity-checked private storage for RFC 822 sources and materialized
  attachments.
- Provider-deletion evidence, recovery notifications, and explicit local purge
  operations.
- Consumer, ownership, provider-development, verification, security, and
  release documentation.

### Compatibility

- PHP 8.4 and 8.5.
- Laravel 12 and 13, tested with stable and lowest-supported dependency graphs.
- PostgreSQL for production; SQLite for tests and local development.

[Unreleased]: https://github.com/jkudish/mail-mirror/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/jkudish/mail-mirror/releases/tag/v0.1.0
