<?php
defined('ABSPATH') || exit;

/**
 * Saved email templates for choosing a message on an individual lead.
 * The existing global confirmation template remains the fallback.
 */
class WKEL_Email_Templates {
    private const OPTION = 'wkel_email_templates';

    public static function get_all(): array {
        $templates = get_option(self::OPTION, []);
        if (!is_array($templates)) {
            return [];
        }
        return array_values(array_filter($templates, static function ($template) {
            return is_array($template)
                && !empty($template['id'])
                && !empty($template['name'])
                && isset($template['subject'], $template['body']);
        }));
    }

    public static function find(string $id): ?array {
        if ($id === '') {
            return null;
        }
        foreach (self::get_all() as $template) {
            if (hash_equals((string) $template['id'], $id)) {
                return $template;
            }
        }
        return null;
    }

    public static function register_menu(): void {
        add_submenu_page(
            'wkel_leads',
            __('Email Templates', 'wk-event-leads'),
            __('Email Templates', 'wk-event-leads'),
            'manage_options',
            'wkel_email_templates',
            [self::class, 'render']
        );
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission.', 'wk-event-leads'));
        }
        check_admin_referer('wkel_save_email_template');

        $templates = self::get_all();
        $id = sanitize_key(wp_unslash($_POST['template_id'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['template_name'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['template_subject'] ?? ''));
        $body = wp_kses_post(wp_unslash($_POST['template_body'] ?? ''));

        if ($name === '' || $subject === '' || trim(wp_strip_all_tags($body)) === '') {
            wp_die(esc_html__('Name, subject and message are required.', 'wk-event-leads'));
        }

        if ($id === '') {
            if (count($templates) >= 50) {
                wp_die(esc_html__('You can save up to 50 templates.', 'wk-event-leads'));
            }
            $id = sanitize_key(wp_generate_uuid4());
            $templates[] = ['id' => $id, 'name' => $name, 'subject' => $subject, 'body' => $body];
        } else {
            $found = false;
            foreach ($templates as &$template) {
                if ($template['id'] === $id) {
                    $template = ['id' => $id, 'name' => $name, 'subject' => $subject, 'body' => $body];
                    $found = true;
                    break;
                }
            }
            unset($template);
            if (!$found) {
                wp_die(esc_html__('Template not found.', 'wk-event-leads'));
            }
        }

        update_option(self::OPTION, array_values($templates), false);
        wp_safe_redirect(add_query_arg(['page' => 'wkel_email_templates', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public static function delete(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission.', 'wk-event-leads'));
        }
        check_admin_referer('wkel_delete_email_template');
        $id = sanitize_key(wp_unslash($_POST['template_id'] ?? ''));
        $template = self::find($id);
        if (!$template) {
            wp_die(esc_html__('Template not found.', 'wk-event-leads'));
        }

        $used = get_posts([
            'post_type' => 'wkel_lead',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [['key' => '_wkel_email_template_id', 'value' => $id]],
        ]);
        if ($used) {
            wp_die(esc_html__('This template is assigned to one or more leads. Change those leads to the default or another template before deleting it.', 'wk-event-leads'));
        }

        $remaining = array_values(array_filter(self::get_all(), static fn($item) => $item['id'] !== $id));
        update_option(self::OPTION, $remaining, false);
        wp_safe_redirect(add_query_arg(['page' => 'wkel_email_templates', 'deleted' => '1'], admin_url('admin.php')));
        exit;
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission.', 'wk-event-leads'));
        }

        $editing = self::find(sanitize_key($_GET['edit'] ?? ''));
        $templates = self::get_all();
        ?>
        <div class="wrap wkel-admin">
            <h1><?php esc_html_e('Email Templates', 'wk-event-leads'); ?></h1>
            <p><?php esc_html_e('Save reusable outreach messages here, then choose one on a lead before sending. New website enquiries continue to use the default confirmation email in Settings → Email.', 'wk-event-leads'); ?></p>
            <?php if (!empty($_GET['updated'])): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Template saved.', 'wk-event-leads'); ?></p></div><?php endif; ?>
            <?php if (!empty($_GET['deleted'])): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Template deleted.', 'wk-event-leads'); ?></p></div><?php endif; ?>

            <h2><?php echo $editing ? esc_html__('Edit template', 'wk-event-leads') : esc_html__('Add a template', 'wk-event-leads'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wkel_save_email_template">
                <input type="hidden" name="template_id" value="<?php echo esc_attr($editing['id'] ?? ''); ?>">
                <?php wp_nonce_field('wkel_save_email_template'); ?>
                <table class="form-table">
                    <tr><th><label for="wkel-template-name"><?php esc_html_e('Template name', 'wk-event-leads'); ?></label></th><td><input id="wkel-template-name" name="template_name" class="regular-text" required value="<?php echo esc_attr($editing['name'] ?? ''); ?>" placeholder="<?php esc_attr_e('Aged care — initial outreach', 'wk-event-leads'); ?>"></td></tr>
                    <tr><th><label for="wkel-template-subject"><?php esc_html_e('Subject', 'wk-event-leads'); ?></label></th><td><input id="wkel-template-subject" name="template_subject" class="large-text" required value="<?php echo esc_attr($editing['subject'] ?? ''); ?>"></td></tr>
                    <tr><th><label for="wkel-template-body"><?php esc_html_e('Message', 'wk-event-leads'); ?></label></th><td><?php wp_editor($editing['body'] ?? '', 'wkel_template_body', ['textarea_name' => 'template_body', 'textarea_rows' => 12, 'teeny' => false]); ?><p class="description"><?php esc_html_e('Available personalisation includes {{first_name}}, {{full_name}}, {{organisation}}, {{event_name}}, {{atncs_url}}, {{enp_url}}, {{sender_name}}, {{sender_phone}}, {{sender_email}} and {{unsubscribe_url}}.', 'wk-event-leads'); ?></p></td></tr>
                </table>
                <?php submit_button($editing ? __('Update template', 'wk-event-leads') : __('Save template', 'wk-event-leads')); ?>
                <?php if ($editing): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wkel_email_templates')); ?>"><?php esc_html_e('Cancel', 'wk-event-leads'); ?></a><?php endif; ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Saved templates', 'wk-event-leads'); ?></h2>
            <?php if (!$templates): ?>
                <p><?php esc_html_e('No saved templates yet. Add one above. The current default email remains available on every lead.', 'wk-event-leads'); ?></p>
            <?php else: ?>
                <table class="widefat striped"><thead><tr><th><?php esc_html_e('Name', 'wk-event-leads'); ?></th><th><?php esc_html_e('Subject', 'wk-event-leads'); ?></th><th><?php esc_html_e('Actions', 'wk-event-leads'); ?></th></tr></thead><tbody>
                <?php foreach ($templates as $template): ?>
                    <tr><td><?php echo esc_html($template['name']); ?></td><td><?php echo esc_html($template['subject']); ?></td><td><a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'wkel_email_templates', 'edit' => $template['id']], admin_url('admin.php'))); ?>"><?php esc_html_e('Edit', 'wk-event-leads'); ?></a> <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js(__('Delete this template?', 'wk-event-leads')); ?>');"><input type="hidden" name="action" value="wkel_delete_email_template"><input type="hidden" name="template_id" value="<?php echo esc_attr($template['id']); ?>"><?php wp_nonce_field('wkel_delete_email_template'); ?><button class="button button-small button-link-delete"><?php esc_html_e('Delete', 'wk-event-leads'); ?></button></form></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <?php
    }
}
