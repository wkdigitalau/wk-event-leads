#!/usr/bin/env bash
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
QA_ROOT=/var/tmp/wkel-qa
mkdir -p "$QA_ROOT/wp64"
curl -fsSL https://wordpress.org/wordpress-6.4.3.tar.gz -o "$QA_ROOT/wp64.tar.gz"
tar -xzf "$QA_ROOT/wp64.tar.gz" -C "$QA_ROOT/wp64" --strip-components=1
cp "$REPO/tests/qa-config.php" "$QA_ROOT/wp64/wp-config.php"
python3 - "$QA_ROOT/wp64/wp-config.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text().replace("'wkel_qa_' . $qa_site", "'wkel_qa_minimum'"); p.write_text(s)
PY
mkdir -p "$QA_ROOT/wp64/wp-content/mu-plugins"
cp "$REPO/tests/qa-guard.php" "$QA_ROOT/wp64/wp-content/mu-plugins/"
ln -sfn "$REPO" "$QA_ROOT/wp64/wp-content/plugins/wk-event-leads"
mariadb -e "CREATE DATABASE IF NOT EXISTS wkel_qa_minimum;"
export WKEL_QA_SITE=cofo
php "$QA_ROOT/wp-cli.phar" core install --path="$QA_ROOT/wp64" --allow-root --url=https://cofo.wkel.test --title='WKEL Dummy minimum' --admin_user=wkelqa --admin_password='DummyQA-only-DoNotUseInProduction-140' --admin_email=dummy-admin@example.invalid --skip-email
php "$QA_ROOT/wp-cli.phar" plugin activate wk-event-leads --path="$QA_ROOT/wp64" --allow-root
php "$REPO/tests/integration.php" "$QA_ROOT/wp64" > "$QA_ROOT/minimum-integration.txt" 2>&1
tail -n 3 "$QA_ROOT/minimum-integration.txt"
