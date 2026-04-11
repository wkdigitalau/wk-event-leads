<?php
defined('ABSPATH') || exit;

class WKEL_Activator {

    public static function activate(): void {
        self::require_includes();

        WKEL_Schema::seed_defaults();
        WKEL_CPT::register();
        flush_rewrite_rules();

        if (!defined('WKEL_ENCRYPTION_KEY')) {
            add_option('wkel_encryption_key_missing', '1');
        }

        add_option('wkel_version', WKEL_VERSION);
        add_option('wkel_rate_limit', 5);
        add_option('wkel_success_message', 'Thanks — check your inbox.');
        add_option('wkel_data_retention_days', 365);
        add_option('wkel_honeypot_enabled', '1');
        add_option('wkel_privacy_policy_url', get_privacy_policy_url());
        add_option('wkel_plugin_display_name', 'Event Leads');
        add_option('wkel_email_subject', 'Great connecting with you today — here\'s what we do');
        add_option('wkel_email_from_name', '');
        add_option('wkel_email_from_address', '');
        add_option('wkel_email_reply_to', '');
        add_option('wkel_sender_name', '');
        add_option('wkel_sender_phone', '');
        add_option('wkel_sender_email', '');
        add_option('wkel_sendgrid_key', '');
        add_option('wkel_atncs_url', '');
        add_option('wkel_enp_url', '');
        add_option('wkel_event_map', json_encode([['slug' => 'general', 'name' => 'General']]));
        add_option('wkel_email_body', self::default_email_body());
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    private static function require_includes(): void {
        $files = [
            'class-wkel-schema.php',
            'class-wkel-cpt.php',
        ];
        foreach ($files as $file) {
            $path = WKEL_PLUGIN_DIR . 'includes/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    private static function default_email_body(): string {
        return '<p>Hi {{first_name}},</p>

<p>It was great connecting with you at the conference.</p>

<p>As discussed, we support aged care and community providers across two key areas:</p>

<p><strong>AT Nurse Consulting Service (ATNCS)</strong><br>
&bull; Clinical governance and compliance support<br>
&bull; Sanctions and regulatory response<br>
&bull; Workforce, systems, and operational improvement</p>

<p>Visit ATNCS: {{atncs_url}}</p>

<p><strong>Elite Nurse Partners (ENP)</strong><br>
&bull; Recruitment of internationally qualified nurses<br>
&bull; Structured transition-to-practice program (50 weeks)<br>
&bull; Ongoing education, supervision, and workforce support</p>

<p>Visit ENP: {{enp_url}}</p>

<p>If you\'re open to it, we\'d be happy to schedule a short call to better understand your current priorities and see where we can support.</p>

<p>Thanks again, and I look forward to staying in touch.</p>

<p>{{sender_name}}<br>
{{sender_phone}} | {{sender_email}}</p>';
    }
}
