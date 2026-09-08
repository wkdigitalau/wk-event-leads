<?php
defined('ABSPATH') || exit;

/**
 * Commercial and service insights inside WordPress.
 *
 * Lead identity and lifecycle remain in WordPress. Google receives and returns
 * aggregate acquisition data only; no lead PII is sent by this class.
 */
class WKEL_Insights {

    public static function register_menu(): void {
        add_submenu_page(
            'wkel_leads',
            __('Insights', 'wk-event-leads'),
            __('Insights', 'wk-event-leads'),
            'manage_options',
            'wkel_insights',
            [self::class, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to view this page.', 'wk-event-leads'));
        }

        $days = absint($_GET['wkel_days'] ?? 30);
        if (!in_array($days, [30, 90], true)) {
            $days = 30;
        }

        $metrics = self::lead_metrics($days);
        $google  = self::google_metrics($days);
        ?>
        <div class="wrap wkel-insights">
            <style>
                .wkel-insights{max-width:1240px}.wkel-insights__head{display:flex;align-items:end;justify-content:space-between;gap:24px;margin:18px 0 20px}.wkel-insights__head h1{margin:0 0 5px}.wkel-insights__head p{margin:0;color:#5d6b78}.wkel-insights__tabs a{display:inline-block;padding:7px 12px;border:1px solid #c9d4dd;border-radius:7px;background:#fff;text-decoration:none;font-weight:650}.wkel-insights__tabs a.is-active{border-color:#2271b1;background:#2271b1;color:#fff}.wkel-insights__grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.wkel-insights__card{padding:19px;border:1px solid #d7e0e7;border-radius:10px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.03)}.wkel-insights__label{display:block;margin-bottom:7px;color:#637382;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.wkel-insights__value{display:block;color:#08233f;font-size:30px;line-height:1;font-weight:800}.wkel-insights__hint{display:block;margin-top:7px;color:#71808d;font-size:12px}.wkel-insights__section{margin-top:20px;padding:22px;border:1px solid #d7e0e7;border-radius:11px;background:#fff}.wkel-insights__section h2{margin:0 0 5px}.wkel-insights__section>p{margin:0 0 18px;color:#637382}.wkel-insights__split{display:grid;grid-template-columns:1fr 1fr;gap:18px}.wkel-insights table{width:100%;border-collapse:collapse}.wkel-insights th,.wkel-insights td{padding:10px 8px;border-bottom:1px solid #e6ebef;text-align:left}.wkel-insights th{color:#637382;font-size:11px;text-transform:uppercase}.wkel-insights code{word-break:break-all}.wkel-insights__notice{padding:14px 16px;border-left:4px solid #dba617;background:#fff8db}.wkel-insights__notice.is-error{border-color:#d63638;background:#fcf0f1}@media(max-width:900px){.wkel-insights__grid{grid-template-columns:1fr 1fr}.wkel-insights__split{grid-template-columns:1fr}}@media(max-width:560px){.wkel-insights__head{display:block}.wkel-insights__tabs{margin-top:14px}.wkel-insights__grid{grid-template-columns:1fr}}
            </style>

            <header class="wkel-insights__head">
                <div><h1><?php esc_html_e('Sales & Service Insights', 'wk-event-leads'); ?></h1><p><?php echo esc_html(sprintf(__('One view of lead outcomes, support demand and aggregate Google acquisition data for the last %d days.', 'wk-event-leads'), $days)); ?></p></div>
                <nav class="wkel-insights__tabs" aria-label="Insight range">
                    <a class="<?php echo $days === 30 ? 'is-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=wkel_insights&wkel_days=30')); ?>">30 days</a>
                    <a class="<?php echo $days === 90 ? 'is-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=wkel_insights&wkel_days=90')); ?>">90 days</a>
                </nav>
            </header>

            <section class="wkel-insights__grid" aria-label="Lead metrics">
                <?php self::metric_card('New records', $metrics['total'], 'All captured sales, service and partner records'); ?>
                <?php self::metric_card('Sales enquiries', $metrics['sales'], 'Lead type: sales'); ?>
                <?php self::metric_card('Qualified', $metrics['qualified'], self::rate($metrics['qualified'], $metrics['sales']) . ' of sales enquiries'); ?>
                <?php self::metric_card('Closed won', $metrics['won'], self::rate($metrics['won'], $metrics['sales']) . ' of sales enquiries'); ?>
                <?php self::metric_card('Bookings', $metrics['bookings'], 'Active Cal.com booking records'); ?>
                <?php self::metric_card('Voice calls', $metrics['calls'], 'Kristine-sourced records'); ?>
                <?php self::metric_card('Support requests', $metrics['support'], 'Support and client-request types'); ?>
                <?php self::metric_card('Unreviewed', $metrics['unreviewed'], 'Needs a staff decision or review'); ?>
            </section>

            <section class="wkel-insights__section">
                <h2><?php esc_html_e('Acquisition and onsite behaviour', 'wk-event-leads'); ?></h2>
                <p><?php esc_html_e('Aggregate GA4 and Search Console data. No names, emails, phone numbers or transcripts are sent to Google.', 'wk-event-leads'); ?></p>
                <?php if (is_wp_error($google)) : ?>
                    <div class="wkel-insights__notice is-error"><strong>Google data unavailable.</strong> <?php echo esc_html($google->get_error_message()); ?></div>
                <?php elseif (empty($google['configured'])) : ?>
                    <div class="wkel-insights__notice"><strong>Google connection not configured.</strong> Add the constants shown below to <code>wp-config.php</code>, grant the service account read access in GA4 and Search Console, then reload this page.</div>
                <?php else : ?>
                    <div class="wkel-insights__grid">
                        <?php self::metric_card('GA4 users', $google['ga4']['active_users'] ?? '—', 'Aggregate active users'); ?>
                        <?php self::metric_card('Sessions', $google['ga4']['sessions'] ?? '—', 'GA4 sessions'); ?>
                        <?php self::metric_card('Key events', $google['ga4']['key_events'] ?? '—', 'Events marked as key events in GA4'); ?>
                        <?php self::metric_card('Search clicks', $google['search']['clicks'] ?? '—', 'Google Search Console'); ?>
                        <?php self::metric_card('Search impressions', $google['search']['impressions'] ?? '—', 'Google Search Console'); ?>
                        <?php self::metric_card('Search CTR', isset($google['search']['ctr']) ? round($google['search']['ctr'] * 100, 1) . '%' : '—', 'Google Search Console'); ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="wkel-insights__split">
                <section class="wkel-insights__section"><h2><?php esc_html_e('Lead sources', 'wk-event-leads'); ?></h2><p><?php esc_html_e('Records by stored source.', 'wk-event-leads'); ?></p><?php self::simple_table($metrics['sources'], 'Source'); ?></section>
                <section class="wkel-insights__section"><h2><?php esc_html_e('Campaign / event', 'wk-event-leads'); ?></h2><p><?php esc_html_e('Records by event or campaign key.', 'wk-event-leads'); ?></p><?php self::simple_table($metrics['events'], 'Event'); ?></section>
            </div>

            <section class="wkel-insights__section">
                <h2><?php esc_html_e('Google connection', 'wk-event-leads'); ?></h2>
                <p><?php esc_html_e('Secrets stay outside the plugin repository. The service account only needs read access.', 'wk-event-leads'); ?></p>
                <table><tbody>
                    <tr><th>GA4 property</th><td><code>define('WKEL_GA4_PROPERTY_ID', '356866720');</code></td></tr>
                    <tr><th>Search Console property</th><td><code>define('WKEL_SEARCH_CONSOLE_SITE_URL', 'https://wkdigital.com.au/');</code></td></tr>
                    <tr><th>Service account</th><td><code>define('WKEL_GOOGLE_SERVICE_ACCOUNT_JSON', '/private/path/google-readonly.json');</code></td></tr>
                </tbody></table>
            </section>
        </div>
        <?php
    }

    private static function metric_card(string $label, int|string $value, string $hint): void {
        echo '<article class="wkel-insights__card"><span class="wkel-insights__label">' . esc_html($label) . '</span><strong class="wkel-insights__value">' . esc_html((string) $value) . '</strong><span class="wkel-insights__hint">' . esc_html($hint) . '</span></article>';
    }

    private static function rate(int $part, int $whole): string {
        return $whole > 0 ? number_format_i18n(($part / $whole) * 100, 1) . '%' : '—';
    }

    private static function simple_table(array $values, string $heading): void {
        arsort($values);
        echo '<table><thead><tr><th>' . esc_html($heading) . '</th><th>Records</th></tr></thead><tbody>';
        foreach (array_slice($values, 0, 10, true) as $key => $count) {
            echo '<tr><td>' . esc_html($key ?: 'Not set') . '</td><td>' . esc_html((string) $count) . '</td></tr>';
        }
        if (!$values) {
            echo '<tr><td colspan="2">No records in this period.</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function lead_metrics(int $days): array {
        $ids = get_posts([
            'post_type'      => 'wkel_lead',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'date_query'     => [['after' => $days . ' days ago', 'inclusive' => true]],
        ]);

        $out = ['total' => count($ids), 'sales' => 0, 'qualified' => 0, 'won' => 0, 'bookings' => 0, 'calls' => 0, 'support' => 0, 'unreviewed' => 0, 'sources' => [], 'events' => []];
        foreach ($ids as $id) {
            $type     = (string) get_post_meta($id, '_wkel_lead_type', true) ?: 'sales';
            $stage    = (string) get_post_meta($id, '_wkel_stage', true) ?: 'new';
            $source   = (string) get_post_meta($id, '_wkel_source', true) ?: 'direct';
            $event    = (string) get_post_meta($id, '_wkel_event', true) ?: 'not_set';
            $reviewed = (string) get_post_meta($id, '_wkel_reviewed', true);
            $cal      = (string) get_post_meta($id, '_wkel_cal_status', true);

            $out['sources'][$source] = ($out['sources'][$source] ?? 0) + 1;
            $out['events'][$event]   = ($out['events'][$event] ?? 0) + 1;
            if ($type === 'sales') $out['sales']++;
            if (in_array($type, ['support', 'client_request'], true)) $out['support']++;
            if (in_array($stage, ['qualified', 'proposal', 'closed_won'], true)) $out['qualified']++;
            if ($stage === 'closed_won') $out['won']++;
            if (in_array($cal, ['created', 'rescheduled'], true)) $out['bookings']++;
            if ($source === 'kristine' || str_contains($event, 'kristine')) $out['calls']++;
            if ($reviewed !== '1' && in_array($stage, ['new', 'needs_review'], true)) $out['unreviewed']++;
        }
        return $out;
    }

    private static function google_metrics(int $days): array|WP_Error {
        if (!defined('WKEL_GA4_PROPERTY_ID') || !defined('WKEL_SEARCH_CONSOLE_SITE_URL') || !defined('WKEL_GOOGLE_SERVICE_ACCOUNT_JSON')) {
            return ['configured' => false];
        }

        $token = self::google_token();
        if (is_wp_error($token)) return $token;

        $start = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $end   = gmdate('Y-m-d');
        $ga4   = self::google_post(
            'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode((string) WKEL_GA4_PROPERTY_ID) . ':runReport',
            $token,
            ['dateRanges' => [['startDate' => $start, 'endDate' => $end]], 'metrics' => [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'keyEvents']]]
        );
        if (is_wp_error($ga4)) return $ga4;

        $search = self::google_post(
            'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode((string) WKEL_SEARCH_CONSOLE_SITE_URL) . '/searchAnalytics/query',
            $token,
            ['startDate' => $start, 'endDate' => $end, 'type' => 'web', 'rowLimit' => 1]
        );
        if (is_wp_error($search)) return $search;

        $ga_values = $ga4['rows'][0]['metricValues'] ?? [];
        $sc_values = $search['rows'][0] ?? [];
        return [
            'configured' => true,
            'ga4' => [
                'active_users' => $ga_values[0]['value'] ?? '0',
                'sessions'     => $ga_values[1]['value'] ?? '0',
                'key_events'   => $ga_values[2]['value'] ?? '0',
            ],
            'search' => [
                'clicks'      => $sc_values['clicks'] ?? 0,
                'impressions' => $sc_values['impressions'] ?? 0,
                'ctr'         => $sc_values['ctr'] ?? 0,
            ],
        ];
    }

    private static function google_token(): string|WP_Error {
        $source = (string) WKEL_GOOGLE_SERVICE_ACCOUNT_JSON;
		$is_json = str_starts_with(ltrim($source), '{');
		$raw     = !$is_json && is_file($source) && is_readable($source) ? file_get_contents($source) : $source;
        $key    = json_decode((string) $raw, true);
        if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            return new WP_Error('wkel_google_credentials', 'The Google service-account JSON is missing or invalid.');
        }

        $cache_key = 'wkel_google_token_' . substr(hash('sha256', (string) $key['client_email']), 0, 16);
        $cached    = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') return $cached;

        $now    = time();
        $header = self::base64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64url(wp_json_encode([
            'iss' => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $input = $header . '.' . $claims;
        $signature = '';
		if (!function_exists('openssl_sign')) {
			return new WP_Error('wkel_google_openssl', 'The PHP OpenSSL extension is required for Google service-account authentication.');
		}
        if (!openssl_sign($input, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            return new WP_Error('wkel_google_signing', 'Could not sign the Google service-account request.');
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $input . '.' . self::base64url($signature)],
        ]);
        if (is_wp_error($response)) return $response;
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) >= 300 || empty($body['access_token'])) {
            return new WP_Error('wkel_google_token', sanitize_text_field($body['error_description'] ?? 'Google authentication failed.'));
        }
        set_transient($cache_key, $body['access_token'], max(60, absint($body['expires_in'] ?? 3600) - 120));
        return $body['access_token'];
    }

    private static function google_post(string $url, string $token, array $body): array|WP_Error {
        $response = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) return $response;
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) >= 300) {
            return new WP_Error('wkel_google_api', sanitize_text_field($decoded['error']['message'] ?? 'Google reporting request failed.'));
        }
        return is_array($decoded) ? $decoded : [];
    }

    private static function base64url(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

