# Synthetic fixture provenance

The Gmail-shaped and JMAP-shaped JSON fixtures in this directory were written
for MailMirror's contract tests. They contain no copied mailbox data, provider
responses, credentials, tokens, or personal information. Every person, address,
message body, identifier, and metadata value is invented; all domains use the
reserved `.test` suffix. The ordinary test suite reads these local files only
and makes no network, provider, mailbox, or paid-service calls.
