# Makerspace Member Success

A Drupal custom module that provides a comprehensive member success console for makerspace administrators. It focuses on the full member lifecycle: onboarding, engagement, retention, and recovery.

## Project Overview

*   **Purpose**: To help makerspace staff track member health, identify at-risk members, and manage outreach through automated "Action Queues."
*   **Architecture**: Uses a **Snapshot Pattern**. A custom database table (`ms_member_success_snapshot`) stores daily aggregated metrics for each member, allowing for high-performance dashboards and views without real-time joins across Drupal and CiviCRM.
*   **Core Technologies**:
    *   **Drupal 10/11**: Core framework.
    *   **CiviCRM**: Source of truth for contact data and outreach history.
    *   **MySQL**: Custom table for snapshots.
    *   **Drush**: For CLI maintenance and snapshot building.

## Key Features

*   **Lifecycle Stages**:
    *   **Onboarding**: New joins needing access (badge status, serial numbers).
    *   **Engagement**: Members in their first 6 months (badge acquisition frequency).
    *   **Retention**: Sustaining members (visit recency).
    *   **Recovery**: Members with payment failures or pauses.
*   **Risk Scoring**: Automated calculation based on payment status, badge count, and facility usage recency.
*   **Dashboards & Queues**:
    *   **Admin Dashboard**: `/admin/makerspace/member-success/dashboard`
    *   **Action Queues**: Specialized Views for each lifecycle stage with direct outreach links.
*   **CiviCRM Integration**: Fetches communication preferences and provides "Send Email" links using configured CiviCRM message templates.

## Key Files & Services

*   `src/Service/MemberSuccessSnapshotBuilder.php`: The primary engine that aggregates data from Users, Profiles, Badge Requests, Access Control Logs, and CiviCRM to build snapshot rows.
*   `src/Controller/MemberSuccessDashboardController.php`: Renders the high-level statistical overview.
*   `src/Commands/MemberSuccessCommands.php`: Implementation of `ms-snapshot:build` (alias `ms-build`).
*   `makerspace_member_success.install`: Defines the `ms_member_success_snapshot` schema and handles view configuration imports.

## Building and Running

### Installation
1.  Enable module: `lando drush en makerspace_member_success`
2.  Import/Verify Views: The module attempts to import the `member_success_queue` view on install/update.

### Snapshot Management
*   **Build all daily snapshots**: `lando drush ms-build`
*   **Build for specific user**: `lando drush ms-build [uid]`

### Configuration
*   **Settings UI**: `/admin/config/makerspace/member-success`
*   **Config Name**: `makerspace_member_success.settings`
*   **Tunables**: Badge thresholds, tenure windows, risk score weights, and CiviCRM template IDs.

## Development Conventions

*   **Data Aggregation**: Always use `MemberSuccessSnapshotBuilder` to add new metrics to the member record. Avoid real-time joins in Views; instead, add a column to the snapshot table and update the service.
*   **CiviCRM**: Use the `Civicrm` service and APIv3 (or v4 if updated) via `CiviCrmHelper` for all CRM interactions.
*   **Lando**: This project is typically run via Lando. Prefix Drush commands with `lando`.
