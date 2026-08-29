# Synthetic fixture provenance

The Gmail-shaped and JMAP-shaped JSON fixtures and `synthetic-message.eml` in
this directory were written for MailMirror's contract tests. They contain no
copied mailbox data, provider responses, credentials, tokens, or personal
information. Every person, address, message body, attachment, identifier, and
metadata value is invented; all domains use the reserved `.test` suffix. The
ordinary test suite reads these local files only and makes no network, provider,
mailbox, or paid-service calls.

Credential lifecycle tests use only inline values prefixed with
`synthetic-3097-`. They were invented for this package test suite on 2026-08-29
and are not structurally derived from a real token, credential, account, or
person. Nested OAuth access/refresh probes and API-token probes intentionally
exercise secret-containment behavior.

Import and reconciliation tests use only inline identifiers and metadata
prefixed with `synthetic`, `invented`, `opaque`, or generic `message-*` values.
They were authored for task #3096 on 2026-08-29. The deterministic reader is an
in-memory test double: pagination, duplicates, retry/rate-limit failures,
malformed payloads, state changes, deletion evidence, crashes, stale
checkpoints, resolution, waiver, and incomplete scans perform no network,
provider, mailbox, queue, credential, or paid-service access.
