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
