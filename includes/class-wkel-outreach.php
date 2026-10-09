<?php
defined('ABSPATH') || exit;

/** Explicit, single-recipient Outreach; saving and previewing never deliver mail. */
class WKEL_Outreach {
    public static function register(): void {
        register_post_type('wkel_template', ['public' => false, 'show_ui' => false, 'show_in_rest' => false,
            'supports' => ['title'], 'rewrite' => false]);
        WKEL_Outreach_Store::upgrade();
        if (add_option('wkel_migration_lock', time(), '', false)) {
            try { self::migrate(); } finally { delete_option('wkel_migration_lock'); }
        } elseif ((int) get_option('wkel_migration_lock') < time() - 300) {
            delete_option('wkel_migration_lock');
        }
    }

    public static function menu(): void {
        add_submenu_page('wkel_leads', 'Outreach', 'Outreach', 'manage_options', 'wkel_outreach', [self::class, 'render']);
    }

    public static function migrate(): void {
        if (get_option('wkel_outreach_schema') !== '1' || get_option('wkel_outreach_migrated') === '1') return;
        // Legacy jobs are also blocked permanently at their callback.
        if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions('wkel_send_confirmation_email', [], 'wk-event-leads');
        global $wpdb;
        $last_id = (int) get_option('wkel_outreach_migration_last_id', 0);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wkel_lead' AND ID > %d ORDER BY ID ASC LIMIT 100", $last_id));
        foreach ($ids as $id) {
            if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions('wkel_send_confirmation_email', ['lead_id' => (int) $id], 'wk-event-leads');
            $old = (string) get_post_meta($id, '_wkel_email_status', true);
            if (!get_post_meta($id, '_wkel_outreach_status', true)) {
                $history_state = match ($old) {
                    'sent', 'delivered' => 'sent', 'failed' => 'failed', 'bounced' => 'bounced',
                    'unsubscribed', 'suppressed' => 'suppressed', default => 'draft',
                };
                $state = get_post_meta($id, '_wkel_marketing_status', true) === 'unsubscribed' ? 'suppressed' : $history_state;
                update_post_meta($id, '_wkel_outreach_status', $state);
                $resend = (string) get_post_meta($id, '_wkel_resend_email_id', true);
                if ($resend) {
                    global $wpdb;
                    $sent = (int) get_post_meta($id, '_wkel_email_sent_at', true);
                    $wpdb->insert(WKEL_Outreach_Store::table('messages'), ['lead_id' => $id, 'template_id' => 0, 'revision' => 0, 'author_id' => 0,
                        'authorization_hash' => hash('sha256', 'legacy:' . $id . ':' . $resend), 'mode' => 'live', 'status' => $history_state,
                        'delivery' => $old === 'delivered' ? 'delivered' : 'legacy', 'payload' => '', 'resend_id' => $resend, 'created_at' => $sent ?: time(), 'sent_at' => $sent]);
                }
                if ($old === 'queued') update_post_meta($id, '_wkel_email_status', 'draft');
                if (get_post_meta($id, '_wkel_marketing_status', true) === 'unsubscribed' || in_array($old, ['bounced', 'suppressed', 'unsubscribed'], true)) {
                    WKEL_Outreach_Store::suppress(WKEL_Email::get_lead_email($id), $old === 'bounced' ? 'bounced' : 'unsubscribed', 'migration');
                }
            }
        }
        if ($ids) update_option('wkel_outreach_migration_last_id', (int) end($ids), false);
        if (count($ids) === 100) return;
        if (!get_option('wkel_outreach_legacy_template')) {
            $id = self::save_template(0, 'Imported legacy template — review required',
                (string) get_option('wkel_email_subject', ''), (string) get_option('wkel_email_body', ''));
            if (is_wp_error($id)) return;
            update_option('wkel_outreach_legacy_template', $id, false);
        }
        if (get_option('wkel_success_message') === 'Thanks ' . chr(0xE2) . chr(0x80) . chr(0x94) . ' check your inbox.') update_option('wkel_success_message', 'Thanks - your details have been received.');
        update_option('wkel_outreach_migrated', '1', false);
        update_option('wkel_version', WKEL_VERSION);
    }

