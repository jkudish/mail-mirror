# Exact-SHA workflow

The invariant is:

> current clean HEAD + current `origin/main` + successful receipt for that SHA
> and plan + explicit approval of the full SHA + open PR at that SHA

`composer pr:check` runs the ordered plan in a disposable, credential-free home.
It rejects dirty state, stale `origin/main`, and changes to HEAD or the worktree.
It writes an atomic mode-`0600` receipt at
`.git/mail-mirror/pr-check/<sha>.json`.

`composer pr:signoff -- --approved-sha <sha>` validates the full SHA, current
receipt and plan hash, dedicated `GH_SIGNOFF_TOKEN`, installed
`basecamp/gh-signoff`, and matching open PR. It rechecks clean HEAD immediately
before running only `gh signoff --commit <sha>`.

After publication, read back the commit status from GitHub and verify its SHA,
context, and successful state. Treat any mismatch as blocking. Never use force.
