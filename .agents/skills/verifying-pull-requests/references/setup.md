# Optional GitHub setup

This repository starts with manual, non-enforced orb signoff. Do not enable a
ruleset, branch protection, or GitHub Actions unless separately authorized.

Install `basecamp/gh-signoff` in the verification environment. Store a separate
fine-grained token as the Amp project secret `GH_SIGNOFF_TOKEN`, scoped only to
`jkudish/mail-mirror`, with Contents read, Pull requests read, and Commit
statuses read/write. Candidate verification never receives this token.

The future enforcement commands below change shared repository state and are
not authorized by ordinary verification:

```bash
gh signoff install --branch main
gh signoff check --branch main
gh signoff contexts --branch main
```
