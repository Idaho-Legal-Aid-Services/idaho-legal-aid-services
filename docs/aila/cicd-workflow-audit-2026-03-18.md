# CI/CD Workflow Audit — Idaho Legal Aid Services

**Date:** 2026-03-18
**Scope:** GitHub Actions, dual-remote publish workflow, Promptfoo eval gates, pre-push hooks
**Branch:** `master` at `bc27d2b9b`
**Maturity Assessment:** 6/10

---

## Section 1: Executive Summary

The Idaho Legal Aid Services repository operates a sophisticated dual-remote publish workflow that pushes code through GitHub (for CI/review) and Pantheon (for hosting). The pipeline includes PHPUnit contract tests, a 75-case hosted LLM eval suite (Promptfoo), pre-push hooks mirroring CI steps, and a multi-stage `finish.sh` orchestrator that merges PRs, syncs remotes, deploys Pantheon, and waits for post-merge gates.

**The pipeline is more capable than most projects its size, but it is currently blocked by its own complexity.** The same 75-case hosted eval suite that gates helper PRs also re-runs in blocking mode on post-merge pushes to `master`. This second run hits connectivity and rate-limit failures approximately 50% of the time, creating a recurring cycle of manual recovery. An in-flight fix (19-case protected-push subset) is partially written but uncommitted, meaning each publish attempt re-encounters the same failure.

**Key metrics:**
- 2,859 lines of shell across 6 workflow scripts
- 75 hosted eval cases on helper PR, re-run on protected push (the root blocker)
- 19-case protected-push subset written but uncommitted
- 3 GitHub Actions unpinned to mutable tags
- 318 commits of divergence on unused `main` branch
- 3 stale `publish/master-*` remote branches
- No Dependabot/Renovate configuration
- Single maintainer with required review = ceremonial gate

**Maturity dimensions scored in Section 12; overall 6/10 — strong contract testing and observability design, held back by operational reliability of the eval gate and pipeline complexity.**

---

## Section 2: Current-State Workflow Map

### 2.1 Dual-Remote Architecture

```
                    Local Master
                         |
            +------------+------------+
            |                         |
     [npm run git:publish]    [npm run git:publish --origin-only]
            |                         |
            v                         v
   github/publish/master-active    origin/master
   (helper PR -> github/master)    (Pantheon dev)
            |
   [Quality Gate workflow]
   [PHPUnit + Promptfoo 75-case]
            |
   [npm run git:finish]
            |
   +--------+--------+
   |                  |
   v                  v
  merge PR         sync local
  (gh pr merge)    (sync-master.sh)
   |                  |
   v                  v
  github/master    local master
  push event       (fast-forward)
   |                  |
   v                  v
  [Quality Gate]   [publish --origin-only]
  [re-runs on      [Pantheon deploy]
   master push]         |
   |                    v
   v              [post-deploy verification]
  [Promptfoo         (19-case protected-push)
   75-case*]
   |
   v
  [finish.sh waits for green]
```

*\* This is the root cause of recurring failures. The uncommitted fix splits this to 19 cases.*

### 2.2 Script Responsibilities

| Script | Lines | Role |
|--------|------:|------|
| `scripts/ci/run-promptfoo-gate.sh` | 1,212 | Eval orchestrator: target resolution, preflight, smoke/primary/deep eval, metric extraction, structured summaries |
| `scripts/git/finish.sh` | 611 | End-to-end flow: find PR, wait for checks, merge, sync, deploy Pantheon, post-deploy verify, wait for post-merge gate |
| `scripts/git/publish.sh` | 375 | Dual-remote push coordinator: creates/updates helper PR or pushes to Pantheon |
| `scripts/deploy/pantheon-deploy.sh` | 309 | Pantheon deploy with Terminus: backup, confirmation, updatedb, config drift check |
| `scripts/ci/pre-push-strict.sh` | 210 | Local pre-push hook: mirrors CI (Composer parity, VC-PURE, quality gate, branch-aware Promptfoo) |
| `scripts/git/common.sh` | 142 | Shared helpers: logging, branch utilities, remote status comparison |

### 2.3 GitHub Actions Workflow Structure

