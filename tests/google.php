<?php
$site = getenv('WKEL_QA_SITE') ?: 'connect';
$mode = $argv[2] ?? 'configured';
if ($mode !== 'missing') {
    define('WKEL_GA4_PROPERTY_ID', $mode === 'invalid' ? 'invalid' : ($mode === 'blank' ? '' : ['connect' => '101', 'cofo' => '202', 'wkdigital' => '303'][$site]));
    define('WKEL_SEARCH_CONSOLE_SITE_URL', $mode === 'mismatch' ? 'https://other-site.invalid/' : 'sc-domain:' . $site . '.wkel.test');
    $key = openssl_pkey_new(['private_key_bits' => 2048]); openssl_pkey_export($key, $private);
    // Generated dummy key exists only in memory and has no access to any Google account.
    define('WKEL_GOOGLE_SERVICE_ACCOUNT_JSON', wp_dummy_json($private, $site));
}
function wp_dummy_json(string $key, string $site): string { return json_encode(['type' => 'service_account', 'client_email' => 'dummy-' . $site . '@example.invalid', 'private_key' => $key, 'private_key_id' => 'dummy-' . $site]); }
require $argv[1] . '/wp-load.php';
if (!str_starts_with(DB_NAME, 'wkel_qa_') || !defined('WKEL_QA_HTTP_GUARD')) throw new RuntimeException('QA guard required');
$calls = [];
add_filter('pre_http_request', function ($pre, $args, $url) use (&$calls, $mode) {
    $calls[] = ['url' => $url, 'body' => $args['body']];
    if ($url === 'https://oauth2.googleapis.com/token') return ['response' => ['code' => 200], 'body' => '{"access_token":"dummy-token","expires_in":3600}', 'headers' => []];
    if ($mode === 'forbidden') return ['response' => ['code' => 403], 'body' => '{"error":{"message":"dummy-secret-do-not-expose"}}', 'headers' => []];
    if (str_contains($url, 'analyticsdata.googleapis.com')) return ['response' => ['code' => 200], 'body' => '{"rows":[{"metricValues":[{"value":"7"},{"value":"8"},{"value":"9"}],"dimensionValues":[{"value":"qa-campaign"},{"value":"qa-source"}]}]}', 'headers' => []];
    return ['response' => ['code' => 200], 'body' => '{"rows":[{"clicks":4,"impressions":20,"ctr":0.2,"position":2,"keys":["https://dummy.invalid/page"]}]}', 'headers' => []];
}, PHP_INT_MAX, 3);
$method = new ReflectionMethod(WKEL_Insights::class, 'google_metrics'); $method->setAccessible(true);
$result = $method->invoke(null, 30);
if (in_array($mode, ['missing', 'blank'], true)) {
    if ($result !== ['configured' => false] || $calls) throw new RuntimeException('Missing config must make zero calls');
} elseif (in_array($mode, ['invalid', 'mismatch'], true)) {
    if (!is_wp_error($result) || $calls) throw new RuntimeException('Invalid site config must fail without calls');
} elseif ($mode === 'forbidden') {
    if (!is_wp_error($result) || str_contains($result->get_error_message(), 'dummy-secret')) throw new RuntimeException('Provider error must be redacted');
} else {
    if (empty($result['configured']) || $result['ga4']['sessions'] !== '8' || $result['search']['clicks'] !== 4) throw new RuntimeException('Aggregate report mismatch');
    if (count($calls) !== 5) throw new RuntimeException('Expected token and four reports');
    $claims = explode('.', $calls[0]['body']['assertion'])[1];
    $claims = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
    if ($claims['scope'] !== 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly') throw new RuntimeException('Scopes must be read-only');
    $requests = json_encode(array_slice($calls, 1));
    foreach (['example.invalid', 'full_name', 'wkel_email', 'transcript', 'phone', 'Dummy Recipient'] as $private) if (str_contains($requests, $private)) throw new RuntimeException('Private data in Google reports');
    foreach (array_slice($calls, 1, 2) as $call) if (!str_contains($call['url'], '/properties/' . WKEL_GA4_PROPERTY_ID . ':runReport')) throw new RuntimeException('GA4 property mismatch');
    foreach (array_slice($calls, 3) as $call) {
        if (!str_contains($call['url'], rawurlencode(WKEL_SEARCH_CONSOLE_SITE_URL))) throw new RuntimeException('Search property mismatch');
        $query = json_decode($call['body'], true);
        if (array_diff(array_keys($query), ['startDate', 'endDate', 'type', 'dimensions', 'rowLimit'])) throw new RuntimeException('Unsupported Search Console request field');
    }
}
echo "PASS: Google $site/$mode; requests mocked, identifiers local, credentials hidden.\n";
