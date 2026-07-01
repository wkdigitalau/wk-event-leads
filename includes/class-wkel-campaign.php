<?php
defined('ABSPATH') || exit;

/**
 * Cold outreach list tracking and public opt-out handling.
 */
class WKEL_Campaign {

    public static function add_rewrite_rules(): void {
        add_rewrite_rule('^unsubscribe/?$', 'index.php?wkel_unsubscribe=1', 'top');
        add_filter('query_vars', [self::class, 'query_vars']);
    }

    public static function query_vars(array $vars): array {
        $vars[] = 'wkel_unsubscribe';
        return $vars;
    }

    public static function register_admin_menu(): void {
        add_submenu_page(
            'wkel_leads',
            __('Campaigns', 'wk-event-leads'),
            __('Campaigns', 'wk-event-leads'),
            'manage_options',
            'wkel_campaigns',
            [self::class, 'render_admin_page']
        );
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to view this page.', 'wk-event-leads'));
        }

        $imported = isset($_GET['imported']) ? absint($_GET['imported']) : null;
        $skipped  = isset($_GET['skipped']) ? absint($_GET['skipped']) : null;
        $suppression_nonce = wp_create_nonce('wkel_export_suppression_csv');
        ?>
        <div class="wrap wkel-admin">
            <h1><?php esc_html_e('Cold Outreach Campaigns', 'wk-event-leads'); ?></h1>

