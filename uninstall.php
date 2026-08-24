<?php
/**
 * WK Event Leads — uninstall.php
 * Runs when plugin is deleted from the WordPress admin.
 * Removes all plugin data: options, posts, meta, scheduled actions.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// -------------------------------------------------------------------------
// 1. Remove all wp_options entries
// -------------------------------------------------------------------------

$options = [
    'wkel_version',
    'wkel_field_schema',
    'wkel_pipeline_stages',
    'wkel_plugin_display_name',
    'wkel_privacy_policy_url',
    'wkel_success_message',
    'wkel_rate_limit',
    'wkel_honeypot_enabled',
    'wkel_data_retention_days',
    'wkel_sendgrid_key',
    'wkel_resend_key',
    'wkel_resend_webhook_secret',
    'wkel_email_from_name',
    'wkel_email_from_address',
    'wkel_email_reply_to',
    'wkel_email_subject',
    'wkel_email_body',
    'wkel_sender_name',
    'wkel_sender_phone',
    'wkel_sender_email',
    'wkel_atncs_url',
    'wkel_enp_url',
    'wkel_event_map',
    'wkel_encryption_key_missing',
];

foreach ($options as $option) {
    delete_option($option);
}

// -------------------------------------------------------------------------
// 2. Remove all wkel_lead posts and their meta
// -------------------------------------------------------------------------

global $wpdb;

$lead_ids = $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wkel_lead'"
);

foreach ($lead_ids as $lead_id) {
    // Delete all meta for this lead
    $wpdb->delete($wpdb->postmeta, ['post_id' => (int) $lead_id]);
}

if (!empty($lead_ids)) {
    $ids_placeholder = implode(',', array_fill(0, count($lead_ids), '%d'));
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE post_type = 'wkel_lead' AND ID IN ($ids_placeholder)",
            ...$lead_ids
        )
    );
}

// -------------------------------------------------------------------------
// 3. Remove scheduled actions from Action Scheduler
// -------------------------------------------------------------------------

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('', [], 'wk-event-leads');
    as_unschedule_all_actions('wkel_send_confirmation_email', [], 'wk-event-leads');
}

// -------------------------------------------------------------------------
// 4. Clear any rate-limiting transients
// -------------------------------------------------------------------------

$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_wkel_rate_%'
     OR option_name LIKE '_transient_timeout_wkel_rate_%'"
);

// Flush rewrite rules
flush_rewrite_rules();
