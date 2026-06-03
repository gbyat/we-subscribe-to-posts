<?php
/**
 * Plugin Name: We Subscribe To Posts
 * Plugin URI: https://github.com/gbyat/we-subscribe-to-posts
 * Description: Post subscription notifications with double opt-in and one-click unsubscribe.
 * Version: 0.1.3
 * Requires at least: 6.6
 * Tested up to: 7.0
 * Requires PHP: 8.1
 * Author: webentwicklerin, Gabriele Laesser
 * Author URI: https://webentwicklerin.at
 * Update URI: https://github.com/gbyat/we-subscribe-to-posts
 * Text Domain: we-subscribe-to-posts
 * Domain Path: /languages
 *
 * @package WeSubscribeToPosts
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WSTP_VERSION' ) ) {
	define( 'WSTP_VERSION', '0.1.3' );
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

if ( ! defined( 'WSTP_GITHUB_REPO' ) ) {
	define( 'WSTP_GITHUB_REPO', 'gbyat/we-subscribe-to-posts' );
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
