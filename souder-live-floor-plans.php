<?php
/**
 * Plugin Name: Souder Live Floor Plans
 * Description: Interactive live floor plans for tenant self-tours, powered by Souder CRE inventory data.
 * Version: 0.1.19
 * Requires PHP: 8.1
 * Author: Souder Properties
 * Text Domain: souder-live-floor-plans
 */

defined( 'ABSPATH' ) || exit;

define( 'SLFP_VERSION', '0.1.19' );
define( 'SLFP_FILE', __FILE__ );
define( 'SLFP_PATH', plugin_dir_path( __FILE__ ) );
define( 'SLFP_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'Plugin', 'Post_Type', 'Suite_Provider', 'Rest', 'Admin', 'Renderer' ) as $class ) {
	require_once SLFP_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
}

register_activation_hook( __FILE__, array( 'SLFP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SLFP_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SLFP_Plugin', 'boot' ) );
