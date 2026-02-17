# Makerspace Member Success Refactor Plan

## Goal
Refactor toward a stable, production-ready structure that supports:
- clear intervention queues,
- repeatable outreach workflow,
- accurate suppression/snooze behavior,
- measurable member outcomes.

This plan keeps current behavior working while improving structure incrementally.

## Target Architecture
1. Lifecycle domain layer:
   `stages`, `outcomes`, `followup statuses`, and transition rules in one place.
2. Snapshot/risk engine:
   deterministic scoring and stage assignment from raw member signals.
3. Case workflow layer:
   outreach state (attempts, snooze date, owner, resolution) managed consistently.
4. Presentation layer:
   views/plugins/forms focused on display and input, not business logic.
5. Metrics layer:
   recovery/retention reporting from normalized outreach data.

## Phase Plan
### Phase 1: Domain Consolidation (Now)
- Add shared lifecycle constants and mappings.
- Remove duplicated magic strings in core paths:
  snapshot builder, queue suppression, contact logging.
- Keep behavior unchanged.

### Phase 2: Queue Rules Hardening
- Move queue visibility logic into dedicated helper/service.
- Add explicit tests for:
  stage transitions,
  suppression reset behavior,
  snooze date filtering.

### Phase 3: Risk/Scoring Isolation
- Extract stage + risk scoring into dedicated scorer class(es).
- Keep thresholds config-driven.
- Add unit tests for each stage scenario.

### Phase 4: Case-Style Workflow
- Introduce explicit work-item/case model (or case-like table fields):
  `status`, `owner_uid`, `snoozed_until`, `resolution_reason`.
- Keep outreach log append-only for analytics.

### Phase 5: UX + Operations
- Add clearer queue affordances:
  owner, snooze reason, resolved reason.
- Add deployment/runbook checks for cron/snapshot health.

## Initial Refactor Started
Implemented in this pass:
- Added `src/Support/MemberSuccessLifecycle.php` as canonical lifecycle mapping.
- Updated:
  - `src/Form/LogContactForm.php`
  - `src/Service/MemberSuccessSnapshotBuilder.php`
  - `makerspace_member_success.module`
  to use shared lifecycle constants/mappings.

This is the first step toward extracting rules from UI and hook code into stable domain logic.

## Phase 2 Started
Implemented in this pass:
- Added `src/Support/MemberSuccessQueueRules.php` for queue visibility and
  suppression reset decisions.
- Added `src/Service/MemberSuccessQueueQueryApplier.php` to centralize Views
  queue SQL condition building.
- Wired queue/suppression logic through this helper in:
  - `makerspace_member_success.module`
  - `src/Service/MemberSuccessSnapshotBuilder.php`
- Added unit tests:
  - `tests/src/Unit/MemberSuccessQueueRulesTest.php`
  - `tests/src/Unit/MemberSuccessLifecycleTest.php`
  - `tests/src/Unit/MemberSuccessQueueQueryApplierTest.php`
- Added end-to-end hardening tests:
  - `tests/src/Functional/QueueVisibilityFunctionalTest.php`
  - `tests/src/Kernel/StageTransitionResetKernelTest.php`
