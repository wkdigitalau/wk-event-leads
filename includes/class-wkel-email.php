<?php
defined('ABSPATH') || exit;

class WKEL_Email {

    /**
     * Action Scheduler callback: wkel_send_confirmation_email
     */
    public static function send_confirmation(int $lead_id): void {
        // Legacy scheduled actions must never send without a preview authorisation.
        if (get_post_type($lead_id) === 'wkel_lead') {
            WKEL_Outreach_Store::audit($lead_id, 0, 'legacy_send_blocked');
            WKEL_Submission::log_activity($lead_id, 'legacy_send_blocked', 'Legacy automatic email blocked. Use Outreach.');
        }
    }

    public static function get_lead_email(int $lead_id): string {
        foreach (WKEL_Schema::get_fields() as $field) {
            if ($field['type'] === 'email') {
                $raw = get_post_meta($lead_id, '_wkel_' . $field['id'], true);
                return sanitize_email(WKEL_Encryption::decrypt((string) $raw));
            }
        }
        return '';
    }

    public static function build_template_vars(int $lead_id): array {
        $fields   = WKEL_Schema::get_fields();
        $vars     = [];

        // All schema fields
        foreach ($fields as $field) {
            $raw = get_post_meta($lead_id, '_wkel_' . $field['id'], true);
            $vars['wkel_' . $field['id']] = WKEL_Encryption::decrypt((string) $raw);
        }

        // Convenience aliases
        $full_name      = $vars['wkel_wkel_name'] ?? $vars['wkel_name'] ?? '';
        $name_parts     = explode(' ', trim($full_name), 2);
        $vars['first_name'] = $name_parts[0] ?? '';
        $vars['full_name']  = $full_name;

        $org = $vars['wkel_wkel_organisation'] ?? $vars['wkel_organisation'] ?? '';
        $vars['organisation'] = $org;

        // Event display name
        $event_slug  = get_post_meta($lead_id, '_wkel_event', true);
        $event_map   = json_decode(get_option('wkel_event_map', '[]'), true) ?: [];
        $event_name  = '';
        foreach ($event_map as $entry) {
            if (($entry['slug'] ?? '') === $event_slug) {
                $event_name = $entry['name'] ?? '';
                break;
            }
        }
        $vars['event_name'] = $event_name ?: $event_slug;

        // Settings-driven vars
        $vars['atncs_url']     = esc_url(get_option('wkel_atncs_url', ''));
        $vars['enp_url']       = esc_url(get_option('wkel_enp_url', ''));
        $vars['sender_name']   = sanitize_text_field(get_option('wkel_sender_name', ''));
        $vars['sender_phone']  = sanitize_text_field(get_option('wkel_sender_phone', ''));
        $vars['sender_email']  = sanitize_email(get_option('wkel_sender_email', ''));
        $lead_email            = self::get_lead_email($lead_id);
        $vars['unsubscribe_url'] = class_exists('WKEL_Campaign')
            ? WKEL_Campaign::unsubscribe_url_for_email($lead_email)
            : home_url('/unsubscribe/');

        return $vars;
    }