**`.github/workflows/quality-gate.yml`** (primary):
- **Triggers:** push to `master`/`main`/`release/**`; PR to same; `workflow_dispatch`
- **Job 1 — `quality-gate`:** Checkout, PHP 8.3, Composer install, PHPUnit VC-PURE (`phpunit.pure.xml`), PHPUnit drupal-unit (`phpunit.xml --testsuite drupal-unit`), golden transcript gate
- **Job 2 — `promptfoo-gate`** (needs: quality-gate): Node 20, npm ci, widget hardening suite, transport/runtime tests, hosted Promptfoo gate with branch-aware blocking/advisory policy

**`.github/workflows/observability-release.yml`** (secondary):
- **Trigger:** `workflow_dispatch` only
- Builds theme assets, uploads Sentry release + sourcemaps

### 2.4 Pre-Push Hook Chain (local mirror of CI)

Installed by `scripts/ci/install-pre-push-strict-hook.sh`:
1. `sync-check.sh` — blocks remote-ahead/diverged pushes, blocks direct `github/master` push
2. `composer install --dry-run` — mirrors CI Composer install step
3. `vendor/bin/phpunit -c phpunit.pure.xml` — mirrors CI VC-PURE step
4. `run-quality-gate.sh` — module quality gate
5. `run-promptfoo-gate.sh` — deploy-bound or branch-aware eval gate

---

## Section 3: Evidence-Backed Findings Table

