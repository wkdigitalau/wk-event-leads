<?php
defined('ABSPATH') || exit;

class WKEL_Export {

    /**
     * Stream a CSV of leads to the browser.
     * $filters: same keys as used by the lead list filter form.
     */
    public static function output_csv(array $filters = []): void {
        $filename = 'wk-event-leads-' . wp_date('Y-m-d-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // BOM for Excel UTF-8 compatibility
        fwrite($output, "\xEF\xBB\xBF");

        $schema_fields  = WKEL_Schema::get_fields();
        $list_fields    = array_filter($schema_fields, fn($f) => !empty($f['show_list']));

        // Header row
        $header = [];
        foreach ($list_fields as $field) {
            $header[] = $field['label'];
        }
        $header[] = 'Event';
        $header[] = 'Stage';
        $header[] = 'Submitted';
        $header[] = 'Email Status';

        fputcsv($output, $header);

        // Data rows
        $query_args = [
            'post_type'      => 'wkel_lead',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $meta_query = [];
        if (!empty($filters['wkel_event'])) {
            $meta_query[] = ['key' => '_wkel_event', 'value' => sanitize_key($filters['wkel_event'])];
        }
        if (!empty($filters['wkel_stage'])) {
            $meta_query[] = ['key' => '_wkel_stage', 'value' => sanitize_key($filters['wkel_stage'])];
        }
        if (!empty($filters['wkel_email_status'])) {
            $meta_query[] = ['key' => '_wkel_email_status', 'value' => sanitize_key($filters['wkel_email_status'])];
        }
        if (!empty($meta_query)) {
            $meta_query['relation'] = 'AND';
            $query_args['meta_query'] = $meta_query;
        }

        $posts  = get_posts($query_args);
        $stages = WKEL_Schema::get_stages();
        $stage_map = [];
        foreach ($stages as $s) {
            $stage_map[$s['id']] = $s['label'];
        }

        foreach ($posts as $post) {
            $row = [];

            foreach ($list_fields as $field) {
                $raw   = get_post_meta($post->ID, '_wkel_' . $field['id'], true);
                $value = WKEL_Encryption::decrypt((string) $raw);
                $row[] = $value;
            }

            $stage_id = get_post_meta($post->ID, '_wkel_stage', true);
            $row[]    = get_post_meta($post->ID, '_wkel_event', true);
            $row[]    = $stage_map[$stage_id] ?? $stage_id;
            $row[]    = get_the_date('Y-m-d H:i:s', $post->ID);
            $row[]    = get_post_meta($post->ID, '_wkel_email_status', true);

            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }
}
