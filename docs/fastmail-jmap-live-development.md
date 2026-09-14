# Check a Fastmail JMAP development account

This script makes live Fastmail requests. Run it only with explicit approval
and a dedicated, non-personal development account. Normal tests use HTTP fakes
and keep JMAP disabled.

## What the check reads

The check reads the JMAP Session resource, mailboxes, identities, and email
inventory. It does not retrieve message or attachment bytes, run an import,
submit mail, change provider data, or access a personal or production mailbox.

Output contains counts, completion state, and a short hash of the provider
account ID.

## Prepare the account

Create a Fastmail API token outside the repository. Inject it through the
consumer application's secret manager and store it as an `ApiTokenCredential`
with `MailAccountConnection`.

Never put the token in a command, shell history, fixture, issue, log, or report.

## Run the check

From a clean MailMirror checkout, run:

```bash
APP_ENV=local \
MAIL_MIRROR_JMAP_ENABLED=true \
MAIL_MIRROR_JMAP_LIVE_OPT_IN='I_UNDERSTAND_THIS_CONTACTS_'"FASTMAIL" \
MAIL_MIRROR_JMAP_CONSUMER_ROOT=/absolute/path/to/disposable-consumer \
MAIL_MIRROR_JMAP_ACCOUNT_ID='<non-secret database ID>' \
MAIL_MIRROR_JMAP_OWNER_TYPE='<consumer morph type>' \
MAIL_MIRROR_JMAP_OWNER_ID='<consumer owner ID>' \
php scripts/fastmail-jmap-live-development-check.php
```

Success starts with `Live Fastmail JMAP read check passed` and reports only
bounded metadata. The script exits with status 2 if any safety gate, owner
value, or driver does not match.

The script is intentionally absent from Composer verification commands. Its
explicit opt-in does not replace approval to contact Fastmail.
