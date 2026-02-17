# Pantheon Go-Live Checklist

## Scope
Use this checklist before enabling `makerspace_member_success` in production.

## 1) Pre-Deploy (Local)
1. Confirm module code changes are committed in the module repo.
2. Confirm site config changes are committed in the site repo:
   - `config/views.view.member_success_queue.yml`
   - `config/makerspace_member_success.settings.yml` (if changed)
   - `config/core.extension.yml` (if enabling module via config)
3. Run local syntax checks:
   - `php -l web/modules/custom/makerspace_member_success/makerspace_member_success.module`
   - `php -l web/modules/custom/makerspace_member_success/src/Service/MemberSuccessSnapshotBuilder.php`
4. Run fast test pass:
   - `lando phpunit web/modules/custom/makerspace_member_success/tests/src/Unit`

## 2) Deploy to Pantheon
1. Push code/config to target environment branch.
2. Run database updates:
   - `terminus drush <site>.<env> -- updb -y`
3. Import config:
   - `terminus drush <site>.<env> -- cim -y`
4. Rebuild caches:
   - `terminus drush <site>.<env> -- cr`
5. Build current snapshots:
   - `terminus drush <site>.<env> -- ms-build`

## 3) Permissions & Access Smoke Check
Validate roles have intended access:
1. Queue viewers:
   - `access makerspace member success queues`
2. Outreach loggers (staff/privileged volunteers):
   - `log makerspace member success contacts`
3. Admin/settings:
   - `administer makerspace member success`

Routes to verify:
1. `/admin/makerspace/member-success/dashboard`
2. `/admin/makerspace/member-success/onboarding`
3. `/admin/makerspace/member-success/engagement`
4. `/admin/makerspace/member-success/retention`
5. `/admin/makerspace/member-success/recovery`
6. `/admin/config/makerspace/member-success`

## 4) Queue Behavior Smoke Check
1. Risk ordering:
   - Default order should be highest risk first on each queue.
2. Snooze filtering:
   - Members with `next_followup_date` in the future should be hidden.
3. Suppression filtering:
   - `outreach_exhausted` and `confirmed_cancellation` should be hidden.
4. Stage transition reset:
   - If a member moves to a new stage (ex: retention -> recovery due to payment fail),
     prior suppression should clear and member should reappear.

## 5) Retention UX Smoke Check
On `/admin/makerspace/member-success/retention`, verify columns:
1. `Member Since` (time-ago tenure)
2. `Last Door Entry` (days ago / Never)
3. `Visits (30d)`
4. `Risk`
5. `Suggested Action`

## 6) Outreach Workflow Smoke Check
1. Open `Log Interaction` for a recovery member.
2. Submit one outcome from each group:
   - `will_return` (expects follow-up sleep)
   - `payment_updated` (resolved behavior)
   - `all_good` (longer sleep, non-resolved)
3. Verify:
   - outreach log row created (`ms_member_outreach_log`)
   - snapshot fields updated (`last_contact_date`, `next_followup_date`, `contact_count`)
   - followup mapping updated as expected

## 7) Cron/Snapshot Operations
1. Ensure cron runs normally.
2. Confirm latest snapshot rows are being maintained (`is_latest = 1` per member/type).
3. Spot-check actionable counts in queue headers after daily snapshot run.

## 8) Rollback Plan
If serious issue appears:
1. Revert latest code/config commit.
2. `drush cim -y`
3. `drush cr`
4. `drush ms-build`
5. Re-test queue visibility and log-contact flow.
