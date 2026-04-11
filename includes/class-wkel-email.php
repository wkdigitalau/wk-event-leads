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

        $api_key = WKEL_Encryption::decrypt(get_option('wkel_sendgrid_key', ''));

        if (!$api_key) {
            update_post_meta($lead_id, '_wkel_email_status', 'failed');
            do_action('wkel_email_failed', $lead_id);
            return;
        }

        $response = wp_remote_post('https://api.sendgrid.com/v3/mail/send', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
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
            update_post_meta($lead_id, '_wkel_email_status', 'sent');
            update_post_meta($lead_id, '_wkel_email_sent_at', time());
            WKEL_Submission::log_activity($lead_id, 'email_sent', 'Confirmation email sent successfully.');
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
            'personalizations' => [[
                'to'      => [['email' => $to_email]],
                'subject' => $subject,
            ]],
            'from'    => ['email' => $from_address, 'name' => $from_name],
            'content' => [[
                'type'  => 'text/html',
                'value' => $body,
            ]],
        ];

        if ($reply_to) {
            $payload['reply_to'] = ['email' => $reply_to];
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
        $api_key = WKEL_Encryption::decrypt(get_option('wkel_sendgrid_key', ''));
        if (!$api_key) {
            return 'SendGrid API key is not configured.';
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
        ];

        $body    = self::replace_template_vars(get_option('wkel_email_body', 'Test email from WK Event Leads.'), $sample_vars);
        $subject = '[TEST] ' . sanitize_text_field(get_option('wkel_email_subject', 'Test Email'));

        $payload = [
            'personalizations' => [['to' => [['email' => $to_email]], 'subject' => $subject]],
            'from'             => ['email' => $from_address, 'name' => sanitize_text_field(get_option('wkel_email_from_name', ''))],
            'content'          => [['type' => 'text/html', 'value' => $body]],
        ];

        $response = wp_remote_post('https://api.sendgrid.com/v3/mail/send', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $code = wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 300) ? true : 'SendGrid error (HTTP ' . $code . '): ' . wp_remote_retrieve_body($response);
    }
}
