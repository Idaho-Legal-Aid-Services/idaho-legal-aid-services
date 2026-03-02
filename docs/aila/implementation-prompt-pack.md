# Aila Roadmap Prompt Pack

## How to use
1. Run one prompt at a time unless you intentionally batch work.
2. Treat the referenced docs as source of truth for that prompt.
3. Keep scope fixed to the prompt ID item and phase constraints.
4. Require evidence-backed output reports with test results.

## Global constraints
1. Roadmap is authoritative for implementation scope.
2. Preserve `llm.enabled=false` in `live` through Phase 2.
3. Enforce phase `What we will NOT do` constraints in every prompt.
4. Add/update tests for code changes unless explicitly N/A.
5. Return changed files, command outputs summary, residual risks, and rollback notes.

## Validation command matrix
| Profile | Command |
|---|---|
| `VC-UNIT` | `ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml --group ilas_site_assistant /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Unit` |
| `VC-DRUPAL-UNIT` | `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml --testsuite drupal-unit` |
| `VC-KERNEL` | `ddev exec vendor/bin/phpunit --configuration /var/www/html/phpunit.xml --group ilas_site_assistant /var/www/html/web/modules/custom/ilas_site_assistant/tests/src/Kernel` |
| `VC-FUNCTIONAL` | `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.xml --testsuite functional` |
| `VC-PURE` | `vendor/bin/phpunit --configuration /home/evancurry/idaho-legal-aid-services/phpunit.pure.xml` |
| `VC-QUALITY-GATE` | `ddev exec bash /var/www/html/web/modules/custom/ilas_site_assistant/tests/run-quality-gate.sh` |
| `VC-PROMPTFOO` | `cd /home/evancurry/idaho-legal-aid-services && npm run eval:promptfoo` |
| `VC-PROMPTFOO-LIVE` | `cd /home/evancurry/idaho-legal-aid-services && npm run eval:promptfoo:live` |
| `VC-RUNBOOK-LOCAL` | `cd /home/evancurry/idaho-legal-aid-services && ddev drush status && ddev drush config:get ilas_site_assistant.settings -y && ddev drush state:get system.cron_last` |
| `VC-RUNBOOK-PANTHEON` | `for ENV in dev test live; do terminus remote:drush idaho-legal-aid-services.$ENV -- config:get ilas_site_assistant.settings -y; done` |
| `VC-TOGGLE-CHECK` | `cd /home/evancurry/idaho-legal-aid-services && rg -n "llm.enabled|vector_search|rate_limit_per_minute|conversation_logging" docs/aila/current-state.md docs/aila/evidence-index.md` |

## Prompt inventory
| Group | IDs | Count |
|---|---|---:|
| Phase 0 Objectives | `P0-OBJ-01..02` | 2 |
| Phase 0 Deliverables | `P0-DEL-01..03` | 3 |
| Phase 0 Entry/Exit/Sprint/NDO | `P0-ENT-01..02, P0-EXT-01..03, P0-SBD-01..02, P0-NDO-01..03` | 10 |
| Phase 1 Objectives | `P1-OBJ-01..03` | 3 |
| Phase 1 Deliverables | `P1-DEL-01..05` | 5 |
| Phase 1 Entry/Exit/Sprint/NDO | `P1-ENT-01..02, P1-EXT-01..03, P1-SBD-01..02, P1-NDO-01..02` | 9 |
| Phase 2 Objectives | `P2-OBJ-01..03` | 3 |
| Phase 2 Deliverables | `P2-DEL-01..04` | 4 |
| Phase 2 Entry/Exit/Sprint/NDO | `P2-ENT-01..02, P2-EXT-01..03, P2-SBD-01..02, P2-NDO-01..02` | 9 |
| Phase 3 Objectives | `P3-OBJ-01..03` | 3 |
| Phase 3 Deliverables | `P3-DEL-01..03` | 3 |
| Phase 3 Entry/Exit/Sprint/NDO | `P3-ENT-01..02, P3-EXT-01..03, P3-SBD-01..02, P3-NDO-01..02` | 9 |
| Cross-phase dependencies | `XDP-01..06` | 6 |
| Blockers | `BLK-01..04` | 4 |
| Scope boundaries | `SCB-01..02` | 2 |
| Mapping lines | `MAP-01..04` | 4 |
| Planning defaults | `DEF-01..02` | 2 |
| **Total** |  | **80** |

---

## Prompt bodies