    public static function save_template(int $id, string $name, string $subject, string $html): int|WP_Error {
        if ($id && get_post_type($id) !== 'wkel_template') return new WP_Error('template', 'Template not found.');
        $html = wp_kses_post($html);
        preg_match_all('/(?:href|src)\s*=\s*([\"\'])(.*?)\1/is', $html, $links);
        foreach ($links[2] as $url) {
            preg_match_all('/\{\{([^{}]+)\}\}/', $url, $fields);
            foreach ($fields[1] as $field) if (!in_array($field, ['unsubscribe_url', 'atncs_url', 'enp_url'], true)) return new WP_Error('private_link', 'Identity merge fields cannot be placed in links or tracking parameters.');
        }
        $subject = sanitize_text_field($subject);
        if (!$name || !$subject || !$html) return new WP_Error('template', 'Name, subject and email body are required.');
        $saved = wp_insert_post(wp_slash(['ID' => $id, 'post_type' => 'wkel_template', 'post_status' => 'private',
            'post_title' => sanitize_text_field($name)]), true);
        if (is_wp_error($saved)) return $saved;
        update_post_meta($saved, '_wkel_subject', $subject);
        update_post_meta($saved, '_wkel_html', wp_slash($html));
        update_post_meta($saved, '_wkel_revision', (int) get_post_meta($saved, '_wkel_revision', true) + 1);
        update_post_meta($saved, '_wkel_approved', '0');
        delete_post_meta($saved, '_wkel_approved_by');
        delete_post_meta($saved, '_wkel_approved_at');
        return (int) $saved;
    }

    public static function approve(int $id): bool|WP_Error {
        if (get_post_type($id) !== 'wkel_template') return new WP_Error('template', 'Template not found.');
        $html = (string) get_post_meta($id, '_wkel_html', true);
        if (!str_contains($html, '{{unsubscribe_url}}')) return new WP_Error('unsubscribe', 'An unsubscribe_url merge field is required.');
        $validation = self::validate_fields((string) get_post_meta($id, '_wkel_subject', true) . $html);
        if (is_wp_error($validation)) return $validation;
        update_post_meta($id, '_wkel_approved', '1');
        update_post_meta($id, '_wkel_approved_by', get_current_user_id());
        update_post_meta($id, '_wkel_approved_at', time());
        return true;
    }

    private static function validate_fields(string $text): bool|WP_Error {
        $allowed = ['first_name', 'full_name', 'organisation', 'event_name', 'sender_name', 'sender_phone', 'sender_email', 'atncs_url', 'enp_url', 'unsubscribe_url'];
        preg_match_all('/\{\{([^{}]+)\}\}/', $text, $matches);
        foreach ($matches[1] as $field) if (!in_array($field, $allowed, true)) return new WP_Error('merge_fields', 'Unknown merge field: ' . sanitize_text_field($field));
        return true;
    }

