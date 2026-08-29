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
provider, mailbox, queue, credential, or paid-service access. High-cardinality
reconciliation, hostile failure metadata, immutable message timestamps, and
configured database-connection cases also use only deterministic inline values
invented for these tests.

`gmail-driver.json` was invented for task #3206 on 2026-08-29. It is a compact
synthetic representation of Gmail API response shapes, not a recorded,
downloaded, transformed, or structurally copied mailbox response. Its profile,
messages, draft, labels, history, send-as identities, signature, addresses,
IDs, pagination token, and timestamps are fictional; all addresses use reserved
`.test` domains. Tests combine it with the already synthetic RFC822 fixture and
Laravel HTTP fakes. No fixture contains an OAuth code, client secret, access
token, refresh token, personal data, or provider-derived mailbox content.

`jmap-driver.json` and the JMAP driver test responses were invented for task
#3207 on 2026-08-29. They are compact synthetic JMAP-shaped data, not recorded,
downloaded, transformed, or structurally copied mailbox responses. All account,
session, state, query, message, thread, mailbox, identity, blob, part, and
pagination IDs are fictional. Tests combine them with the synthetic RFC822
fixture and Laravel HTTP fakes while stray requests are prohibited. No fixture
contains an API token, provider-derived mailbox content, or personal data.
The RFC 8621 null-array, empty-keyword, and empty-content cases are original
minimal examples written from the public field contracts; they are not copied
from a provider response or mailbox.