### Prompt `P0-OBJ-01`
**Prompt ID**: `P0-OBJ-01`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Objectives #1  
**Task**: Implement this roadmap item literally: "Resolve top security and config-governance unknowns that block downstream execution. (Refs: current-state §8; evidence-index CLAIM-012, CLAIM-095, CLAIM-113; system-map Diagram B; runbook §2)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Resolve top security and config-governance unknowns that block downstream execution. (Refs: current-state §8; evidence-index CLAIM-012, CLAIM-095, CLAIM-113; system-map Diagram B; runbook §2)".  
**Validation Commands**: VC-UNIT, VC-DRUPAL-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-OBJ-02`
**Prompt ID**: `P0-OBJ-02`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Objectives #2  
**Task**: Implement this roadmap item literally: "Lock safety/compliance assumptions before adding new runtime complexity. (Refs: current-state §4C, §6; evidence-index CLAIM-039, CLAIM-058, CLAIM-090; system-map Diagram B; runbook §1)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Lock safety/compliance assumptions before adding new runtime complexity. (Refs: current-state §4C, §6; evidence-index CLAIM-039, CLAIM-058, CLAIM-090; system-map Diagram B; runbook §1)".  
**Validation Commands**: VC-UNIT, VC-DRUPAL-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-DEL-01`
**Prompt ID**: `P0-DEL-01`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Key deliverables #1  
**Task**: Implement this roadmap item literally: "CSRF auth matrix + endpoint hardening implementation scope and acceptance tests (`IMP-SEC-01`). (Refs: current-state §6, §8; evidence-index CLAIM-012, CLAIM-113; system-map Diagram B; runbook §2)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: Security & Privacy Hardening -> CSRF enforcement matrix + hardening; Risk linkage: R-SEC-01.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "CSRF auth matrix + endpoint hardening implementation scope and acceptance tests (`IMP-SEC-01`). (Refs: current-state §6, §8; evidence-index CLAIM-012, CLAIM-113; system-map Diagram B; runbook §2)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-DEL-02`
**Prompt ID**: `P0-DEL-02`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Key deliverables #2  
**Task**: Implement this roadmap item literally: "Config schema parity fix plan for `vector_search` and config drift checks (`IMP-CONF-01`). (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-096; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: No direct backlog row; roadmap is authoritative for this item; Risk linkage: R-RAG-02.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Config schema parity fix plan for `vector_search` and config drift checks (`IMP-CONF-01`). (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-096; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-DEL-03`
**Prompt ID**: `P0-DEL-03`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Key deliverables #3  
**Task**: Implement this roadmap item literally: "Policy governance spec for “no legal advice” audit fields and reporting (`IMP-GOV-01` prep). (Refs: current-state §4C, §4F; evidence-index CLAIM-039, CLAIM-047, CLAIM-058; system-map Diagram B; runbook §5)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: Governance & Compliance -> No-legal-advice audit reporting; Risk linkage: R-GOV-01.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Policy governance spec for “no legal advice” audit fields and reporting (`IMP-GOV-01` prep). (Refs: current-state §4C, §4F; evidence-index CLAIM-039, CLAIM-047, CLAIM-058; system-map Diagram B; runbook §5)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-ENT-01`
**Prompt ID**: `P0-ENT-01`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Entry criteria #1  
**Task**: Implement this roadmap item literally: "Audit baseline accepted as source of truth. (Refs: current-state §1; evidence-index CLAIM-001; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Audit baseline accepted as source of truth. (Refs: current-state §1; evidence-index CLAIM-001; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-ENT-02`
**Prompt ID**: `P0-ENT-02`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Entry criteria #2  
**Task**: Implement this roadmap item literally: "Security/compliance owner roles assigned for CSRF and policy workstreams. (Refs: current-state §7; evidence-index CLAIM-013; system-map Diagram A; runbook §1)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Security/compliance owner roles assigned for CSRF and policy workstreams. (Refs: current-state §7; evidence-index CLAIM-013; system-map Diagram A; runbook §1)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-EXT-01`
**Prompt ID**: `P0-EXT-01`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Exit criteria #1  
**Task**: Implement this roadmap item literally: "Authenticated/anonymous CSRF behavior verified with deterministic expected outcomes. (Refs: current-state §8; evidence-index CLAIM-113; system-map Diagram B; runbook §2)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Authenticated/anonymous CSRF behavior verified with deterministic expected outcomes. (Refs: current-state §8; evidence-index CLAIM-113; system-map Diagram B; runbook §2)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-EXT-02`
**Prompt ID**: `P0-EXT-02`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Exit criteria #2  
**Task**: Implement this roadmap item literally: "`vector_search` config schema/export parity approach approved and test strategy defined. (Refs: current-state §4H; evidence-index CLAIM-095, CLAIM-096; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "`vector_search` config schema/export parity approach approved and test strategy defined. (Refs: current-state §4H; evidence-index CLAIM-095, CLAIM-096; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-EXT-03`
**Prompt ID**: `P0-EXT-03`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Exit criteria #3  
**Task**: Implement this roadmap item literally: "Phase 1 observability stories have unblocked dependencies. (Refs: current-state §4F, §8; evidence-index CLAIM-120, CLAIM-122; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Phase 1 observability stories have unblocked dependencies. (Refs: current-state §4F, §8; evidence-index CLAIM-120, CLAIM-122; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-SBD-01`
**Prompt ID**: `P0-SBD-01`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Suggested sprint breakdown #1  
**Task**: Implement this roadmap item literally: "Week 1: CSRF matrix tests + runtime verification updates."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Week 1: CSRF matrix tests + runtime verification updates.".  
**Validation Commands**: VC-UNIT, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-SBD-02`
**Prompt ID**: `P0-SBD-02`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> Suggested sprint breakdown #2  
**Task**: Implement this roadmap item literally: "Week 2: Config parity and governance artifacts for compliance reporting."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Week 2: Config parity and governance artifacts for compliance reporting.".  
**Validation Commands**: VC-UNIT, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-NDO-01`
**Prompt ID**: `P0-NDO-01`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> What we will NOT do #1  
**Task**: Implement this roadmap item literally: "No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Enforce out-of-scope constraints explicitly and reject prohibited changes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)".  
**Validation Commands**: VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-NDO-02`
**Prompt ID**: `P0-NDO-02`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> What we will NOT do #2  
**Task**: Implement this roadmap item literally: "No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Enforce out-of-scope constraints explicitly and reject prohibited changes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2)".  
**Validation Commands**: VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P0-NDO-03`
**Prompt ID**: `P0-NDO-03`  
**Roadmap Item**: Phase 0 (Sprint 1): Quick wins / safety hardening -> What we will NOT do #3  
**Task**: Implement this roadmap item literally: "No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM enablement. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No major UI redesign. (Refs: current-state §4A; evidence-index CLAIM-031; system-map Diagram A; runbook §2) ; No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Enforce out-of-scope constraints explicitly and reject prohibited changes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "No broad architectural refactor beyond minimal seam prep. (Refs: current-state §3; evidence-index CLAIM-020; system-map Diagram B; runbook §4)".  
**Validation Commands**: VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-OBJ-01`
**Prompt ID**: `P1-OBJ-01`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Objectives #1  
**Task**: Implement this roadmap item literally: "Establish production-grade visibility (errors, traces, performance, queue health, SLOs). (Refs: current-state §4F, §4G; evidence-index CLAIM-051, CLAIM-079, CLAIM-082, CLAIM-084; system-map Diagram B; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Establish production-grade visibility (errors, traces, performance, queue health, SLOs). (Refs: current-state §4F, §4G; evidence-index CLAIM-051, CLAIM-079, CLAIM-082, CLAIM-084; system-map Diagram B; runbook §3)".  
**Validation Commands**: VC-UNIT, VC-DRUPAL-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-OBJ-02`
**Prompt ID**: `P1-OBJ-02`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Objectives #2  
**Task**: Implement this roadmap item literally: "Formalize deterministic degrade behavior under dependency failures. (Refs: current-state §4B, §4D; evidence-index CLAIM-048, CLAIM-063, CLAIM-065; system-map Diagram B; runbook §2)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Formalize deterministic degrade behavior under dependency failures. (Refs: current-state §4B, §4D; evidence-index CLAIM-048, CLAIM-063, CLAIM-065; system-map Diagram B; runbook §2)".  
**Validation Commands**: VC-UNIT, VC-DRUPAL-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-OBJ-03`
**Prompt ID**: `P1-OBJ-03`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Objectives #3  
**Task**: Implement this roadmap item literally: "Convert existing test assets into enforced quality gates. (Refs: current-state §4F, §8; evidence-index CLAIM-086, CLAIM-105, CLAIM-122; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Convert existing test assets into enforced quality gates. (Refs: current-state §4F, §8; evidence-index CLAIM-086, CLAIM-105, CLAIM-122; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-UNIT, VC-DRUPAL-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-DEL-01`
**Prompt ID**: `P1-DEL-01`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Key deliverables #1  
**Task**: Implement this roadmap item literally: "Sentry and Langfuse staged enablement with redaction validation (`IMP-OBS-01`). (Refs: current-state §4F, §6; evidence-index CLAIM-079, CLAIM-083, CLAIM-120; system-map Diagram A; runbook §5)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: Observability & Monitoring -> Sentry staged enablement + redaction validation; Langfuse baseline traces + queue health; Risk linkage: R-OBS-01, R-OBS-02, R-SEC-03.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Sentry and Langfuse staged enablement with redaction validation (`IMP-OBS-01`). (Refs: current-state §4F, §6; evidence-index CLAIM-079, CLAIM-083, CLAIM-120; system-map Diagram A; runbook §5)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-DEL-02`
**Prompt ID**: `P1-DEL-02`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Key deliverables #2  
**Task**: Implement this roadmap item literally: "SLO set and alert policy for availability/latency/errors/cron/queue (`IMP-SLO-01`). (Refs: current-state §4F, §4G; evidence-index CLAIM-084, CLAIM-121; system-map Diagram B; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: Performance & Cost -> SLOs and alerting baseline; Risk linkage: R-OBS-03, R-PERF-02.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "SLO set and alert policy for availability/latency/errors/cron/queue (`IMP-SLO-01`). (Refs: current-state §4F, §4G; evidence-index CLAIM-084, CLAIM-121; system-map Diagram B; runbook §3)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-DEL-03`
**Prompt ID**: `P1-DEL-03`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Key deliverables #3  
**Task**: Implement this roadmap item literally: "CI integration for PHPUnit + promptfoo smoke/regression (`IMP-TST-01`). (Refs: current-state §4F, §8; evidence-index CLAIM-086, CLAIM-105, CLAIM-122; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: Maintainability & Testing -> CI quality gate implementation; Risk linkage: R-MNT-02.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "CI integration for PHPUnit + promptfoo smoke/regression (`IMP-TST-01`). (Refs: current-state §4F, §8; evidence-index CLAIM-086, CLAIM-105, CLAIM-122; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-DEL-04`
**Prompt ID**: `P1-DEL-04`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Key deliverables #4  
**Task**: Implement this roadmap item literally: "Failure-mode contract tests and replay/idempotency test coverage (`IMP-REL-01`, `IMP-REL-02`). (Refs: current-state §4B, §4D; evidence-index CLAIM-035, CLAIM-046, CLAIM-063; system-map Diagram B; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: Reliability & Error Handling -> Integration failure contract tests; Idempotency and replay correctness; Risk linkage: R-REL-01, R-REL-03.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Failure-mode contract tests and replay/idempotency test coverage (`IMP-REL-01`, `IMP-REL-02`). (Refs: current-state §4B, §4D; evidence-index CLAIM-035, CLAIM-046, CLAIM-063; system-map Diagram B; runbook §4)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-DEL-05`
**Prompt ID**: `P1-DEL-05`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Key deliverables #5  
**Task**: Implement this roadmap item literally: "Retrospective regression bridge for disambiguation/schema reliability, loop/empty-query safeguards, and blocking deep transcript gating (`IMP-REL-03`, `IMP-REL-04`, `IMP-TST-02`). (Refs: roadmap §Retrospective addendum (2026-02-27 production failures) rows `IMP-REL-03`, `IMP-REL-04`, `IMP-TST-02`; backlog §Retrospective Regression Hardening; retrospective-qa-2026-02-27-production-failures.md §6)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md); [retrospective-qa-2026-02-27-production-failures.md](/home/evancurry/idaho-legal-aid-services/docs/aila/retrospective-qa-2026-02-27-production-failures.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Canonicalize disambiguation option schema to `intent` with temporary `value` alias compatibility; Add post-sanitize empty-message guard with deterministic `400 invalid_message`; Add conversation-state loop-prevention metadata (clarify counter + prior-question hash); Enforce deep multi-turn transcript suite as blocking gate for target branches via `scripts/ci/run-promptfoo-gate.sh` with deep-config override and preserved config-override behavior in `promptfoo-evals/scripts/run-promptfoo.sh`; Backlog linkage: Reliability & Error Handling -> `IMP-REL-03`, `IMP-REL-04`; Maintainability & Testing -> `IMP-TST-02`; Risk linkage: R-REL-04, R-REL-05, R-REL-06, R-REL-07, R-MNT-02.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Normalize disambiguation option schema (`intent` canonical; `value` accepted as deprecated alias), and harden mixed forms/guides clarify behavior (`IMP-REL-03`). Add controller guard for empty-after-sanitize messages and loop-prevention metadata for repeated clarify cycles (`IMP-REL-04`). Expand blocking regression gate to include deep multi-turn transcript replay and UI/controller contract assertions (`IMP-TST-02`). Includes golden replay coverage for `i need some help`, `custody forms?`, `eviction forms or guides?`, and repeated `eviction forms` no-loop behavior. (Refs: roadmap §Retrospective addendum rows `IMP-REL-03`, `IMP-REL-04`, `IMP-TST-02`)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE, VC-PROMPTFOO.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-ENT-01`
**Prompt ID**: `P1-ENT-01`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Entry criteria #1  
**Task**: Implement this roadmap item literally: "Phase 0 CSRF and config-parity blockers are resolved or have approved mitigations. (Refs: current-state §8; evidence-index CLAIM-113, CLAIM-095; system-map Diagram B; runbook §2)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Phase 0 CSRF and config-parity blockers are resolved or have approved mitigations. (Refs: current-state §8; evidence-index CLAIM-113, CLAIM-095; system-map Diagram B; runbook §2)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-ENT-02`
**Prompt ID**: `P1-ENT-02`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Entry criteria #2  
**Task**: Implement this roadmap item literally: "Platform credentials and destination approvals are available for telemetry integrations. (Refs: current-state §4H; evidence-index CLAIM-098; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Platform credentials and destination approvals are available for telemetry integrations. (Refs: current-state §4H; evidence-index CLAIM-098; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-EXT-01`
**Prompt ID**: `P1-EXT-01`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Exit criteria #1  
**Task**: Implement this roadmap item literally: "Critical alerts and dashboards operate in non-live and are tested. (Refs: current-state §4F; evidence-index CLAIM-084; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Critical alerts and dashboards operate in non-live and are tested. (Refs: current-state §4F; evidence-index CLAIM-084; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-EXT-02`
**Prompt ID**: `P1-EXT-02`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Exit criteria #2  
**Task**: Implement this roadmap item literally: "CI quality gate is mandatory for merge/release path. (Refs: current-state §8; evidence-index CLAIM-122; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "CI quality gate is mandatory for merge/release path. (Refs: current-state §8; evidence-index CLAIM-122; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-EXT-03`
**Prompt ID**: `P1-EXT-03`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Exit criteria #3  
**Task**: Implement this roadmap item literally: "Reliability failure matrix tests pass against target environments. (Refs: current-state §4B, §4D; evidence-index CLAIM-048, CLAIM-063, CLAIM-065; system-map Diagram B; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Reliability failure matrix tests pass against target environments. (Refs: current-state §4B, §4D; evidence-index CLAIM-048, CLAIM-063, CLAIM-065; system-map Diagram B; runbook §4)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-SBD-01`
**Prompt ID**: `P1-SBD-01`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Suggested sprint breakdown #1  
**Task**: Implement this roadmap item literally: "Sprint 2: Sentry/Langfuse bootstrap, log schema normalization, initial SLO drafts."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Sprint 2: Sentry/Langfuse bootstrap, log schema normalization, initial SLO drafts.".  
**Validation Commands**: VC-UNIT, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-SBD-02`
**Prompt ID**: `P1-SBD-02`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> Suggested sprint breakdown #2  
**Task**: Implement this roadmap item literally: "Sprint 3: Alert policy finalization, CI gate rollout, reliability failure matrix completion."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Sprint 3: Alert policy finalization, CI gate rollout, reliability failure matrix completion.".  
**Validation Commands**: VC-UNIT, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-NDO-01`
**Prompt ID**: `P1-NDO-01`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> What we will NOT do #1  
**Task**: Implement this roadmap item literally: "No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Enforce out-of-scope constraints explicitly and reject prohibited changes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)".  
**Validation Commands**: VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P1-NDO-02`
**Prompt ID**: `P1-NDO-02`  
**Roadmap Item**: Phase 1 (Sprints 2-3): Observability + reliability baseline -> What we will NOT do #2  
**Task**: Implement this roadmap item literally: "No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live LLM rollout. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Enforce out-of-scope constraints explicitly and reject prohibited changes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "No full redesign of retrieval architecture. (Refs: current-state §4D; evidence-index CLAIM-060, CLAIM-065; system-map Diagram B; runbook §4)".  
**Validation Commands**: VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-OBJ-01`
**Prompt ID**: `P2-OBJ-01`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Objectives #1  
**Task**: Implement this roadmap item literally: "Raise grounding quality with confidence-aware response behavior and citation-first responses. (Refs: current-state §4D; evidence-index CLAIM-062, CLAIM-065; system-map Diagram B; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Raise grounding quality with confidence-aware response behavior and citation-first responses. (Refs: current-state §4D; evidence-index CLAIM-062, CLAIM-065; system-map Diagram B; runbook §4)".  
**Validation Commands**: VC-UNIT, VC-DRUPAL-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-OBJ-02`
**Prompt ID**: `P2-OBJ-02`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Objectives #2  
**Task**: Implement this roadmap item literally: "Mature evaluation coverage and release confidence for RAG/response correctness. (Refs: current-state §4F; evidence-index CLAIM-086, CLAIM-105; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Mature evaluation coverage and release confidence for RAG/response correctness. (Refs: current-state §4F; evidence-index CLAIM-086, CLAIM-105; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-UNIT, VC-DRUPAL-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-OBJ-03`
**Prompt ID**: `P2-OBJ-03`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Objectives #3  
**Task**: Implement this roadmap item literally: "Enforce governance around source freshness and provenance. (Refs: current-state §4D, §8; evidence-index CLAIM-067, CLAIM-122; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Enforce governance around source freshness and provenance. (Refs: current-state §4D, §8; evidence-index CLAIM-067, CLAIM-122; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-UNIT, VC-DRUPAL-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-DEL-01`
**Prompt ID**: `P2-DEL-01`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Key deliverables #1  
**Task**: Implement this roadmap item literally: "`/assistant/api/message` contract expansion proposal and rollout plan: `confidence`, `citations[]`, `decision_reason`, request-id normalization. (Refs: current-state §4B, §4D; evidence-index CLAIM-035, CLAIM-049, CLAIM-062; system-map Diagram B; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: Retrieval Quality -> Confidence + citation response contract; Risk linkage: R-RAG-01, R-REL-03.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "`/assistant/api/message` contract expansion proposal and rollout plan: `confidence`, `citations[]`, `decision_reason`, request-id normalization. (Refs: current-state §4B, §4D; evidence-index CLAIM-035, CLAIM-049, CLAIM-062; system-map Diagram B; runbook §4)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-DEL-02`
**Prompt ID**: `P2-DEL-02`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Key deliverables #2  
**Task**: Implement this roadmap item literally: "Retrieval confidence/refusal thresholds integrated with eval harness and regression gating (`IMP-RAG-01`). (Refs: current-state §4D; evidence-index CLAIM-062, CLAIM-065, CLAIM-086; system-map Diagram B; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: Retrieval Quality -> Confidence + citation response contract; Risk linkage: R-RAG-01.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Retrieval confidence/refusal thresholds integrated with eval harness and regression gating (`IMP-RAG-01`). (Refs: current-state §4D; evidence-index CLAIM-062, CLAIM-065, CLAIM-086; system-map Diagram B; runbook §4)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-DEL-03`
**Prompt ID**: `P2-DEL-03`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Key deliverables #3  
**Task**: Implement this roadmap item literally: "Vector index hygiene policy, metadata standards, and refresh monitoring (`IMP-RAG-02`). (Refs: current-state §4D, §4G; evidence-index CLAIM-066, CLAIM-067, CLAIM-121; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: Retrieval Quality -> Vector index hygiene and freshness policy; Risk linkage: R-RAG-02, R-GOV-02.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Vector index hygiene policy, metadata standards, and refresh monitoring (`IMP-RAG-02`). (Refs: current-state §4D, §4G; evidence-index CLAIM-066, CLAIM-067, CLAIM-121; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-DEL-04`
**Prompt ID**: `P2-DEL-04`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Key deliverables #4  
**Task**: Implement this roadmap item literally: "Promptfoo dataset expansion for weak grounding, escalation, and safety boundary scenarios. (Refs: current-state §4C, §4F; evidence-index CLAIM-055, CLAIM-086, CLAIM-105; system-map Diagram B; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: No direct backlog row; roadmap is authoritative for this item; Risk linkage: R-MNT-02, R-LLM-01.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Promptfoo dataset expansion for weak grounding, escalation, and safety boundary scenarios. (Refs: current-state §4C, §4F; evidence-index CLAIM-055, CLAIM-086, CLAIM-105; system-map Diagram B; runbook §4)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-ENT-01`
**Prompt ID**: `P2-ENT-01`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Entry criteria #1  
**Task**: Implement this roadmap item literally: "Observability + CI baselines are operational from Phase 1. (Refs: current-state §4F; evidence-index CLAIM-084, CLAIM-122; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Observability + CI baselines are operational from Phase 1. (Refs: current-state §4F; evidence-index CLAIM-084, CLAIM-122; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-ENT-02`
**Prompt ID**: `P2-ENT-02`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Entry criteria #2  
**Task**: Implement this roadmap item literally: "Config parity and retrieval tuning controls are stable across environments. (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-096, CLAIM-116; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Config parity and retrieval tuning controls are stable across environments. (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-096, CLAIM-116; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-EXT-01`
**Prompt ID**: `P2-EXT-01`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Exit criteria #1  
**Task**: Implement this roadmap item literally: "Retrieval contract and confidence logic pass regression thresholds. (Refs: current-state §4D, §4F; evidence-index CLAIM-062, CLAIM-086; system-map Diagram B; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Retrieval contract and confidence logic pass regression thresholds. (Refs: current-state §4D, §4F; evidence-index CLAIM-062, CLAIM-086; system-map Diagram B; runbook §4)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-EXT-02`
**Prompt ID**: `P2-EXT-02`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Exit criteria #2  
**Task**: Implement this roadmap item literally: "Citation coverage and low-confidence refusal metrics are within approved targets. (Refs: current-state §4D; evidence-index CLAIM-065; system-map Diagram B; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Citation coverage and low-confidence refusal metrics are within approved targets. (Refs: current-state §4D; evidence-index CLAIM-065; system-map Diagram B; runbook §4)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-EXT-03`
**Prompt ID**: `P2-EXT-03`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Exit criteria #3  
**Task**: Implement this roadmap item literally: "Live LLM remains disabled pending Phase 3 readiness review. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Live LLM remains disabled pending Phase 3 readiness review. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-SBD-01`
**Prompt ID**: `P2-SBD-01`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Suggested sprint breakdown #1  
**Task**: Implement this roadmap item literally: "Sprint 4: response contract + retrieval-confidence implementation and tests."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Sprint 4: response contract + retrieval-confidence implementation and tests.".  
**Validation Commands**: VC-UNIT, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-SBD-02`
**Prompt ID**: `P2-SBD-02`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> Suggested sprint breakdown #2  
**Task**: Implement this roadmap item literally: "Sprint 5: dataset expansion, provenance/freshness workflows, threshold calibration."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Sprint 5: dataset expansion, provenance/freshness workflows, threshold calibration.".  
**Validation Commands**: VC-UNIT, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-NDO-01`
**Prompt ID**: `P2-NDO-01`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> What we will NOT do #1  
**Task**: Implement this roadmap item literally: "No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Enforce out-of-scope constraints explicitly and reject prohibited changes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)".  
**Validation Commands**: VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P2-NDO-02`
**Prompt ID**: `P2-NDO-02`  
**Roadmap Item**: Phase 2 (Sprints 4-5): Retrieval quality + eval harness maturity -> What we will NOT do #2  
**Task**: Implement this roadmap item literally: "No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No live production LLM enablement in this phase. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3) ; No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Enforce out-of-scope constraints explicitly and reject prohibited changes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "No broad platform migration outside current Pantheon baseline. (Refs: current-state §1, §5; evidence-index CLAIM-115, CLAIM-119; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-OBJ-01`
**Prompt ID**: `P3-OBJ-01`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Objectives #1  
**Task**: Implement this roadmap item literally: "Complete accessibility and mobile UX hardening with explicit acceptance gates. (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-031, CLAIM-032; system-map Diagram A; runbook §2)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Complete accessibility and mobile UX hardening with explicit acceptance gates. (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-031, CLAIM-032; system-map Diagram A; runbook §2)".  
**Validation Commands**: VC-UNIT, VC-DRUPAL-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-OBJ-02`
**Prompt ID**: `P3-OBJ-02`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Objectives #2  
**Task**: Implement this roadmap item literally: "Finalize performance and cost guardrails with operational runbooks. (Refs: current-state §4F, §4E; evidence-index CLAIM-077, CLAIM-084; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Finalize performance and cost guardrails with operational runbooks. (Refs: current-state §4F, §4E; evidence-index CLAIM-077, CLAIM-084; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-UNIT, VC-DRUPAL-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-OBJ-03`
**Prompt ID**: `P3-OBJ-03`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Objectives #3  
**Task**: Implement this roadmap item literally: "Deliver release readiness package and governance attestation. (Refs: current-state §7; evidence-index CLAIM-108, CLAIM-115; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Deliver release readiness package and governance attestation. (Refs: current-state §7; evidence-index CLAIM-108, CLAIM-115; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-UNIT, VC-DRUPAL-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-DEL-01`
**Prompt ID**: `P3-DEL-01`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Key deliverables #1  
**Task**: Implement this roadmap item literally: "Keyboard/SR regression suite and mobile timeout/error-state acceptance tests (`IMP-UX-01`). (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-026, CLAIM-032; system-map Diagram A; runbook §2)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: UX & Accessibility -> Keyboard + screen-reader regression suite; Mobile error/loading state hardening; Risk linkage: R-UX-01, R-UX-02.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Keyboard/SR regression suite and mobile timeout/error-state acceptance tests (`IMP-UX-01`). (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-026, CLAIM-032; system-map Diagram A; runbook §2)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-DEL-02`
**Prompt ID**: `P3-DEL-02`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Key deliverables #2  
**Task**: Implement this roadmap item literally: "Cost-control policy and budget guardrails (`IMP-COST-01`). (Refs: current-state §4E; evidence-index CLAIM-076, CLAIM-077, CLAIM-080; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: Performance & Cost -> LLM cost guardrails pre-rollout; Risk linkage: R-PERF-01.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Cost-control policy and budget guardrails (`IMP-COST-01`). (Refs: current-state §4E; evidence-index CLAIM-076, CLAIM-077, CLAIM-080; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-DEL-03`
**Prompt ID**: `P3-DEL-03`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Key deliverables #3  
**Task**: Implement this roadmap item literally: "Release checklist with compliance/retention/access attestations and rollback playbook. (Refs: current-state §6, §7; evidence-index CLAIM-059, CLAIM-087, CLAIM-088; system-map Diagram A; runbook §5)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [backlog.md](/home/evancurry/idaho-legal-aid-services/docs/aila/backlog.md); [gap-analysis.md](/home/evancurry/idaho-legal-aid-services/docs/aila/gap-analysis.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Backlog linkage: Governance & Compliance -> Retention/access attestation workflow; Risk linkage: R-GOV-01, R-GOV-02.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Release checklist with compliance/retention/access attestations and rollback playbook. (Refs: current-state §6, §7; evidence-index CLAIM-059, CLAIM-087, CLAIM-088; system-map Diagram A; runbook §5)".  
**Validation Commands**: VC-UNIT, VC-KERNEL, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-ENT-01`
**Prompt ID**: `P3-ENT-01`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Entry criteria #1  
**Task**: Implement this roadmap item literally: "Phase 2 retrieval quality targets are met and documented. (Refs: current-state §4D, §4F; evidence-index CLAIM-065, CLAIM-086; system-map Diagram B; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Phase 2 retrieval quality targets are met and documented. (Refs: current-state §4D, §4F; evidence-index CLAIM-065, CLAIM-086; system-map Diagram B; runbook §4)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-ENT-02`
**Prompt ID**: `P3-ENT-02`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Entry criteria #2  
**Task**: Implement this roadmap item literally: "SLO/alert operational data has at least one sprint of trend history. (Refs: current-state §4F, §4G; evidence-index CLAIM-084, CLAIM-121; system-map Diagram B; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "SLO/alert operational data has at least one sprint of trend history. (Refs: current-state §4F, §4G; evidence-index CLAIM-084, CLAIM-121; system-map Diagram B; runbook §3)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-EXT-01`
**Prompt ID**: `P3-EXT-01`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Exit criteria #1  
**Task**: Implement this roadmap item literally: "UX/a11y test suite is gating and passing. (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-032, CLAIM-105; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "UX/a11y test suite is gating and passing. (Refs: current-state §4A; evidence-index CLAIM-025, CLAIM-032, CLAIM-105; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-EXT-02`
**Prompt ID**: `P3-EXT-02`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Exit criteria #2  
**Task**: Implement this roadmap item literally: "Cost/performance controls are documented, monitored, and accepted by product/platform owners. (Refs: current-state §4E, §4F; evidence-index CLAIM-077, CLAIM-084; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Cost/performance controls are documented, monitored, and accepted by product/platform owners. (Refs: current-state §4E, §4F; evidence-index CLAIM-077, CLAIM-084; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-EXT-03`
**Prompt ID**: `P3-EXT-03`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Exit criteria #3  
**Task**: Implement this roadmap item literally: "Final release packet includes known-unknown disposition and residual risk signoff. (Refs: current-state §8; evidence-index CLAIM-122; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Use runbook verification commands and current-state known unknowns/toggles where applicable.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Final release packet includes known-unknown disposition and residual risk signoff. (Refs: current-state §8; evidence-index CLAIM-122; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-SBD-01`
**Prompt ID**: `P3-SBD-01`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Suggested sprint breakdown #1  
**Task**: Implement this roadmap item literally: "Sprint 6 Week 1: UX/a11y and mobile hardening."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Sprint 6 Week 1: UX/a11y and mobile hardening.".  
**Validation Commands**: VC-UNIT, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-SBD-02`
**Prompt ID**: `P3-SBD-02`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> Suggested sprint breakdown #2  
**Task**: Implement this roadmap item literally: "Sprint 6 Week 2: performance/cost guardrails and governance signoff."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "Sprint 6 Week 2: performance/cost guardrails and governance signoff.".  
**Validation Commands**: VC-UNIT, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-NDO-01`
**Prompt ID**: `P3-NDO-01`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> What we will NOT do #1  
**Task**: Implement this roadmap item literally: "No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Enforce out-of-scope constraints explicitly and reject prohibited changes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `P3-NDO-02`
**Prompt ID**: `P3-NDO-02`  
**Roadmap Item**: Phase 3 (Sprint 6): UX polish + performance/cost optimization -> What we will NOT do #2  
**Task**: Implement this roadmap item literally: "No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: No net-new assistant channels or third-party model expansion beyond audited providers. (Refs: current-state §4E; evidence-index CLAIM-073, CLAIM-074; system-map Diagram A; runbook §3) ; No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4).  
**Implementation Requirements**: Implement only the targeted roadmap item; Preserve phase constraints, including no live LLM enablement through Phase 2; Add/update tests tied to acceptance criteria; Run validation commands and report outcomes; Enforce out-of-scope constraints explicitly and reject prohibited changes.  
**Acceptance Criteria**: Roadmap item is completed exactly as specified and remains within scope: "No platform-wide refactor of unrelated Drupal subsystems. (Refs: current-state §1; evidence-index CLAIM-010; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `XDP-01`
**Prompt ID**: `XDP-01`  
**Roadmap Item**: Cross-phase dependency row #1  
**Task**: Implement dependency guardrails for: "CSRF hardening (`IMP-SEC-01`)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Preserve roadmap sequencing and all phase scope boundaries.  
**Implementation Requirements**: Implement only this cross-phase dependency contract; Workstream: CSRF hardening (`IMP-SEC-01`); Depends on: Authenticated test matrix and route enforcement verification; Consumed in: Phase 0 -> prerequisite for Phases 1-3; Owner role: Security Engineer + Drupal Lead; Add dependency gate checks and report unresolved dependencies.  
**Acceptance Criteria**: Dependency gate accurately blocks downstream work when prerequisites are missing and passes when complete.  
**Validation Commands**: VC-UNIT, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `XDP-02`
**Prompt ID**: `XDP-02`  
**Roadmap Item**: Cross-phase dependency row #2  
**Task**: Implement dependency guardrails for: "Config parity (`IMP-CONF-01`)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Preserve roadmap sequencing and all phase scope boundaries.  
**Implementation Requirements**: Implement only this cross-phase dependency contract; Workstream: Config parity (`IMP-CONF-01`); Depends on: Schema mapping + env drift checks; Consumed in: Phase 0 -> prerequisite for Phase 2 retrieval tuning; Owner role: Drupal Lead; Add dependency gate checks and report unresolved dependencies.  
**Acceptance Criteria**: Dependency gate accurately blocks downstream work when prerequisites are missing and passes when complete.  
**Validation Commands**: VC-UNIT, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `XDP-03`
**Prompt ID**: `XDP-03`  
**Roadmap Item**: Cross-phase dependency row #3  
**Task**: Implement dependency guardrails for: "Observability baseline (`IMP-OBS-01`)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Preserve roadmap sequencing and all phase scope boundaries.  
**Implementation Requirements**: Implement only this cross-phase dependency contract; Workstream: Observability baseline (`IMP-OBS-01`); Depends on: Sentry/Langfuse credentials, redaction validation; Consumed in: Phase 1 -> prerequisite for Phase 2/3 optimization; Owner role: SRE/Platform Engineer; Add dependency gate checks and report unresolved dependencies.  
**Acceptance Criteria**: Dependency gate accurately blocks downstream work when prerequisites are missing and passes when complete.  
**Validation Commands**: VC-UNIT, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `XDP-04`
**Prompt ID**: `XDP-04`  
**Roadmap Item**: Cross-phase dependency row #4  
**Task**: Implement dependency guardrails for: "CI quality gate (`IMP-TST-01`)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Preserve roadmap sequencing and all phase scope boundaries.  
**Implementation Requirements**: Implement only this cross-phase dependency contract; Workstream: CI quality gate (`IMP-TST-01`); Depends on: CI owner/platform decisions; Consumed in: Phase 1 -> prerequisite for all subsequent release gates; Owner role: QA/Automation Engineer + TPM; Add dependency gate checks and report unresolved dependencies.  
**Acceptance Criteria**: Dependency gate accurately blocks downstream work when prerequisites are missing and passes when complete.  
**Validation Commands**: VC-UNIT, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `XDP-05`
**Prompt ID**: `XDP-05`  
**Roadmap Item**: Cross-phase dependency row #5  
**Task**: Implement dependency guardrails for: "Retrieval confidence contract (`IMP-RAG-01`)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Preserve roadmap sequencing and all phase scope boundaries.  
**Implementation Requirements**: Implement only this cross-phase dependency contract; Workstream: Retrieval confidence contract (`IMP-RAG-01`); Depends on: Config parity + observability signals + eval harness; Consumed in: Phase 2 -> prerequisite for Phase 3 readiness signoff; Owner role: AI/RAG Engineer; Add dependency gate checks and report unresolved dependencies.  
**Acceptance Criteria**: Dependency gate accurately blocks downstream work when prerequisites are missing and passes when complete.  
**Validation Commands**: VC-UNIT, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `XDP-06`
**Prompt ID**: `XDP-06`  
**Roadmap Item**: Cross-phase dependency row #6  
**Task**: Implement dependency guardrails for: "Cost guardrails (`IMP-COST-01`)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Preserve roadmap sequencing and all phase scope boundaries.  
**Implementation Requirements**: Implement only this cross-phase dependency contract; Workstream: Cost guardrails (`IMP-COST-01`); Depends on: Observability and usage telemetry from Phase 1/2; Consumed in: Phase 3; Owner role: Product + Platform; Add dependency gate checks and report unresolved dependencies.  
**Acceptance Criteria**: Dependency gate accurately blocks downstream work when prerequisites are missing and passes when complete.  
**Validation Commands**: VC-UNIT, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `BLK-01`
**Prompt ID**: `BLK-01`  
**Roadmap Item**: Critical blocker #1  
**Task**: Resolve this blocker literally: "CSRF authenticated behavior unknown blocks endpoint hardening finalization. (Refs: current-state §8; evidence-index CLAIM-012, CLAIM-113; system-map Diagram B; runbook §2)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: Resolve only this blocker; preserve all phase constraints.  
**Implementation Requirements**: Implement only blocker-resolution work; Use runbook verification procedures for proof; Risk linkage: R-SEC-01.  
**Acceptance Criteria**: Blocker is closed with reproducible evidence: "CSRF authenticated behavior unknown blocks endpoint hardening finalization. (Refs: current-state §8; evidence-index CLAIM-012, CLAIM-113; system-map Diagram B; runbook §2)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `BLK-02`
**Prompt ID**: `BLK-02`  
**Roadmap Item**: Critical blocker #2  
**Task**: Resolve this blocker literally: "`vector_search` schema/export parity issue blocks reliable cross-env retrieval tuning. (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-096; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: Resolve only this blocker; preserve all phase constraints.  
**Implementation Requirements**: Implement only blocker-resolution work; Use runbook verification procedures for proof; Risk linkage: R-RAG-02.  
**Acceptance Criteria**: Blocker is closed with reproducible evidence: "`vector_search` schema/export parity issue blocks reliable cross-env retrieval tuning. (Refs: current-state §4H, §5; evidence-index CLAIM-095, CLAIM-096; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `BLK-03`
**Prompt ID**: `BLK-03`  
**Roadmap Item**: Critical blocker #3  
**Task**: Resolve this blocker literally: "CI workflow ownership/source of truth unknown blocks mandatory gate rollout. (Refs: current-state §8; evidence-index CLAIM-122; system-map Diagram A; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: Resolve only this blocker; preserve all phase constraints.  
**Implementation Requirements**: Implement only blocker-resolution work; Use runbook verification procedures for proof; Risk linkage: R-MNT-02.  
**Acceptance Criteria**: Blocker is closed with reproducible evidence: "CI workflow ownership/source of truth unknown blocks mandatory gate rollout. (Refs: current-state §8; evidence-index CLAIM-122; system-map Diagram A; runbook §3)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `BLK-04`
**Prompt ID**: `BLK-04`  
**Roadmap Item**: Critical blocker #4  
**Task**: Resolve this blocker literally: "Sustained cron/queue load behavior unverified blocks final SLO tuning for async telemetry pipelines. (Refs: current-state §8; evidence-index CLAIM-118, CLAIM-121; system-map Diagram B; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md); [risk-register.md](/home/evancurry/idaho-legal-aid-services/docs/aila/risk-register.md).  
**Scope Boundaries**: Resolve only this blocker; preserve all phase constraints.  
**Implementation Requirements**: Implement only blocker-resolution work; Use runbook verification procedures for proof; Risk linkage: R-REL-02, R-OBS-03.  
**Acceptance Criteria**: Blocker is closed with reproducible evidence: "Sustained cron/queue load behavior unverified blocks final SLO tuning for async telemetry pipelines. (Refs: current-state §8; evidence-index CLAIM-118, CLAIM-121; system-map Diagram B; runbook §3)".  
**Validation Commands**: VC-RUNBOOK-LOCAL, VC-RUNBOOK-PANTHEON, VC-QUALITY-GATE.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `SCB-01`
**Prompt ID**: `SCB-01`  
**Roadmap Item**: Scope boundary #1  
**Task**: Implement boundary enforcement for: "LLM live enablement is explicitly out of scope through Phase 2 and only reconsidered after Phase 3 readiness review. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: This prompt is itself a hard boundary and must be enforced.  
**Implementation Requirements**: Implement only boundary-enforcement checks; Reject out-of-scope changes when boundary is violated; Preserve no-live-LLM-through-Phase-2 policy where relevant.  
**Acceptance Criteria**: Boundary is enforceable and violations are detectable: "LLM live enablement is explicitly out of scope through Phase 2 and only reconsidered after Phase 3 readiness review. (Refs: current-state §5; evidence-index CLAIM-119; system-map Diagram B; runbook §3)".  
**Validation Commands**: VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `SCB-02`
**Prompt ID**: `SCB-02`  
**Roadmap Item**: Scope boundary #2  
**Task**: Implement boundary enforcement for: "Roadmap focuses on safety, quality, reliability, and governance improvements on current architecture; no full rewrite is planned. (Refs: current-state §1, §3; evidence-index CLAIM-010, CLAIM-020; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: This prompt is itself a hard boundary and must be enforced.  
**Implementation Requirements**: Implement only boundary-enforcement checks; Reject out-of-scope changes when boundary is violated; Preserve no-live-LLM-through-Phase-2 policy where relevant.  
**Acceptance Criteria**: Boundary is enforceable and violations are detectable: "Roadmap focuses on safety, quality, reliability, and governance improvements on current architecture; no full rewrite is planned. (Refs: current-state §1, §3; evidence-index CLAIM-010, CLAIM-020; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-TOGGLE-CHECK.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `MAP-01`
**Prompt ID**: `MAP-01`  
**Roadmap Item**: Phase mapping line #1  
**Task**: Implement mapping guardrails for: "Phase 0 = Sprint 1."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Maintain roadmap phase-to-sprint sequencing exactly as documented.  
**Implementation Requirements**: Implement only schedule/mapping guard checks; Detect and report out-of-phase work.  
**Acceptance Criteria**: Phase mapping is enforceable and verified: "Phase 0 = Sprint 1.".  
**Validation Commands**: VC-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `MAP-02`
**Prompt ID**: `MAP-02`  
**Roadmap Item**: Phase mapping line #2  
**Task**: Implement mapping guardrails for: "Phase 1 = Sprints 2-3."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Maintain roadmap phase-to-sprint sequencing exactly as documented.  
**Implementation Requirements**: Implement only schedule/mapping guard checks; Detect and report out-of-phase work.  
**Acceptance Criteria**: Phase mapping is enforceable and verified: "Phase 1 = Sprints 2-3.".  
**Validation Commands**: VC-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `MAP-03`
**Prompt ID**: `MAP-03`  
**Roadmap Item**: Phase mapping line #3  
**Task**: Implement mapping guardrails for: "Phase 2 = Sprints 4-5."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Maintain roadmap phase-to-sprint sequencing exactly as documented.  
**Implementation Requirements**: Implement only schedule/mapping guard checks; Detect and report out-of-phase work.  
**Acceptance Criteria**: Phase mapping is enforceable and verified: "Phase 2 = Sprints 4-5.".  
**Validation Commands**: VC-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `MAP-04`
**Prompt ID**: `MAP-04`  
**Roadmap Item**: Phase mapping line #4  
**Task**: Implement mapping guardrails for: "Phase 3 = Sprint 6."  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Maintain roadmap phase-to-sprint sequencing exactly as documented.  
**Implementation Requirements**: Implement only schedule/mapping guard checks; Detect and report out-of-phase work.  
**Acceptance Criteria**: Phase mapping is enforceable and verified: "Phase 3 = Sprint 6.".  
**Validation Commands**: VC-UNIT.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `DEF-01`
**Prompt ID**: `DEF-01`  
**Roadmap Item**: Planning default #1  
**Task**: Implement default enforcement for: "`llm.enabled` remains disabled in `live` through Phase 2. (Refs: current-state §5; evidence-index CLAIM-069, CLAIM-119; system-map Diagram B; runbook §3)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Enforce planning defaults as non-negotiable unless roadmap is revised.  
**Implementation Requirements**: Implement only default-enforcement checks; Add clear failure signals when defaults are violated.  
**Acceptance Criteria**: Planning default is enforced with reproducible checks: "`llm.enabled` remains disabled in `live` through Phase 2. (Refs: current-state §5; evidence-index CLAIM-069, CLAIM-119; system-map Diagram B; runbook §3)".  
**Validation Commands**: VC-TOGGLE-CHECK, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

### Prompt `DEF-02`
**Prompt ID**: `DEF-02`  
**Roadmap Item**: Planning default #2  
**Task**: Implement default enforcement for: "Timeline = 12 weeks / 6 two-week sprints. (Refs: current-state §7; evidence-index CLAIM-108, CLAIM-115; system-map Diagram A; runbook §4)"  
**Required Documents**: [roadmap.md](/home/evancurry/idaho-legal-aid-services/docs/aila/roadmap.md); [current-state.md](/home/evancurry/idaho-legal-aid-services/docs/aila/current-state.md); [evidence-index.md](/home/evancurry/idaho-legal-aid-services/docs/aila/evidence-index.md); [system-map.mmd](/home/evancurry/idaho-legal-aid-services/docs/aila/system-map.mmd); [runbook.md](/home/evancurry/idaho-legal-aid-services/docs/aila/runbook.md).  
**Scope Boundaries**: Enforce planning defaults as non-negotiable unless roadmap is revised.  
**Implementation Requirements**: Implement only default-enforcement checks; Add clear failure signals when defaults are violated.  
**Acceptance Criteria**: Planning default is enforced with reproducible checks: "Timeline = 12 weeks / 6 two-week sprints. (Refs: current-state §7; evidence-index CLAIM-108, CLAIM-115; system-map Diagram A; runbook §4)".  
**Validation Commands**: VC-TOGGLE-CHECK, VC-RUNBOOK-PANTHEON.  
**Expected Output Report**: Changed files list; test command outputs summary; residual risks/unknowns; rollback notes for risky changes.

## Completeness checklist
1. Coverage: all 81 prompt IDs are present.
2. References: every prompt includes absolute-path document links.
3. Constraints: every prompt includes phase-specific scope boundaries.
4. Acceptance and validation: every prompt includes measurable acceptance criteria and validation commands.
5. Governance linkage: all deliverable prompts include backlog and risk linkage.
6. Policy: prompts preserve 'LLM live disabled through Phase 2.'
