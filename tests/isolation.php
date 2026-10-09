<?php
if (!str_starts_with(DB_NAME, 'wkel_qa_') || !defined('WKEL_QA_HTTP_GUARD')) throw new RuntimeException('QA guard required');
global $wpdb;
$site = getenv('WKEL_QA_SITE');
if (($args[0] ?? '') === 'setup') {
    update_option('wkel_resend_key', WKEL_Encryption::encrypt('dummy-isolated-key-' . $site));
    if (!$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wkel_template' AND post_title = %s", 'QA isolation ' . $site))) wp_insert_post(['post_type' => 'wkel_template', 'post_status' => 'private', 'post_title' => 'QA isolation ' . $site]);
} else {
    $titles = $wpdb->get_col("SELECT post_title FROM {$wpdb->posts} WHERE post_type = 'wkel_template' AND post_title LIKE 'QA isolation %'");
    if ($titles !== ['QA isolation ' . $site]) throw new RuntimeException('Template isolation failed');
    if (WKEL_Encryption::decrypt(get_option('wkel_resend_key')) !== 'dummy-isolated-key-' . $site) throw new RuntimeException('Credential isolation failed');
    if (get_option('wkel_qa_site_marker') !== DB_NAME) throw new RuntimeException('Database marker isolation failed');
    echo "PASS: $site templates, encrypted credentials and database marker are isolated.\n";
}
