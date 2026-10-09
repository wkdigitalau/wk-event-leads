<?php
define('WKEL_QA_HTTP_GUARD', true);
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', function ($pre, $args, $url) {
    $GLOBALS['wkel_http_calls'][] = ['url' => $url, 'method' => $args['method'] ?? 'GET'];
    if ($url === 'https://api.resend.com/emails' && isset($GLOBALS['wkel_mock'])) {
        $mode = $GLOBALS['wkel_mock'];
        if ($mode === 'timeout') return new WP_Error('qa_timeout', 'Dummy transport timeout');
        if ($mode === 'rejected') return ['response' => ['code' => 422], 'body' => '{"message":"Dummy rejected, never log identity or keys"}', 'headers' => []];
        return ['response' => ['code' => 200], 'body' => $mode === 'malformed' ? '{}' : wp_json_encode(['id' => 'qa-' . wp_generate_uuid4()]), 'headers' => []];
    }
    if (str_starts_with($url, 'https://api.resend.com/emails/receiving/') && isset($GLOBALS['wkel_inbound_email'])) return ['response' => ['code' => 200], 'body' => wp_json_encode(['from' => $GLOBALS['wkel_inbound_email'], 'subject' => 'Dummy reply', 'text' => 'Dummy follow-up text', 'message_id' => 'qa-reply-message']), 'headers' => []];
    return new WP_Error('qa_network_blocked', 'All external HTTP is blocked in local QA.');
}, PHP_INT_MAX, 3);
