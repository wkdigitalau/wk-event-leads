#!/usr/bin/env bash
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
export WKEL_QA_SITE=cofo
php "$REPO/tests/race.php" /var/tmp/wkel-qa/wordpress setup
for i in 1 2 3 4; do php "$REPO/tests/race.php" /var/tmp/wkel-qa/wordpress send > "/var/tmp/wkel-qa/race-$i.txt" 2>&1 & done
wait
php "$REPO/tests/race.php" /var/tmp/wkel-qa/wordpress verify
