<?php
$qa_site = getenv('WKEL_QA_SITE') ?: 'connect';
if (!in_array($qa_site, ['connect', 'cofo', 'wkdigital'], true)) exit('Invalid QA site');
define('DB_NAME', 'wkel_qa_' . $qa_site);
define('DB_USER', 'root'); define('DB_PASSWORD', getenv('WKEL_QA_DB_PASSWORD') ?: ''); define('DB_HOST', getenv('WKEL_QA_DB_HOST') ?: 'localhost');
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
$table_prefix = 'wp_';
define('AUTH_KEY', 'isolated-qa-auth'); define('SECURE_AUTH_KEY', 'isolated-qa-secure');
define('LOGGED_IN_KEY', 'isolated-qa-login'); define('NONCE_KEY', 'isolated-qa-nonce');
define('AUTH_SALT', 'isolated-qa-auth-salt'); define('SECURE_AUTH_SALT', 'isolated-qa-secure-salt');
define('LOGGED_IN_SALT', 'isolated-qa-login-salt'); define('NONCE_SALT', 'isolated-qa-nonce-salt');
define('WKEL_ENCRYPTION_KEY', str_repeat('ab', 32)); define('WKEL_ENCRYPTION_IV', str_repeat('cd', 16));
define('WKEL_OUTREACH_ALLOW_LIVE', true); // QA transport is always intercepted by the MU guard.
define('DISABLE_WP_CRON', true); define('WP_DEBUG', true); define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false);
if (getenv('WKEL_QA_UI')) { define('WP_HOME', 'http://127.0.0.1:18741'); define('WP_SITEURL', 'http://127.0.0.1:18741'); }
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
