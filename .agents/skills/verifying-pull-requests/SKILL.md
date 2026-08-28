---
name: verifying-pull-requests
description: Verifies MailMirror pull requests in an Amp orb and posts trusted exact-SHA signoff. Use before creating, updating, or signing off a pull request.
---

# Verifying Pull Requests

Use the repository-owned commands. Do not reconstruct or bypass the plan.

1. Run `./.agents/setup` in an unprepared orb.
2. Commit the intended changes and require a clean worktree.
3. Run `composer pr:check`.
4. Record the full SHA and receipt path printed by the command.
5. Create or update the pull request without signoff.
6. Ask the human to review and explicitly approve that full SHA.
7. After approval, run `composer pr:signoff -- --approved-sha <full-sha>`.
8. Read the status back from GitHub and confirm it targets the same SHA.

A new commit invalidates the approval, receipt, and signoff. Never copy or edit a
receipt. Never force signoff.

Read [references/workflow.md](references/workflow.md) for fail-closed invariants,
[references/coverage.md](references/coverage.md) before describing the proof,
and [references/setup.md](references/setup.md) only for separately authorized
one-time GitHub setup.

Verification and signoff do not authorize merge, publication, release,
deployment, production access, provider calls, or spend.