            <?php if ($imported !== null): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            esc_html__('Import complete: %1$d contacts tracked, %2$d rows skipped.', 'wk-event-leads'),
                            $imported,
                            $skipped
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e('Import campaign contacts', 'wk-event-leads'); ?></h2>
            <p><?php esc_html_e('Upload a CSV with headers. Recommended columns: email, name, organisation, campaign, list_type, segment, role.', 'wk-event-leads'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('wkel_import_campaign_contacts'); ?>
                <input type="hidden" name="action" value="wkel_import_campaign_contacts">
                <table class="form-table">
                    <tr>
                        <th><label for="wkel_campaign_csv"><?php esc_html_e('CSV file', 'wk-event-leads'); ?></label></th>
                        <td><input type="file" id="wkel_campaign_csv" name="wkel_campaign_csv" accept=".csv,text/csv" required></td>
                    </tr>
                    <tr>
                        <th><label for="wkel_default_campaign"><?php esc_html_e('Default campaign', 'wk-event-leads'); ?></label></th>
                        <td>
                            <input type="text" id="wkel_default_campaign" name="wkel_default_campaign" value="agentic-cosec" class="regular-text">
                            <p class="description"><?php esc_html_e('Used when the CSV does not include a campaign column.', 'wk-event-leads'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wkel_default_list_type"><?php esc_html_e('Default list type', 'wk-event-leads'); ?></label></th>
                        <td>
                            <select id="wkel_default_list_type" name="wkel_default_list_type">
                                <option value="for_profit"><?php esc_html_e('For profit', 'wk-event-leads'); ?></option>
                                <option value="not_for_profit"><?php esc_html_e('Not for profit', 'wk-event-leads'); ?></option>
                                <option value="mixed"><?php esc_html_e('Mixed', 'wk-event-leads'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Import Contacts', 'wk-event-leads')); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Unsubscribe links', 'wk-event-leads'); ?></h2>
            <p>
                <?php esc_html_e('Use this variable in email templates:', 'wk-event-leads'); ?>
                <code>{{unsubscribe_url}}</code>
            </p>
            <p>
                <?php esc_html_e('Fallback public opt-out page:', 'wk-event-leads'); ?>
                <a href="<?php echo esc_url(self::unsubscribe_page_url()); ?>" target="_blank" rel="noopener">
                    <?php echo esc_html(self::unsubscribe_page_url()); ?>
                </a>
            </p>

            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin-post.php?action=wkel_export_suppression_csv&_wpnonce=' . $suppression_nonce)); ?>">
                    <?php esc_html_e('Export Suppression List', 'wk-event-leads'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public static function handle_import(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Forbidden.', 'wk-event-leads'));
        }
        if (!check_admin_referer('wkel_import_campaign_contacts')) {
            wp_die(__('Invalid nonce.', 'wk-event-leads'));
        }
        if (empty($_FILES['wkel_campaign_csv']['tmp_name']) || !is_uploaded_file($_FILES['wkel_campaign_csv']['tmp_name'])) {
            wp_safe_redirect(admin_url('admin.php?page=wkel_campaigns&imported=0&skipped=1'));
            exit;
        }

        $default_campaign = sanitize_key($_POST['wkel_default_campaign'] ?? 'agentic-cosec');
        $default_list_type = sanitize_key($_POST['wkel_default_list_type'] ?? 'for_profit');
        $handle = fopen($_FILES['wkel_campaign_csv']['tmp_name'], 'r');
        $imported = 0;
        $skipped = 0;

        if (!$handle) {
            wp_safe_redirect(admin_url('admin.php?page=wkel_campaigns&imported=0&skipped=1'));
            exit;
        }

        $headers = fgetcsv($handle);
        $headers = is_array($headers) ? array_map([self::class, 'normalise_header'], $headers) : [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = self::row_to_assoc($headers, $row);
            $email = sanitize_email($data['email'] ?? $data['email_address'] ?? '');

            if (!$email || self::is_email_suppressed($email)) {
                $skipped++;
                continue;
            }

            $lead_id = self::upsert_campaign_contact([
                'email'        => $email,
                'name'         => sanitize_text_field($data['name'] ?? $data['full_name'] ?? ''),
                'organisation' => sanitize_text_field($data['organisation'] ?? $data['organization'] ?? $data['company'] ?? ''),
                'campaign'     => sanitize_key($data['campaign'] ?? $default_campaign),
                'list_type'    => sanitize_key($data['list_type'] ?? $default_list_type),
                'segment'      => sanitize_text_field($data['segment'] ?? ''),
                'role'         => sanitize_text_field($data['role'] ?? $data['title'] ?? ''),
            ]);

            if ($lead_id) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        fclose($handle);
        wp_safe_redirect(admin_url('admin.php?page=wkel_campaigns&imported=' . $imported . '&skipped=' . $skipped));
        exit;
    }

    public static function maybe_render_unsubscribe_page(): void {
        $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        if (get_query_var('wkel_unsubscribe') !== '1' && $path !== 'unsubscribe') {
            return;
        }

        $email = sanitize_email($_GET['email'] ?? '');
        $token = sanitize_text_field($_GET['token'] ?? '');
        $message = '';
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wkel_unsubscribe')) {
                $message = __('We could not verify the request. Please try again.', 'wk-event-leads');
            } else {
                $email = sanitize_email($_POST['email'] ?? '');
                if (!$email) {
                    $message = __('Enter a valid email address to opt out.', 'wk-event-leads');
                } else {
                    self::suppress_email($email, 'manual_form');
                    $success = true;
                    $message = __('You have been opted out. We will not send further marketing emails to this address.', 'wk-event-leads');
                }
            }
        } elseif ($email && self::verify_unsubscribe_token($email, $token)) {
            self::suppress_email($email, 'signed_link');
            $success = true;
            $message = __('You have been opted out. We will not send further marketing emails to this address.', 'wk-event-leads');
        }

        status_header(200);
        nocache_headers();
        self::render_unsubscribe_html($email, $message, $success);
        exit;
    }

    public static function unsubscribe_url_for_email(string $email): string {
        $email = sanitize_email($email);
        if (!$email) {
            return self::unsubscribe_page_url();
        }

        return add_query_arg(
            [
                'email' => $email,
                'token' => self::unsubscribe_token($email),
            ],
            self::unsubscribe_page_url()
        );
    }

    public static function unsubscribe_page_url(): string {
        $public_url = get_option('wkel_public_unsubscribe_url', 'https://wkdigital.com.au/unsubscribe/');
        $public_url = esc_url_raw($public_url);

        return $public_url ?: home_url('/unsubscribe/');
    }

    public static function is_lead_suppressed(int $lead_id): bool {
        return get_post_meta($lead_id, '_wkel_marketing_status', true) === 'unsubscribed';
    }

    public static function is_email_suppressed(string $email): bool {
        $hash = self::email_hash($email);
        if (!$hash) {
            return false;
        }

        $existing = get_posts([
            'post_type'      => 'wkel_lead',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_wkel_email_hash', 'value' => $hash],
                ['key' => '_wkel_marketing_status', 'value' => 'unsubscribed'],
            ],
            'fields'         => 'ids',
        ]);

        return !empty($existing);
    }

