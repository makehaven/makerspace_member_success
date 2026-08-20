#!/bin/bash
# Run this module's kernel tests inside Lando: lando ssh -c "bash web/modules/custom/makerspace_member_success/run-kernel-tests.sh [path...]"
set -e
cd /app
export SIMPLETEST_DB="mysql://pantheon:pantheon@database/pantheon"
export SIMPLETEST_BASE_URL="http://appserver"
TARGETS="${@:-web/modules/custom/makerspace_member_success/tests/src/Kernel}"
vendor/bin/phpunit -c web/core/phpunit.xml.dist --no-progress $TARGETS
