<?php
defined('ABSPATH') || exit;

class WKEL_Form {

    public static function register_shortcode(): void {
        add_shortcode('wkel_capture_form', [self::class, 'render']);
    }

    public static function enqueue_assets(): void {
        global $post;
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'wkel_capture_form')) {
            return;
        }

        wp_enqueue_style(
            'wkel-form',
            WKEL_PLUGIN_URL . 'assets/css/form.css',
            [],
            WKEL_VERSION
        );

        wp_enqueue_script(
            'wkel-form',
            WKEL_PLUGIN_URL . 'assets/js/form.js',
            [],
            WKEL_VERSION,
            true
        );

        wp_localize_script('wkel-form', 'wkelForm', [
            'restUrl' => esc_url_raw(rest_url('wk-event-leads/v1/submit')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Shortcode callback: [wkel_capture_form event="..." heading="..." redirect="..."]
     */
    public static function render(array $atts): string {
        $atts = shortcode_atts([
            'event'    => 'general',
            'heading'  => get_option('wkel_plugin_display_name', 'Event Leads'),
            'redirect' => '',
            'style'    => 'default',
        ], $atts, 'wkel_capture_form');

        // URL param overrides shortcode attribute
        $event = isset($_GET['event'])
            ? sanitize_key($_GET['event'])
            : sanitize_key($atts['event']);

        $fields          = WKEL_Schema::get_form_fields();
        $privacy_url     = esc_url(get_option('wkel_privacy_policy_url', get_privacy_policy_url()));
        $success_message = esc_html(get_option('wkel_success_message', 'Thanks — check your inbox.'));

        ob_start();
        include WKEL_PLUGIN_DIR . 'templates/form.php';
        return ob_get_clean();
    }
}
