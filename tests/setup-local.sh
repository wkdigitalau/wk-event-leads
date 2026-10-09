#!/usr/bin/env bash
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
QA_ROOT=/var/tmp/wkel-qa
mkdir -p "$QA_ROOT"
cd "$QA_ROOT"
if [ ! -f wp-cli.phar ]; then curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o wp-cli.phar; fi
if [ ! -d wordpress ]; then curl -fsSL https://wordpress.org/latest.tar.gz -o wordpress.tar.gz; tar -xzf wordpress.tar.gz; fi
service mariadb start
if [ "${1:-}" = "--reset" ]; then
    mariadb -e "DROP DATABASE IF EXISTS wkel_qa_connect; DROP DATABASE IF EXISTS wkel_qa_cofo; DROP DATABASE IF EXISTS wkel_qa_wkdigital;"
fi
mariadb -e "CREATE DATABASE IF NOT EXISTS wkel_qa_connect; CREATE DATABASE IF NOT EXISTS wkel_qa_cofo; CREATE DATABASE IF NOT EXISTS wkel_qa_wkdigital;"

mkdir -p wordpress/wp-content/mu-plugins
ln -sfn "$REPO" wordpress/wp-content/plugins/wk-event-leads
cp "$REPO/tests/qa-config.php" wordpress/wp-config.php
cp "$REPO/tests/qa-guard.php" wordpress/wp-content/mu-plugins/wkel-qa-guard.php
for site in connect cofo wkdigital; do
    export WKEL_QA_SITE="$site"
    if ! php wp-cli.phar core is-installed --path=wordpress --allow-root >/dev/null 2>&1; then
        php wp-cli.phar core install --path=wordpress --allow-root --url="https://$site.wkel.test" --title="WKEL Dummy $site" --admin_user=wkelqa --admin_password='DummyQA-only-DoNotUseInProduction-140' --admin_email=dummy-admin@example.invalid --skip-email
        php wp-cli.phar plugin activate wk-event-leads --path=wordpress --allow-root
    fi
    php wp-cli.phar eval 'echo "WordPress " . get_bloginfo("version") . "; WKEL " . WKEL_VERSION . PHP_EOL;' --path=wordpress --allow-root
    php "$REPO/tests/integration.php" "$QA_ROOT/wordpress" > "$QA_ROOT/$site-integration.txt" 2>&1
    tail -n 3 "$QA_ROOT/$site-integration.txt"
    for mode in missing blank invalid mismatch configured forbidden; do php "$REPO/tests/google.php" "$QA_ROOT/wordpress" "$mode"; done
done