    public static function payload(int $lead_id, int $template_id): array|WP_Error {
        if (get_post_type($lead_id) !== 'wkel_lead' || get_post_type($template_id) !== 'wkel_template') return new WP_Error('record', 'Lead or template not found.');
        if (get_post_meta($template_id, '_wkel_approved', true) !== '1') return new WP_Error('approval', 'Choose an approved template.');
        $to = WKEL_Email::get_lead_email($lead_id);
        $from = sanitize_email(get_option('wkel_email_from_address', ''));
        if (!is_email($to) || !is_email($from)) return new WP_Error('address', 'A valid recipient and site sender address are required.');
        $vars = WKEL_Email::build_template_vars($lead_id);
        $subject = (string) get_post_meta($template_id, '_wkel_subject', true);
        $html = (string) get_post_meta($template_id, '_wkel_html', true);
        $valid = self::validate_fields($subject . $html);
        if (is_wp_error($valid)) return $valid;
        preg_match_all('/\{\{([^{}]+)\}\}/', $subject . $html, $matches);
        foreach ($matches[1] as $field) if (trim((string) ($vars[$field] ?? '')) === '') return new WP_Error('merge_empty', 'Missing value for merge field: ' . $field);
        $subject = strtr($subject, array_combine(array_map(fn($k) => '{{' . $k . '}}', array_keys($vars)), array_map('sanitize_text_field', $vars)));
        $html = WKEL_Email::replace_template_vars($html, $vars);
        if (str_contains($subject . $html, '{{')) return new WP_Error('merge_unresolved', 'Unresolved merge field.');
        $name = sanitize_text_field(get_option('wkel_email_from_name', ''));
        $payload = ['from' => $name ? $name . ' <' . $from . '>' : $from, 'to' => [$to], 'subject' => $subject, 'html' => $html];
        $reply = sanitize_email(get_option('wkel_email_reply_to', ''));
        if ($reply) $payload['reply_to'] = [$reply];
        return $payload;
    }

    private static function fingerprint(int $lead_id, int $template_id, array $payload): string {
        return hash('sha256', wp_json_encode([home_url(), get_current_blog_id(), $lead_id, $template_id,
            get_post_meta($template_id, '_wkel_revision', true), get_post_meta($lead_id), $payload, get_option('wkel_outreach_enabled'),
            get_option('wkel_outreach_dry_run'), hash('sha256', (string) get_option('wkel_resend_key', ''))]));
    }

    public static function preview(int $lead_id, int $template_id): array|WP_Error {
        $payload = self::payload($lead_id, $template_id);
        if (is_wp_error($payload)) return $payload;
        $token = wp_generate_uuid4();
        set_transient('wkel_preview_' . $token, ['user' => get_current_user_id(), 'lead' => $lead_id, 'template' => $template_id,
            'fingerprint' => self::fingerprint($lead_id, $template_id, $payload)], 900);
        return ['token' => $token, 'payload' => $payload];
    }

    public static function send(string $token): string|WP_Error {
        if (!current_user_can('manage_options')) return new WP_Error('permission', 'Forbidden.');
        $preview = get_transient('wkel_preview_' . $token);
        if (!is_array($preview) || $preview['user'] !== get_current_user_id()) return new WP_Error('preview', 'Preview has expired. Preview again before sending.');
        $lead = (int) $preview['lead'];
        $template = (int) $preview['template'];
        if (get_option('wkel_outreach_enabled', '0') !== '1') return self::reject($lead, 'disabled');
        if (get_option('wkel_outreach_migrated') !== '1') return self::reject($lead, 'migration_pending');
        $payload = self::payload($lead, $template);
        if (is_wp_error($payload)) return self::reject($lead, $payload->get_error_code());
        if (!hash_equals($preview['fingerprint'], self::fingerprint($lead, $template, $payload))) return self::reject($lead, 'preview_changed');
        $blocked = WKEL_Outreach_Store::blocked($lead, $payload['to'][0]);
        if ($blocked) return self::reject($lead, $blocked);
        $lock = 'wkel_send_lock_' . $lead;
        if (!add_option($lock, time(), '', false)) return self::reject($lead, 'send_locked');
        try {
            return self::dispatch($lead, $template, $token, $payload);
        } finally {
            delete_option($lock);
        }
    }

    private static function reject(int $lead, string $code): WP_Error {
        WKEL_Outreach_Store::audit($lead, 0, 'send_blocked', $code);
        WKEL_Submission::log_activity($lead, 'outreach_blocked', 'Outreach send blocked: ' . sanitize_key($code));
        return new WP_Error($code, 'Send blocked: ' . sanitize_key($code) . '.');
    }

