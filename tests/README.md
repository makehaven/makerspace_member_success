# Member Success Module - Test Suite

Comprehensive testing for the Member Success Queue system including unit tests, functional tests, and E2E visual tests.

---

## Test Coverage

### Unit Tests (`tests/src/Unit/`)

Fast, isolated tests for business logic:

- ✅ Outcome → Followup Status mapping
- ✅ Sleep period calculation (7, 14 days, etc.)
- ✅ Conditional outcome options by contact method
- ✅ Manual "Outreach Exhausted" override

**Run unit tests:**
```bash
lando phpunit --testsuite=unit --filter=makerspace_member_success
```

### Functional Tests (`tests/src/Functional/`)

Full-stack Drupal tests with database interactions:

- ✅ Log contact form submission
- ✅ Snapshot outreach tracking updates
- ✅ Auto-mapping to followup status
- ✅ Sleep period calculation (next_followup_date)
- ✅ Outreach log table entries
- ✅ Payment updated → resolved (9999-12-31)
- ✅ Will return → 14-day sleep
- ✅ Email sent → 7-day sleep
- ✅ Manual exhausted override

**Run functional tests:**
```bash
lando phpunit --group=makerspace_member_success
```

### Playwright E2E Tests (`playwright-tests/`)

Visual regression and full browser workflow tests:

- ✅ Queue layout with photos and account links
- ✅ Member name displays correctly (not "Unknown")
- ✅ Payment risk badges (Failed/Paused/OK)
- ✅ Risk score sorting (DESC, highest first)
- ✅ Log Contact form displays phone number
- ✅ Conditional outcome options (AJAX)
- ✅ Sleep behavior verification
- ✅ Visual regression snapshots

**Run E2E tests:**
```bash
cd playwright-tests/
npx playwright test member-success-workflow.spec.ts
```

**Run with UI mode:**
```bash
cd playwright-tests/
npx playwright test member-success-workflow.spec.ts --ui
```

**Update visual regression snapshots:**
```bash
cd playwright-tests/
npx playwright test member-success-workflow.spec.ts --update-snapshots
```

---

## Quick Start

### 1. Run All Unit Tests
```bash
lando phpunit --testsuite=unit --filter=makerspace_member_success
```

### 2. Run All Functional Tests
```bash
lando phpunit --group=makerspace_member_success
```

### 3. Run E2E Visual Tests
```bash
cd playwright-tests/
npx playwright test member-success-workflow.spec.ts
```

---

## Test Scenarios Covered

### Auto-Mapping Logic
| Outcome | Expected Status | Sleep Days | Test Coverage |
|---------|----------------|------------|---------------|
| Payment Updated | NULL (resolved) | -1 (permanent) | ✅ Unit, Functional, E2E |
| Confirmed Cancel | confirmed_cancellation | -1 (permanent) | ✅ Unit, Functional |
| Will Return | return_intent | 14 days | ✅ Unit, Functional, E2E |
| Needs Time | outreach_active | 14 days | ✅ Unit, Functional |
| No Answer | outreach_active | 3 days | ✅ Unit, Functional |
| Left Voicemail | outreach_active | 7 days | ✅ Unit, Functional |
| Email Sent | outreach_active | 7 days | ✅ Unit, Functional, E2E |
| Email Bounced | outreach_active | 0 days | ✅ Unit, Functional |

### Conditional Outcomes by Method
| Method | Available Outcomes | Test Coverage |
|--------|-------------------|---------------|
| Phone | Spoke, No Answer, Left Voicemail | ✅ Unit, E2E |
| Email | Replied, Sent, Bounced | ✅ Unit, E2E |
| In-Person | Spoke (various) | ✅ Unit |
| Other | Contact Made, No Response | ✅ Unit |

### Visual Regression
- Queue layouts (all 4 queues)
- Member photos and placeholders
- Account links (CRM, CB, Stripe)
- Payment risk badges
- Days Waiting color coding
- Log Contact form

---

## Test Data Setup

### Functional Tests
Functional tests automatically create:
- Admin user with permissions
- Test member user
- Member success snapshot
- Required database tables

### E2E Tests
E2E tests require:
- Test users created via `scripts/setup-test-users.php`
- Database with sample snapshot data
- Running local Lando environment

**Setup test users:**
```bash
lando drush php:script scripts/setup-test-users.php
```

---

## Debugging Failed Tests

### Unit Tests
```bash
# Verbose output
lando phpunit --testsuite=unit --filter=LogContactFormTest --verbose

# Debug specific test
lando phpunit --testsuite=unit --filter=testMapOutcomeToFollowupStatus
```

### Functional Tests
```bash
# Show HTML output on failure
lando phpunit --group=makerspace_member_success --printer HTML

# Run single test method
lando phpunit --filter=testLogPhoneContactWillReturn
```

### E2E Tests
```bash
# Debug mode with browser visible
cd playwright-tests/
npx playwright test member-success-workflow.spec.ts --debug

# Run specific test
npx playwright test -g "Log contact with Will Return"

# View test report
npx playwright show-report
```

---

## CI/CD Integration

### GitHub Actions Example
```yaml
- name: Run Member Success Unit Tests
  run: lando phpunit --testsuite=unit --filter=makerspace_member_success

- name: Run Member Success Functional Tests
  run: lando phpunit --group=makerspace_member_success

- name: Run Member Success E2E Tests
  run: |
    cd playwright-tests/
    npx playwright test member-success-workflow.spec.ts
```

---

## Adding New Tests

### Unit Test Template
```php
public function testYourNewFeature() {
  $form = $this->getMockBuilder(LogContactForm::class)
    ->disableOriginalConstructor()
    ->onlyMethods([])
    ->getMock();

  $method = new \ReflectionMethod(LogContactForm::class, 'yourMethod');
  $method->setAccessible(TRUE);

  $result = $method->invoke($form, 'input');
  $this->assertEquals('expected', $result);
}
```

### Functional Test Template
```php
public function testYourWorkflow() {
  $this->drupalLogin($this->adminUser);
  $this->drupalGet('/your/path');

  $edit = ['field' => 'value'];
  $this->submitForm($edit, 'Submit');

  $this->assertSession()->pageTextContains('Success');
}
```

### E2E Test Template
```typescript
test('Your visual test', async ({ page }) => {
  await page.goto('/your/path');
  await expect(page.locator('selector')).toBeVisible();
  await page.screenshot({ path: 'screenshots/your-feature.png' });
});
```

---

## Test Maintenance

### When to Update Tests

**Update unit tests when:**
- Mapping logic changes
- Sleep periods change
- New outcomes added

**Update functional tests when:**
- Form fields change
- Database schema changes
- Workflow changes

**Update E2E tests when:**
- UI layout changes
- New features added
- User workflows change

### Updating Visual Snapshots
```bash
cd playwright-tests/
npx playwright test member-success-workflow.spec.ts --update-snapshots
```

---

## Coverage Reports

### Generate PHPUnit Coverage
```bash
lando phpunit --group=makerspace_member_success --coverage-html coverage/
```

View at: `web/modules/custom/makerspace_member_success/coverage/index.html`

---

## Known Issues / TODO

- [ ] CiviCRM activity creation testing (requires CiviCRM test environment)
- [ ] Phone number display testing (requires CiviCRM data)
- [ ] Multi-contact tracking (3+ attempts warning)
- [ ] Snapshot rebuild triggers after contact logging

---

**Last Updated:** 2026-02-15
**Test Coverage:** ~85% (estimated)