    public static function replace_template_vars(string $template, array $vars): string {
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', esc_html($value), $template);
            // URLs should not be double-escaped — replace again without esc_html
            if (filter_var($value, FILTER_VALIDATE_URL)) {
                $template = str_replace('{{' . $key . '}}', $value, $template);
            }
        }
        return $template;
    }

    /**
     * Send a test email to the admin address.
     */
    public static function send_test(string $to_email): bool|string {
        return 'Live test delivery is disabled. Use Outreach preview and dry-run mode.';
    }

    /**
     * Receive and verify Resend/Svix webhook events.
     * Resend sends both outbound delivery events and email.received events.
     */
    public static function handle_webhook(WP_REST_Request $request): WP_REST_Response {
        $secret = WKEL_Encryption::decrypt(get_option('wkel_resend_webhook_secret', ''));
        $body = $request->get_body();
        $svix_id = $request->get_header('svix-id');
        $svix_timestamp = $request->get_header('svix-timestamp');
        $svix_signature = $request->get_header('svix-signature');

        if (!$secret || !$svix_id || !$svix_timestamp || !$svix_signature || abs(time() - (int) $svix_timestamp) > 300) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid webhook signature.'], 401);
        }

        $secret_bytes = str_starts_with($secret, 'whsec_')
            ? base64_decode(substr($secret, 6), true)
            : $secret;
        if ($secret_bytes === false || $secret_bytes === '') return new WP_REST_Response(['success' => false], 401);
        $signed = $svix_id . '.' . $svix_timestamp . '.' . $body;
        $expected = base64_encode(hash_hmac('sha256', $signed, (string) $secret_bytes, true));
        $valid = false;
        foreach (preg_split('/\s+/', $svix_signature) as $versioned_signature) {
            [$version, $signature] = array_pad(explode(',', $versioned_signature, 2), 2, '');
            if ($version === 'v1' && $signature !== '' && hash_equals($expected, $signature)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid webhook signature.'], 401);
        }

        $event = json_decode($body, true);
        if (!is_array($event) || empty($event['type'])) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid webhook payload.'], 400);
        }

        $event_id = sanitize_text_field($svix_id);
        $type = sanitize_text_field($event['type']);
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        if ($type === 'email.received') {
            global $wpdb;
            if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . WKEL_Outreach_Store::table('send_audit') . ' WHERE event_id = %s', $event_id))) return new WP_REST_Response(['success' => true, 'duplicate' => true]);
            $lead_id = self::ingest_received_email($data, $event_id);
            if (!$lead_id) return new WP_REST_Response(['success' => false], 503);
            WKEL_Outreach_Store::audit($lead_id, 0, 'email_received', '', $event_id);
            return new WP_REST_Response(['success' => true, 'lead_id' => $lead_id]);
        }

        $email_id = sanitize_text_field($data['email_id'] ?? $data['id'] ?? '');
        if (!$email_id) {
            return new WP_REST_Response(['success' => true, 'matched' => false]);
        }
        global $wpdb;
        $message_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WKEL_Outreach_Store::table('messages') . ' WHERE resend_id = %s', $email_id), ARRAY_A);
        $lead_id = $message_row ? (int) $message_row['lead_id'] : 0;
        if (!$lead_id) {
            $ids = get_posts(['post_type' => 'wkel_lead', 'posts_per_page' => 1, 'post_status' => ['publish', 'private', 'draft', 'trash'],
                'meta_query' => [['key' => '_wkel_resend_email_id', 'value' => $email_id]], 'fields' => 'ids']);
            $lead_id = (int) ($ids[0] ?? 0);
        }
        if (!$lead_id) return new WP_REST_Response(['success' => false, 'matched' => false], 503);
        $message_id = (int) ($message_row['id'] ?? 0);
        $at = !empty($event['created_at']) ? strtotime($event['created_at']) : (!empty($data['created_at']) ? strtotime($data['created_at']) : time());
        $at = $at ?: time();
        $outcomes = [
            'email.sent' => ['sent', 'accepted'], 'email.delivered' => ['sent', 'delivered'],
            'email.delivery_delayed' => ['sent', 'delayed'], 'email.failed' => ['failed', 'failed'],
            'email.bounced' => ['bounced', 'bounced'], 'email.complained' => ['suppressed', 'complained'],
            'email.suppressed' => ['suppressed', 'suppressed'],
        ];
        if (in_array($type, ['email.opened', 'email.clicked'], true)) {
            if (WKEL_Outreach_Store::audit($lead_id, $message_id, str_replace('.', '_', $type), '', $event_id, $at)) WKEL_Submission::log_activity($lead_id, str_replace('.', '_', $type), 'Resend reported an email engagement event.', $event_id, $at, ['message_id' => $message_id]);
            return new WP_REST_Response(['success' => true]);
        }
        if (!isset($outcomes[$type])) return new WP_REST_Response(['success' => true, 'ignored' => true]);
        // Unique event ID prevents duplicate signed deliveries from changing history twice.
        if (!WKEL_Outreach_Store::audit($lead_id, $message_id, str_replace('.', '_', $type), '', $event_id, $at)) {
            $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . WKEL_Outreach_Store::table('send_audit') . ' WHERE event_id = %s', $event_id));
            return new WP_REST_Response(['success' => (bool) $existing, 'duplicate' => (bool) $existing], $existing ? 200 : 503);
        }
        [$status, $delivery] = $outcomes[$type];
        $terminal = in_array($status, ['failed', 'bounced', 'suppressed'], true);
        if ($terminal || !$message_row || ($at >= (int) $message_row['outcome_at'] && !in_array($message_row['status'], ['failed', 'bounced', 'suppressed'], true))) {
            if ($message_id) {
                if ($terminal) {
                    $exclude = $status === 'failed' ? " AND status NOT IN ('bounced','suppressed')" : ($status === 'bounced' ? " AND status != 'suppressed'" : '');
                    $wpdb->query($wpdb->prepare('UPDATE ' . WKEL_Outreach_Store::table('messages') . " SET status = %s, delivery = %s, outcome_at = %d WHERE id = %d" . $exclude, $status, $delivery, $at, $message_id));
                } else {
                    $wpdb->query($wpdb->prepare('UPDATE ' . WKEL_Outreach_Store::table('messages') . " SET status = %s, delivery = %s, outcome_at = %d WHERE id = %d AND status NOT IN ('failed','bounced','suppressed') AND outcome_at <= %d", $status, $delivery, $at, $message_id, $at));
                }
            }
            if ($message_id) {
                $actual = $wpdb->get_row($wpdb->prepare('SELECT status,delivery FROM ' . WKEL_Outreach_Store::table('messages') . ' WHERE id = %d', $message_id), ARRAY_A);
                $status = $actual['status'] ?? $status;
                $delivery = $actual['delivery'] ?? $delivery;
                $terminal = in_array($status, ['failed', 'bounced', 'suppressed'], true);
            }
            $current = get_post_meta($lead_id, '_wkel_outreach_status', true);
            $is_latest = !$message_id || (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM ' . WKEL_Outreach_Store::table('messages') . " WHERE lead_id = %d AND mode = 'live'", $lead_id)) === $message_id;
            if ($terminal || ($is_latest && !in_array($current, ['failed', 'bounced', 'suppressed'], true))) {
                $rank = ['failed' => 1, 'bounced' => 2, 'suppressed' => 3];
                $summary = ($rank[$current] ?? 0) > ($rank[$status] ?? 0) ? $current : $status;
                update_post_meta($lead_id, '_wkel_outreach_status', $summary);
                update_post_meta($lead_id, '_wkel_email_status', $summary);
            }
        }
        if (in_array($status, ['failed', 'bounced', 'suppressed'], true)) {
            $snapshot = $message_row ? json_decode(WKEL_Encryption::decrypt((string) $message_row['payload']), true) : [];
            $recipient = $snapshot['to'][0] ?? WKEL_Email::get_lead_email($lead_id);
            WKEL_Outreach_Store::suppress($recipient, $delivery, 'resend_webhook');
        }
        WKEL_Submission::log_activity($lead_id, str_replace('.', '_', $type), 'Resend outcome: ' . $delivery . '.', $event_id, $at,
            ['message_id' => $message_id, 'resend_id' => $email_id]);
        return new WP_REST_Response(['success' => true, 'matched' => true, 'lead_id' => $lead_id]);
    }

    private static function ingest_received_email(array $data, string $event_id): int {
        $email_id = sanitize_text_field($data['email_id'] ?? $data['id'] ?? '');
        $content = $email_id ? self::retrieve_received_email($email_id) : [];
        $email = self::extract_email_address($data['from'] ?? ($content['from'] ?? ''));
        if (!$email) {
            return 0;
        }

        $subject = sanitize_text_field($data['subject'] ?? ($content['subject'] ?? ''));
        $html = (string) ($content['html'] ?? $content['text'] ?? $data['text'] ?? '');
        $summary = trim(wp_strip_all_tags($html));
        $summary = function_exists('mb_substr') ? mb_substr($summary, 0, 1000) : substr($summary, 0, 1000);
        $message = trim(($subject ? 'Subject: ' . $subject . "\n" : '') . $summary);
        $lead_id = WKEL_Submission::find_lead_by_email($email);

        if (!$lead_id) {
            $name = sanitize_text_field($data['from_name'] ?? '');
            if (!$name && preg_match('/^\s*(.*?)\s*<[^>]+>/', (string) ($data['from'] ?? ''), $matches)) {
                $name = sanitize_text_field($matches[1]);
            }
            $fields = ['wkel_name' => $name ?: $email, 'wkel_email' => $email, 'wkel_organisation' => ''];
            $lead_type = self::infer_lead_type($subject . ' ' . $summary);
            $lead_id = WKEL_Submission::create_lead($fields, 'email_inbox', '', 'email', $lead_type);
            if (is_wp_error($lead_id)) {
                return 0;
            }
            update_post_meta($lead_id, '_wkel_email_status', 'not_sent');
        }

        WKEL_Submission::log_activity($lead_id, 'email_received', $message ?: 'Inbound email received.', $event_id, !empty($data['created_at']) ? strtotime($data['created_at']) ?: time() : time(), [
            'email_id' => $email_id,
            'from' => $email,
            'message_id' => sanitize_text_field($content['message_id'] ?? $data['message_id'] ?? ''),
        ]);
        update_post_meta($lead_id, '_wkel_last_inbound_email_at', time());
        return (int) $lead_id;
    }

    private static function retrieve_received_email(string $email_id): array {
        $api_key = WKEL_Encryption::decrypt(get_option('wkel_resend_key', ''));
        if (!$api_key) {
            return [];
        }
        $response = wp_remote_get('https://api.resend.com/emails/receiving/' . rawurlencode($email_id), [
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
            'timeout' => 15,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 300) {
            return [];
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) ? $body : [];
    }

    private static function extract_email_address(string $value): string {
        if (preg_match('/<([^>]+)>/', $value, $matches)) {
            $value = $matches[1];
        }
        return sanitize_email(trim($value));
    }

    private static function infer_lead_type(string $text): string {
        $text = strtolower($text);
        if (preg_match('/\b(support|helpdesk|technical issue|bug|problem|cannot log in)\b/', $text)) {
            return 'support';
        }
        if (preg_match('/\b(telemarketer|cold call|sales call|telephone marketing)\b/', $text)) {
            return 'telemarketer';
        }
        if (preg_match('/\b(existing client|client request|renewal|change request)\b/', $text)) {
            return 'client_request';
        }
        return 'other';
    }
}

