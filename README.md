# Makerspace Member Success

Provides a member success console for onboarding, engagement, retention, and recapture.

## Features

*   **Dashboard**: High-level statistical overview of member lifecycle stages.
*   **Action Queues**: Targeted lists for Onboarding, Engagement, Retention, and Recovery.
*   **Snapshot System**: Daily snapshots of member data for trend analysis.
*   **CiviCRM Integration**:
    *   Dynamic email links to CiviCRM "Send Email" activity.
    *   Configurable message templates per lifecycle stage.
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

## Architecture

This module uses a custom table `ms_member_success_snapshot` to store daily aggregated data. This ensures fast reporting and Views performance without complex real-time queries. The snapshot builder service runs on cron or via Drush.
