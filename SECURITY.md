# Security policy

## Report a vulnerability

Email security reports to [joey@jkudish.com](mailto:joey@jkudish.com) with the
subject `MailMirror security report`.

Include:

- the affected version or commit;
- the conditions needed to reproduce the issue;
- the security impact;
- a minimal reproduction or suggested fix, when available.

Do not include credentials, tokens, real email, provider responses, object
contents, or another person's data. Use synthetic examples and describe how to
share any sensitive supporting material safely.

Please allow time to confirm the report and prepare a fix before public
disclosure.

## Security boundaries

MailMirror stores encrypted provider credentials and private email objects.
Consumer applications still own user authentication, owner authorization,
secret management, storage-disk access, retention policy, and HTTP response
limits.

See the [ownership and boundary contract](docs/architecture/ownership-and-boundaries.md)
for the package's trust boundaries.
