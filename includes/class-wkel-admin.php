<?php
defined('ABSPATH') || exit;

class WKEL_Admin {

    public static function register_menu(): void {
        $display_name = get_option('wkel_plugin_display_name', 'Event Leads');

        add_menu_page(
            esc_html($display_name),
            esc_html($display_name),
            'manage_options',
            'wkel_leads',
            [self::class, 'render_kanban'],
            'dashicons-groups',
            26
        );

        add_submenu_page(
            'wkel_leads',
            __('Pipeline', 'wk-event-leads'),
            __('Pipeline', 'wk-event-leads'),
            'manage_options',
            'wkel_leads',
            [self::class, 'render_kanban']
        );

        add_submenu_page(
            'wkel_leads',
            __('All Leads', 'wk-event-leads'),
            __('All Leads', 'wk-event-leads'),
            'manage_options',
            'wkel_all_leads',
            [self::class, 'render_lead_list']
        );
    }

    public static function enqueue_assets(string $hook): void {
        $admin_pages = ['toplevel_page_wkel_leads', 'event-leads_page_wkel_all_leads'];

        if (!in_array($hook, $admin_pages, true)) {
            return;
        }

        // Admin general styles
        wp_enqueue_style(
            'wkel-admin',
            WKEL_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WKEL_VERSION
        );

        // Kanban page
        if ($hook === 'toplevel_page_wkel_leads') {
            wp_enqueue_style(
                'wkel-kanban',
                WKEL_PLUGIN_URL . 'assets/css/kanban.css',
                [],
                WKEL_VERSION
            );

            wp_enqueue_script(
                'sortablejs',
                'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js',
                [],
                '1.15.2',
                true
            );

            wp_enqueue_script(
                'wkel-kanban',
                WKEL_PLUGIN_URL . 'assets/js/kanban.js',
                ['sortablejs'],
                WKEL_VERSION,
                true
            );

            $stages = WKEL_Schema::get_stages();
            usort($stages, fn($a, $b) => $a['order'] <=> $b['order']);

            wp_localize_script('wkel-kanban', 'wkelKanban', [
                'restBase'    => esc_url_raw(rest_url('wk-event-leads/v1')),
                'nonce'       => wp_create_nonce('wp_rest'),
                'stages'      => $stages,
                'schemaFields'=> WKEL_Schema::get_fields(),
                'allStages'   => $stages,
            ]);
        }

        // All leads page
        if ($hook === 'event-leads_page_wkel_all_leads') {
            wp_enqueue_script(
                'wkel-admin',
                WKEL_PLUGIN_URL . 'assets/js/admin.js',
                [],
                WKEL_VERSION,
                true
            );

            wp_localize_script('wkel-admin', 'wkelAdmin', [
                'restBase'  => esc_url_raw(rest_url('wk-event-leads/v1')),
                'nonce'     => wp_create_nonce('wp_rest'),
                'exportUrl' => admin_url('admin-post.php?action=wkel_export_csv'),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Kanban board page
    // -------------------------------------------------------------------------

    public static function render_kanban(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to view this page.', 'wk-event-leads'));
        }

        $stages  = WKEL_Schema::get_stages();
        usort($stages, fn($a, $b) => $a['order'] <=> $b['order']);

        $filters = [
            'event'        => sanitize_key($_GET['wkel_event'] ?? ''),
            'email_status' => sanitize_key($_GET['wkel_email_status'] ?? ''),
            'search'       => sanitize_text_field($_GET['wkel_search'] ?? ''),
        ];

        $leads_by_stage = WKEL_Pipeline::get_leads_by_stage($filters);
        $event_map      = json_decode(get_option('wkel_event_map', '[]'), true) ?: [];

        include WKEL_PLUGIN_DIR . 'templates/kanban.php';
    }

    // -------------------------------------------------------------------------
    // All Leads list page
    // -------------------------------------------------------------------------

    public static function render_lead_list(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to view this page.', 'wk-event-leads'));
        }

        // Handle bulk actions
        self::handle_bulk_actions();

        if (!class_exists('WKEL_Lead_List_Table')) {
            require_once WKEL_PLUGIN_DIR . 'includes/class-wkel-admin.php';
        }

        $table = new WKEL_Lead_List_Table();
        $table->prepare_items();

        $event_map    = json_decode(get_option('wkel_event_map', '[]'), true) ?: [];
        $stages       = WKEL_Schema::get_stages();
        $export_nonce = wp_create_nonce('wkel_export_csv');

        ?>
        <div class="wrap wkel-admin">
            <h1 class="wp-heading-inline"><?php esc_html_e('All Leads', 'wk-event-leads'); ?></h1>

            <a href="<?php echo esc_url(admin_url('admin-post.php?action=wkel_export_csv&_wpnonce=' . $export_nonce . self::filter_query_string())); ?>"
               class="page-title-action"><?php esc_html_e('Export CSV', 'wk-event-leads'); ?></a>

            <hr class="wp-header-end">

            <!-- Filters -->
            <form method="get">
                <input type="hidden" name="page" value="wkel_all_leads">

                <select name="wkel_event">
                    <option value=""><?php esc_html_e('All Events', 'wk-event-leads'); ?></option>
                    <?php foreach ($event_map as $entry): ?>
                        <option value="<?php echo esc_attr($entry['slug'] ?? ''); ?>"
                            <?php selected($_GET['wkel_event'] ?? '', $entry['slug'] ?? ''); ?>>
                            <?php echo esc_html($entry['name'] ?? $entry['slug'] ?? ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="wkel_stage">
                    <option value=""><?php esc_html_e('All Stages', 'wk-event-leads'); ?></option>
                    <?php foreach ($stages as $stage): ?>
                        <option value="<?php echo esc_attr($stage['id']); ?>"
                            <?php selected($_GET['wkel_stage'] ?? '', $stage['id']); ?>>
                            <?php echo esc_html($stage['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="wkel_email_status">
                    <option value=""><?php esc_html_e('All Email Statuses', 'wk-event-leads'); ?></option>
                    <option value="queued"  <?php selected($_GET['wkel_email_status'] ?? '', 'queued'); ?>><?php esc_html_e('Queued', 'wk-event-leads'); ?></option>
                    <option value="sent"    <?php selected($_GET['wkel_email_status'] ?? '', 'sent'); ?>><?php esc_html_e('Sent', 'wk-event-leads'); ?></option>
                    <option value="failed"  <?php selected($_GET['wkel_email_status'] ?? '', 'failed'); ?>><?php esc_html_e('Failed', 'wk-event-leads'); ?></option>
                </select>

                <?php submit_button(__('Filter', 'wk-event-leads'), 'secondary', 'filter_action', false); ?>
            </form>

            <form method="post">
                <?php
                $table->search_box(__('Search Leads', 'wk-event-leads'), 'wkel_search');
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    private static function handle_bulk_actions(): void {
        $action = $_POST['action'] ?? $_POST['action2'] ?? '';
        if (!$action || $action === '-1') {
            return;
        }

        $nonce = $_POST['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'bulk-wkel_leads')) {
            return;
        }

        $lead_ids = array_map('intval', $_POST['lead'] ?? []);
        if (empty($lead_ids)) {
            return;
        }

        switch ($action) {
            case 'delete':
                foreach ($lead_ids as $id) {
                    do_action('wkel_lead_deleted', $id);
                    wp_delete_post($id, true);
                }
                break;

            case 'resend_email':
                foreach ($lead_ids as $id) {
                    update_post_meta($id, '_wkel_email_status', 'queued');
                    update_post_meta($id, '_wkel_email_attempts', 0);
                    if (function_exists('as_schedule_single_action')) {
                        as_schedule_single_action(time(), 'wkel_send_confirmation_email', ['lead_id' => $id], 'wk-event-leads');
                    }
                }
                break;

            default:
                // move_to_{stage}
                if (str_starts_with($action, 'move_to_')) {
                    $stage = substr($action, 8);
                    if (WKEL_Schema::get_stage($stage)) {
                        foreach ($lead_ids as $id) {
                            $old = get_post_meta($id, '_wkel_stage', true);
                            update_post_meta($id, '_wkel_stage', $stage);
                            do_action('wkel_lead_stage_changed', $id, $old, $stage);
                        }
                    }
                }
                break;
        }
    }

    private static function filter_query_string(): string {
        $params = [];
        foreach (['wkel_event', 'wkel_stage', 'wkel_email_status', 's'] as $key) {
            if (!empty($_GET[$key])) {
                $params['&' . $key . '='] = sanitize_text_field($_GET[$key]);
            }
        }
        $result = '';
        foreach ($params as $k => $v) {
            $result .= $k . urlencode($v);
        }
        return $result;
    }
}

// -------------------------------------------------------------------------
// WP_List_Table for leads
// -------------------------------------------------------------------------

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WKEL_Lead_List_Table extends WP_List_Table {

    private array $schema_fields;
    private array $stages;

    public function __construct() {
        parent::__construct([
            'singular' => 'lead',
            'plural'   => 'leads',
            'ajax'     => false,
        ]);
        $this->schema_fields = WKEL_Schema::get_fields();
        $this->stages        = WKEL_Schema::get_stages();
    }

    public function get_columns(): array {
        $columns = ['cb' => '<input type="checkbox">'];

        foreach ($this->schema_fields as $field) {
            if (!empty($field['show_list'])) {
                $columns[$field['id']] = esc_html($field['label']);
            }
        }

        $columns = apply_filters('wkel_lead_list_columns', $columns);

        $columns['_wkel_event']        = __('Event', 'wk-event-leads');
        $columns['_wkel_stage']        = __('Stage', 'wk-event-leads');
        $columns['post_date']          = __('Submitted', 'wk-event-leads');
        $columns['_wkel_email_status'] = __('Email', 'wk-event-leads');

        return $columns;
    }

    public function get_sortable_columns(): array {
        return [
            '_wkel_event'        => ['_wkel_event', false],
            '_wkel_stage'        => ['_wkel_stage', false],
            'post_date'          => ['date', true],
            '_wkel_email_status' => ['_wkel_email_status', false],
        ];
    }

    protected function get_bulk_actions(): array {
        $actions = [
            'delete'       => __('Delete', 'wk-event-leads'),
            'resend_email' => __('Resend Email', 'wk-event-leads'),
        ];

        foreach ($this->stages as $stage) {
            $actions['move_to_' . $stage['id']] = sprintf(__('Move to: %s', 'wk-event-leads'), $stage['label']);
        }

        return apply_filters('wkel_bulk_actions', $actions);
    }

    public function prepare_items(): void {
        $per_page     = 25;
        $current_page = $this->get_pagenum();

        $query_args = [
            'post_type'      => 'wkel_lead',
            'posts_per_page' => $per_page,
            'paged'          => $current_page,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $meta_query = [];

        if (!empty($_GET['wkel_event'])) {
            $meta_query[] = ['key' => '_wkel_event', 'value' => sanitize_key($_GET['wkel_event'])];
        }
        if (!empty($_GET['wkel_stage'])) {
            $meta_query[] = ['key' => '_wkel_stage', 'value' => sanitize_key($_GET['wkel_stage'])];
        }
        if (!empty($_GET['wkel_email_status'])) {
            $meta_query[] = ['key' => '_wkel_email_status', 'value' => sanitize_key($_GET['wkel_email_status'])];
        }

        if (!empty($meta_query)) {
            $meta_query['relation'] = 'AND';
            $query_args['meta_query'] = $meta_query;
        }

        if (!empty($_GET['s'])) {
            $query_args['s'] = sanitize_text_field($_GET['s']);
        }

        // Sorting by meta
        $orderby = sanitize_key($_GET['orderby'] ?? 'date');
        $order   = strtoupper(sanitize_key($_GET['order'] ?? 'DESC'));
        $order   = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';

        if (in_array($orderby, ['_wkel_event', '_wkel_stage', '_wkel_email_status'], true)) {
            $query_args['meta_key'] = $orderby;
            $query_args['orderby']  = 'meta_value';
            $query_args['order']    = $order;
        } else {
            $query_args['orderby'] = 'date';
            $query_args['order']   = $order;
        }

        $query       = new WP_Query($query_args);
        $this->items = $query->posts;

        $this->set_pagination_args([
            'total_items' => $query->found_posts,
            'per_page'    => $per_page,
            'total_pages' => ceil($query->found_posts / $per_page),
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    protected function column_default($item, $column_name): string {
        // Schema field columns
        foreach ($this->schema_fields as $field) {
            if ($field['id'] === $column_name && !empty($field['show_list'])) {
                $raw   = get_post_meta($item->ID, '_wkel_' . $field['id'], true);
                $value = WKEL_Encryption::decrypt((string) $raw);
                return esc_html($value);
            }
        }

        switch ($column_name) {
            case '_wkel_event':
                return esc_html(get_post_meta($item->ID, '_wkel_event', true));

            case '_wkel_stage':
                $stage_id  = get_post_meta($item->ID, '_wkel_stage', true);
                $stage_obj = WKEL_Schema::get_stage($stage_id);
                $label     = $stage_obj ? $stage_obj['label'] : $stage_id;
                $color     = $stage_obj ? $stage_obj['color'] : '#6B7280';
                return sprintf(
                    '<span class="wkel-stage-badge" style="background:%s;color:#fff;padding:2px 8px;border-radius:3px;">%s</span>',
                    esc_attr($color),
                    esc_html($label)
                );

            case 'post_date':
                return esc_html(get_the_date('Y-m-d H:i', $item->ID));

            case '_wkel_email_status':
                return self::email_status_badge(get_post_meta($item->ID, '_wkel_email_status', true));
        }

        return '';
    }

    protected function column_cb($item): string {
        return sprintf('<input type="checkbox" name="lead[]" value="%d">', $item->ID);
    }

    private static function email_status_badge(string $status): string {
        $colors = ['queued' => '#9CA3AF', 'sent' => '#10B981', 'failed' => '#EF4444'];
        $labels = ['queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed'];
        $color  = $colors[$status] ?? '#9CA3AF';
        $label  = $labels[$status] ?? $status;
        return sprintf(
            '<span class="wkel-email-badge" style="display:inline-flex;align-items:center;gap:4px;">'
            . '<span style="width:8px;height:8px;border-radius:50%%;background:%s;display:inline-block;"></span>'
            . '%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }
}

// CSV export handler
add_action('admin_post_wkel_export_csv', function () {
    if (!current_user_can('manage_options')) {
        wp_die(__('Forbidden.', 'wk-event-leads'));
    }

    if (!check_admin_referer('wkel_export_csv')) {
        wp_die(__('Invalid nonce.', 'wk-event-leads'));
    }

    require_once WKEL_PLUGIN_DIR . 'includes/class-wkel-export.php';
    WKEL_Export::output_csv($_GET);
});
