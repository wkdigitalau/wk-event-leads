<?php
defined('ABSPATH') || exit;

class WKEL_Submission {

    public static function register_routes(): void {
        register_rest_route('wk-event-leads/v1', '/submit', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle'],
            'permission_callback' => '__return_true',
            'args'                => [],
        ]);

        // Stage update endpoint (used by kanban drag-drop)
        register_rest_route('wk-event-leads/v1', '/lead/(?P<id>\d+)/stage', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [self::class, 'update_stage'],
            'permission_callback' => [self::class, 'admin_permission'],
            'args'                => [
                'id'    => ['validate_callback' => fn($v) => is_numeric($v)],
                'stage' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);

        // Lead detail — GET, PATCH, DELETE on same route
        register_rest_route('wk-event-leads/v1', '/lead/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [self::class, 'get_lead'],
                'permission_callback' => [self::class, 'admin_permission'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [self::class, 'update_lead'],
                'permission_callback' => [self::class, 'admin_permission'],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [self::class, 'delete_lead'],
                'permission_callback' => [self::class, 'admin_permission'],
            ],
        ]);

        // Resend email endpoint
        register_rest_route('wk-event-leads/v1', '/lead/(?P<id>\d+)/resend-email', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'resend_email'],
            'permission_callback' => [self::class, 'admin_permission'],
        ]);
    }

    public static function admin_permission(): bool {
        return current_user_can('manage_options');
    }

    // -------------------------------------------------------------------------
    // Form submission
    // -------------------------------------------------------------------------

    public static function handle(WP_REST_Request $request): WP_REST_Response {
        $body        = $request->get_json_params();
        $silent_ok   = new WP_REST_Response(['success' => true, 'message' => get_option('wkel_success_message', 'Thanks — check your inbox.')]);

        // 1. Honeypot
        if (WKEL_Security::honeypot_triggered($body)) {
            return $silent_ok;
        }

        // 3. Rate limit
        $ip = WKEL_Security::get_submitter_ip();
        if (WKEL_Security::rate_limit_exceeded($ip)) {
            return $silent_ok;
        }

        // 4. Sanitise and validate schema fields
        $form_fields = WKEL_Schema::get_form_fields();
        $sanitised   = [];
        $errors      = [];

        foreach ($form_fields as $field) {
            $id        = $field['id'];
            $raw_value = $body[$id] ?? '';

            // Handle checkbox arrays
            if ($field['type'] === 'checkbox' && is_array($raw_value)) {
                $sanitised[$id] = implode(', ', array_map('sanitize_text_field', $raw_value));
            } else {
                $sanitised[$id] = WKEL_Security::sanitise_by_type((string) $raw_value, $field['type']);
            }

            if (!empty($field['required']) && $sanitised[$id] === '') {
                $errors[$id] = sprintf(__('%s is required.', 'wk-event-leads'), $field['label']);
                continue;
            }

            if ($field['type'] === 'email' && $sanitised[$id] !== '' && !is_email($sanitised[$id])) {
                $errors[$id] = __('Please enter a valid email address.', 'wk-event-leads');
            }
        }

        if (!empty($errors)) {
            return new WP_REST_Response(['success' => false, 'errors' => $errors], 422);
        }

        // 5. Privacy checkbox
        if (empty($body['wkel_privacy']) || $body['wkel_privacy'] !== '1') {
            return new WP_REST_Response([
                'success' => false,
                'errors'  => ['wkel_privacy' => __('You must accept the Privacy Policy.', 'wk-event-leads')],
            ], 422);
        }

        // 6. Duplicate check — same email + same event within 24 hours
        $event = sanitize_key($body['event'] ?? 'general');
        if (self::is_duplicate($sanitised, $event)) {
            return $silent_ok;
        }

        // Create lead
        $lead_id = self::create_lead($sanitised, $event, $ip);

        if (is_wp_error($lead_id)) {
            return new WP_REST_Response(['success' => false, 'message' => __('Could not save your submission. Please try again.', 'wk-event-leads')], 500);
        }

        // Queue confirmation email via Action Scheduler if available, otherwise send directly
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), 'wkel_send_confirmation_email', ['lead_id' => $lead_id], 'wk-event-leads');
        } else {
            WKEL_Email::send_confirmation($lead_id);
        }

        do_action('wkel_lead_created', $lead_id, $sanitised);

        return new WP_REST_Response(['success' => true, 'message' => get_option('wkel_success_message', 'Thanks — check your inbox.')]);
    }

    private static function is_duplicate(array $sanitised, string $event): bool {
        // Find email field
        $email_value = '';
        foreach (WKEL_Schema::get_fields() as $field) {
            if ($field['type'] === 'email' && isset($sanitised[$field['id']])) {
                $email_value = $sanitised[$field['id']];
                break;
            }
        }

        if (!$email_value) {
            return false;
        }

        $email_hash = hash('sha256', strtolower(trim($email_value)));

        $existing = get_posts([
            'post_type'      => 'wkel_lead',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'date_query'     => [['after' => '24 hours ago']],
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_wkel_email_hash', 'value' => $email_hash],
                ['key' => '_wkel_event', 'value' => $event],
            ],
            'fields'         => 'ids',
        ]);

        return !empty($existing);
    }

    private static function create_lead(array $sanitised, string $event, string $ip): int|WP_Error {
        // Determine organisation for post title
        $org = '';
        foreach (WKEL_Schema::get_fields() as $field) {
            if ($field['id'] === 'wkel_organisation' && isset($sanitised[$field['id']])) {
                $org = $sanitised[$field['id']];
                break;
            }
        }

        $title = ($org ?: 'Lead') . ' — ' . wp_date('Y-m-d');

        $lead_id = wp_insert_post([
            'post_type'   => 'wkel_lead',
            'post_status' => 'publish',
            'post_title'  => $title,
        ], true);

        if (is_wp_error($lead_id)) {
            return $lead_id;
        }

        // Store schema fields — encrypt if flagged
        $fields = WKEL_Schema::get_fields();
        foreach ($fields as $field) {
            $id    = $field['id'];
            $value = $sanitised[$id] ?? '';

            if (!empty($field['encrypted'])) {
                $value = WKEL_Encryption::encrypt($value);
            }

            update_post_meta($lead_id, '_wkel_' . $id, $value);
        }

        // Store email hash for duplicate detection (never encrypted, never decrypted — hash only)
        $email_field = null;
        foreach ($fields as $f) {
            if ($f['type'] === 'email') { $email_field = $f; break; }
        }
        if ($email_field && isset($sanitised[$email_field['id']])) {
            update_post_meta($lead_id, '_wkel_email_hash', hash('sha256', strtolower(trim($sanitised[$email_field['id']]))));
        }

        // First pipeline stage
        $first_stage = WKEL_Schema::get_first_stage();
        $stage_id    = $first_stage ? $first_stage['id'] : 'new';

        // System meta
        $system_meta = [
            '_wkel_event'           => $event,
            '_wkel_stage'           => $stage_id,
            '_wkel_ip_hash'         => hash('sha256', $ip),
            '_wkel_email_status'    => 'queued',
            '_wkel_email_attempts'  => 0,
            '_wkel_privacy_accepted'=> '1',
            '_wkel_submitted_at'    => time(),
            '_wkel_source'          => 'direct',
        ];

        foreach ($system_meta as $key => $value) {
            update_post_meta($lead_id, $key, $value);
        }

        // Activity log
        self::log_activity($lead_id, 'submitted', 'Lead submitted via form.');

        return $lead_id;
    }

    // -------------------------------------------------------------------------
    // Stage update (kanban drag-drop)
    // -------------------------------------------------------------------------
    // Lead GET (detail panel)
    // -------------------------------------------------------------------------

    public static function get_lead(WP_REST_Request $request): WP_REST_Response {
        $lead_id = (int) $request['id'];
        $detail  = WKEL_Pipeline::get_lead_detail($lead_id);

        if (!$detail) {
            return new WP_REST_Response(['success' => false, 'message' => 'Lead not found.'], 404);
        }

        return new WP_REST_Response($detail);
    }

    // -------------------------------------------------------------------------

    public static function update_stage(WP_REST_Request $request): WP_REST_Response {
        $lead_id   = (int) $request['id'];
        $new_stage = sanitize_key($request['stage']);

        if (get_post_type($lead_id) !== 'wkel_lead') {
            return new WP_REST_Response(['success' => false, 'message' => 'Lead not found.'], 404);
        }

        if (!WKEL_Schema::get_stage($new_stage)) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid stage.'], 422);
        }

        $old_stage = get_post_meta($lead_id, '_wkel_stage', true);
        update_post_meta($lead_id, '_wkel_stage', $new_stage);

        self::log_activity($lead_id, 'stage_changed', sprintf('Stage changed from %s to %s.', $old_stage, $new_stage));

        do_action('wkel_lead_stage_changed', $lead_id, $old_stage, $new_stage);

        return new WP_REST_Response(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // Lead update (modal inline edit)
    // -------------------------------------------------------------------------

    public static function update_lead(WP_REST_Request $request): WP_REST_Response {
        $lead_id = (int) $request['id'];
        $body    = $request->get_json_params();

        if (get_post_type($lead_id) !== 'wkel_lead') {
            return new WP_REST_Response(['success' => false, 'message' => 'Lead not found.'], 404);
        }

        $fields = WKEL_Schema::get_fields();

        foreach ($fields as $field) {
            $id = $field['id'];
            if (!array_key_exists($id, $body)) {
                continue;
            }
            $value = WKEL_Security::sanitise_by_type((string) $body[$id], $field['type']);
            if (!empty($field['encrypted'])) {
                $value = WKEL_Encryption::encrypt($value);
            }
            update_post_meta($lead_id, '_wkel_' . $id, $value);
        }

        // Stage
        if (!empty($body['stage'])) {
            $new_stage = sanitize_key($body['stage']);
            if (WKEL_Schema::get_stage($new_stage)) {
                $old_stage = get_post_meta($lead_id, '_wkel_stage', true);
                if ($old_stage !== $new_stage) {
                    update_post_meta($lead_id, '_wkel_stage', $new_stage);
                    self::log_activity($lead_id, 'stage_changed', "Stage changed from {$old_stage} to {$new_stage}.");
                    do_action('wkel_lead_stage_changed', $lead_id, $old_stage, $new_stage);
                }
            }
        }

        // Admin notes
        if (array_key_exists('admin_notes', $body)) {
            update_post_meta($lead_id, '_wkel_admin_notes', sanitize_textarea_field($body['admin_notes']));
        }

        return new WP_REST_Response(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // Lead delete
    // -------------------------------------------------------------------------

    public static function delete_lead(WP_REST_Request $request): WP_REST_Response {
        $lead_id = (int) $request['id'];

        if (get_post_type($lead_id) !== 'wkel_lead') {
            return new WP_REST_Response(['success' => false, 'message' => 'Lead not found.'], 404);
        }

        do_action('wkel_lead_deleted', $lead_id);

        wp_delete_post($lead_id, true);

        return new WP_REST_Response(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // Resend email
    // -------------------------------------------------------------------------

    public static function resend_email(WP_REST_Request $request): WP_REST_Response {
        $lead_id = (int) $request['id'];

        if (get_post_type($lead_id) !== 'wkel_lead') {
            return new WP_REST_Response(['success' => false, 'message' => 'Lead not found.'], 404);
        }

        update_post_meta($lead_id, '_wkel_email_status', 'queued');
        update_post_meta($lead_id, '_wkel_email_attempts', 0);

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), 'wkel_send_confirmation_email', ['lead_id' => $lead_id], 'wk-event-leads');
        } else {
            WKEL_Email::send_confirmation($lead_id);
        }

        return new WP_REST_Response(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // Activity log
    // -------------------------------------------------------------------------

    public static function log_activity(int $lead_id, string $type, string $message): void {
        $log   = get_post_meta($lead_id, '_wkel_activity_log', true);
        $log   = $log ? json_decode($log, true) : [];
        $log[] = [
            'type'    => $type,
            'message' => $message,
            'at'      => time(),
        ];
        update_post_meta($lead_id, '_wkel_activity_log', json_encode($log));
    }
}