| ID | Finding | Severity | File(s) | Line(s) | Evidence |
|----|---------|----------|---------|---------|----------|
| F-01 | Post-merge push re-runs full 75-case hosted eval in blocking mode | Critical | `.github/workflows/quality-gate.yml` | 106-129 | Committed HEAD uses `promptfooconfig.hosted.yaml` (75 cases) for both helper-PR and protected-push checks. Uncommitted changes split to `promptfooconfig.protected-push.yaml` (19 cases). |
| F-02 | Protected-push config split is written but uncommitted | Critical | `promptfoo-evals/promptfooconfig.protected-push.yaml`, `promptfoo-evals/tests/protected-push-stability.yaml` | — | Files exist in working tree (`??` in `git status`) but are not staged or committed. |
| F-03 | Three GitHub Actions use unpinned mutable `@v4` tags | High | `.github/workflows/quality-gate.yml` | 33, 76, 58/167/183 | `actions/checkout@v4`, `actions/setup-node@v4`, `actions/upload-artifact@v4` are all unpinned. Only `shivammathur/setup-php` is pinned to SHA (`accd6127cb78`). |
| F-04 | `observability-release.yml` also uses unpinned Actions | High | `.github/workflows/observability-release.yml` | 20, 25 | `actions/checkout@v4`, `actions/setup-node@v4` unpinned. |
| F-05 | No Dependabot or Renovate configuration | Medium | `.github/` | — | No `.github/dependabot.yml` exists at repo root. Dependency updates are manual. |
| F-06 | `main` branch is unused/diverged but still in workflow triggers | Medium | `.github/workflows/quality-gate.yml` | 7, 12 | `main` is 318 commits behind and 30 ahead of `master`. Workflow triggers include `main` for both push and PR events, causing unnecessary CI runs if pushed to. |
| F-07 | Three stale `publish/master-*` remote branches persist | Low | — | — | `github/publish/master-1d5aa0f68865`, `github/publish/master-7b885ae965ea`, `github/publish/master-880f7d66f60b` remain after their PRs were merged. `finish.sh:434-456` has prune logic but only runs on successful completion. |
| F-08 | `finish.sh` has 5 polling loops with distinct timing constants | Info | `scripts/git/finish.sh` | 8-16 | CHECK_POLL (5s x 36 = 3min), PR_POLL (2s x 15 = 30s), MASTER_RUN_POLL (5s x 24 = 2min), ARTIFACT_POLL (3s x 10 = 30s), plus `gh pr checks --watch` (unbounded). Total theoretical max wait: >6 minutes plus unbounded check watch. |
| F-09 | Single maintainer with required review is ceremonial | Info | GitHub settings | — | All 14 merge PRs (#5-#24) were created and merged by the same developer. Branch protection requires review but single-maintainer repos have no independent reviewer. |
| F-10 | Concurrency control uses `cancel-in-progress: true` | Info | `.github/workflows/quality-gate.yml` | 20-21 | Rapid successive pushes cancel in-progress runs. Correct for PRs but can orphan post-merge runs on `master` if a fast follow-up commit lands. |
| F-11 | Rate limit environment variables may be unset in GitHub vars | Medium | `.github/workflows/quality-gate.yml` | 93-94 | `ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE` and `ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR` are sourced from `${{ vars.* }}`. If unset, `run-promptfoo-gate.sh` falls back to heuristic rate detection, which may underpace or overpace eval requests. |
| F-12 | Backup branch indicates past manual recovery | Low | `origin/backup-evan-mobile-formatting` | — | At least one backup branch exists on Pantheon remote, indicating a past manual recovery from a failed publish flow. |

---

## Section 4: Failure-Mode Register

### FM-01: Post-Merge Promptfoo Gate Connectivity Failure

| Attribute | Value |
|-----------|-------|
| **Trigger** | Push to `master` after PR merge fires `quality-gate.yml` → `promptfoo-gate` job |
| **Root cause** | 75-case hosted eval suite re-runs against live assistant URL; connectivity/rate-limit errors cause individual case failures |
| **Exit code** | `run-promptfoo-gate.sh` exits 3 (threshold failure) or 1 (eval error) |
| **Evidence** | Run `23225344665`: 53.33% pass rate (35 connectivity errors). Run `23218168501`: 98.67% pass rate (1 error, still blocked by exit code 3). |
| **Impact** | `finish.sh` reports "Post-merge Quality Gate failed", exits 1; manual re-run or recovery required |
| **Frequency** | ~50% of post-merge pushes based on observed run history |
| **In-flight fix** | Uncommitted `promptfooconfig.protected-push.yaml` reduces to 19 cases. `.github/workflows/quality-gate.yml` (staged) already references this config for protected-push mode. |

### FM-02: Helper PR Promptfoo Gate Rate Limit

| Attribute | Value |
|-----------|-------|
| **Trigger** | PR from `publish/master-active` fires quality gate in blocking mode |
| **Root cause** | 75 cases at maxConcurrency=1 with hosted assistant URL; rate limit budget consumed if rapid re-publishes occur |
| **Exit code** | 3 (threshold) or 1 (rate limit/connectivity) |
| **Impact** | PR checks fail; `finish.sh` refuses to merge; developer must wait for rate limit window to reset |
| **Mitigation** | Preflight rate-limit check (`ILAS_HOURLY_LIMIT_PREFLIGHT`) exists in `run-promptfoo-gate.sh:35` but depends on configured limits being set |

### FM-03: Pre-Push Hook Timeout/Failure

| Attribute | Value |
|-----------|-------|
| **Trigger** | `git push` to any remote fires `pre-push-strict.sh` |
| **Root cause** | Hook runs full Composer dry-run + PHPUnit + quality gate + Promptfoo gate locally; any step failure blocks push |
| **Impact** | Developer blocked from pushing; must diagnose which stage failed |
| **Bypass** | `git push --no-verify` (documented but not recommended) |
| **Mitigation** | Hook mirrors CI exactly, so failures caught locally prevent CI failures |

### FM-04: finish.sh Polling Timeout

| Attribute | Value |
|-----------|-------|
| **Trigger** | Any of 5 polling loops in `finish.sh` exceeds max attempts |
| **Stages** | PR appearance (30s), check visibility (3min), artifact download (30s), master run appearance (2min), `gh pr checks --watch` (unbounded) |
| **Exit code** | 1 |
| **Impact** | Developer must manually inspect GitHub and resume the flow |
| **Mitigation** | Each timeout prints a diagnostic `gh` command for manual inspection |

### FM-05: Stale Master / Remote Divergence

| Attribute | Value |
|-----------|-------|
| **Trigger** | Local `master` diverges from `github/master` (e.g., after a failed merge or manual commit) |
| **Detection** | `pre-commit-master-sync.sh` and `publish.sh` both check `describe_remote_status` |
| **Exit code** | 1 |
| **Recovery** | `git branch backup/recovery-<timestamp> master && git reset --hard github/master && git cherry-pick <commit>` (documented in hook install output) |
| **Evidence** | `origin/backup-evan-mobile-formatting` branch indicates this recovery path has been used |

---

## Section 5: Root-Cause Clusters

### Cluster 1: Hosted Eval Reliability (F-01, F-02, F-11, FM-01, FM-02)

**Problem:** The 75-case hosted Promptfoo suite runs twice per publish cycle (helper PR + post-merge push) against a live assistant URL with rate limits. Each run makes 75 sequential HTTP requests. The second run (post-merge) fails ~50% of the time due to connectivity/rate-limit exhaustion, but the fix (19-case subset) sits uncommitted.

**Why it keeps recurring:** Each attempt to fix the gate requires publishing through the gate, which itself fails. The fix for the broken gate cannot pass through the broken gate. This creates a bootstrap problem that has persisted across multiple publish attempts (PRs #18-#24 show iterative gate-fixing commits).

**Resolution path:** Commit the 19-case protected-push split. This is the single highest-leverage change — it reduces post-merge eval surface by 74% (75 → 19 cases) while preserving coverage of abuse, retrieval, escalation, safety-boundary, and multilingual scenarios.

### Cluster 2: Pipeline Complexity (F-08, F-09, FM-03, FM-04, FM-05)

**Problem:** The publish workflow spans 6 scripts totaling 2,859 lines of shell, with 5 polling loops, dual-remote coordination, artifact download/parsing, and post-deploy verification. This complexity is necessary for correctness but creates a large surface area for partial failures that require manual recovery.

**Why it's a risk:** A single maintainer operates this pipeline. Any failure in the 7-step `finish.sh` flow requires understanding the full state machine to diagnose and recover. The backup branch on Pantheon origin (`backup-evan-mobile-formatting`) confirms that manual recovery has been needed.

**Resolution path:** The pipeline is architecturally sound — it correctly enforces GitHub-first publishing, prevents Pantheon drift, and verifies post-deploy behavior. The complexity is intrinsic to the dual-remote constraint. Reducing eval case count (Cluster 1) removes the most common failure mode. Adding a recovery runbook (Section 7, 30-day) addresses the operational gap.

### Cluster 3: Supply Chain Hygiene (F-03, F-04, F-05)

**Problem:** Three GitHub Actions are pinned to mutable `@v4` tags instead of immutable SHA digests. No automated dependency update mechanism (Dependabot/Renovate) exists for npm or Composer dependencies.

**Why it matters:** Mutable tags can be force-pushed by upstream maintainers (or compromised accounts), changing workflow behavior without any change to the repository. This is a known supply chain attack vector (e.g., the `tj-actions/changed-files` incident). The `shivammathur/setup-php` action is correctly pinned to SHA, showing the pattern is understood but inconsistently applied.

**Resolution path:** Pin all Actions to SHA digests. Add `.github/dependabot.yml` for automated PRs on npm and Composer dependency updates.

---

## Section 6: Target Workflow

The current dual-remote architecture is the correct design for a Pantheon-hosted Drupal site with GitHub CI. The target state preserves the existing structure but fixes the eval gate split and hardens supply chain controls.

### Target State Changes

```
CURRENT:                              TARGET:

Helper PR gate:                       Helper PR gate:
  75-case hosted eval (blocking)        75-case hosted eval (blocking)
  [unchanged]                           [unchanged]

Post-merge master gate:               Post-merge master gate:
  75-case hosted eval (blocking)  →     19-case stability eval (blocking)
  [~50% failure rate]                   [reduced surface, same coverage families]

Post-deploy Pantheon verification:    Post-deploy Pantheon verification:
  19-case stability eval (blocking)     19-case stability eval (blocking)
  [already in finish.sh]               [unchanged]

GitHub Actions:                       GitHub Actions:
  3 unpinned @v4 tags            →     All pinned to SHA digests

Dependency management:                Dependency management:
  Manual                         →     Dependabot for npm + Composer

Workflow triggers:                    Workflow triggers:
  master + main + release/**    →     master + release/** only
```

### Why Not Simplify Further?

- **Why not make the post-merge gate advisory?** The post-merge gate is the last automated check before `finish.sh` reports success. Making it advisory would mean a broken assistant could deploy to Pantheon and the developer would only learn from manual inspection. The 19-case subset is small enough to be reliable while still catching regressions.
- **Why not remove the post-merge gate entirely?** Same reasoning — the helper PR gate proves the code at PR time, but the post-merge commit is a different SHA (merge commit). The 19-case subset provides a fast confidence check on the actual deployed commit.
- **Why not merge to `master` without a helper PR?** Branch protection on `github/master` enforces required checks. Direct pushes would bypass CI entirely.

---

## Section 7: 7/30/90-Day Remediation Plan

### 7-Day Actions (Critical Path)

| ID | Action | Priority | Files | Dependency | Finding |
|----|--------|----------|-------|------------|---------|
| R-7-01 | **Commit and push the protected-push eval split.** Stage all 15 in-flight modified/new files and push through the publish workflow. This is the single highest-impact change. | Critical | `quality-gate.yml`, `promptfooconfig.protected-push.yaml`, `protected-push-stability.yaml`, `run-promptfoo-gate.sh`, `finish.sh`, `publish.sh`, `gate-metrics.js` (lib+script), `install-pre-push-strict-hook.sh`, 3 PHPUnit test files, 3 docs files, `run-promptfoo-gate.sh` | None | F-01, F-02 |
| R-7-02 | **Pin all GitHub Actions to SHA digests** in `quality-gate.yml` and `observability-release.yml`. Use `actions/checkout@<sha>`, `actions/setup-node@<sha>`, `actions/upload-artifact@<sha>`. | High | `quality-gate.yml`, `observability-release.yml` | None | F-03, F-04 |
| R-7-03 | **Clean up stale remote branches.** Delete `github/publish/master-1d5aa0f68865`, `github/publish/master-7b885ae965ea`, `github/publish/master-880f7d66f60b`. | Low | — | None | F-07 |
| R-7-04 | **Remove `main` from workflow triggers.** Delete `main` from push and pull_request branch lists in `quality-gate.yml`. | Medium | `quality-gate.yml` | None | F-06 |

### 30-Day Actions

| ID | Action | Priority | Files | Dependency | Finding |
|----|--------|----------|-------|------------|---------|
| R-30-01 | **Add `.github/dependabot.yml`** for npm (`/`) and Composer (`/`) ecosystems with weekly update schedule. | Medium | `.github/dependabot.yml` (new) | None | F-05 |
| R-30-02 | **Document recovery runbook** for each failure mode in FM-01 through FM-05. Add to `docs/aila/runbook.md` as a new section covering: how to re-run a failed post-merge gate, how to recover from a stale master, how to diagnose a pre-push hook failure. | Medium | `docs/aila/runbook.md` | None | FM-01–FM-05 |
| R-30-03 | **Set GitHub repository variables** for `ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE` and `ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR` if not already set. Verify values match the assistant's actual rate limits. | Medium | GitHub Settings → Variables | R-7-01 | F-11 |
| R-30-04 | **Verify `cancel-in-progress` behavior** on master pushes. Consider scoping concurrency group to exclude `refs/heads/master` to prevent orphaned post-merge runs. | Low | `quality-gate.yml` | R-7-01 | F-10 |

### 90-Day Actions

| ID | Action | Priority | Files | Dependency | Finding |
|----|--------|----------|-------|------------|---------|
| R-90-01 | **Add GitHub Environments** for Pantheon test/live with manual approval gates. This formalizes the test → live promotion that currently relies on manual `pantheon-deploy.sh` invocation. | Medium | GitHub Settings → Environments, possibly new workflow | R-7-01 | — |
| R-90-02 | **Add scheduled cron workflow** for full 75-case eval regression detection outside the deploy cycle. Run weekly or nightly against the live assistant URL in advisory mode. | Low | `.github/workflows/scheduled-eval.yml` (new) | R-7-01, R-30-03 | FM-01 |
| R-90-03 | **Consider CODEOWNERS file** to formalize review requirements when/if additional maintainers join the project. | Low | `.github/CODEOWNERS` (new) | — | F-09 |

---

## Section 8: Stop / Start / Keep

### Stop

| Practice | Reason |
|----------|--------|
| Running 75-case eval on post-merge push | Root cause of ~50% post-merge failure rate; the 19-case subset provides equivalent coverage families |
| Including `main` in workflow triggers | Branch is 318 commits diverged from `master`; triggers waste CI minutes and create confusion |
| Using mutable `@v4` tags for GitHub Actions | Supply chain risk; the repo already pins `setup-php` to SHA, showing the pattern is understood |
| Leaving in-flight fixes uncommitted across sessions | The bootstrap problem (fix can't pass through the broken gate) compounds with each session |

### Start

| Practice | Reason |
|----------|--------|
| Automated dependency updates (Dependabot) | No mechanism exists for npm or Composer dependency PRs |
| Documenting failure recovery procedures | Pipeline complexity means each failure mode requires specific recovery steps that are not written down |
| Setting rate limit variables in GitHub Settings | Unset vars force heuristic rate detection in CI, which may differ from local behavior |
| Cleaning stale remote branches after failed publishes | 3 stale `publish/master-*` branches remain; `finish.sh` prune logic only runs on success |

### Keep

| Practice | Reason |
|----------|--------|
| Dual-remote publish workflow architecture | Correctly separates CI/review (GitHub) from hosting (Pantheon) with explicit sync gates |
| Pre-push hook mirroring CI steps | Catches failures locally before they reach CI; Composer parity and VC-PURE checks are valuable |
| Contract tests locking workflow wiring | `QualityGateEnforcementContractTest`, `PushWorkflowGuardTest`, `PromptfooGateReliabilityContractTest` prevent silent drift between scripts, workflows, and documentation |
| Helper PR pattern with `publish/master-active` | Rolling helper branch avoids stale per-commit branches (legacy `publish/master-<sha>` pattern was replaced) |
| Structured gate summaries and artifact upload | `gate-summary.txt` and `structured-error-summary.json` provide machine-readable diagnostics for automated decisions |
| Post-deploy Pantheon verification | Catches regressions that only manifest on hosted infrastructure |
| `maxConcurrency: 1` for hosted evals | Prevents rate limit exhaustion from parallel requests |
| Concurrency control with `cancel-in-progress` | Prevents queue buildup from rapid successive pushes |

---

## Section 9: Internal Policy Draft

### CI/CD Quality Gate Policy — ILAS Repository

**Version:** 1.0 (2026-03-18)
**Scope:** All changes merged to `master` or `release/*` branches

#### 1. Required Gates

All merges to protected branches must pass:
1. **PHPUnit VC-PURE** — Pure unit tests (`phpunit.pure.xml`)
2. **PHPUnit VC-DRUPAL-UNIT** — Drupal unit tests (`phpunit.xml --testsuite drupal-unit`)
3. **Golden Transcript Gate** — Quality gate script (`run-quality-gate.sh`)
4. **Widget Hardening Suite** — UX/a11y tests (`run-assistant-widget-hardening.mjs`)
5. **Promptfoo Transport/Runtime** — Offline eval infrastructure tests
6. **Hosted Promptfoo Gate** — Live assistant evaluation:
   - **Helper PR (75 cases):** Full hosted profile, blocking mode
   - **Protected push (19 cases):** Stability subset, blocking mode
   - **Post-deploy verification (19 cases):** Pantheon dev, blocking mode

#### 2. Branch Protection

- `master`: Require passing status checks, require PR review
- Direct pushes to `github/master` are blocked by pre-push hook
- Pantheon `origin/master` push requires `github/master` to be in sync first

#### 3. Local Pre-Push Requirements

Developers must install strict hooks (`npm run install-hooks`). Local pushes mirror CI:
- Composer lock file parity (dry-run)
- PHPUnit VC-PURE parity
- Module quality gate
- Branch-aware Promptfoo gate

#### 4. Failure Recovery

- Failed post-merge gate: inspect with `gh run view <id>`, re-run if transient
- Stale master: `npm run git:sync-master`, cherry-pick if needed
- Stuck publish: `gh pr list --state open`, close superseded PRs manually

#### 5. Supply Chain

- All GitHub Actions must be pinned to immutable SHA digests
- Dependency updates via Dependabot with weekly cadence
- Third-party action changes require review of the SHA diff

---

## Section 10: Implementation Changes

### 10.1 Immediate: Commit In-Flight Protected-Push Split (R-7-01)

The following files have uncommitted changes that implement the protected-push eval split. These changes are already written and tested locally — they need to be committed and published.

**Staged (M):**
- `.github/workflows/quality-gate.yml` — Splits helper-PR vs protected-push config selection (line 119 vs 128)

**Modified (unstaged):**
- `docs/aila/current-state.md` — Documentation updates
- `docs/aila/evidence-index.md` — New claim entries
- `docs/aila/risk-register.md` — Updated R-MNT-02
- `docs/aila/roadmap.md` — Roadmap updates
- `docs/aila/runbook.md` — Protected-push verification
- `promptfoo-evals/lib/gate-metrics.js` — Metric extraction updates
- `promptfoo-evals/scripts/gate-metrics.js` — CLI updates
- `scripts/ci/install-pre-push-strict-hook.sh` — Hook documentation updates
- `scripts/ci/run-promptfoo-gate.sh` — Protected-push metric min-count overrides
- `scripts/git/finish.sh` — Protected-push config reference, post-deploy verification
- `scripts/git/publish.sh` — Publish improvements
- 3 PHPUnit test files — Updated assertions for 19-case subset

**New (untracked):**
- `promptfoo-evals/promptfooconfig.protected-push.yaml` — 19-case eval config
- `promptfoo-evals/tests/protected-push-stability.yaml` — 19 test cases (3 abuse, 4 retrieval, 8 grounding/escalation/safety, 4 multilingual)
- `docs/aila/runtime/tovr-16-final-consolidation-roadmap.txt` — Consolidation roadmap

### 10.2 Pin GitHub Actions to SHA (R-7-02)

**`.github/workflows/quality-gate.yml`:**

| Current | Pinned |
|---------|--------|
| `actions/checkout@v4` (lines 33, 71) | Pin to current v4 SHA |
| `actions/setup-node@v4` (line 76) | Pin to current v4 SHA |
| `actions/upload-artifact@v4` (lines 58, 167, 183) | Pin to current v4 SHA |

**`.github/workflows/observability-release.yml`:**

| Current | Pinned |
|---------|--------|
| `actions/checkout@v4` (line 20) | Pin to current v4 SHA |
| `actions/setup-node@v4` (line 25) | Pin to current v4 SHA |

*Note: Use `gh api repos/actions/checkout/git/ref/tags/v4 --jq .object.sha` or similar to resolve current SHAs. Add a `# v4` comment after the SHA for readability.*

### 10.3 Remove `main` from Triggers (R-7-04)

**`.github/workflows/quality-gate.yml`:**

Remove lines 7 and 12 (`- main`) from push and pull_request trigger blocks.

### 10.4 Add Dependabot Configuration (R-30-01)

Create **`.github/dependabot.yml`**:

```yaml
version: 2
updates:
  - package-ecosystem: npm
    directory: "/"
    schedule:
      interval: weekly
    open-pull-requests-limit: 5

  - package-ecosystem: composer
    directory: "/"
    schedule:
      interval: weekly
    open-pull-requests-limit: 5

  - package-ecosystem: github-actions
    directory: "/"
    schedule:
      interval: weekly
    open-pull-requests-limit: 5
```

### 10.5 Clean Stale Remote Branches (R-7-03)

```bash
git push github :refs/heads/publish/master-1d5aa0f68865
git push github :refs/heads/publish/master-7b885ae965ea
git push github :refs/heads/publish/master-880f7d66f60b
```

---

## Section 11: Open Questions

### Q-01: What are the actual values of rate limit variables?

`ILAS_CONFIGURED_RATE_LIMIT_PER_MINUTE` and `ILAS_CONFIGURED_RATE_LIMIT_PER_HOUR` are referenced in `quality-gate.yml:93-94` as `${{ vars.* }}`. If these GitHub repository variables are unset, `run-promptfoo-gate.sh` falls back to heuristic detection from the assistant's response headers. The actual configured values affect whether the 75-case helper PR suite can complete within a single rate window.

**Impact on recommendations:** If rate limits are tight (e.g., 10 req/min), even the 19-case protected-push subset needs ~2 minutes of sequential requests. The rate budget analysis in `run-promptfoo-gate.sh` (`PLANNED_MESSAGE_REQUEST_BUDGET`) accounts for this, but correct values must be configured for accurate preflight checks.

### Q-02: Is Pantheon Integrated Composer enabled?

The repository has both `composer.json` at root and a `pantheon.upstream.yml`. If Integrated Composer is enabled, Pantheon runs `composer install` on push to `origin/master`, which means the build artifact on Pantheon may differ from the local vendor directory. This affects whether the pre-push Composer dry-run parity check is sufficient or whether a build verification step should be added.

**Impact on recommendations:** If Integrated Composer is enabled, the current Composer parity check in `pre-push-strict.sh` is correct (it verifies lock file consistency, not vendor contents). If it's not enabled, vendor directory must be committed and the parity check is less meaningful.

### Q-03: Should the post-merge gate re-run be configurable per PR?

The current design always runs the post-merge gate. For documentation-only or config-only changes, a path filter could skip the Promptfoo gate. However, path filters add complexity and the 19-case subset is fast enough (~2 minutes) that the simplicity benefit of always running it may outweigh the time savings.

**Impact on recommendations:** If this is desired, add a `paths-ignore` filter to the `promptfoo-gate` job. The contract tests in `QualityGateEnforcementContractTest` would need updating to match.

### Q-04: What is the intended long-term role of the `main` branch?

`main` is currently 318 commits behind `master` with 30 diverged commits (early Pantheon scaffold). It's unclear whether `main` was the original Pantheon default branch that was superseded by `master`, or whether it serves another purpose. The recommendation to remove it from triggers assumes it's fully superseded.

**Impact on recommendations:** If `main` has any planned use (e.g., GitHub Pages, documentation site), removing it from triggers would be premature. If it's purely historical, it could also be deleted from GitHub after the trigger cleanup.

### Q-05: Should the scheduled regression eval (R-90-02) run against dev or live?

Running against `live` catches production regressions but consumes production rate limits. Running against `dev` is safer but may not reflect the exact production state (Pantheon `dev` auto-deploys on push, but `test`/`live` require manual promotion).

**Impact on recommendations:** The current post-deploy verification in `finish.sh:386-417` runs against `dev`. A scheduled eval should probably target the same environment to maintain consistency, with `live` reserved for manual spot-checks.

---

## Section 12: Scorecard

| # | Dimension | Score (0-5) | Evidence | Recommendation |
|---|-----------|:-----------:|----------|----------------|
| 1 | **Pipeline reliability** | 2 | Post-merge gate fails ~50% of the time (FM-01). Fix is written but uncommitted (F-02). | Commit protected-push split immediately (R-7-01). |
| 2 | **Supply chain security** | 2 | 3 Actions unpinned (F-03, F-04). No Dependabot (F-05). `setup-php` correctly pinned, showing intent. | Pin all Actions (R-7-02), add Dependabot (R-30-01). |
| 3 | **Test coverage** | 4 | 75-case hosted eval, 19-case stability subset, PHPUnit contract tests locking workflow wiring, widget hardening suite. Strong coverage breadth. | Maintain; add scheduled regression runs (R-90-02). |
| 4 | **Observability** | 4 | Structured gate summaries, artifact upload, diagnostic error summaries in JSON and text. Sentry integration with sourcemaps workflow. Langfuse tracing. | Maintain; verify Sentry source-map resolution (existing TOVR-16 blocker). |
| 5 | **Recovery procedures** | 2 | Recovery commands documented in hook install output. No formal runbook for CI failure modes. Backup branch evidence shows manual recovery has occurred. | Document recovery runbook (R-30-02). |
| 6 | **Branch management** | 3 | `publish/master-active` rolling branch is correct. Legacy branches cleaned on success. 3 stale branches remain. Unused `main` in triggers. | Clean stale branches (R-7-03), remove `main` (R-7-04). |
| 7 | **Local-CI parity** | 5 | Pre-push hook mirrors every CI step: Composer dry-run, VC-PURE, quality gate, Promptfoo gate. Contract tests enforce this parity. | Best practice. Keep. |
| 8 | **Deployment safety** | 4 | `pantheon-deploy.sh` has backup, confirmation token, updatedb verification, config drift check. `finish.sh` enforces GitHub-before-Pantheon ordering. | Add Environments with approval gates (R-90-01). |
| 9 | **Documentation** | 3 | Contract tests serve as executable documentation. Hook install script documents the full flow. Formal runbook exists but lacks CI failure recovery. | Add failure recovery section (R-30-02). |
| 10 | **Operational simplicity** | 2 | 2,859 lines of shell across 6 scripts. 5 polling loops in `finish.sh`. Single maintainer must understand full state machine. Complexity is intrinsic to dual-remote constraint but creates operational risk. | Reducing eval count (R-7-01) eliminates most common failure. Runbook (R-30-02) addresses knowledge concentration. |

**Overall: 31/50 (6.2/10)**

**Interpretation:** The pipeline is architecturally well-designed with excellent local-CI parity and strong contract test coverage. The low scores cluster around operational reliability (the eval gate bootstrap problem) and supply chain hygiene (unpinned Actions, no Dependabot). The 7-day actions (R-7-01 through R-7-04) would raise the overall score to approximately 8/10 by fixing the most impactful issues.

---

*Generated 2026-03-18. All file:line citations reference `master` at `bc27d2b9b`.*
