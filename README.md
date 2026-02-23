# Makerspace Member Success

Provides a member success console for onboarding, engagement, retention, and recapture.

## 🎯 Quick Access

### **📊 Intervention Performance Dashboard**
**[View Dashboard](/admin/makerspace/member-success/contractor-performance)**

Track staff/volunteer effectiveness, ROI, and outreach success rates.

**What you'll see:**
- Members at risk & annual value saved
- Staff performance (contacts, resolutions, success rates)
- Channel effectiveness (phone vs email vs in-person)
- Monthly trends (last 6 months)
- Average days to resolution

**Quick Stats Guides:**
- **2-minute setup**: See `/CONTRACTOR_STATS_QUICK_START.md` in project root
- **Detailed guide**: See `/docs/CONTRACTOR_STATS_USAGE_GUIDE.md`

---

## Features

*   **Dashboard**: High-level statistical overview of member lifecycle stages.
*   **Action Queues**: Targeted lists for Onboarding, Engagement, Retention, and Recovery.
*   **Intervention Performance Metrics**: ROI calculations, staff effectiveness, channel success rates.
*   **Snapshot System**: Daily snapshots of member data for trend analysis.
*   **CiviCRM Integration**:
    *   Dynamic email links to CiviCRM "Send Email" activity.
    *   Configurable message templates per lifecycle stage.
    *   Automatic activity logging for all outreach.
*   **Risk Scoring**: Automated risk assessment based on payment status, badge acquisition, and visit frequency.

## Installation

1.  Enable the module: `drush en makerspace_member_success`
2.  Run database updates: `drush updb`
3.  Generate initial snapshots: `drush ms-build`

## Configuration

Go to `/admin/config/makerspace/member-success` to configure:
*   **Thresholds**: Days for onboarding, engagement windows, and retention checks.
*   **Email Templates**: Select default CiviCRM message templates for each stage's action button.
*   **Mappings**: Define which CiviCRM activity types count as outreach.

## Drush Commands

*   `drush ms-snapshot:build` (alias: `ms-build`): Generates daily snapshots for all active members.
*   `drush ms-build [uid]`: Generates a snapshot for a specific user ID.

## Template Variants (Issue-Based)

Use stage defaults as your baseline, then add targeted variants in settings:

- `Email template overrides`
- `SMS template overrides`

Rule format (one per line):

`stage.reason=template_id`

Examples:

- `recovery.payment_failed=123`
- `onboarding.door_badge_pending=456`
- `retention.inactive_*=789`
- `*.payment_failed=123` (applies to all stages)

Matching behavior:

1. Exact stage + exact reason (highest priority)
2. Exact stage + wildcard reason
3. Wildcard stage + exact reason
4. Wildcard stage + wildcard reason
5. Stage default template fallback

This keeps template branching in policy/config, instead of large conditional blocks in one message template.

## Key URLs

After installation, access these dashboards:

- **Intervention Performance**: `/admin/makerspace/member-success/contractor-performance`
- **Member Success Dashboard**: `/admin/makerspace/member-success/dashboard`
- **Onboarding Queue**: `/admin/makerspace/member-success/onboarding`
- **Engagement Queue**: `/admin/makerspace/member-success/engagement`
- **Retention Queue**: `/admin/makerspace/member-success/retention`
- **Recovery Queue**: `/admin/makerspace/member-success/recovery`
- **Settings**: `/admin/config/makerspace/member-success`

Find these in the admin menu under: **Admin → Member Success**

## Services

- `makerspace_member_success.snapshot_builder` - Daily snapshot generation and risk scoring
- `makerspace_member_success.recovery_metrics` - Contractor performance analytics and ROI calculations

## Architecture

This module uses custom tables:
- `ms_member_success_snapshot` - Daily aggregated member data for fast reporting
- `ms_member_outreach_log` - Complete history of all contact attempts

The snapshot builder service runs daily via cron or manually via Drush. This ensures fast reporting and Views performance without complex real-time queries.

## Refactor Roadmap

Incremental refactor plan is documented in:
- `web/modules/custom/makerspace_member_success/docs/REFACTOR_PLAN.md`

Production deployment checklist is documented in:
- `web/modules/custom/makerspace_member_success/docs/PANTHEON_GO_LIVE_CHECKLIST.md`
