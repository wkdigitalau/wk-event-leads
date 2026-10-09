#!/usr/bin/env bash
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
for site in connect cofo wkdigital; do
    export WKEL_QA_SITE="$site"
    php /var/tmp/wkel-qa/wp-cli.phar eval-file "$REPO/tests/isolation.php" setup --path=/var/tmp/wkel-qa/wordpress --allow-root
 done
for site in connect cofo wkdigital; do
    export WKEL_QA_SITE="$site"
    php /var/tmp/wkel-qa/wp-cli.phar eval-file "$REPO/tests/isolation.php" verify --path=/var/tmp/wkel-qa/wordpress --allow-root
 done
