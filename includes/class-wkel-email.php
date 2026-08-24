<?php
defined('ABSPATH') || exit;

class WKEL_Email {

    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY  = 300; // 5 minutes

    /**
     * Action Scheduler callback: wkel_send_confirmation_email
     */
    public static function send_confirmation(int $lead_id): void {
        if (get_post_type($lead_id) !== 'wkel_lead') {
            return;
        }

        if (class_exists('WKEL_Campaign') && WKEL_Campaign::is_lead_suppressed($lead_id)) {
            update_post_meta($lead_id, '_wkel_email_status', 'unsubscribed');
            WKEL_Submission::log_activity($lead_id, 'email_suppressed', 'Email was not sent because the contact has opted out.');
            return;
        }

        $attempts = (int) get_post_meta($lead_id, '_wkel_email_attempts', true);

        if ($attempts >= self::MAX_ATTEMPTS) {
            update_post_meta($lead_id, '_wkel_email_status', 'failed');
            do_action('wkel_email_failed', $lead_id);
            return;
        }

        update_post_meta($lead_id, '_wkel_email_attempts', $attempts + 1);

        $payload = self::build_payload($lead_id);

        if (!$payload) {
            // Missing configuration — don't retry
            update_post_meta($lead_id, '_wkel_email_status', 'failed');
            do_action('wkel_email_failed', $lead_id);
            return;
        }

        $payload = apply_filters('wkel_email_payload', $payload, $lead_id);

        $api_key = WKEL_Encryption::decrypt(get_option('wkel_resend_key', ''));

        if (!$api_key) {
            update_post_meta($lead_id, '_wkel_email_status', 'failed');
            do_action('wkel_email_failed', $lead_id);
            return;
        }

        $response = wp_remote_post('https://api.resend.com/emails', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Idempotency-Key'=> 'wkel-lead-' . $lead_id . '-confirmation',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            self::schedule_retry($lead_id);
            return;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true) ?: [];
            update_post_meta($lead_id, '_wkel_email_status', 'sent');
            update_post_meta($lead_id, '_wkel_email_sent_at', time());
            if (!empty($response_body['id'])) {
                update_post_meta($lead_id, '_wkel_resend_email_id', sanitize_text_field($response_body['id']));
            }
            WKEL_Submission::log_activity($lead_id, 'email_sent', 'Confirmation email sent through Resend.', !empty($response_body['id']) ? $response_body['id'] : null);
            do_action('wkel_email_sent', $lead_id);
        } else {
            self::schedule_retry($lead_id);
        }
    }

    private static function schedule_retry(int $lead_id): void {
        $attempts = (int) get_post_meta($lead_id, '_wkel_email_attempts', true);

        if ($attempts >= self::MAX_ATTEMPTS) {
            update_post_meta($lead_id, '_wkel_email_status', 'failed');
            WKEL_Submission::log_activity($lead_id, 'email_failed', 'Email delivery failed after ' . self::MAX_ATTEMPTS . ' attempts.');
            do_action('wkel_email_failed', $lead_id);
            return;
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + self::RETRY_DELAY,
                'wkel_send_confirmation_email',
                ['lead_id' => $lead_id],
                'wk-event-leads'
            );
        }
    }

    private static function build_payload(int $lead_id): ?array {
        $to_email = self::get_lead_email($lead_id);
        if (!$to_email) {
            return null;
        }

        $from_name    = sanitize_text_field(get_option('wkel_email_from_name', ''));
        $from_address = sanitize_email(get_option('wkel_email_from_address', ''));
        $reply_to     = sanitize_email(get_option('wkel_email_reply_to', ''));
        $subject      = sanitize_text_field(get_option('wkel_email_subject', 'Great connecting with you today'));

        if (!$from_address) {
            return null;
        }

        $vars    = self::build_template_vars($lead_id);
        $vars    = apply_filters('wkel_email_template_vars', $vars, $lead_id);
        $body    = self::replace_template_vars(get_option('wkel_email_body', ''), $vars);

        $payload = [
            'from'    => $from_name ? $from_name . ' <' . $from_address . '>' : $from_address,
            'to'      => [$to_email],
            'subject' => $subject,
            'html'    => $body,
        ];

        if ($reply_to) {
            $payload['reply_to'] = [$reply_to];
        }

        return $payload;
    }

    private static function get_lead_email(int $lead_id): string {
        foreach (WKEL_Schema::get_fields() as $field) {
            if ($field['type'] === 'email') {
                $raw = get_post_meta($lead_id, '_wkel_' . $field['id'], true);
                return WKEL_Encryption::decrypt($raw);
            }
        }
        return '';
    }

    private static function build_template_vars(int $lead_id): array {
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

    private static function replace_template_vars(string $template, array $vars): string {
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
        $api_key = WKEL_Encryption::decrypt(get_option('wkel_resend_key', ''));
        if (!$api_key) {
            return 'Resend API key is not configured.';
        }

        $from_address = sanitize_email(get_option('wkel_email_from_address', ''));
        if (!$from_address) {
            return 'From email address is not configured.';
        }

        $sample_vars = [
            'first_name'   => 'Test',
            'full_name'    => 'Test User',
            'organisation' => 'Test Organisation',
            'event_name'   => 'Test Event',
            'atncs_url'    => get_option('wkel_atncs_url', 'https://example.com'),
            'enp_url'      => get_option('wkel_enp_url', 'https://example.com'),
            'sender_name'  => get_option('wkel_sender_name', 'Test Sender'),
            'sender_phone' => get_option('wkel_sender_phone', ''),
            'sender_email' => get_option('wkel_sender_email', ''),
            'unsubscribe_url' => home_url('/unsubscribe/'),
        ];

        $body    = self::replace_template_vars(get_option('wkel_email_body', 'Test email from WK Event Leads.'), $sample_vars);
        $subject = '[TEST] ' . sanitize_text_field(get_option('wkel_email_subject', 'Test Email'));

        $from_name = sanitize_text_field(get_option('wkel_email_from_name', ''));
        $payload = [
            'from'    => $from_name ? $from_name . ' <' . $from_address . '>' : $from_address,
            'to'      => [$to_email],
            'subject' => $subject,
            'html'    => $body,
        ];

        $response = wp_remote_post('https://api.resend.com/emails', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'Idempotency-Key'=> 'wkel-test-' . wp_generate_uuid4(),
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $code = wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 300) ? true : 'Resend error (HTTP ' . $code . '): ' . wp_remote_retrieve_body($response);
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
            $lead_id = self::ingest_received_email($data, $event_id);
            return new WP_REST_Response(['success' => true, 'lead_id' => $lead_id]);
        }

        $email_id = sanitize_text_field($data['email_id'] ?? $data['id'] ?? '');
        if (!$email_id) {
            return new WP_REST_Response(['success' => true, 'matched' => false]);
        }
        $ids = get_posts([
            'post_type' => 'wkel_lead',
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'meta_query' => [['key' => '_wkel_resend_email_id', 'value' => $email_id]],
            'fields' => 'ids',
        ]);
        if (empty($ids)) {
            return new WP_REST_Response(['success' => true, 'matched' => false]);
        }

        $lead_id = (int) $ids[0];
        $labels = [
            'email.sent' => ['email_sent', 'Resend accepted the email for sending.'],
            'email.delivered' => ['email_delivered', 'Resend confirmed delivery of the email.'],
            'email.delivery_delayed' => ['email_delayed', 'Resend reported a delivery delay.'],
            'email.failed' => ['email_failed', 'Resend reported that the email failed.'],
            'email.bounced' => ['email_bounced', 'Resend reported that the email bounced.'],
            'email.complained' => ['email_complained', 'Resend reported a spam complaint.'],
            'email.opened' => ['email_opened', 'Recipient opened the email.'],
            'email.clicked' => ['email_clicked', 'Recipient clicked a link in the email.'],
            'email.suppressed' => ['email_suppressed', 'Resend suppressed the email.'],
        ];
        if (isset($labels[$event['type']])) {
            [$activity_type, $message] = $labels[$event['type']];
            WKEL_Submission::log_activity($lead_id, $activity_type, $message, $event_id, !empty($data['created_at']) ? strtotime($data['created_at']) ?: time() : time(), ['resend' => $data]);
        }
        if ($type === 'email.delivered') {
            update_post_meta($lead_id, '_wkel_email_status', 'delivered');
        } elseif (in_array($type, ['email.failed', 'email.bounced', 'email.complained', 'email.suppressed'], true)) {
            update_post_meta($lead_id, '_wkel_email_status', 'failed');
        }
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
