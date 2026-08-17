# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Purpose

A Drupal 11 custom module providing a member success console for makerspace administrators. Tracks the full member lifecycle (onboarding → engagement → retention → recovery) through automated action queues, risk scoring, and CiviCRM-integrated outreach logging.

## Commands

```bash
# Build daily snapshots for all active members
lando drush ms-build

# Build snapshot for a specific user
lando drush ms-build [uid]

# Run all unit tests for this module
lando phpunit --testsuite=unit --filter=MemberSuccess

# Run a single test class
lando phpunit web/modules/custom/makerspace_member_success/tests/src/Unit/MemberSuccessLifecycleTest.php

# Run kernel tests (needs DB)
lando phpunit web/modules/custom/makerspace_member_success/tests/src/Kernel/

# Run all module tests by group
lando phpunit --group=makerspace_member_success
```

See parent `CLAUDE.md` for full Lando setup and Playwright commands.

## Architecture

### Snapshot Pattern (core design)

Never query Drupal users, badges, access logs, or CiviCRM contacts in real time. All member metrics are pre-aggregated daily into `ms_member_success_snapshot`. The `is_latest = 1` flag marks the current snapshot per user.

To add a new metric: add a column in `makerspace_member_success.install`, populate it in `MemberSuccessSnapshotBuilder::buildSnapshotForUser()`, expose it in `hook_views_data()` in the `.module` file, and optionally create a Views field plugin in `src/Plugin/views/field/`.

### Lifecycle State Machine

```
onboarding → engagement → retention
                ↑               ↑
                └── recovery ───┘  (payment failure)
```

- **Onboarding**: door badge not active OR serial number missing
- **Engagement**: door badge active, activation < `new_member_days` (default 180d)
- **Retention**: door badge active, activation ≥ 180d
- **Recovery**: `payment_failed = true` (payment pause does NOT trigger recovery)
- **Paused**: `payment_pause = true` — and pause WINS over a lingering
  payment-failed flag (2026-08-17): a member whose failed invoice was written
  off before pausing must not sit in recovery for their whole pause.

Stage transition resets outreach tracking (but never operator suppression
statuses). Separately, a **new payment-failure episode** — the payment-failed
flag going 0→1 against the previous snapshot — re-opens the member's outreach
file entirely: snoozes, attempt counts, AND the episode-scoped statuses
`outreach_exhausted` / `confirmed_cancellation` / `no_action_needed` /
`return_intent` (policy: Kate 2026-08-17; `needs_review` survives). Card
retries and second cards inside one unresolved failure are the SAME episode
and never re-trigger this. All stage/outcome/status constants live in
`src/Support/MemberSuccessLifecycle.php` — always use those, never raw strings.

### Queue Visibility (snooze/suppression)

After logging a contact, members are hidden from queues based on:
1. `next_followup_date > today` — snooze period (3, 7, or 14 days by outcome)
2. `outreach_status` in a resolved/suppressed state (exhausted, confirmed cancellation, return intent)

All visibility decisions go through `src/Support/MemberSuccessQueueRules.php`. SQL filter application is in `src/Service/MemberSuccessQueueQueryApplier.php`, wired via `hook_views_query_alter()`.

### Key Services

| Service ID | Class | Role |
|---|---|---|
| `makerspace_member_success.snapshot_builder` | `MemberSuccessSnapshotBuilder` | Aggregates all member signals into snapshot rows |
| `makerspace_member_success.activity_logger` | `CiviCrmActivityLogger` | Creates CiviCRM activities when outreach is logged |
| `makerspace_member_success.civicrm_helper` | `CiviCrmHelper` | Fetches CiviCRM message templates for email action links |
| `makerspace_member_success.recovery_metrics` | `RecoveryMetrics` | Analytics: resolution rate, avg attempts, exhaustion rate |
| `makerspace_member_success.queue_query_applier` | `MemberSuccessQueueQueryApplier` | Applies snooze/suppression SQL to Views queries |

### Outreach Flow

1. Staff clicks "Log Contact" → `LogContactForm` at `/admin/makerspace/member-success/log-contact/{user}`
2. Form writes to `ms_member_outreach_log` (append-only; used for analytics)
3. Form updates the current snapshot row: `last_contact_date`, `next_followup_date`, `contact_count`, `outreach_status`
4. Optionally creates a CiviCRM activity via `CiviCrmActivityLogger`
5. Member disappears from queue until snooze date passes or suppression clears

### Contact Outcomes → Sleep Periods

| Outcome | Sleep | Followup Status |
|---|---|---|
| `payment_updated` | permanent | NULL (resolved) |
| `confirmed_cancel` | permanent | `confirmed_cancellation` |
| `will_return` | 14 days | `return_intent` |
| `needs_time` | 14 days | `outreach_active` |
| `no_answer` | 3 days | `outreach_active` |
| `left_message` | 7 days | `outreach_active` |
| `email_sent` | 7 days | `outreach_active` |
| `email_bounced` | 0 days | `outreach_active` |

Mappings live in `MemberSuccessLifecycle::sleepDaysForOutcome()` and `followupStatusForOutcome()`.

## Configuration

Settings at `/admin/config/makerspace/member-success` (`makerspace_member_success.settings`):

- `door_badge_tid` — Taxonomy term ID for door badge (default: 1519)
- `badge_one_days` — Days before missing first badge is flagged (default: 28)
- `badge_four_days` — Days window for 4-badge engagement check (default: 180)
- `new_member_days` — Onboarding/engagement cutoff in days (default: 180)
- `retention_recency_days` — Visit inactivity thresholds for risk tiers (default: [30, 60, 90])
- `civicrm_preferred_method_field` — CiviCRM field name for contact preference
- `outreach_activity_types` — CiviCRM activity types counted as outreach

## Database Tables

- `ms_member_success_snapshot` — Daily denormalized member metrics; query always with `is_latest = 1` for current state
- `ms_member_outreach_log` — Append-only contact history; used only for analytics queries

## Testing Structure

```
tests/src/Unit/       # Pure PHP; no DB; test outcome mappings, queue rules, risk logic
tests/src/Kernel/     # Drupal DB; test snapshot writes, stage transition resets
tests/src/Functional/ # Full Drupal browser; test form submissions, queue visibility
```

The `src/Support/` classes (`MemberSuccessLifecycle`, `MemberSuccessQueueRules`) are designed for easy unit testing — no Drupal dependencies.

## Refactor Status (docs/REFACTOR_PLAN.md)

Phases 1 and 2 are complete (domain constants extracted, queue rules isolated). Phase 3 (extract risk scoring into a dedicated scorer class) is next. Avoid embedding new risk logic directly in `MemberSuccessSnapshotBuilder`; keep it extractable.
