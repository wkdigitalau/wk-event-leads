<?php
/**
 * Plugin Name:    WK Event Leads
 * Plugin URI:     https://wkdigital.com.au
 * Description:    Schema-driven lead capture, pipeline management, and automated follow-up for WK Digital client sites.
 * Version:        1.0.0
 * Author:         WK Digital
 * Author URI:     https://wkdigital.com.au
 * Text Domain:    wk-event-leads
 * Domain Path:    /languages
 * Requires PHP:   8.1
 * Requires WP:    6.4
 */

defined('ABSPATH') || exit;

define('WKEL_VERSION',     '1.0.0');
define('WKEL_PLUGIN_FILE', __FILE__);
define('WKEL_PLUGIN_DIR',  plugin_dir_path(__FILE__));
define('WKEL_PLUGIN_URL',  plugin_dir_url(__FILE__));

require_once WKEL_PLUGIN_DIR . 'includes/class-wkel-activator.php';
require_once WKEL_PLUGIN_DIR . 'includes/class-wkel-loader.php';

register_activation_hook(__FILE__, ['WKEL_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['WKEL_Activator', 'deactivate']);

function wkel_init(): void {
    $loader = new WKEL_Loader();
    $loader->run();
}
add_action('plugins_loaded', 'wkel_init');