    private static function dispatch(int $lead, int $template, string $token, array $payload): string|WP_Error {
        global $wpdb;
        $dry = !defined('WKEL_OUTREACH_ALLOW_LIVE') || WKEL_OUTREACH_ALLOW_LIVE !== true || get_option('wkel_outreach_dry_run', '1') !== '0';
        $key = WKEL_Encryption::decrypt((string) get_option('wkel_resend_key', ''));
        if (!$dry && (!$key || WKEL_Encryption::is_encrypted($key))) return self::reject($lead, 'resend_not_configured');
        if (!$dry && (!defined('WKEL_ENCRYPTION_KEY') || !defined('WKEL_ENCRYPTION_IV'))) return self::reject($lead, 'encryption_not_configured');
        // Do not store a second plaintext copy of identity in the message ledger.
        $snapshot = WKEL_Encryption::encrypt(wp_json_encode($payload));
        if (!$dry && !WKEL_Encryption::is_encrypted($snapshot)) return self::reject($lead, 'encryption_invalid');
        if (!WKEL_Encryption::is_encrypted($snapshot)) $snapshot = '';
        $ok = $wpdb->insert(WKEL_Outreach_Store::table('messages'), ['lead_id' => $lead, 'template_id' => $template,
            'revision' => (int) get_post_meta($template, '_wkel_revision', true), 'author_id' => get_current_user_id(),
            'authorization_hash' => hash('sha256', $token), 'mode' => $dry ? 'dry_run' : 'live',
            'status' => $dry ? 'draft' : 'queued', 'payload' => $snapshot, 'created_at' => time()]);
        if (!$ok) return self::reject($lead, 'authorization_used_or_storage_failed');
        $id = (int) $wpdb->insert_id;
        delete_transient('wkel_preview_' . $token);
        if (!WKEL_Outreach_Store::audit($lead, $id, 'send_authorized', $dry ? 'dry_run' : 'live')) {
            $wpdb->update(WKEL_Outreach_Store::table('messages'), ['status' => 'failed'], ['id' => $id]);
            return new WP_Error('audit_failed', 'Audit could not be saved; delivery was blocked.');
        }
        if ($dry) {
            WKEL_Outreach_Store::audit($lead, $id, 'dry_run_complete');
            WKEL_Submission::log_activity($lead, 'outreach_dry_run', 'Dry run completed. No delivery request was made.');
            return 'Dry run completed. No email was sent.';
        }
        // Final check immediately before the only delivery call.
        $blocked = WKEL_Outreach_Store::blocked($lead, $payload['to'][0]);
        if ($blocked) {
            $wpdb->update(WKEL_Outreach_Store::table('messages'), ['status' => 'suppressed', 'outcome_at' => time()], ['id' => $id]);
            return self::reject($lead, $blocked);
        }
        update_post_meta($lead, '_wkel_outreach_status', 'queued');
        update_post_meta($lead, '_wkel_email_status', 'queued');
        $response = wp_remote_post('https://api.resend.com/emails', ['headers' => [
            'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json',
            'Idempotency-Key' => 'wkel-' . hash('sha256', home_url() . ':' . get_current_blog_id() . ':' . $token)],
            'body' => wp_json_encode($payload), 'timeout' => 20]);
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        $body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
        $resend = sanitize_text_field($body['id'] ?? '');
        if ($code < 200 || $code >= 300 || !$resend) {
            $error = $code ? 'http_' . $code : 'transport_uncertain';
            $wpdb->update(WKEL_Outreach_Store::table('messages'), ['status' => 'failed', 'delivery' => $code ? 'rejected' : 'uncertain', 'outcome_at' => time()], ['id' => $id]);
            update_post_meta($lead, '_wkel_outreach_status', 'failed');
            update_post_meta($lead, '_wkel_email_status', 'failed');
            WKEL_Outreach_Store::suppress($payload['to'][0], 'failed', 'send_failure');
            WKEL_Outreach_Store::audit($lead, $id, 'send_failed', $error);
            WKEL_Submission::log_activity($lead, 'outreach_failed', 'Outreach delivery failed: ' . $error . '. No automatic retry.');
            return new WP_Error('send_failed', 'Delivery failed. Review the audit before any further send.');
        }
        if (false === $wpdb->update(WKEL_Outreach_Store::table('messages'), ['status' => 'sent', 'delivery' => 'accepted', 'resend_id' => $resend, 'sent_at' => time()], ['id' => $id])) {
            update_post_meta($lead, '_wkel_outreach_status', 'failed');
            update_post_meta($lead, '_wkel_email_status', 'failed');
            WKEL_Outreach_Store::suppress($payload['to'][0], 'failed', 'accepted_storage_failed');
            WKEL_Outreach_Store::audit($lead, $id, 'send_failed', 'accepted_storage_failed');
            return new WP_Error('storage_failed', 'Provider accepted delivery but history could not be saved. Do not resend.');
        }
        update_post_meta($lead, '_wkel_outreach_status', 'sent');
        update_post_meta($lead, '_wkel_email_status', 'sent');
        update_post_meta($lead, '_wkel_resend_email_id', $resend);
        update_post_meta($lead, '_wkel_email_sent_at', time());
        WKEL_Outreach_Store::audit($lead, $id, 'send_accepted');
        WKEL_Submission::log_activity($lead, 'outreach_sent', 'Outreach email accepted by Resend.', $resend);
        return 'Email accepted by Resend. Delivery outcome will appear in the history.';
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.');
        $result = null;
        $preview = null;
        $lead = absint($_GET['lead_id'] ?? $_POST['lead_id'] ?? 0);
        $template = absint($_GET['template_id'] ?? $_POST['template_id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('wkel_outreach');
            $action = sanitize_key($_POST['wkel_outreach_action'] ?? '');
            switch ($action) {
                case 'settings':
                    update_option('wkel_outreach_enabled', !empty($_POST['enabled']) ? '1' : '0');
                    update_option('wkel_outreach_dry_run', !empty($_POST['dry_run']) ? '1' : '0');
                    $result = 'Outreach settings saved.';
                    break;
                case 'add':
                    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
                    if (!is_email($email)) { $result = new WP_Error('email', 'Enter a valid email.'); break; }
                    $lead = WKEL_Submission::find_lead_by_email($email);
                    if (!$lead) $lead = WKEL_Submission::create_lead(['wkel_email' => $email,
                        'wkel_name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                        'wkel_organisation' => sanitize_text_field(wp_unslash($_POST['organisation'] ?? ''))], 'outreach', '', 'outreach', 'other');
                    if (is_wp_error($lead)) { $result = $lead; $lead = 0; break; }
                    update_post_meta($lead, '_wkel_outreach_enrolled', '1');
                    $result = 'Lead staged. No email sent.';
                    break;
                case 'save_template':
                    $result = self::save_template($template, wp_unslash($_POST['template_name'] ?? ''), wp_unslash($_POST['subject'] ?? ''), wp_unslash($_POST['html'] ?? ''));
                    if (!is_wp_error($result)) { $template = $result; $result = 'Template saved as draft. Approval is required.'; }
                    break;
                case 'approve': $result = self::approve($template); if ($result === true) $result = 'Template approved.'; break;
                case 'preview':
                    $preview = self::preview($lead, $template);
                    if (is_wp_error($preview)) { $result = $preview; $preview = null; }
                    break;
                case 'send': $result = self::send(sanitize_text_field(wp_unslash($_POST['preview_token'] ?? ''))); break;
            }
        }
        $templates = get_posts(['post_type' => 'wkel_template', 'post_status' => 'private', 'posts_per_page' => -1]);
        $page = max(1, absint($_GET['wkel_outreach_page'] ?? 1));
        $leads = get_posts(['post_type' => 'wkel_lead', 'post_status' => 'publish', 'posts_per_page' => 51, 'offset' => ($page - 1) * 50, 'orderby' => 'ID', 'order' => 'DESC',
            'meta_query' => ['relation' => 'OR', ['key' => '_wkel_outreach_enrolled', 'value' => '1'], ['key' => '_wkel_campaign', 'compare' => 'EXISTS']]]);
        $has_next = count($leads) > 50;
        $leads = array_slice($leads, 0, 50);
        echo '<div class="wrap"><h1>Outreach</h1><p>Stage leads, approve templates, preview and explicitly send one email at a time. Bulk sending is disabled.</p>';
        if ($result !== null) echo '<div class="notice ' . (is_wp_error($result) ? 'notice-error' : 'notice-success') . '"><p>' . esc_html(is_wp_error($result) ? $result->get_error_message() : (string) $result) . '</p></div>';
        echo '<form method="post">'; wp_nonce_field('wkel_outreach');
        echo '<input type="hidden" name="wkel_outreach_action" value="settings"><label><input type="checkbox" name="enabled" value="1" ' . checked(get_option('wkel_outreach_enabled'), '1', false) . '> Enable Outreach on this installation</label> <label><input type="checkbox" name="dry_run" value="1" ' . checked(get_option('wkel_outreach_dry_run', '1'), '1', false) . '> Dry run</label> ';
        submit_button('Save mode', 'secondary', 'submit', false); echo '</form>';
        if (!defined('WKEL_OUTREACH_ALLOW_LIVE') || WKEL_OUTREACH_ALLOW_LIVE !== true) echo '<p><strong>Live delivery is locked by server configuration. All sends are dry runs.</strong></p>';
        echo '<h2>Add a draft lead</h2><form method="post">'; wp_nonce_field('wkel_outreach');
        echo '<input type="hidden" name="wkel_outreach_action" value="add"><label>Name <input name="name" placeholder="Name"></label> <label>Organisation <input name="organisation" placeholder="Organisation"></label> <label>Email <input name="email" type="email" placeholder="Email" required></label> ';
        submit_button('Add to staging', 'secondary', 'submit', false); echo '</form>';
        echo '<h2>Staging and history</h2><table class="widefat striped"><thead><tr><th>Lead</th><th>Outreach status</th><th>Action</th></tr></thead><tbody>';
        foreach ($leads as $item) echo '<tr><td>' . esc_html($item->post_title) . '<br><small>' . esc_html(WKEL_Email::get_lead_email($item->ID)) . '</small></td><td>' . esc_html(get_post_meta($item->ID, '_wkel_outreach_status', true) ?: 'draft') . '</td><td><a href="' . esc_url(admin_url('admin.php?page=wkel_outreach&lead_id=' . $item->ID)) . '">Select / history</a></td></tr>';
        echo '</tbody></table><p>';
        if ($page > 1) echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wkel_outreach&wkel_outreach_page=' . ($page - 1))) . '">Previous contacts</a> ';
        if ($has_next) echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wkel_outreach&wkel_outreach_page=' . ($page + 1))) . '">Next contacts</a>';
        echo '</p><h2>Preview email</h2><form method="post">'; wp_nonce_field('wkel_outreach');
        echo '<input type="hidden" name="wkel_outreach_action" value="preview"><label>Lead ID <input type="number" name="lead_id" min="1" required value="' . esc_attr($lead ?: '') . '"></label> <label for="wkel-approved-template">Approved template</label> <select id="wkel-approved-template" name="template_id">';
        foreach ($templates as $item) if (get_post_meta($item->ID, '_wkel_approved', true) === '1') echo '<option value="' . esc_attr($item->ID) . '" ' . selected($template, $item->ID, false) . '>' . esc_html($item->post_title) . '</option>';
        echo '</select> '; submit_button('Preview', 'secondary', 'submit', false); echo '</form>';
        if ($preview) {
            echo '<h3>To: ' . esc_html($preview['payload']['to'][0]) . '</h3><p>From: ' . esc_html($preview['payload']['from']) . '</p><p>Subject: ' . esc_html($preview['payload']['subject']) . '</p>';
            echo '<iframe title="Formatted email preview" sandbox="" referrerpolicy="no-referrer" style="width:100%;height:420px;background:white;border:1px solid #ccc" srcdoc="' . esc_attr('<!doctype html><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:"><meta charset="utf-8">' . $preview['payload']['html']) . '"></iframe>';
            echo '<form method="post">'; wp_nonce_field('wkel_outreach');
            echo '<input type="hidden" name="wkel_outreach_action" value="send"><input type="hidden" name="lead_id" value="' . esc_attr($lead) . '"><input type="hidden" name="preview_token" value="' . esc_attr($preview['token']) . '">';
            submit_button('Send this email', 'primary', 'submit', false); echo '</form>';
        }
        if ($lead && get_post_type($lead) === 'wkel_lead') {
            echo '<h3>Message history</h3><table class="widefat"><tr><th>ID / mode</th><th>Status / delivery</th><th>Resend ID</th><th>Created / outcome</th></tr>';
            foreach (WKEL_Outreach_Store::messages($lead) as $row) echo '<tr><td>' . esc_html($row['id'] . ' / ' . $row['mode']) . '</td><td>' . esc_html($row['status'] . ' / ' . $row['delivery']) . '</td><td>' . esc_html($row['resend_id'] ?? '') . '</td><td>' . esc_html(wp_date('Y-m-d H:i:s', $row['created_at']) . ($row['outcome_at'] ? ' / ' . wp_date('Y-m-d H:i:s', $row['outcome_at']) : '')) . '</td></tr>';
            echo '</table>';
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare('SELECT event,code,occurred_at FROM ' . WKEL_Outreach_Store::table('send_audit') . ' WHERE lead_id = %d ORDER BY id DESC LIMIT 50', $lead), ARRAY_A);
            echo '<h3>Send audit</h3><ul>';
            foreach ($rows as $row) echo '<li>' . esc_html(wp_date('Y-m-d H:i:s', $row['occurred_at']) . ' — ' . $row['event'] . ' ' . $row['code']) . '</li>';
            echo '</ul><p>Replies and follow-up notes remain in the Event Leads pipeline.</p>';
        }
        echo '<h2>Templates</h2><ul>';
        foreach ($templates as $item) echo '<li><a href="' . esc_url(admin_url('admin.php?page=wkel_outreach&template_id=' . $item->ID)) . '">' . esc_html($item->post_title) . '</a> — ' . (get_post_meta($item->ID, '_wkel_approved', true) === '1' ? 'Approved' : 'Draft') . '</li>';
        echo '</ul><form method="post">'; wp_nonce_field('wkel_outreach');
        echo '<input type="hidden" name="template_id" value="' . esc_attr($template) . '"><input type="hidden" name="wkel_outreach_action" value="save_template"><p><label>Name <input name="template_name" required value="' . esc_attr($template ? get_the_title($template) : '') . '"></label></p><p><label>Subject <input class="large-text" name="subject" required value="' . esc_attr(get_post_meta($template, '_wkel_subject', true)) . '"></label></p><p><label>Email HTML<textarea class="large-text" rows="12" name="html" required>' . esc_textarea(get_post_meta($template, '_wkel_html', true)) . '</textarea></label></p><p>Merge fields: first_name, full_name, organisation, event_name, sender_name, sender_phone, sender_email, atncs_url, enp_url, unsubscribe_url. Use {{field}}. Unsubscribe is required.</p>';
        submit_button('Save template as draft'); echo '</form>';
        if ($template) { echo '<form method="post">'; wp_nonce_field('wkel_outreach'); echo '<input type="hidden" name="template_id" value="' . esc_attr($template) . '"><input type="hidden" name="wkel_outreach_action" value="approve">'; submit_button('Approve this revision', 'secondary'); echo '</form>'; }
        echo '<hr><h2>Campaign imports and suppression</h2>';
        WKEL_Campaign::render_admin_page();
        echo '</div>';
    }
}
