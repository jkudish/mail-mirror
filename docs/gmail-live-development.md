# Gmail live development-account check

MailMirror's ordinary tests, verification, consumer installs, and CI use only
Laravel HTTP fakes and keep `mail-mirror.gmail.enabled` false. They cannot opt
into this lane. The lane is a separately named, direct script intended only for
a later, explicitly human-authorized check against a dedicated development
account.

## Safety boundary

The check performs profile, label, send-as identity, history, and/or message
inventory reads. It does not retrieve message bodies, run the import engine,
write mailbox state, send mail, create or update drafts, delete mail, or revoke
the provider grant. An expired access token may be refreshed and rotated through
the encrypted `MailAccountConnection` lifecycle. Output contains only counts,
completion state, and a truncated hash of the provider account ID.

Do not use a personal or production mailbox. Do not paste credentials or tokens
into a command, shell history, issue, log, fixture, or report.

## Preconditions

In a disposable Laravel 12 or 13 consumer application:

1. Configure a Google OAuth client outside the repository.
2. Configure exactly `https://www.googleapis.com/auth/gmail.modify`; never add
   `https://mail.google.com/`, settings scopes, compose, send, or other scopes.
3. Complete consent only after separate human authorization, then store the
   returned `OAuthTokenSetCredential` through `MailAccountConnection` for a
   Gmail `MailAccount` owned by an explicit consumer owner tuple.
4. Supply the client ID, client secret, and redirect URI through the consumer's
   secret manager as `MAIL_MIRROR_GMAIL_CLIENT_ID`,
   `MAIL_MIRROR_GMAIL_CLIENT_SECRET`, and
   `MAIL_MIRROR_GMAIL_REDIRECT_URI`. The client secret must be injected into the
   process environment; MailMirror intentionally never copies it into Laravel's
   configuration array or configuration cache.

## Explicit invocation

Run from a clean MailMirror checkout only after provider-access authorization.
Use a secrets-safe environment injector rather than literal secret values:

```bash
APP_ENV=local \
MAIL_MIRROR_GMAIL_ENABLED=true \
MAIL_MIRROR_GMAIL_LIVE_OPT_IN=I_UNDERSTAND_THIS_CONTACTS_GMAIL \
MAIL_MIRROR_GMAIL_CONSUMER_ROOT=/absolute/path/to/disposable-consumer \
MAIL_MIRROR_GMAIL_ACCOUNT_ID='<non-secret database ID>' \
MAIL_MIRROR_GMAIL_OWNER_TYPE='<consumer morph type>' \
MAIL_MIRROR_GMAIL_OWNER_ID='<consumer owner ID>' \
php scripts/gmail-live-development-check.php
```

The script refuses to run unless every gate is present, the consumer boots as
`local`, Gmail is explicitly enabled, and the account ID, owner tuple, and Gmail
driver all match durable state. This script is intentionally absent from all
Composer scripts and verification commands.
