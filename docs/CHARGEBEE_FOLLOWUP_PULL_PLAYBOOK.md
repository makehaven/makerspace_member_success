# Chargebee Followup Pull Playbook

## Goal
Populate Drupal `field_member_followup_status` from existing Chargebee custom field values (`cf_Cancelation_Followup`) for members with Chargebee IDs.

This is intended for one-time migration/backfill and occasional reconciliation.

## Preconditions
1. The environment has code containing:
   - `ms:chargebee-pull-followup`
   - `ms:chargebee-pull-followup-bulk`
2. Chargebee API credentials are configured in `chargebee_portal.settings`.
3. `field_member_followup_status` exists on users.

## 1) Validate Current State (Test)
```bash
terminus drush makehaven-website.test -- ms:followup-audit
```

## 2) Dry-Run Pull (Test)
Start with a bounded sample:
```bash
terminus drush makehaven-website.test -- ms:chargebee-pull-followup-bulk --dry-run --limit=200 --only-empty
```

Then run the full dry-run:
```bash
terminus drush makehaven-website.test -- ms:chargebee-pull-followup-bulk --dry-run --limit=5000 --only-empty
```

Review summary output:
1. `Users changed`
2. `Skipped reason` counts
3. Any `unmapped_chargebee_value` cases

## 3) Apply Pull (Test)
```bash
terminus drush makehaven-website.test -- ms:chargebee-pull-followup-bulk --limit=5000 --only-empty
```

Verify:
```bash
terminus drush makehaven-website.test -- ms:followup-audit
```

Expected: `Canonical values populated` increases from baseline.

## 4) Repeat on Live (Dry-Run First)
```bash
terminus drush makehaven-website.live -- ms:followup-audit
terminus drush makehaven-website.live -- ms:chargebee-pull-followup-bulk --dry-run --limit=5000 --only-empty
```

If dry-run looks correct, apply:
```bash
terminus drush makehaven-website.live -- ms:chargebee-pull-followup-bulk --limit=5000 --only-empty
terminus drush makehaven-website.live -- ms:followup-audit
```

## 5) Optional Overwrite Mode
Default behavior does not overwrite non-empty Drupal followup values that differ from Chargebee.

If explicit overwrite is required:
```bash
terminus drush makehaven-website.<env> -- ms:chargebee-pull-followup-bulk --overwrite --limit=5000
```

Use `--overwrite` only after reviewing dry-run output.

