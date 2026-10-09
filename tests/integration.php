<?php
/** Run only against the isolated QA databases, with the HTTP guard installed. */
require $argv[1] . '/wp-load.php';
if (!str_starts_with(DB_NAME, 'wkel_qa_') || !defined('WKEL_QA_HTTP_GUARD')) throw new RuntimeException('Isolated QA guard required.');
require_once ABSPATH . 'wp-admin/includes/template.php';
$_SERVER['HTTP_HOST'] = wp_parse_url(home_url(), PHP_URL_HOST);
wp_set_current_user(1);
$GLOBALS['wkel_mock'] = 'accepted';
$GLOBALS['wkel_http_calls'] = [];
$GLOBALS['wkel_scheduled'] = [];
if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action(...$args) { $GLOBALS['wkel_scheduled'][] = $args; return 1; }
}
if (!function_exists('as_unschedule_all_actions')) {
    function as_unschedule_all_actions($hook, $args = [], $group = '') {
        $GLOBALS['wkel_scheduled'] = array_values(array_filter($GLOBALS['wkel_scheduled'], fn($job) => !($job[1] === $hook && $job[2] === $args && $job[3] === $group)));
    }
}
$count = 0;
function check(bool $ok, string $name): void { global $count; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $count++; echo "PASS: $name\n"; }
function lead(string $suffix): int {
    $id = WKEL_Submission::create_lead(['wkel_name' => 'Dummy <Recipient> ' . $suffix, 'wkel_email' => 'dummy-' . $suffix . '@example.invalid', 'wkel_organisation' => 'Dummy & Co'], 'qa', '', 'outreach', 'other');
    if (is_wp_error($id)) throw new RuntimeException('Create failed');
    update_post_meta($id, '_wkel_outreach_enrolled', '1');
    return $id;
}
function preview(int $lead, int $template): string {
    $result = WKEL_Outreach::preview($lead, $template);
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
    return $result['token'];
}
function message(int $lead): array { return WKEL_Outreach_Store::messages($lead)[0] ?? []; }
function webhook(string $type, string $resend, string $event, int $at = 0): WP_REST_Response {
    $body = wp_json_encode(['type' => $type, 'created_at' => gmdate('c', $at ?: time()), 'data' => ['email_id' => $resend]]);
    $stamp = (string) time();
    $secret = base64_decode(substr(WKEL_Encryption::decrypt(get_option('wkel_resend_webhook_secret')), 6));
    $signature = base64_encode(hash_hmac('sha256', $event . '.' . $stamp . '.' . $body, $secret, true));
    $request = new WP_REST_Request('POST'); $request->set_body($body);
    $request->set_header('svix-id', $event); $request->set_header('svix-timestamp', $stamp); $request->set_header('svix-signature', 'v1,' . $signature);
    return WKEL_Email::handle_webhook($request);
}
$wpdb->suppress_errors(true);
check(get_option('wkel_outreach_migrated') === '1', 'schema and migration complete');
check(get_option('wkel_outreach_enabled') === '0', 'Outreach disabled by default');
check(get_option('wkel_outreach_dry_run') === '1', 'dry-run default');
$request = new WP_REST_Request('POST'); $request->set_header('Content-Type', 'application/json');
$request->set_body(wp_json_encode(['wkel_name' => 'Dummy Form', 'wkel_email' => 'dummy-form@example.invalid', 'wkel_organisation' => 'Dummy', 'source' => 'admin', 'event' => 'qa-form']));
check(WKEL_Submission::handle($request)->get_data()['success'] === true && count($GLOBALS['wkel_http_calls']) === 0 && count($GLOBALS['wkel_scheduled']) === 0, 'form/admin submission saves without sending');
update_option('wkel_email_from_address', 'sender@example.invalid');
update_option('wkel_email_from_name', 'Dummy Sender');
update_option('wkel_resend_key', WKEL_Encryption::encrypt('dummy-key-not-valid'));
update_option('wkel_resend_webhook_secret', WKEL_Encryption::encrypt('whsec_' . base64_encode('dummy-webhook-secret')));
$id = lead('initial');
check(get_post_meta($id, '_wkel_email_status', true) === 'draft', 'lead creation is draft');
check(get_post_meta($id, '_wkel_privacy_accepted', true) === '0' && get_post_meta($id, '_wkel_marketing_status', true) === 'unknown', 'manual staging does not assert privacy acceptance or subscription');
check(count($GLOBALS['wkel_http_calls']) === 0 && count($GLOBALS['wkel_scheduled']) === 0, 'creation has no HTTP delivery or scheduled job');
WKEL_Email::send_confirmation($id);
check(count($GLOBALS['wkel_http_calls']) === 0, 'legacy callback cannot deliver');
check(is_string(WKEL_Email::send_test('dummy@example.invalid')) && count($GLOBALS['wkel_http_calls']) === 0, 'legacy test-send disabled');
$request = new WP_REST_Request('POST'); $request['id'] = $id;
check(WKEL_Submission::resend_email($request)->get_status() === 409, 'legacy REST resend blocked');
$t = WKEL_Outreach::save_template(0, 'QA template', 'Hello {{first_name}}', '<p>Hello {{full_name}}, {{organisation}}</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>');
check(is_int($t), 'template saved');
check(is_wp_error(WKEL_Outreach::preview($id, $t)), 'unapproved template cannot preview/send');
check(WKEL_Outreach::approve($t) === true, 'template explicitly approved');
$payload = WKEL_Outreach::payload($id, $t);
check($payload['subject'] === 'Hello Dummy' && str_contains($payload['html'], '&lt;Recipient&gt;') && str_contains($payload['html'], 'Dummy &amp; Co'), 'subject merging and HTML escaping');
check(str_contains($payload['html'], home_url('/unsubscribe/')), 'unsubscribe points to local installation');
check(!str_contains($payload['html'], 'example.invalid') && !str_contains($payload['html'], '?email='), 'unsubscribe link contains no recipient email');
check(count($GLOBALS['wkel_http_calls']) === 0, 'preview never calls delivery');
$token = preview($id, $t);
check(is_wp_error(WKEL_Outreach::send($token)), 'disabled installation cannot send');
update_option('wkel_outreach_enabled', '1');
$token = preview($id, $t);
$result = WKEL_Outreach::send($token);
check(is_string($result) && str_contains($result, 'Dry run'), 'explicit Send dry-run completes');
check(count($GLOBALS['wkel_http_calls']) === 0 && message($id)['status'] === 'draft', 'dry-run remains unsent and no delivery request');
check(is_wp_error(WKEL_Outreach::send($token)), 'same authorisation cannot replay');
$token = preview($id, $t); update_post_meta($id, '_wkel_wkel_name', WKEL_Encryption::encrypt('Edited Dummy'));
check(is_wp_error(WKEL_Outreach::send($token)), 'lead edit invalidates preview');
$token = preview($id, $t); update_option('wkel_email_from_name', 'Changed Sender');
check(is_wp_error(WKEL_Outreach::send($token)), 'sender edit invalidates preview');
$token = preview($id, $t); wp_set_current_user(0);
check(is_wp_error(WKEL_Outreach::send($token)), 'unauthorised user blocked'); wp_set_current_user(1);
$token = preview($id, $t); wp_set_current_user(2);
check(is_wp_error(WKEL_Outreach::send($token)), 'preview cannot be used by another user'); wp_set_current_user(1);
$bad = WKEL_Outreach::save_template(0, 'Unknown', 'Hello {{unknown}}', '<a href="{{unsubscribe_url}}">Opt out</a>');
check(is_wp_error(WKEL_Outreach::approve($bad)), 'unknown merge fields block approval');
$bad = WKEL_Outreach::save_template(0, 'No unsubscribe', 'Hello', '<p>Hello</p>');
check(is_wp_error(WKEL_Outreach::approve($bad)), 'unsubscribe required for approval');
check(is_wp_error(WKEL_Outreach::save_template(0, 'PII tracking', 'Hello', '<a href="https://example.invalid/?name={{full_name}}">Link</a>')), 'PII cannot enter link parameters');
$token = preview($id, $t); WKEL_Outreach::save_template($t, 'Edited', 'Hello', '<p>Hi</p><a href="{{unsubscribe_url}}">Opt out</a>');
check(get_post_meta($t, '_wkel_approved', true) === '0' && is_wp_error(WKEL_Outreach::send($token)), 'template edit resets approval and invalidates preview');
WKEL_Outreach::approve($t);
$empty_t = WKEL_Outreach::save_template(0, 'Missing merge', 'Hello {{sender_phone}}', '<a href="{{unsubscribe_url}}">Opt out</a>'); WKEL_Outreach::approve($empty_t);
check(is_wp_error(WKEL_Outreach::preview($id, $empty_t)), 'missing merge values block preview');
$token = preview($id, $t); set_transient('wkel_preview_' . $token, false, 1);
check(is_wp_error(WKEL_Outreach::send($token)), 'expired preview blocked');
// Suppression is tested after preview and against duplicate/trashed records.
foreach (['failed', 'bounced', 'suppressed', 'unsubscribed'] as $state) {
    $l = lead($state); $token = preview($l, $t); update_post_meta($l, '_wkel_email_status', $state);
    check(is_wp_error(WKEL_Outreach::send($token)), 'pre-send gate blocks ' . $state);
}
$l = lead('optout'); $token = preview($l, $t); WKEL_Campaign::suppress_email(WKEL_Email::get_lead_email($l), 'qa');
check(is_wp_error(WKEL_Outreach::send($token)), 'unsubscribe after preview blocks Send');
$email = WKEL_Email::get_lead_email($l); wp_delete_post($l, true);
WKEL_Outreach_Store::suppress($email, 'failed', 'qa-late-failure');
check(WKEL_Outreach_Store::blocked(0, $email) === 'unsubscribed', 'suppression survives deletion and late failures');
$l = lead('duplicate'); $dup = lead('duplicate'); update_post_meta($dup, '_wkel_email_status', 'bounced'); wp_trash_post($dup);
check(is_wp_error(WKEL_Outreach::send(preview($l, $t))), 'bounced duplicate in trash blocks send');
$import = new ReflectionMethod(WKEL_Campaign::class, 'upsert_campaign_contact'); $import->setAccessible(true);
$l = $import->invoke(null, ['email' => 'csv@example.invalid', 'name' => 'CSV Dummy', 'organisation' => 'Dummy', 'campaign' => 'qa', 'list_type' => 'mixed', 'segment' => 'qa', 'role' => 'Dummy']);
check(get_post_meta($l, '_wkel_outreach_status', true) === 'draft' && get_post_meta($l, '_wkel_stage', true) !== 'contacted', 'campaign import stages unsent lead');
WKEL_Campaign::suppress_email('csv@example.invalid', 'qa');
$import->invoke(null, ['email' => 'csv@example.invalid', 'name' => 'Changed', 'organisation' => 'Dummy', 'campaign' => 'qa', 'list_type' => 'mixed']);
check(WKEL_Outreach_Store::blocked($l, 'csv@example.invalid') === 'unsubscribed', 'reimport cannot clear suppression');
check(count($GLOBALS['wkel_http_calls']) === 0, 'all save/import/preview/suppression cases make zero delivery requests');
// Simulated live branch: the MU guard intercepts every HTTP call; no network is used.
update_option('wkel_outreach_dry_run', '0');
$l = lead('accepted'); $token = preview($l, $t); $result = WKEL_Outreach::send($token);
check(is_string($result) && message($l)['status'] === 'sent' && count($GLOBALS['wkel_http_calls']) === 1, 'mocked Resend acceptance and sent status');
$row = message($l); $resend = $row['resend_id'];
check($resend !== '' && (int) $row['sent_at'] > 0, 'message ID and sent timestamp persisted');
$stored = $wpdb->get_var($wpdb->prepare('SELECT payload FROM ' . WKEL_Outreach_Store::table('messages') . ' WHERE id = %d', $row['id']));
check(WKEL_Encryption::is_encrypted($stored) && !str_contains($stored, 'example.invalid'), 'rendered snapshot encrypted');
check(is_wp_error(WKEL_Outreach::send($token)) && count($GLOBALS['wkel_http_calls']) === 1, 'repeat click cannot duplicate delivery');
check(webhook('email.delivered', $resend, 'qa-delivery')->get_status() === 200 && message($l)['delivery'] === 'delivered', 'signed delivery outcome');
check(webhook('email.delivery_delayed', $resend, 'qa-old-delay', time() - 100)->get_status() === 200 && message($l)['delivery'] === 'delivered', 'older webhook cannot regress delivery');
webhook('email.opened', $resend, 'qa-open'); webhook('email.clicked', $resend, 'qa-click');
check(message($l)['delivery'] === 'delivered', 'engagement activity preserves delivery status');
check(webhook('email.bounced', $resend, 'qa-bounce')->get_status() === 200 && message($l)['status'] === 'bounced', 'bounce outcome remains distinct');
check(webhook('email.bounced', $resend, 'qa-bounce')->get_data()['duplicate'] === true, 'duplicate webhook deduplicated');
webhook('email.delivered', $resend, 'qa-later-delivery', time() + 10);
check(message($l)['status'] === 'bounced' && WKEL_Outreach_Store::blocked($l, WKEL_Email::get_lead_email($l)) === 'bounced', 'delivery cannot clear bounce suppression');
webhook('email.failed', $resend, 'qa-late-failed', time() + 20);
check(message($l)['status'] === 'bounced', 'late failure cannot downgrade bounced status');
check(is_wp_error(WKEL_Outreach::send(preview($l, $t))), 'bounce blocks subsequent send');
$bad = new WP_REST_Request('POST'); $bad->set_body('{}');
check(WKEL_Email::handle_webhook($bad)->get_status() === 401, 'unsigned webhook rejected');
foreach (['rejected', 'timeout', 'malformed'] as $mode) {
    $GLOBALS['wkel_mock'] = $mode; $l = lead($mode); $result = WKEL_Outreach::send(preview($l, $t));
    check(is_wp_error($result) && message($l)['status'] === 'failed', 'mocked ' . $mode . ' failure recorded');
    check(is_wp_error(WKEL_Outreach::send(preview($l, $t))), 'failed recipient blocks another send: ' . $mode);
}
check(count($GLOBALS['wkel_scheduled']) === 0, 'no automatic retries or jobs');
$GLOBALS['wkel_mock'] = 'accepted';
$l = lead('locked'); $token = preview($l, $t); add_option('wkel_send_lock_' . $l, time(), '', false);
check(is_wp_error(WKEL_Outreach::send($token)), 'concurrent send lock blocks delivery'); delete_option('wkel_send_lock_' . $l);
update_option('wkel_resend_key', '');
check(is_wp_error(WKEL_Outreach::send(preview($l, $t))), 'missing Resend configuration logged and blocked');
update_option('wkel_resend_key', WKEL_Encryption::encrypt('dummy-key-not-valid'));
// Legacy migration preserves history, replies, notes and suppression and is repeatable.
$l = lead('legacy'); update_post_meta($l, '_wkel_email_status', 'delivered'); update_post_meta($l, '_wkel_resend_email_id', 'legacy-qa-id'); update_post_meta($l, '_wkel_email_sent_at', time() - 1000); delete_post_meta($l, '_wkel_outreach_status');
WKEL_Submission::log_activity($l, 'note_added', 'Dummy follow-up note');
$before = get_post_meta($l, '_wkel_activity_log', true);
$queued_legacy = lead('legacy-queued'); update_post_meta($queued_legacy, '_wkel_email_status', 'queued'); delete_post_meta($queued_legacy, '_wkel_outreach_status');
as_schedule_single_action(time() + 60, 'wkel_send_confirmation_email', ['lead_id' => $queued_legacy], 'wk-event-leads');
$optout_legacy = lead('legacy-optout'); update_post_meta($optout_legacy, '_wkel_marketing_status', 'unsubscribed'); update_post_meta($optout_legacy, '_wkel_email_status', 'sent'); delete_post_meta($optout_legacy, '_wkel_outreach_status');
delete_option('wkel_outreach_migrated'); update_option('wkel_outreach_migration_last_id', 0); WKEL_Outreach::migrate();
check(get_post_meta($queued_legacy, '_wkel_outreach_status', true) === 'draft' && count($GLOBALS['wkel_scheduled']) === 0, 'legacy queued jobs cancelled with exact lead arguments');
check(message($l)['resend_id'] === 'legacy-qa-id' && message($l)['delivery'] === 'delivered', 'legacy ID and delivery history migrated');
check(get_post_meta($l, '_wkel_activity_log', true) === $before, 'migration preserves pipeline activity');
check(get_post_meta($optout_legacy, '_wkel_outreach_status', true) === 'suppressed', 'migration prioritises unsubscribe over historical sent status');
$rows_before = $wpdb->get_var('SELECT COUNT(*) FROM ' . WKEL_Outreach_Store::table('messages')); WKEL_Outreach::migrate();
check($rows_before === $wpdb->get_var('SELECT COUNT(*) FROM ' . WKEL_Outreach_Store::table('messages')), 'migration is idempotent');
// A reply uses the existing lead and activity timeline, without creating a second identity.
$GLOBALS['wkel_inbound_email'] = WKEL_Email::get_lead_email($id);
check(webhook('email.received', 'qa-inbound', 'qa-reply')->get_status() === 200, 'signed inbound reply ingested');
$activities = json_decode(get_post_meta($id, '_wkel_activity_log', true), true);
check(count(array_filter($activities, fn($row) => $row['type'] === 'email_received')) === 1, 'reply retained in existing pipeline');
check(webhook('email.received', 'qa-inbound', 'qa-reply')->get_data()['duplicate'] === true, 'duplicate reply deduplicated');
$GLOBALS['wkel_mock'] = 'accepted';
foreach (['email.failed' => 'failed', 'email.complained' => 'suppressed', 'email.suppressed' => 'suppressed'] as $event => $state) {
    $outcome_lead = lead(str_replace('.', '-', $event));
    WKEL_Outreach::send(preview($outcome_lead, $t));
    webhook($event, message($outcome_lead)['resend_id'], 'qa-' . $event);
    check(message($outcome_lead)['status'] === $state, 'webhook outcome: ' . $event);
    check(is_wp_error(WKEL_Outreach::send(preview($outcome_lead, $t))), 'webhook outcome blocks resend: ' . $event);
}
$google = new ReflectionMethod(WKEL_Insights::class, 'google_metrics'); $google->setAccessible(true);
check($google->invoke(null, 30) === ['configured' => false], 'unconfigured Google makes no requests');
ob_start(); WKEL_Insights::render(); $ui = ob_get_clean();
check(!str_contains($ui, '356866720') && !str_contains($ui, 'wkdigital.com.au') && str_contains($ui, 'Not configured'), 'Insights contains no static WK Digital properties');
for ($n = 0; $n < 55; $n++) lead('page-' . $n);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['lead_id' => $id]; $_POST = [];
ob_start(); WKEL_Outreach::render(); $ui = ob_get_clean();
check(substr_count($ui, '>Select / history</a>') === 50 && str_contains($ui, 'Next contacts'), 'staging paginates beyond fifty contacts');
$_GET['wkel_outreach_page'] = 2; ob_start(); WKEL_Outreach::render(); $page2 = ob_get_clean();
check(str_contains($page2, 'Previous contacts') && substr_count($page2, '>Select / history</a>') > 0, 'older staged contacts remain accessible');
check(str_contains($ui, 'Outreach') && str_contains($ui, 'Message history') && !str_contains($ui, 'dummy-key-not-valid'), 'Outreach UI renders history without credentials');
$log = $wpdb->get_col('SELECT code FROM ' . WKEL_Outreach_Store::table('send_audit'));
check(!str_contains(implode(' ', $log), 'dummy-key') && !str_contains(implode(' ', $log), 'example.invalid'), 'audit errors contain no credentials or identity');
WKEL_Settings::register_settings();
update_option('wkel_cal_webhook_secret', 'dummy-cal-secret');
$cal_secret = get_option('wkel_cal_webhook_secret');
update_option('wkel_cal_webhook_secret', str_repeat("\xE2\x80\xA2", 8));
check(get_option('wkel_cal_webhook_secret') === $cal_secret && WKEL_Encryption::decrypt($cal_secret) === 'dummy-cal-secret', 'masked Cal secret retains its own value');
$key = get_option('wkel_resend_key'); update_option('wkel_resend_key', $key);
check(get_option('wkel_resend_key') === $key, 'secret sanitisation is idempotent');
update_option('wkel_qa_site_marker', DB_NAME);
update_option('wkel_outreach_enabled', '0'); update_option('wkel_outreach_dry_run', '1');
echo 'RESULT: ' . $count . " checks passed; all HTTP delivery was intercepted.\n";
