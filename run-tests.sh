#!/bin/bash

# Member Success Module Test Runner
# Usage: ./run-tests.sh [unit|functional|all]

set -e

cd /app

echo "========================================="
echo "Member Success Module Tests"
echo "========================================="
echo ""

case "${1:-all}" in
  unit)
    echo "Running Unit Tests..."
    vendor/bin/phpunit \
      --bootstrap=web/core/tests/bootstrap.php \
      --configuration=web/core \
      web/modules/custom/makerspace_member_success/tests/src/Unit/
    ;;

  functional)
    echo "Running Functional Tests..."
    vendor/bin/phpunit \
      --bootstrap=web/core/tests/bootstrap.php \
      --configuration=web/core \
      --group=makerspace_member_success \
      web/modules/custom/makerspace_member_success/tests/src/Functional/
    ;;

  e2e)
    echo "Running E2E Tests..."
    cd playwright-tests/
    npx playwright test member-success-workflow.spec.ts
    ;;

  all)
    echo "Running All Tests..."
    echo ""
    echo "--- Unit Tests ---"
    vendor/bin/phpunit \
      --bootstrap=web/core/tests/bootstrap.php \
      --configuration=web/core \
      web/modules/custom/makerspace_member_success/tests/src/Unit/

    echo ""
    echo "--- Functional Tests ---"
    vendor/bin/phpunit \
      --bootstrap=web/core/tests/bootstrap.php \
      --configuration=web/core \
      --group=makerspace_member_success \
      web/modules/custom/makerspace_member_success/tests/src/Functional/

    echo ""
    echo "✅ All tests complete!"
    ;;

  *)
    echo "Usage: $0 [unit|functional|e2e|all]"
    exit 1
    ;;
esac

echo ""
echo "✅ Tests complete!"
