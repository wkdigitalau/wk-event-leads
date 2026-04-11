<?php
defined('ABSPATH') || exit;

class WKEL_Security {

    // -------------------------------------------------------------------------
    // Nonce helpers
    // -------------------------------------------------------------------------

    public static function create_form_nonce(): string {
        return wp_create_nonce('wkel_submit');
    }

    public static function verify_form_nonce(string $nonce): bool {
        return (bool) wp_verify_nonce($nonce, 'wkel_submit');
    }

    public static function create_admin_nonce(string $action): string {
        return wp_create_nonce('wkel_admin_' . $action);
    }

    public static function verify_admin_nonce(string $nonce, string $action): bool {
        return (bool) wp_verify_nonce($nonce, 'wkel_admin_' . $action);
    }

    // -------------------------------------------------------------------------
    // Honeypot
    // -------------------------------------------------------------------------

    /**
     * Returns true if the honeypot field has been populated (i.e. a bot submitted).
     */
    public static function honeypot_triggered(array $post_data): bool {
        if (get_option('wkel_honeypot_enabled', '1') !== '1') {
            return false;
        }
        return !empty($post_data['wkel_hp']);
    }

    // -------------------------------------------------------------------------
    // Rate limiting
    // -------------------------------------------------------------------------

    /**
     * Returns true if this IP has exceeded the submission limit.
     * Increments the counter on each call.
     */
    public static function rate_limit_exceeded(string $ip): bool {
        $limit = (int) get_option('wkel_rate_limit', 5);
        $key   = 'wkel_rate_' . hash('sha256', $ip);
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return true;
        }

        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return false;
    }

    public static function get_submitter_ip(): string {
        // Use REMOTE_ADDR only — do not trust X-Forwarded-For without proxy config
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    // -------------------------------------------------------------------------
    // Input sanitisation
    // -------------------------------------------------------------------------

    /**
     * Sanitise a value by field type.
     */
    public static function sanitise_by_type(mixed $value, string $type): string {
        $value = (string) $value;
        return match ($type) {
            'email'    => sanitize_email($value),
            'textarea' => sanitize_textarea_field($value),
            default    => sanitize_text_field($value),
        };
    }

    /**
     * Sanitise all submitted form values against the schema.
     * Returns an array of sanitised values keyed by field id.
     */
    public static function sanitise_submission(array $raw, array $fields): array {
        $clean = [];
        foreach ($fields as $field) {
            $id          = $field['id'];
            $raw_value   = $raw[$id] ?? '';
            $clean[$id]  = self::sanitise_by_type($raw_value, $field['type']);
        }
        return $clean;
    }

    // -------------------------------------------------------------------------
    // Capability check
    // -------------------------------------------------------------------------

    public static function current_user_can_manage(): bool {
        return current_user_can('manage_options');
    }
}
