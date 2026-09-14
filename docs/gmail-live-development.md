# Check a Gmail development account

This script makes live Gmail requests. Run it only with explicit approval and a
dedicated, non-personal development account. Normal tests use HTTP fakes and
keep Gmail disabled.

## What the check reads

The check may read the account profile, labels, send-as identities, history,
and message inventory. It does not read message bodies, run an import, send or
delete mail, change mailbox state, or revoke the grant. An expired access token
may be refreshed and stored through `MailAccountConnection`.

Output contains counts, completion state, and a short hash of the provider
account ID. Do not use a personal or production mailbox. Never put credentials
in a command, shell history, issue, log, fixture, or report.

## Prepare the account

In a disposable Laravel 12 or 13 application:

1. Configure a Google OAuth client outside the repository.
2. Request exactly `https://www.googleapis.com/auth/gmail.modify`. Do not add
   full-mail, settings, compose, or send scopes.
3. Store OAuth state and the PKCE verifier in a short-lived server-side session.
   Pass the S256 challenge to `GmailOAuth::authorizationUrl()` and the verifier
   to `GmailOAuth::exchange()`.
4. Store the returned `OAuthTokenSetCredential` through
   `MailAccountConnection` for an owned Gmail account.
5. Inject the client ID, client secret, and redirect URI through your secret
   manager as `MAIL_MIRROR_GMAIL_CLIENT_ID`,
   `MAIL_MIRROR_GMAIL_CLIENT_SECRET`, and
   `MAIL_MIRROR_GMAIL_REDIRECT_URI`.

The client secret must come from the process environment. MailMirror does not
copy it into Laravel's configuration array or cache.

## Run the check

From a clean MailMirror checkout, use a secret-safe environment injector and
run:

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

Success starts with `Live Gmail read check passed` and reports only bounded
metadata. The script exits with status 2 if any safety gate, owner value, or
driver does not match.

Laravel's HTTP client materializes provider JSON before MailMirror can inspect
decoded fields. Your application must bound the HTTP response body. MailMirror
separately bounds pages, cursors, history, headers, MIME parts, identities, and
encoded raw messages.
