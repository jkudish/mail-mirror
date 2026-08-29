# Exact-SHA workflow

The invariant is:

> current clean HEAD + current `origin/main` + successful receipt for that SHA
> and plan + resolved signoff authority + open PR at that SHA

Signoff authority is resolved by either explicit human approval of the full SHA
or explicit prospective delegation for defined work, acceptance gates, and the
signoff consequence. Prospective delegation lets the lead agent approve only the
exact SHA it reviewed and verified after every delegated gate passes. It is not
blanket repository authority: a changed objective, scope, acceptance gate, or
consequence requires new human authorization.

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

Merge is a separate consequence. Merge only with explicit authority for that
consequence, whether granted for the exact SHA or prospectively for the defined
work after named gates pass.
