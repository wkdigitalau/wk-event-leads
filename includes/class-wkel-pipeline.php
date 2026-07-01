<?php
defined('ABSPATH') || exit;

/**
 * Pipeline data layer — helpers for kanban and lead list.
 * Stage CRUD delegates to WKEL_Schema.
 */
class WKEL_Pipeline {

    /**
     * Get all leads for kanban, grouped by stage.
     * Returns: [ 'stage_id' => [ lead_data, ... ], ... ]
     */
    public static function get_leads_by_stage(array $filters = []): array {
        $stages = WKEL_Schema::get_stages();
        usort($stages, fn($a, $b) => $a['order'] <=> $b['order']);

        $result = [];
        foreach ($stages as $stage) {
            $result[$stage['id']] = [];
        }

        $query_args = [
            'post_type'      => 'wkel_lead',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ];

        $meta_query = [];

        if (!empty($filters['event'])) {
            $meta_query[] = ['key' => '_wkel_event', 'value' => sanitize_key($filters['event'])];
        }

        if (!empty($filters['email_status'])) {
            $meta_query[] = ['key' => '_wkel_email_status', 'value' => sanitize_key($filters['email_status'])];
        }

        if (!empty($meta_query)) {
            $meta_query['relation'] = 'AND';
            $query_args['meta_query'] = $meta_query;
        }

        $posts = get_posts($query_args);

        $schema_fields = WKEL_Schema::get_fields();
        $search        = isset($filters['search']) ? strtolower(sanitize_text_field($filters['search'])) : '';

        foreach ($posts as $post) {
            $card = self::build_card($post, $schema_fields);

            if ($search) {
                $haystack = strtolower($card['name'] . ' ' . $card['organisation']);
                if (!str_contains($haystack, $search)) {
                    continue;
                }
            }

            $stage_id = $card['stage'];
            if (isset($result[$stage_id])) {
                $result[$stage_id][] = $card;
            } else {
                // Stage was deleted — put in first stage
                reset($result);
                $first = key($result);
                if ($first !== null) {
                    $result[$first][] = $card;
                }
            }
        }

        return $result;
    }

    /**
     * Build a single kanban card data array for a lead post.
     */
    public static function build_card(WP_Post $post, array $schema_fields): array {
        $card = [
            'id'           => $post->ID,
            'stage'        => get_post_meta($post->ID, '_wkel_stage', true),
            'email_status' => get_post_meta($post->ID, '_wkel_email_status', true),
            'submitted_at' => (int) get_post_meta($post->ID, '_wkel_submitted_at', true),
            'event'        => get_post_meta($post->ID, '_wkel_event', true),
            'campaign'     => get_post_meta($post->ID, '_wkel_campaign', true),
            'list_type'    => get_post_meta($post->ID, '_wkel_list_type', true),
            'marketing_status' => get_post_meta($post->ID, '_wkel_marketing_status', true) ?: 'subscribed',
            'name'         => '',
            'organisation' => '',
            'extra_fields' => [],
        ];

        foreach ($schema_fields as $field) {
            $raw   = get_post_meta($post->ID, '_wkel_' . $field['id'], true);
            $value = WKEL_Encryption::decrypt((string) $raw);

            if ($field['id'] === 'wkel_name') {
                $card['name'] = $value;
            } elseif ($field['id'] === 'wkel_organisation') {
                $card['organisation'] = $value;
            }

            if (!empty($field['show_kanban'])) {
                $card['extra_fields'][] = [
                    'label' => $field['label'],
                    'value' => $value,
                ];
            }
        }

        $card = apply_filters('wkel_kanban_card_data', $card, $post->ID);

        return $card;
    }

    /**
     * Full lead data for the detail modal.
     */
    public static function get_lead_detail(int $lead_id): ?array {
        $post = get_post($lead_id);
        if (!$post || $post->post_type !== 'wkel_lead') {
            return null;
        }

        $schema_fields = WKEL_Schema::get_fields();
        $fields        = [];

        foreach ($schema_fields as $field) {
            $raw   = get_post_meta($lead_id, '_wkel_' . $field['id'], true);
            $value = WKEL_Encryption::decrypt((string) $raw);
            $fields[] = [
                'id'       => $field['id'],
                'label'    => $field['label'],
                'type'     => $field['type'],
                'locked'   => !empty($field['locked']),
                'options'  => $field['options'] ?? [],
                'value'    => $value,
            ];
        }

        $stage_id   = get_post_meta($lead_id, '_wkel_stage', true);
        $stage_obj  = WKEL_Schema::get_stage($stage_id);
        $activity   = get_post_meta($lead_id, '_wkel_activity_log', true);
        $activity   = $activity ? json_decode($activity, true) : [];

        return [
            'id'           => $lead_id,
            'fields'       => $fields,
            'stage'        => $stage_id,
            'stage_label'  => $stage_obj ? $stage_obj['label'] : $stage_id,
            'email_status' => get_post_meta($lead_id, '_wkel_email_status', true),
            'email_sent_at'=> (int) get_post_meta($lead_id, '_wkel_email_sent_at', true),
            'event'        => get_post_meta($lead_id, '_wkel_event', true),
            'campaign'     => get_post_meta($lead_id, '_wkel_campaign', true),
            'list_type'    => get_post_meta($lead_id, '_wkel_list_type', true),
            'marketing_status' => get_post_meta($lead_id, '_wkel_marketing_status', true) ?: 'subscribed',
            'unsubscribed_at' => (int) get_post_meta($lead_id, '_wkel_unsubscribed_at', true),
            'submitted_at' => (int) get_post_meta($lead_id, '_wkel_submitted_at', true),
            'admin_notes'  => get_post_meta($lead_id, '_wkel_admin_notes', true),
            'activity_log' => $activity,
        ];
    }
}
