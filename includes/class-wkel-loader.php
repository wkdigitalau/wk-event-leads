<?php
defined('ABSPATH') || exit;

class WKEL_Loader {

    private array $actions = [];
    private array $filters = [];

    public function run(): void {
        $this->load_dependencies();
        $this->define_hooks();
        $this->load_modules();
        $this->register_hooks();
        $this->maybe_show_encryption_notice();
    }

    private function load_dependencies(): void {
        $includes = [
            'class-wkel-encryption.php',
            'class-wkel-security.php',
            'class-wkel-schema.php',
            'class-wkel-cpt.php',
            'class-wkel-form.php',
            'class-wkel-submission.php',
            'class-wkel-email.php',
            'class-wkel-campaign.php',
            'class-wkel-enp-review.php',
            'class-wkel-pipeline.php',
            'class-wkel-admin.php',
            'class-wkel-settings.php',
            'class-wkel-export.php',
        ];

        foreach ($includes as $file) {
            $path = WKEL_PLUGIN_DIR . 'includes/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    private function define_hooks(): void {
        // CPT
        $this->add_action('init', 'WKEL_CPT', 'register');
        $this->add_action('init', 'WKEL_Schema', 'ensure_stage_defaults', 5, 0);

        // Form shortcode
        $this->add_action('init', 'WKEL_Form', 'register_shortcode');
        $this->add_action('wp_enqueue_scripts', 'WKEL_Form', 'enqueue_assets');

        // REST submission
        $this->add_action('rest_api_init', 'WKEL_Submission', 'register_routes');

        // Email (Action Scheduler)
        $this->add_action('wkel_send_confirmation_email', 'WKEL_Email', 'send_confirmation', 10, 1);

        // Cold outreach campaign tracking and public opt-out
        $this->add_action('init', 'WKEL_Campaign', 'add_rewrite_rules');
        $this->add_action('template_redirect', 'WKEL_Campaign', 'maybe_render_unsubscribe_page');
        $this->add_action('template_redirect', 'WKEL_ENP_Review', 'maybe_render', 0, 0);
        $this->add_action('admin_post_wkel_import_campaign_contacts', 'WKEL_Campaign', 'handle_import');
        $this->add_action('admin_post_wkel_export_suppression_csv', 'WKEL_Campaign', 'export_suppression_csv');

        // Admin
        if (is_admin()) {
            $this->add_action('admin_menu', 'WKEL_Admin', 'register_menu');
            $this->add_action('admin_menu', 'WKEL_Campaign', 'register_admin_menu');
            $this->add_action('admin_enqueue_scripts', 'WKEL_Admin', 'enqueue_assets');
            $this->add_action('admin_menu', 'WKEL_Settings', 'register_menu');
            $this->add_action('admin_init', 'WKEL_Settings', 'register_settings');
        }
    }

    private function load_modules(): void {
        $modules_dir = WKEL_PLUGIN_DIR . 'modules/';
        if (!is_dir($modules_dir)) {
            return;
        }

        foreach (glob($modules_dir . '*/module.php') as $module_file) {
            require_once $module_file;
            $class = $this->get_module_class($module_file);
            if ($class && class_exists($class) && in_array('WKEL_Module_Interface', class_implements($class), true)) {
                $module = new $class();
                $module->init();
            }
        }
    }

    private function get_module_class(string $file): string {
        $dir   = basename(dirname($file));
        $parts = explode('-', $dir);
        $parts = array_map('ucfirst', $parts);
        return 'WKEL_Module_' . implode('_', $parts);
    }

    private function add_action(string $hook, string $class, string $method, int $priority = 10, int $args = 1): void {
        $this->actions[] = compact('hook', 'class', 'method', 'priority', 'args');
    }

    private function add_filter(string $hook, string $class, string $method, int $priority = 10, int $args = 1): void {
        $this->filters[] = compact('hook', 'class', 'method', 'priority', 'args');
    }

    private function register_hooks(): void {
        foreach ($this->actions as $hook) {
            add_action(
                $hook['hook'],
                [$hook['class'], $hook['method']],
                $hook['priority'],
                $hook['args']
            );
        }
        foreach ($this->filters as $hook) {
            add_filter(
                $hook['hook'],
                [$hook['class'], $hook['method']],
                $hook['priority'],
                $hook['args']
            );
        }
    }

    private function maybe_show_encryption_notice(): void {
        if (!is_admin()) {
            return;
        }
        if (!defined('WKEL_ENCRYPTION_KEY')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>WK Event Leads:</strong> '
                    . esc_html('WKEL_ENCRYPTION_KEY and WKEL_ENCRYPTION_IV are not defined in wp-config.php. PII fields will not be encrypted. Define these constants before capturing live data.')
                    . '</p></div>';
            });
        }
    }
}

/**
 * Phase 2 module interface — defined in Phase 1, used by future modules.
 */
interface WKEL_Module_Interface {
    public function get_name(): string;
    public function get_version(): string;
    public function init(): void;
}
