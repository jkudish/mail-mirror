# Fastmail JMAP live development-account check

MailMirror's ordinary tests, verification, consumer installs, and CI use only
Laravel HTTP fakes and keep `mail-mirror.jmap.enabled` false. They cannot opt
into this separately named, direct-only lane. Run it only after explicit human
authorization against a dedicated non-personal development account.

The check performs JMAP Session discovery plus Mailbox, Identity, and Email
inventory reads. It does not retrieve message or attachment bytes, run the
import engine, submit mail, write or destroy any provider object, mutate a
mailbox, or access a personal or production mailbox. Output contains only
counts, completion state, and a truncated hash of the provider account ID.

Provision a Fastmail API token outside the repository, inject it through a
consumer application's secret manager, and store it as an `ApiTokenCredential`
through `MailAccountConnection`. Never paste a token into a command, shell
history, fixture, issue, log, or report.

Run the script directly from a clean MailMirror checkout; it is intentionally
absent from Composer scripts and verification. Set `APP_ENV=local`, explicitly
enable the JMAP driver, set the live opt-in environment variable to the exact
acknowledgement required by the script, and supply these non-secret locators:

```bash
APP_ENV=local \
MAIL_MIRROR_JMAP_ENABLED=1 \
MAIL_MIRROR_JMAP_LIVE_OPT_IN='I_UNDERSTAND_THIS_CONTACTS_FASTMAIL' \
MAIL_MIRROR_JMAP_CONSUMER_ROOT=/absolute/path/to/disposable-consumer \
MAIL_MIRROR_JMAP_ACCOUNT_ID='<non-secret database ID>' \
MAIL_MIRROR_JMAP_OWNER_TYPE='<consumer morph type>' \
MAIL_MIRROR_JMAP_OWNER_ID='<consumer owner ID>' \
php scripts/fastmail-jmap-live-development-check.php
```

The script refuses to run unless every gate is present, the consumer boots as
`local`, JMAP is explicitly enabled, and the durable account, owner tuple, and
JMAP driver all match. This lane is documented but must not be executed without
separate provider-access authorization.
