# Synthetic fixture provenance

Every fixture in this directory was written for MailMirror's tests. None was
recorded, downloaded, transformed, or derived from a real mailbox or provider
response.

The fixtures contain invented people, addresses, messages, attachments,
identifiers, metadata, credentials, and tokens. Email addresses use the
reserved `.test` suffix. Credential probes use values prefixed with
`synthetic-`; they do not match the structure of real credentials.

`gmail-driver.json` and `jmap-driver.json` are compact representations of the
public Gmail and JMAP response contracts. The Gmail profile, labels, history,
send-as identities, messages, and pagination values are fictional. The JMAP
account, session, state, query, mailbox, identity, blob, message, part, and
pagination values are also fictional.

Tests combine these JSON files with `synthetic-message.eml`, inline synthetic
records, deterministic readers, and Laravel HTTP fakes. Stray HTTP requests are
blocked. The suite does not contact a provider, mailbox, network service, or
paid service.

The minimal null-array, empty-keyword, and empty-content cases were written from
the public RFC 8621 field contracts. They were not copied from provider data.
