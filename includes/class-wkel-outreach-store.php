<?php
defined('ABSPATH') || exit;

/** Site-local durable message history and suppression ledger. */
class WKEL_Outreach_Store {
    public static function table(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix . 'wkel_' . $suffix;
    }

    public static function upgrade(): void {
        if (get_option('wkel_outreach_schema') === '1') return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $messages = self::table('messages');
        $audit = self::table('send_audit');
        $suppression = self::table('suppression');
        dbDelta("CREATE TABLE $messages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL,
            template_id bigint(20) unsigned NOT NULL,
            revision bigint(20) unsigned NOT NULL,
            author_id bigint(20) unsigned NOT NULL,
            authorization_hash varchar(64) NOT NULL,
            mode varchar(12) NOT NULL,
            status varchar(16) NOT NULL,
            delivery varchar(24) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            resend_id varchar(100) DEFAULT NULL,
            created_at bigint(20) unsigned NOT NULL,
            sent_at bigint(20) unsigned NOT NULL DEFAULT 0,
            outcome_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY authorization_hash (authorization_hash),
            UNIQUE KEY resend_id (resend_id),
            KEY lead_id (lead_id)
        ) $charset;");
        dbDelta("CREATE TABLE $audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
            message_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event varchar(40) NOT NULL,
            event_id varchar(100) DEFAULT NULL,
            code varchar(40) NOT NULL DEFAULT '',
            occurred_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY lead_id (lead_id),
            KEY message_id (message_id)
        ) $charset;");
        dbDelta("CREATE TABLE $suppression (
            email_hash varchar(64) NOT NULL,
            reason varchar(24) NOT NULL,
            source varchar(40) NOT NULL,
            created_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (email_hash)
        ) $charset;");
        foreach ([$messages, $audit, $suppression] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return;
        }
        add_option('wkel_outreach_enabled', '0', '', false);
        add_option('wkel_outreach_dry_run', '1', '', false);
        update_option('wkel_outreach_schema', '1', false);
    }

    public static function audit(int $lead_id, int $message_id, string $event, string $code = '', ?string $event_id = null, ?int $at = null): bool {
        global $wpdb;
        return false !== $wpdb->insert(self::table('send_audit'), [
            'lead_id' => $lead_id, 'message_id' => $message_id, 'actor_id' => get_current_user_id(),
            'event' => sanitize_key($event), 'code' => sanitize_key($code),
            'event_id' => $event_id, 'occurred_at' => $at ?: time(),
        ]);
    }

    public static function suppress(string $email, string $reason, string $source): void {
        global $wpdb;
        $email = strtolower(trim(sanitize_email($email)));
        if (!$email) return;
        self::suppress_hash(hash('sha256', $email), $reason, $source);
    }

    public static function suppress_hash(string $hash, string $reason, string $source): void {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) return;
        global $wpdb;
        $rank = "FIELD(reason,'failed','bounced','suppressed','complained','unsubscribed')";
        $new_rank = "FIELD(VALUES(reason),'failed','bounced','suppressed','complained','unsubscribed')";
        $wpdb->query($wpdb->prepare('INSERT INTO ' . self::table('suppression') . " (email_hash,reason,source,created_at) VALUES (%s,%s,%s,%d)
            ON DUPLICATE KEY UPDATE source=IF($new_rank > $rank, VALUES(source), source),
            created_at=IF($new_rank > $rank, VALUES(created_at), created_at), reason=IF($new_rank > $rank, VALUES(reason), reason)",
            $hash, sanitize_key($reason), sanitize_key($source), time()));
    }

    public static function blocked(int $lead_id, string $email): string {
        global $wpdb;
        $hash = hash('sha256', strtolower(trim($email)));
        $reason = $wpdb->get_var($wpdb->prepare('SELECT reason FROM ' . self::table('suppression') . ' WHERE email_hash = %s', $hash));
        if ($reason) return sanitize_key($reason);
        // Include duplicate, trashed and legacy records: import must not bypass an opt-out.
        $ids = get_posts(['post_type' => 'wkel_lead', 'post_status' => ['publish', 'draft', 'private', 'pending', 'trash'],
            'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [['key' => '_wkel_email_hash', 'value' => $hash]]]);
        $ids[] = $lead_id;
        foreach (array_unique($ids) as $id) {
            if (get_post_meta($id, '_wkel_marketing_status', true) === 'unsubscribed') return 'unsubscribed';
            foreach (['_wkel_email_status', '_wkel_outreach_status'] as $key) {
                $status = get_post_meta($id, $key, true);
                if (in_array($status, ['failed', 'bounced', 'suppressed', 'unsubscribed'], true)) return $status;
            }
        }
        return '';
    }

    public static function messages(int $lead_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT id,mode,status,delivery,resend_id,created_at,sent_at,outcome_at FROM ' . self::table('messages') . ' WHERE lead_id = %d ORDER BY id DESC LIMIT 50', $lead_id), ARRAY_A) ?: [];
    }
}
