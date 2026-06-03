<?php
/**
 * Plugin Name: We Subscribe To Posts
 * Description: Post subscription notifications with double opt-in and one-click unsubscribe.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: webentwicklerin, Gabriele Laesser
 * Author URI: https://webentwicklerin.at
 * Text Domain: we-subscribe-to-posts
 * Domain Path: /languages
 *
 * @package WeSubscribeToPosts
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WSTP_VERSION' ) ) {
	define( 'WSTP_VERSION', '0.1.0' );
}

if ( ! defined( 'WSTP_FILE' ) ) {
	define( 'WSTP_FILE', __FILE__ );
}

if ( ! defined( 'WSTP_PATH' ) ) {
	define( 'WSTP_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WSTP_URL' ) ) {
	define( 'WSTP_URL', plugin_dir_url( __FILE__ ) );
}

require_once WSTP_PATH . 'includes/core/class-autoloader.php';

\WSTP\Core\Autoloader::register();

register_activation_hook( __FILE__, array( '\WSTP\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\WSTP\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		$plugin = new \WSTP\Plugin();
		$plugin->run();
	}
);
