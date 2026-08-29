# Optional GitHub setup

This repository starts with manual, non-enforced orb signoff. Do not enable a
ruleset, branch protection, or GitHub Actions unless separately authorized.

Install `basecamp/gh-signoff` pinned at `v0.4.1` in the verification environment. Store a separate
fine-grained token as the Amp project secret `GH_SIGNOFF_TOKEN`, scoped only to
`jkudish/jmail` and `jkudish/mail-mirror`, with Pull requests read and Commit
statuses read/write. Metadata access is implicit; Contents access is not needed.
The combined Amp project intentionally supplies this one credential to both
checkouts, and each repository uses it only to inspect its matching pull request
and publish the approved exact-SHA status. Candidate verification never receives
this token.

The future enforcement commands below change shared repository state and are
not authorized by ordinary verification:

```bash
gh signoff install --branch main
gh signoff check --branch main
gh signoff contexts --branch main
```