    public static function suppress_email(string $email, string $source = 'manual'): int {
        $hash = self::email_hash($email);
        $lead_id = self::find_lead_by_email_hash($hash);

        if (!$lead_id) {
            $lead_id = self::upsert_campaign_contact([
                'email'        => $email,
                'name'         => '',
                'organisation' => 'Suppression list',
                'campaign'     => 'suppression',
                'list_type'    => 'suppression',
                'segment'      => '',
                'role'         => '',
            ]);
        }

        if ($lead_id) {
            update_post_meta($lead_id, '_wkel_marketing_status', 'unsubscribed');
            update_post_meta($lead_id, '_wkel_unsubscribed_at', time());
            update_post_meta($lead_id, '_wkel_unsubscribe_source', sanitize_key($source));
            update_post_meta($lead_id, '_wkel_email_status', 'unsubscribed');
            WKEL_Submission::log_activity($lead_id, 'unsubscribed', 'Contact opted out of marketing emails.');
        }

        return (int) $lead_id;
    }

    public static function export_suppression_csv(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Forbidden.', 'wk-event-leads'));
        }
        if (!check_admin_referer('wkel_export_suppression_csv')) {
            wp_die(__('Invalid nonce.', 'wk-event-leads'));
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wk-suppression-list-' . wp_date('Y-m-d-His') . '.csv"');

        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Email', 'Unsubscribed At', 'Source']);

        $posts = get_posts([
            'post_type'      => 'wkel_lead',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                ['key' => '_wkel_marketing_status', 'value' => 'unsubscribed'],
            ],
        ]);

        foreach ($posts as $post) {
            fputcsv($output, [
                self::get_lead_email($post->ID),
                self::format_timestamp((int) get_post_meta($post->ID, '_wkel_unsubscribed_at', true)),
                get_post_meta($post->ID, '_wkel_unsubscribe_source', true),
            ]);
        }

