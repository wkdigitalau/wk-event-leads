<?php
defined('ABSPATH') || exit;

class WKEL_Settings {

    private static array $tabs = [
        'general'  => 'General',
        'email'    => 'Email',
        'fields'   => 'Fields',
        'pipeline' => 'Pipeline',
        'events'   => 'Events',
        'urls'     => 'URLs',
        'security' => 'Security',
    ];

    public static function register_menu(): void {
        add_submenu_page(
            'wkel_leads',
            __('Settings', 'wk-event-leads'),
            __('Settings', 'wk-event-leads'),
            'manage_options',
            'wkel_settings',
            [self::class, 'render']
        );
    }

    public static function register_settings(): void {
        // General
        register_setting('wkel_general', 'wkel_plugin_display_name', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wkel_general', 'wkel_privacy_policy_url',  ['sanitize_callback' => 'esc_url_raw']);
        register_setting('wkel_general', 'wkel_success_message',     ['sanitize_callback' => 'sanitize_text_field']);

        // Email
        register_setting('wkel_email', 'wkel_sendgrid_key',      ['sanitize_callback' => [self::class, 'sanitise_api_key']]);
        register_setting('wkel_email', 'wkel_email_from_name',   ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wkel_email', 'wkel_email_from_address',['sanitize_callback' => 'sanitize_email']);
        register_setting('wkel_email', 'wkel_email_reply_to',    ['sanitize_callback' => 'sanitize_email']);
        register_setting('wkel_email', 'wkel_email_subject',     ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wkel_email', 'wkel_email_body',        ['sanitize_callback' => 'wp_kses_post']);
        register_setting('wkel_email', 'wkel_sender_name',       ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wkel_email', 'wkel_sender_phone',      ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wkel_email', 'wkel_sender_email',      ['sanitize_callback' => 'sanitize_email']);

        // Security
        register_setting('wkel_security', 'wkel_rate_limit',          ['sanitize_callback' => 'absint']);
        register_setting('wkel_security', 'wkel_honeypot_enabled',    ['sanitize_callback' => 'sanitize_key']);
        register_setting('wkel_security', 'wkel_data_retention_days', ['sanitize_callback' => 'absint']);

        // URLs
        register_setting('wkel_urls', 'wkel_atncs_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('wkel_urls', 'wkel_enp_url',   ['sanitize_callback' => 'esc_url_raw']);
    }

    public static function sanitise_api_key(string $value): string {
        $value = sanitize_text_field($value);
        // If unchanged (displayed as placeholder), keep existing
        if ($value === '••••••••') {
            return get_option('wkel_sendgrid_key', '');
        }
        return WKEL_Encryption::encrypt($value);
    }

    // -------------------------------------------------------------------------
    // Render settings page
    // -------------------------------------------------------------------------

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission.', 'wk-event-leads'));
        }

        self::handle_non_settings_api_actions();

        $active_tab = sanitize_key($_GET['tab'] ?? 'general');
        if (!array_key_exists($active_tab, self::$tabs)) {
            $active_tab = 'general';
        }

        $tabs = apply_filters('wkel_settings_tabs', self::$tabs);

        ?>
        <div class="wrap wkel-admin">
            <h1><?php echo esc_html(get_option('wkel_plugin_display_name', 'Event Leads')); ?> — <?php esc_html_e('Settings', 'wk-event-leads'); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wkel_settings&tab=' . $slug)); ?>"
                       class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            switch ($active_tab) {
                case 'general':  self::tab_general();  break;
                case 'email':    self::tab_email();    break;
                case 'fields':   self::tab_fields();   break;
                case 'pipeline': self::tab_pipeline(); break;
                case 'events':   self::tab_events();   break;
                case 'urls':     self::tab_urls();     break;
                case 'security': self::tab_security(); break;
            }
            ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: General
    // -------------------------------------------------------------------------

    private static function tab_general(): void {
        ?>
        <form method="post" action="options.php" class="wkel-settings-form">
        <?php settings_fields('wkel_general'); ?>
        <table class="form-table">
            <tr>
                <th><label for="wkel_plugin_display_name"><?php esc_html_e('Plugin Display Name', 'wk-event-leads'); ?></label></th>
                <td>
                    <input type="text" id="wkel_plugin_display_name" name="wkel_plugin_display_name"
                           value="<?php echo esc_attr(get_option('wkel_plugin_display_name', 'Event Leads')); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Shown in the WordPress admin menu (white-label support).', 'wk-event-leads'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wkel_privacy_policy_url"><?php esc_html_e('Privacy Policy URL', 'wk-event-leads'); ?></label></th>
                <td>
                    <input type="url" id="wkel_privacy_policy_url" name="wkel_privacy_policy_url"
                           value="<?php echo esc_attr(get_option('wkel_privacy_policy_url', '')); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Required. Must be live before the form is published or QR code is printed.', 'wk-event-leads'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wkel_success_message"><?php esc_html_e('Success Message', 'wk-event-leads'); ?></label></th>
                <td>
                    <input type="text" id="wkel_success_message" name="wkel_success_message"
                           value="<?php echo esc_attr(get_option('wkel_success_message', 'Thanks — check your inbox.')); ?>" class="regular-text">
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Email
    // -------------------------------------------------------------------------

    private static function tab_email(): void {
        ?><form method="post" action="options.php" class="wkel-settings-form"><?php
        settings_fields('wkel_email');

        $has_key = !empty(get_option('wkel_sendgrid_key', ''));

        // Handle test email
        $test_result = null;
        if (!empty($_POST['wkel_test_email']) && check_admin_referer('wkel_test_email')) {
            $test_result = WKEL_Email::send_test(sanitize_email($_POST['wkel_test_email']));
        }
        ?>
        <?php if ($test_result !== null): ?>
            <div class="notice <?php echo $test_result === true ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                <p><?php echo $test_result === true
                    ? esc_html__('Test email sent successfully.', 'wk-event-leads')
                    : esc_html($test_result); ?></p>
            </div>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th><label for="wkel_sendgrid_key"><?php esc_html_e('SendGrid API Key', 'wk-event-leads'); ?></label></th>
                <td>
                    <input type="password" id="wkel_sendgrid_key" name="wkel_sendgrid_key"
                           value="<?php echo $has_key ? '••••••••' : ''; ?>" class="regular-text" autocomplete="new-password">
                    <p class="description"><?php esc_html_e('Stored encrypted. Leave unchanged to keep existing key.', 'wk-event-leads'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wkel_email_from_name"><?php esc_html_e('From Name', 'wk-event-leads'); ?></label></th>
                <td><input type="text" id="wkel_email_from_name" name="wkel_email_from_name"
                           value="<?php echo esc_attr(get_option('wkel_email_from_name', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="wkel_email_from_address"><?php esc_html_e('From Email Address', 'wk-event-leads'); ?></label></th>
                <td><input type="email" id="wkel_email_from_address" name="wkel_email_from_address"
                           value="<?php echo esc_attr(get_option('wkel_email_from_address', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="wkel_email_reply_to"><?php esc_html_e('Reply-To Email', 'wk-event-leads'); ?></label></th>
                <td><input type="email" id="wkel_email_reply_to" name="wkel_email_reply_to"
                           value="<?php echo esc_attr(get_option('wkel_email_reply_to', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="wkel_email_subject"><?php esc_html_e('Email Subject', 'wk-event-leads'); ?></label></th>
                <td><input type="text" id="wkel_email_subject" name="wkel_email_subject"
                           value="<?php echo esc_attr(get_option('wkel_email_subject', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Email Body', 'wk-event-leads'); ?></th>
                <td>
                    <?php
                    wp_editor(
                        get_option('wkel_email_body', ''),
                        'wkel_email_body',
                        ['textarea_name' => 'wkel_email_body', 'textarea_rows' => 18, 'teeny' => false]
                    );
                    ?>
                    <p class="description">
                        <?php esc_html_e('Template variables: {{first_name}} {{full_name}} {{organisation}} {{event_name}} {{atncs_url}} {{enp_url}} {{sender_name}} {{sender_phone}} {{sender_email}}', 'wk-event-leads'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="wkel_sender_name"><?php esc_html_e('Sender Name', 'wk-event-leads'); ?></label></th>
                <td><input type="text" id="wkel_sender_name" name="wkel_sender_name"
                           value="<?php echo esc_attr(get_option('wkel_sender_name', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="wkel_sender_phone"><?php esc_html_e('Sender Phone', 'wk-event-leads'); ?></label></th>
                <td><input type="text" id="wkel_sender_phone" name="wkel_sender_phone"
                           value="<?php echo esc_attr(get_option('wkel_sender_phone', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="wkel_sender_email"><?php esc_html_e('Sender Email', 'wk-event-leads'); ?></label></th>
                <td><input type="email" id="wkel_sender_email" name="wkel_sender_email"
                           value="<?php echo esc_attr(get_option('wkel_sender_email', '')); ?>" class="regular-text"></td>
            </tr>
        </table>

        <?php submit_button(); ?>
        </form>

        <hr>
        <h2><?php esc_html_e('Test Email', 'wk-event-leads'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('wkel_test_email'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="wkel_test_email_address"><?php esc_html_e('Send test to', 'wk-event-leads'); ?></label></th>
                    <td>
                        <input type="email" id="wkel_test_email_address" name="wkel_test_email"
                               value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text">
                        <?php submit_button(__('Send Test Email', 'wk-event-leads'), 'secondary', 'wkel_send_test', false); ?>
                    </td>
                </tr>
            </table>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Fields (field builder)
    // -------------------------------------------------------------------------

    private static function tab_fields(): void {
        // Handle CRUD actions
        if (!empty($_POST['wkel_field_action']) && check_admin_referer('wkel_field_builder')) {
            self::handle_field_action($_POST['wkel_field_action'], $_POST);
        }

        $fields = WKEL_Schema::get_fields();
        usort($fields, fn($a, $b) => $a['order'] <=> $b['order']);
        ?>
        <h2><?php esc_html_e('Field Schema', 'wk-event-leads'); ?></h2>
        <p><?php esc_html_e('Locked fields cannot be deleted or have their type changed. Custom fields can be fully edited.', 'wk-event-leads'); ?></p>

        <form method="post">
            <?php wp_nonce_field('wkel_field_builder'); ?>
            <input type="hidden" name="wkel_field_action" value="">

            <table class="wp-list-table widefat fixed striped wkel-fields-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Label', 'wk-event-leads'); ?></th>
                        <th><?php esc_html_e('Type', 'wk-event-leads'); ?></th>
                        <th><?php esc_html_e('Required', 'wk-event-leads'); ?></th>
                        <th><?php esc_html_e('Encrypted', 'wk-event-leads'); ?></th>
                        <th><?php esc_html_e('Form', 'wk-event-leads'); ?></th>
                        <th><?php esc_html_e('Kanban', 'wk-event-leads'); ?></th>
                        <th><?php esc_html_e('List', 'wk-event-leads'); ?></th>
                        <th><?php esc_html_e('Actions', 'wk-event-leads'); ?></th>
                    </tr>
                </thead>
                <tbody id="wkel-fields-sortable">
                    <?php foreach ($fields as $field): ?>
                        <tr class="wkel-field-row <?php echo !empty($field['locked']) ? 'wkel-locked' : ''; ?>"
                            data-id="<?php echo esc_attr($field['id']); ?>">
                            <td>
                                <strong><?php echo esc_html($field['label']); ?></strong>
                                <br><small><code><?php echo esc_html($field['id']); ?></code></small>
                                <?php if (!empty($field['locked'])): ?>
                                    <span class="wkel-badge"><?php esc_html_e('Locked', 'wk-event-leads'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($field['type']); ?></td>
                            <td><?php echo !empty($field['required'])    ? '&#10003;' : '&#8212;'; ?></td>
                            <td><?php echo !empty($field['encrypted'])   ? '&#10003;' : '&#8212;'; ?></td>
                            <td><?php echo !empty($field['show_form'])   ? '&#10003;' : '&#8212;'; ?></td>
                            <td><?php echo !empty($field['show_kanban']) ? '&#10003;' : '&#8212;'; ?></td>
                            <td><?php echo !empty($field['show_list'])   ? '&#10003;' : '&#8212;'; ?></td>
                            <td>
                                <button type="button" class="button button-small wkel-edit-field"
                                        data-field='<?php echo esc_attr(wp_json_encode($field)); ?>'>
                                    <?php esc_html_e('Edit', 'wk-event-leads'); ?>
                                </button>
                                <?php if (empty($field['locked'])): ?>
                                    <button type="submit" class="button button-small button-link-delete"
                                            name="wkel_field_action" value="delete_field"
                                            onclick="document.getElementById('wkel-field-id-input').value='<?php echo esc_js($field['id']); ?>';
                                                     return confirm('Delete this field? Existing lead data is retained.');">
                                        <?php esc_html_e('Delete', 'wk-event-leads'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <input type="hidden" id="wkel-field-id-input" name="wkel_field_id" value="">
            <input type="hidden" id="wkel-field-order-input" name="wkel_field_order" value="">

            <p>
                <button type="button" class="button button-primary" id="wkel-add-field-btn">
                    <?php esc_html_e('+ Add Field', 'wk-event-leads'); ?>
                </button>
            </p>
        </form>

        <!-- Add/Edit Field Modal -->
        <div id="wkel-field-modal" style="display:none;" class="wkel-modal-overlay">
            <div class="wkel-modal">
                <h2 id="wkel-field-modal-title"><?php esc_html_e('Add Field', 'wk-event-leads'); ?></h2>
                <form method="post" id="wkel-field-modal-form">
                    <?php wp_nonce_field('wkel_field_builder'); ?>
                    <input type="hidden" name="wkel_field_action" id="wkel-modal-action" value="add_field">
                    <input type="hidden" name="wkel_field_id" id="wkel-modal-field-id" value="">

                    <table class="form-table">
                        <tr>
                            <th><label for="wkel-modal-label"><?php esc_html_e('Label', 'wk-event-leads'); ?></label></th>
                            <td><input type="text" id="wkel-modal-label" name="wkel_field_label" class="regular-text" required></td>
                        </tr>
                        <tr id="wkel-modal-type-row">
                            <th><label for="wkel-modal-type"><?php esc_html_e('Field Type', 'wk-event-leads'); ?></label></th>
                            <td>
                                <select id="wkel-modal-type" name="wkel_field_type">
                                    <option value="text">Text</option>
                                    <option value="email">Email</option>
                                    <option value="dropdown">Dropdown</option>
                                    <option value="checkbox">Checkbox</option>
                                    <option value="textarea">Textarea</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Required', 'wk-event-leads'); ?></th>
                            <td><input type="checkbox" name="wkel_field_required" id="wkel-modal-required" value="1"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Encrypt (PII)', 'wk-event-leads'); ?></th>
                            <td><input type="checkbox" name="wkel_field_encrypted" id="wkel-modal-encrypted" value="1"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Show on Form', 'wk-event-leads'); ?></th>
                            <td><input type="checkbox" name="wkel_field_show_form" id="wkel-modal-show-form" value="1" checked></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Show on Kanban', 'wk-event-leads'); ?></th>
                            <td>
                                <input type="checkbox" name="wkel_field_show_kanban" id="wkel-modal-show-kanban" value="1">
                                <span id="wkel-kanban-warning" style="color:#b32d2e;display:none;">
                                    <?php esc_html_e('Warning: More than 4 kanban fields is not recommended.', 'wk-event-leads'); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Show in List', 'wk-event-leads'); ?></th>
                            <td><input type="checkbox" name="wkel_field_show_list" id="wkel-modal-show-list" value="1" checked></td>
                        </tr>
                        <tr id="wkel-modal-options-row" style="display:none;">
                            <th><label for="wkel-modal-options"><?php esc_html_e('Options', 'wk-event-leads'); ?></label></th>
                            <td>
                                <textarea id="wkel-modal-options" name="wkel_field_options" rows="5" class="regular-text"
                                          placeholder="<?php esc_attr_e('One option per line', 'wk-event-leads'); ?>"></textarea>
                            </td>
                        </tr>
                    </table>

                    <div class="wkel-modal-footer">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save Field', 'wk-event-leads'); ?></button>
                        <button type="button" class="button" id="wkel-modal-cancel"><?php esc_html_e('Cancel', 'wk-event-leads'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (function(){
            const modal = document.getElementById('wkel-field-modal');
            const addBtn = document.getElementById('wkel-add-field-btn');
            const cancelBtn = document.getElementById('wkel-modal-cancel');
            const typeSelect = document.getElementById('wkel-modal-type');
            const optionsRow = document.getElementById('wkel-modal-options-row');

            addBtn.addEventListener('click', function() {
                document.getElementById('wkel-field-modal-title').textContent = 'Add Field';
                document.getElementById('wkel-modal-action').value = 'add_field';
                document.getElementById('wkel-modal-field-id').value = '';
                document.getElementById('wkel-field-modal-form').reset();
                document.getElementById('wkel-modal-type-row').style.display = '';
                modal.style.display = 'flex';
            });

            cancelBtn.addEventListener('click', function() { modal.style.display = 'none'; });
            modal.addEventListener('click', function(e) { if (e.target === modal) modal.style.display = 'none'; });

            document.querySelectorAll('.wkel-edit-field').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const field = JSON.parse(this.dataset.field);
                    document.getElementById('wkel-field-modal-title').textContent = 'Edit Field';
                    document.getElementById('wkel-modal-action').value = 'update_field';
                    document.getElementById('wkel-modal-field-id').value = field.id;
                    document.getElementById('wkel-modal-label').value = field.label;
                    document.getElementById('wkel-modal-type').value = field.type;
                    document.getElementById('wkel-modal-required').checked = !!field.required;
                    document.getElementById('wkel-modal-encrypted').checked = !!field.encrypted;
                    document.getElementById('wkel-modal-show-form').checked = !!field.show_form;
                    document.getElementById('wkel-modal-show-kanban').checked = !!field.show_kanban;
                    document.getElementById('wkel-modal-show-list').checked = !!field.show_list;
                    document.getElementById('wkel-modal-options').value = (field.options || []).join('\n');
                    const isLocked = !!field.locked;
                    document.getElementById('wkel-modal-type-row').style.display = isLocked ? 'none' : '';
                    toggleOptionsRow(field.type);
                    modal.style.display = 'flex';
                });
            });

            typeSelect.addEventListener('change', function() { toggleOptionsRow(this.value); });

            function toggleOptionsRow(type) {
                optionsRow.style.display = (type === 'dropdown' || type === 'checkbox') ? '' : 'none';
            }

            // Kanban field count warning
            const kanbanCheck = document.getElementById('wkel-modal-show-kanban');
            kanbanCheck.addEventListener('change', function() {
                const currentCount = document.querySelectorAll('.wkel-field-row td:nth-child(6)').length;
                // rough check — show warning if already 4+
                const kanbanCount = [...document.querySelectorAll('.wkel-field-row td:nth-child(6)')]
                    .filter(td => td.textContent.trim() === '✓').length;
                document.getElementById('wkel-kanban-warning').style.display =
                    (this.checked && kanbanCount >= 4) ? 'inline' : 'none';
            });
        }());
        </script>
        <?php
    }

    private static function handle_field_action(string $action, array $post): void {
        switch ($action) {
            case 'add_field':
                $options_raw = sanitize_textarea_field($post['wkel_field_options'] ?? '');
                $options     = $options_raw
                    ? array_filter(array_map('trim', explode("\n", $options_raw)))
                    : [];
                $id          = sanitize_key($post['wkel_field_label'] ?? '');
                $id          = 'custom_' . $id . '_' . wp_rand(100, 999);

                WKEL_Schema::add_field([
                    'id'          => $id,
                    'label'       => sanitize_text_field($post['wkel_field_label'] ?? ''),
                    'type'        => sanitize_key($post['wkel_field_type'] ?? 'text'),
                    'required'    => !empty($post['wkel_field_required']),
                    'encrypted'   => !empty($post['wkel_field_encrypted']),
                    'locked'      => false,
                    'show_form'   => !empty($post['wkel_field_show_form']),
                    'show_kanban' => !empty($post['wkel_field_show_kanban']),
                    'show_list'   => !empty($post['wkel_field_show_list']),
                    'order'       => 999,
                    'options'     => array_values($options),
                ]);
                break;

            case 'update_field':
                $field_id    = sanitize_key($post['wkel_field_id'] ?? '');
                $options_raw = sanitize_textarea_field($post['wkel_field_options'] ?? '');
                $options     = $options_raw
                    ? array_filter(array_map('trim', explode("\n", $options_raw)))
                    : [];

                WKEL_Schema::update_field($field_id, [
                    'label'       => sanitize_text_field($post['wkel_field_label'] ?? ''),
                    'type'        => sanitize_key($post['wkel_field_type'] ?? 'text'),
                    'required'    => !empty($post['wkel_field_required']),
                    'encrypted'   => !empty($post['wkel_field_encrypted']),
                    'show_form'   => !empty($post['wkel_field_show_form']),
                    'show_kanban' => !empty($post['wkel_field_show_kanban']),
                    'show_list'   => !empty($post['wkel_field_show_list']),
                    'options'     => array_values($options),
                ]);
                break;

            case 'delete_field':
                WKEL_Schema::delete_field(sanitize_key($post['wkel_field_id'] ?? ''));
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Tab: Pipeline
    // -------------------------------------------------------------------------

    private static function tab_pipeline(): void {
        if (!empty($_POST['wkel_stage_action']) && check_admin_referer('wkel_stage_builder')) {
            self::handle_stage_action($_POST['wkel_stage_action'], $_POST);
        }

        $stages = WKEL_Schema::get_stages();
        usort($stages, fn($a, $b) => $a['order'] <=> $b['order']);
        ?>
        <h2><?php esc_html_e('Pipeline Stages', 'wk-event-leads'); ?></h2>

        <form method="post">
            <?php wp_nonce_field('wkel_stage_builder'); ?>
            <input type="hidden" name="wkel_stage_action" value="">
            <input type="hidden" id="wkel-stage-id-input" name="wkel_stage_id" value="">

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Label', 'wk-event-leads'); ?></th>
                        <th><?php esc_html_e('Color', 'wk-event-leads'); ?></th>
                        <th><?php esc_html_e('Actions', 'wk-event-leads'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stages as $stage): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($stage['label']); ?></strong>
                                <?php if (!empty($stage['locked'])): ?>
                                    <span class="wkel-badge"><?php esc_html_e('Locked', 'wk-event-leads'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="display:inline-block;width:20px;height:20px;background:<?php echo esc_attr($stage['color']); ?>;border-radius:3px;vertical-align:middle;"></span>
                                <?php echo esc_html($stage['color']); ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small wkel-edit-stage"
                                        data-stage='<?php echo esc_attr(wp_json_encode($stage)); ?>'>
                                    <?php esc_html_e('Edit', 'wk-event-leads'); ?>
                                </button>
                                <?php if (empty($stage['locked'])): ?>
                                    <button type="submit" class="button button-small button-link-delete"
                                            name="wkel_stage_action" value="delete_stage"
                                            onclick="document.getElementById('wkel-stage-id-input').value='<?php echo esc_js($stage['id']); ?>';
                                                     document.getElementById('wkel-reassign-modal').style.display='flex';
                                                     return false;">
                                        <?php esc_html_e('Delete', 'wk-event-leads'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" class="button button-primary" id="wkel-add-stage-btn">
                    <?php esc_html_e('+ Add Stage', 'wk-event-leads'); ?>
                </button>
            </p>
        </form>

        <!-- Add/Edit Stage Modal -->
        <div id="wkel-stage-modal" style="display:none;" class="wkel-modal-overlay">
            <div class="wkel-modal">
                <h2 id="wkel-stage-modal-title"><?php esc_html_e('Add Stage', 'wk-event-leads'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('wkel_stage_builder'); ?>
                    <input type="hidden" name="wkel_stage_action" id="wkel-stage-modal-action" value="add_stage">
                    <input type="hidden" name="wkel_stage_id" id="wkel-stage-modal-id" value="">
                    <table class="form-table">
                        <tr>
                            <th><label for="wkel-stage-modal-label"><?php esc_html_e('Label', 'wk-event-leads'); ?></label></th>
                            <td><input type="text" id="wkel-stage-modal-label" name="wkel_stage_label" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="wkel-stage-modal-color"><?php esc_html_e('Color', 'wk-event-leads'); ?></label></th>
                            <td><input type="color" id="wkel-stage-modal-color" name="wkel_stage_color" value="#6B7280"></td>
                        </tr>
                    </table>
                    <div class="wkel-modal-footer">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save Stage', 'wk-event-leads'); ?></button>
                        <button type="button" class="button" id="wkel-stage-cancel"><?php esc_html_e('Cancel', 'wk-event-leads'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Stage reassign modal -->
        <div id="wkel-reassign-modal" style="display:none;" class="wkel-modal-overlay">
            <div class="wkel-modal">
                <h2><?php esc_html_e('Reassign Leads', 'wk-event-leads'); ?></h2>
                <p><?php esc_html_e('Move existing leads in this stage to:', 'wk-event-leads'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('wkel_stage_builder'); ?>
                    <input type="hidden" name="wkel_stage_action" value="delete_stage">
                    <input type="hidden" name="wkel_stage_id" id="wkel-reassign-stage-id" value="">
                    <select name="wkel_reassign_to" class="regular-text">
                        <?php foreach ($stages as $s): ?>
                            <?php if (empty($s['locked']) || $s['id'] === 'new'): ?>
                                <option value="<?php echo esc_attr($s['id']); ?>"><?php echo esc_html($s['label']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <div class="wkel-modal-footer">
                        <button type="submit" class="button button-primary button-link-delete"><?php esc_html_e('Delete & Reassign', 'wk-event-leads'); ?></button>
                        <button type="button" class="button" onclick="document.getElementById('wkel-reassign-modal').style.display='none'">
                            <?php esc_html_e('Cancel', 'wk-event-leads'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (function(){
            const stageModal = document.getElementById('wkel-stage-modal');
            document.getElementById('wkel-add-stage-btn').addEventListener('click', function() {
                document.getElementById('wkel-stage-modal-title').textContent = 'Add Stage';
                document.getElementById('wkel-stage-modal-action').value = 'add_stage';
                document.getElementById('wkel-stage-modal-id').value = '';
                document.getElementById('wkel-stage-modal-label').value = '';
                document.getElementById('wkel-stage-modal-color').value = '#6B7280';
                stageModal.style.display = 'flex';
            });
            document.getElementById('wkel-stage-cancel').addEventListener('click', function() {
                stageModal.style.display = 'none';
            });
            stageModal.addEventListener('click', function(e) { if (e.target === stageModal) stageModal.style.display = 'none'; });

            document.querySelectorAll('.wkel-edit-stage').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const s = JSON.parse(this.dataset.stage);
                    document.getElementById('wkel-stage-modal-title').textContent = 'Edit Stage';
                    document.getElementById('wkel-stage-modal-action').value = 'update_stage';
                    document.getElementById('wkel-stage-modal-id').value = s.id;
                    document.getElementById('wkel-stage-modal-label').value = s.label;
                    document.getElementById('wkel-stage-modal-color').value = s.color;
                    stageModal.style.display = 'flex';
                });
            });

            // Wire up delete buttons to reassign modal
            document.querySelectorAll('.button-link-delete').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const stageId = document.getElementById('wkel-stage-id-input').value;
                    document.getElementById('wkel-reassign-stage-id').value = stageId;
                });
            });
        }());
        </script>
        <?php
    }

    private static function handle_stage_action(string $action, array $post): void {
        switch ($action) {
            case 'add_stage':
                $label = sanitize_text_field($post['wkel_stage_label'] ?? '');
                $color = sanitize_hex_color($post['wkel_stage_color'] ?? '#6B7280') ?? '#6B7280';
                $id    = sanitize_key($label) . '_' . wp_rand(100, 999);
                WKEL_Schema::add_stage(['id' => $id, 'label' => $label, 'color' => $color]);
                break;

            case 'update_stage':
                WKEL_Schema::update_stage(
                    sanitize_key($post['wkel_stage_id'] ?? ''),
                    [
                        'label' => sanitize_text_field($post['wkel_stage_label'] ?? ''),
                        'color' => sanitize_hex_color($post['wkel_stage_color'] ?? '#6B7280') ?? '#6B7280',
                    ]
                );
                break;

            case 'delete_stage':
                WKEL_Schema::delete_stage(
                    sanitize_key($post['wkel_stage_id'] ?? ''),
                    sanitize_key($post['wkel_reassign_to'] ?? 'new')
                );
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Tab: Events
    // -------------------------------------------------------------------------

    private static function tab_events(): void {
        if (!empty($_POST['wkel_save_events']) && check_admin_referer('wkel_events_settings')) {
            $slugs  = $_POST['event_slug']  ?? [];
            $names  = $_POST['event_name']  ?? [];
            $map    = [];
            for ($i = 0; $i < count($slugs); $i++) {
                $slug = sanitize_key($slugs[$i] ?? '');
                $name = sanitize_text_field($names[$i] ?? '');
                if ($slug && $name) {
                    $map[] = ['slug' => $slug, 'name' => $name];
                }
            }
            update_option('wkel_event_map', wp_json_encode($map));
        }

        $event_map = json_decode(get_option('wkel_event_map', '[]'), true) ?: [];
        ?>
        <h2><?php esc_html_e('Event Slug → Display Name Mapping', 'wk-event-leads'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('wkel_events_settings'); ?>
            <table class="wp-list-table widefat fixed striped" id="wkel-event-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Slug (URL parameter)', 'wk-event-leads'); ?></th>
                        <th><?php esc_html_e('Display Name', 'wk-event-leads'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($event_map as $entry): ?>
                        <tr>
                            <td><input type="text" name="event_slug[]" value="<?php echo esc_attr($entry['slug']); ?>" class="regular-text"></td>
                            <td><input type="text" name="event_name[]" value="<?php echo esc_attr($entry['name']); ?>" class="regular-text"></td>
                            <td><button type="button" class="button wkel-remove-row"><?php esc_html_e('Remove', 'wk-event-leads'); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button type="button" class="button" id="wkel-add-event-row">
                    <?php esc_html_e('+ Add Event', 'wk-event-leads'); ?>
                </button>
            </p>
            <?php submit_button(__('Save Events', 'wk-event-leads'), 'primary', 'wkel_save_events'); ?>
        </form>
        <script>
        document.getElementById('wkel-add-event-row').addEventListener('click', function() {
            const tbody = document.querySelector('#wkel-event-table tbody');
            const row = document.createElement('tr');
            row.innerHTML = '<td><input type="text" name="event_slug[]" class="regular-text"></td>'
                + '<td><input type="text" name="event_name[]" class="regular-text"></td>'
                + '<td><button type="button" class="button wkel-remove-row">Remove</button></td>';
            tbody.appendChild(row);
        });
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('wkel-remove-row')) {
                e.target.closest('tr').remove();
            }
        });
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: URLs
    // -------------------------------------------------------------------------

    private static function tab_urls(): void {
        ?>
        <form method="post" action="options.php" class="wkel-settings-form">
        <?php settings_fields('wkel_urls'); ?>

        <table class="form-table">
            <tr>
                <th><label for="wkel_atncs_url"><?php esc_html_e('ATNCS Website URL', 'wk-event-leads'); ?></label></th>
                <td><input type="url" id="wkel_atncs_url" name="wkel_atncs_url"
                           value="<?php echo esc_attr(get_option('wkel_atncs_url', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="wkel_enp_url"><?php esc_html_e('ENP Platform URL', 'wk-event-leads'); ?></label></th>
                <td><input type="url" id="wkel_enp_url" name="wkel_enp_url"
                           value="<?php echo esc_attr(get_option('wkel_enp_url', '')); ?>" class="regular-text"></td>
            </tr>
        </table>
        <?php submit_button(); ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Security
    // -------------------------------------------------------------------------

    private static function tab_security(): void {
        ?>
        <form method="post" action="options.php" class="wkel-settings-form">
        <?php settings_fields('wkel_security'); ?>

        <table class="form-table">
            <tr>
                <th><label for="wkel_rate_limit"><?php esc_html_e('Rate Limit', 'wk-event-leads'); ?></label></th>
                <td>
                    <input type="number" id="wkel_rate_limit" name="wkel_rate_limit" min="1" max="100"
                           value="<?php echo esc_attr(get_option('wkel_rate_limit', 5)); ?>" class="small-text">
                    <p class="description"><?php esc_html_e('Maximum form submissions per IP address per hour.', 'wk-event-leads'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Honeypot', 'wk-event-leads'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wkel_honeypot_enabled" value="1"
                               <?php checked('1', get_option('wkel_honeypot_enabled', '1')); ?>>
                        <?php esc_html_e('Enable honeypot spam protection', 'wk-event-leads'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="wkel_data_retention_days"><?php esc_html_e('Data Retention', 'wk-event-leads'); ?></label></th>
                <td>
                    <input type="number" id="wkel_data_retention_days" name="wkel_data_retention_days" min="0"
                           value="<?php echo esc_attr(get_option('wkel_data_retention_days', 365)); ?>" class="small-text">
                    <p class="description"><?php esc_html_e('Days to keep lead data. 0 = never auto-delete. Default: 365.', 'wk-event-leads'); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
        </form>
        <?php
    }

    private static function handle_non_settings_api_actions(): void {
        // Events tab and stage/field builder use their own nonces — handled inside tab methods
    }
}
