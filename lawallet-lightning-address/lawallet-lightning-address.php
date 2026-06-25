<?php
/**
 * Plugin Name: Accept Bitcoin with your Lightning Address
 * Plugin URI: https://wordpress.lawallet.io
 * Description: Connect payments with most popular Lightning wallets without registration or credentials.
 * Version: 0.6.15
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: LaWallet
 * Author URI: https://lawallet.io
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: lawallet-lightning-address
 * Domain Path: /languages
 * WC requires at least: 8.0
 * WC tested up to: 10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCLL_VERSION', '0.6.15' );
define( 'WCLL_PLUGIN_FILE', __FILE__ );
define( 'WCLL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCLL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WCLL_PLUGIN_DIR . 'includes/class-wcll-nostr.php';
require_once WCLL_PLUGIN_DIR . 'includes/class-wcll-nostr-relay.php';
require_once WCLL_PLUGIN_DIR . 'includes/class-wcll-nwc-client.php';
require_once WCLL_PLUGIN_DIR . 'includes/class-wcll-nwc-manager.php';
require_once WCLL_PLUGIN_DIR . 'includes/class-wcll-lnurl-client.php';
require_once WCLL_PLUGIN_DIR . 'includes/class-wcll-rates.php';
require_once WCLL_PLUGIN_DIR . 'includes/class-wcll-discovery.php';
// Optional GitHub self-updater (only present in builds distributed outside the WordPress.org directory).
if ( file_exists( WCLL_PLUGIN_DIR . 'includes/class-wcll-updater.php' ) ) {
	require_once WCLL_PLUGIN_DIR . 'includes/class-wcll-updater.php';
}
require_once WCLL_PLUGIN_DIR . 'includes/class-wcll-plugin.php';

register_activation_hook( __FILE__, array( 'WCLL_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCLL_Plugin', 'deactivate' ) );

add_action( 'before_woocommerce_init', array( 'WCLL_Plugin', 'declare_woocommerce_features' ) );
add_action( 'plugins_loaded', array( 'WCLL_Plugin', 'init' ), 11 );