        fclose($output);
        exit;
    }

    private static function upsert_campaign_contact(array $contact): int {
        $email = sanitize_email($contact['email'] ?? '');
        $hash = self::email_hash($email);
        if (!$email || !$hash) {
            return 0;
        }

        $lead_id = self::find_lead_by_email_hash($hash);
        if (!$lead_id) {
            $title = ($contact['organisation'] ?: $contact['name'] ?: $email) . ' - ' . wp_date('Y-m-d');
            $lead_id = wp_insert_post([
                'post_type'   => 'wkel_lead',
                'post_status' => 'publish',
                'post_title'  => $title,
            ], true);

            if (is_wp_error($lead_id)) {
                return 0;
            }
        }

        $values = [
            'wkel_name'         => $contact['name'] ?: $email,
            'wkel_email'        => $email,
            'wkel_organisation' => $contact['organisation'] ?: 'Unknown',
        ];

        foreach (WKEL_Schema::get_fields() as $field) {
            $value = $values[$field['id']] ?? '';
            if ($value === '') {
                continue;
            }
            if (!empty($field['encrypted'])) {
                $value = WKEL_Encryption::encrypt($value);
            }
            update_post_meta($lead_id, '_wkel_' . $field['id'], $value);
        }

        $first_stage = WKEL_Schema::get_stage('contacted') ? 'contacted' : (WKEL_Schema::get_first_stage()['id'] ?? 'new');
        update_post_meta($lead_id, '_wkel_email_hash', $hash);
        update_post_meta($lead_id, '_wkel_event', sanitize_key($contact['campaign'] ?? 'agentic-cosec'));
        update_post_meta($lead_id, '_wkel_stage', get_post_meta($lead_id, '_wkel_stage', true) ?: $first_stage);
        update_post_meta($lead_id, '_wkel_source', 'cold_email');
        update_post_meta($lead_id, '_wkel_campaign', sanitize_key($contact['campaign'] ?? 'agentic-cosec'));
        update_post_meta($lead_id, '_wkel_list_type', sanitize_key($contact['list_type'] ?? ''));
        update_post_meta($lead_id, '_wkel_segment', sanitize_text_field($contact['segment'] ?? ''));
        update_post_meta($lead_id, '_wkel_role', sanitize_text_field($contact['role'] ?? ''));
        update_post_meta($lead_id, '_wkel_marketing_status', get_post_meta($lead_id, '_wkel_marketing_status', true) ?: 'subscribed');
        update_post_meta($lead_id, '_wkel_imported_at', get_post_meta($lead_id, '_wkel_imported_at', true) ?: time());
        update_post_meta($lead_id, '_wkel_email_status', get_post_meta($lead_id, '_wkel_email_status', true) ?: 'not_sent');
        update_post_meta($lead_id, '_wkel_submitted_at', get_post_meta($lead_id, '_wkel_submitted_at', true) ?: time());

        WKEL_Submission::log_activity($lead_id, 'campaign_imported', 'Contact added or updated for cold outreach campaign tracking.');

        return (int) $lead_id;
    }

    private static function render_unsubscribe_html(string $email, string $message, bool $success): void {
        $nonce = wp_create_nonce('wkel_unsubscribe');
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e('Unsubscribe - WK Digital', 'wk-event-leads'); ?></title>
            <?php wp_head(); ?>
        </head>
        <body <?php body_class('wkel-unsubscribe-page'); ?>>
            <main style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f7f8;padding:32px 16px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
                <section style="width:100%;max-width:560px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:32px;box-shadow:0 18px 45px rgba(7,21,26,.08);">
                    <p style="margin:0 0 10px;color:#1E6FBF;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;"><?php esc_html_e('WK Digital', 'wk-event-leads'); ?></p>
                    <h1 style="margin:0 0 16px;color:#07151a;font-size:30px;line-height:1.15;"><?php esc_html_e('Marketing opt-out', 'wk-event-leads'); ?></h1>
                    <p style="margin:0 0 22px;color:#4b5563;line-height:1.6;"><?php esc_html_e('Enter your email address and we will suppress it from future marketing campaigns.', 'wk-event-leads'); ?></p>

                    <?php if ($message): ?>
                        <div style="margin-bottom:20px;padding:12px 14px;border-radius:6px;background:<?php echo $success ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo $success ? '#166534' : '#991b1b'; ?>;">
                            <?php echo esc_html($message); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$success): ?>
                        <form method="post">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                            <label for="wkel_unsubscribe_email" style="display:block;margin-bottom:6px;color:#111827;font-weight:650;"><?php esc_html_e('Email address', 'wk-event-leads'); ?></label>
                            <input id="wkel_unsubscribe_email" name="email" type="email" value="<?php echo esc_attr($email); ?>" required style="width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:12px 14px;font-size:16px;margin-bottom:16px;">
                            <button type="submit" style="appearance:none;border:0;border-radius:6px;background:#1E6FBF;color:#fff;font-weight:700;padding:12px 18px;cursor:pointer;"><?php esc_html_e('Opt out', 'wk-event-leads'); ?></button>
                        </form>
                    <?php endif; ?>
                </section>
            </main>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }

    private static function row_to_assoc(array $headers, array $row): array {
        $data = [];
        foreach ($headers as $index => $header) {
            if ($header !== '') {
                $data[$header] = $row[$index] ?? '';
            }
        }
        return $data;
    }

    private static function normalise_header(string $header): string {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        return sanitize_key(str_replace([' ', '-'], '_', trim($header)));
    }

    private static function verify_unsubscribe_token(string $email, string $token): bool {
        return $token && hash_equals(self::unsubscribe_token($email), $token);
    }

    private static function unsubscribe_token(string $email): string {
        return hash_hmac('sha256', strtolower(trim($email)), wp_salt('auth'));
    }

    private static function email_hash(string $email): string {
        $email = sanitize_email($email);
        return $email ? hash('sha256', strtolower(trim($email))) : '';
    }

    private static function find_lead_by_email_hash(string $hash): int {
        if (!$hash) {
            return 0;
        }

        $existing = get_posts([
            'post_type'      => 'wkel_lead',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'meta_query'     => [
                ['key' => '_wkel_email_hash', 'value' => $hash],
            ],
            'fields'         => 'ids',
        ]);

        return !empty($existing) ? (int) $existing[0] : 0;
    }

    private static function get_lead_email(int $lead_id): string {
        foreach (WKEL_Schema::get_fields() as $field) {
            if ($field['type'] === 'email') {
                $raw = get_post_meta($lead_id, '_wkel_' . $field['id'], true);
                return WKEL_Encryption::decrypt((string) $raw);
            }
        }
        return '';
    }

    private static function format_timestamp(int $timestamp): string {
        return $timestamp > 0 ? wp_date('Y-m-d H:i:s', $timestamp) : '';
    }
}
