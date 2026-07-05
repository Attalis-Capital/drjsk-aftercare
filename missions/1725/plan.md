# Plan: Revision Pickup — PR #8 Review 4623648295 (#1700-P2)

Generated: 2026-07-05T00:00:00Z
Planner model: claude-opus-4-7
Mission tier: AMBER

## Deviation (amended 2026-07-05, Generator: claude-opus-4-8)

The plan assumed PR #8 was **open** with 7 addressable CHANGES_REQUESTED items on
a live branch to push follow-up commits to. On pickup, three facts contradicted
that premise and force a documented deviation (per mission rule: amend plan.md
with rationale before deviating):

1. **PR #8 is already merged and closed** (squash-merged to `main` as `0e8ab67`).
   Its head branch `infra/1723-railway-staging` cannot be "updated" and pushing to
   it would neither reopen the PR nor honestly reflect state. Per CLAUDE.md git
   strategy (PR-only, feature branch off `main`), the deliverable moves to a
   **new feature branch + new PR** referencing #8 and the review.
2. **The review contains 3 actionable items, not 7** (1 BLOCKER + 2 VERIFY, plus 2
   explicit "no change requested" notes; 0 inline comments). Reconciled per this
   plan's own Risk #7 before proceeding.
3. **All 3 items are already resolved in merged `main`** (evidence with file/line
   in `missions/1725/revision-response.md`): BLOCKER → `docker/uploads.ini`
   (25M/26M) wired into `Dockerfile.railway`; VERIFY 1 → existence-guard around
   `db:seed` in `docker/railway-entrypoint.sh`; VERIFY 2 → no GD code path exists.

**Deviation:** produce no net-new code change to the reviewed surface (fabricating
one would misrepresent `main`). Instead deliver a self-contained, verifiable
revision-response artifact (`missions/1725/revision-response.md`) that embeds the
review, reconciles the count, and cites each resolution — plus this amendment and a
CHANGELOG entry. Council mods P1–P4 are addressed inside that artifact.

The original plan below is retained verbatim for the record.
Target repo: Attalis-Capital/drjsk-aftercare

## Architecture

This mission is a revision pickup: the Generator must clone the target repository, check out the branch associated with PR #8, fetch the full review body for review ID 4623648295 (submitted by shinny77), and address all seven CHANGES_REQUESTED items. The work is purely reactive — no new feature design is required. The Generator modifies existing code on the PR branch and pushes follow-up commits.

Because the wiki page for `drjsk-aftercare` was not found and the repo has not yet been cloned, the Generator must begin with a discovery phase: clone the repo, read the PR diff, read the review comments, and understand the codebase structure before making any changes. Each review item should be addressed in a minimal, focused manner — no scope creep beyond what the reviewer requested.

The architectural constraint is simple: the Generator works on the existing PR branch, makes incremental commits (one per logical review item or a small group of related items), and pushes to the same branch so the PR updates in place. No new branches, no rebasing, no force-pushing.

Key files and paths are unknown until the repo is cloned and the review body is read. The Generator must use `gh pr view 8` and `gh api` to retrieve the review comments, then map each comment to the relevant file and line range.

## Risk Register

### 1. Review body retrieval failure

**Likelihood:** Medium
**Impact:** Blocks entire mission — cannot address items without knowing what they are.
**Mitigation:** Use `gh api repos/Attalis-Capital/drjsk-aftercare/pulls/8/reviews/4623648295/comments` to fetch individual review comments. Fall back to `gh pr view 8 --comments` if the review API endpoint fails. If both fail, abort the mission cleanly with a status report rather than guessing at changes.

### 2. Misinterpreting a review comment

**Likelihood:** Medium
**Impact:** Produces a change that does not satisfy the reviewer, requiring another revision cycle.
**Mitigation:** For each review item, the Generator should quote the reviewer's comment verbatim in the commit message and explain what was changed and why. Where a comment is ambiguous, prefer the most conservative interpretation (smallest change that satisfies the request). If a comment is genuinely unclear, leave a reply on the PR asking for clarification rather than guessing.

### 3. Merge conflicts or stale branch

**Likelihood:** Low-Medium
**Impact:** Push fails or introduces broken code.
**Mitigation:** After cloning, the Generator should check whether the PR branch is behind the base branch. If it is, rebase onto the base branch before making changes. Run any available test suite or linter after all changes are made, before pushing. Never force-push; if a conflict arises that cannot be resolved cleanly, report it and pause.

## Decomposition

The mission has more than five steps due to the seven review items plus setup and verification.

### Phase 1: Discovery

1. **Clone the repository.** `git clone` the `Attalis-Capital/drjsk-aftercare` repo and check out the PR branch (retrieve branch name via `gh pr view 8 --json headRefName`).

2. **Fetch the full review body.** Use `gh api repos/Attalis-Capital/drjsk-aftercare/pulls/8/reviews/4623648295/comments` to retrieve all seven CHANGES_REQUESTED items. Parse and list each item with its target file, line range, and requested change.

3. **Understand the codebase.** Read the project structure, any existing CLAUDE.md or README, build/test configuration, and the files touched by the PR diff (`gh pr diff 8`). Identify the test runner, linter, and any CI checks.

### Phase 2: Implementation

4. **Address each review item.** For each of the seven items, in the order they appear in the review:
   - Read the relevant file and surrounding context.
   - Make the requested change with minimal scope.
   - Stage and commit with a message that references the review comment (e.g., "Address review: <summary of request>").
   - If two or more items touch the same file in related ways, they may be combined into a single commit with a clear message covering both.

### Phase 3: Verification and Push

5. **Run tests and linters.** Execute whatever test suite and lint checks the project uses. Fix any failures introduced by the changes.

6. **Push the commits.** Push to the PR branch. Confirm via `gh pr view 8` that the PR reflects the new commits.

7. **Post a summary comment on the PR.** Leave a comment on PR #8 listing each review item and how it was addressed, so the reviewer can re-review efficiently.

## Dependencies

- **GitHub CLI (`gh`):** Required for fetching PR metadata, review comments, and posting replies. Must be authenticated with sufficient permissions to push to the PR branch and comment on the PR.
- **Git:** Required for cloning, branching, committing, and pushing.
- **Repository build/test tooling:** Unknown until the repo is cloned. The Generator must discover and use whatever tooling is present (e.g., `npm test`, `pytest`, `make test`, or similar).
- **Network access:** Required for cloning from GitHub and pushing commits.
- **No wiki dependency:** The wiki page was not found, so no external knowledge base is available. All context must come from the repo itself and the PR review.

## Verification Approach

1. **Per-item verification:** After addressing each review item, re-read the reviewer's comment and confirm the change satisfies it. If the comment included a specific code suggestion, verify the final code matches or improves upon it.

2. **Test suite:** Run the full test suite after all changes. All tests must pass. If the project has no tests, note this in the PR comment.

3. **Linter/formatter:** Run any configured linter or formatter. Fix all violations before pushing.

4. **Diff review:** Before pushing, run `git diff HEAD~N..HEAD` (where N is the number of new commits) and review the aggregate diff to ensure no unintended changes, no leftover debug code, and no regressions.

5. **PR state check:** After pushing, verify via `gh pr checks 8` that CI checks are passing (or at least triggered). If checks fail, investigate and fix before marking the mission complete.

6. **Comment audit:** Verify that the summary comment posted on the PR accounts for all seven review items — none missed, none misattributed.

7. **Count confirmation:** The review contained exactly seven CHANGES_REQUESTED items. The Generator must confirm it addressed exactly seven. If the count does not match after fetching the review, reconcile before proceeding.
