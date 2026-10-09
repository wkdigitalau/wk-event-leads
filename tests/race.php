<?php
require $argv[1] . '/wp-load.php';
if (!str_starts_with(DB_NAME, 'wkel_qa_') || !defined('WKEL_QA_HTTP_GUARD')) throw new RuntimeException('QA guard required');
wp_set_current_user(1); $GLOBALS['wkel_mock'] = 'accepted';
$file = '/var/tmp/wkel-qa/race.json';
if ($argv[2] === 'setup') {
    update_option('wkel_outreach_enabled', '1'); update_option('wkel_outreach_dry_run', '0');
    $id = WKEL_Submission::create_lead(['wkel_name' => 'Race Dummy', 'wkel_email' => 'race-dummy@example.invalid', 'wkel_organisation' => 'Dummy'], 'qa', '', 'outreach', 'other');
    $template = WKEL_Outreach::save_template(0, 'Race', 'Hello', '<p>Hello</p><a href="{{unsubscribe_url}}">Opt out</a>'); WKEL_Outreach::approve($template);
    $preview = WKEL_Outreach::preview($id, $template); file_put_contents($file, json_encode(['id' => $id, 'token' => $preview['token']]));
} elseif ($argv[2] === 'send') {
    $data = json_decode(file_get_contents($file), true); $result = WKEL_Outreach::send($data['token']);
    echo is_wp_error($result) ? 'blocked' : 'accepted';
} else {
    $data = json_decode(file_get_contents($file), true);
    $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . WKEL_Outreach_Store::table('messages') . ' WHERE lead_id = %d', $data['id']));
    $accepted = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . WKEL_Outreach_Store::table('send_audit') . " WHERE lead_id = %d AND event = 'send_accepted'", $data['id']));
    if ((int) $count !== 1 || (int) $accepted !== 1) throw new RuntimeException('Duplicate concurrent delivery');
    update_option('wkel_outreach_enabled', '0'); update_option('wkel_outreach_dry_run', '1');
    echo "PASS: four simultaneous send requests yielded exactly one message and one mocked acceptance.\n";
}
