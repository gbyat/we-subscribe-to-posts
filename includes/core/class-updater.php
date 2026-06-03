<?php
/**
 * GitHub updater for plugin auto-updates.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles update checks against latest GitHub release.
 */
class Updater {
	/**
	 * Plugin file absolute path.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Plugin header data.
	 *
	 * @var array<string,mixed>
	 */
	private $plugin = array();

	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Whether plugin is active.
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Parsed GitHub response.
	 *
	 * @var object|null
	 */
	private $github_response;

	/**
	 * Constructor.
	 *
	 * @param string $file Plugin file path.
	 */
	public function __construct( string $file ) {
		$this->file     = $file;
		$this->basename = plugin_basename( $this->file );
		$this->active   = function_exists( 'is_plugin_active' ) ? is_plugin_active( $this->basename ) : true;

		add_action( 'admin_init', array( $this, 'set_plugin_properties' ) );
		add_action( 'admin_init', array( $this, 'get_github_response' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_transient' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_popup' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'purge' ), 10, 2 );
	}

	/**
	 * Check whether GitHub updates are enabled in settings.
	 *
	 * @return bool
	 */
	private function is_updates_enabled(): bool {
		$settings = get_option( 'wstp_settings', array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}

		return isset( $settings['github_updates_enabled'] ) && 'yes' === sanitize_key( (string) $settings['github_updates_enabled'] );
	}

	/**
	 * Load installed plugin metadata.
	 *
	 * @return void
	 */
	public function set_plugin_properties(): void {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			return;
		}
		$this->plugin = get_plugin_data( $this->file );
	}

	/**
	 * Fetch latest release payload from GitHub API.
	 *
	 * @return void
	 */
	public function get_github_response(): void {
		if ( ! $this->is_updates_enabled() ) {
			return;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . WSTP_GITHUB_REPO . '/releases/latest',
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept' => 'application/vnd.github+json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return;
		}

		$parsed = json_decode( $body );
		if ( ! is_object( $parsed ) || ! isset( $parsed->tag_name ) ) {
			return;
		}
		if ( isset( $parsed->prerelease ) && true === $parsed->prerelease ) {
			return;
		}
		if ( isset( $parsed->draft ) && true === $parsed->draft ) {
			return;
		}
		$tag_name = ltrim( (string) $parsed->tag_name, 'v' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $tag_name ) ) {
			return;
		}

		$this->github_response = $parsed;
	}

	/**
	 * Inject custom update info into WordPress update transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function modify_transient( $transient ) {
		if ( ! $this->is_updates_enabled() || ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! $this->github_response || ! $this->active || empty( $this->plugin['Version'] ) ) {
			return $transient;
		}

		$current_version = (string) $this->plugin['Version'];
		$new_version     = ltrim( (string) $this->github_response->tag_name, 'v' );
		if ( version_compare( $current_version, $new_version, '>=' ) ) {
			return $transient;
		}

		$download_url = $this->resolve_release_asset_url();
		if ( '' === $download_url ) {
			return $transient;
		}

		$compat = $this->get_plugin_data_from_zip( $download_url );
		$tested = is_array( $compat ) && isset( $compat['Tested up to'] ) ? (string) $compat['Tested up to'] : (string) ( $this->plugin['Tested up to'] ?? '6.6' );
		$requires = is_array( $compat ) && isset( $compat['Requires at least'] ) ? (string) $compat['Requires at least'] : (string) ( $this->plugin['Requires at least'] ?? '6.6' );
		$requires_php = is_array( $compat ) && isset( $compat['Requires PHP'] ) ? (string) $compat['Requires PHP'] : (string) ( $this->plugin['Requires PHP'] ?? '8.1' );
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $this->basename ] = (object) array(
			'slug'         => dirname( $this->basename ),
			'new_version'  => $new_version,
			'url'          => 'https://github.com/' . WSTP_GITHUB_REPO,
			'package'      => $download_url,
			'tested'       => $tested,
			'requires'     => $requires,
			'requires_php' => $requires_php,
		);

		return $transient;
	}

	/**
	 * Provide plugin-information popup data for updater UI.
	 *
	 * @param false|object|array $result Plugin info.
	 * @param string             $action Current plugins API action.
	 * @param object             $args Plugin info args.
	 * @return false|object|array
	 */
	public function plugin_popup( $result, string $action, $args ) {
		if ( ! $this->is_updates_enabled() || 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) ) {
			return $result;
		}

		$accepted_slugs = array( dirname( $this->basename ), $this->basename );
		if ( ! in_array( (string) $args->slug, $accepted_slugs, true ) ) {
			return $result;
		}
		if ( ! $this->github_response ) {
			return $result;
		}

		$changelog = isset( $this->github_response->body ) && is_string( $this->github_response->body ) ? $this->github_response->body : __( 'No changelog available.', 'we-subscribe-to-posts' );
		$version = isset( $this->github_response->tag_name ) ? (string) $this->github_response->tag_name : (string) ( $this->plugin['Version'] ?? '' );
		$download_link = $this->resolve_release_asset_url();

		return (object) array(
			'name'              => (string) ( $this->plugin['Name'] ?? 'We Subscribe To Posts' ),
			'slug'              => dirname( $this->basename ),
			'version'           => ltrim( $version, 'v' ),
			'author'            => (string) ( $this->plugin['AuthorName'] ?? '' ),
			'author_profile'    => (string) ( $this->plugin['AuthorURI'] ?? '' ),
			'homepage'          => 'https://github.com/' . WSTP_GITHUB_REPO,
			'short_description' => (string) ( $this->plugin['Description'] ?? '' ),
			'sections'          => array(
				'description' => (string) ( $this->plugin['Description'] ?? '' ),
				'changelog'   => nl2br( esc_html( $changelog ) ),
			),
			'download_link'     => $download_link,
		);
	}

	/**
	 * Keep plugin active after update and fix destination folder.
	 *
	 * @param bool  $response Installer response.
	 * @param array $hook_extra Extra upgrader data.
	 * @param array $result Install result.
	 * @return array
	 */
	public function after_install( $response, array $hook_extra, array $result ): array {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || $this->basename !== (string) $hook_extra['plugin'] ) {
			return $result;
		}

		$install_directory = plugin_dir_path( $this->file );
		if ( isset( $result['destination'] ) && is_string( $result['destination'] ) && $wp_filesystem ) {
			$wp_filesystem->move( $result['destination'], $install_directory );
			$result['destination'] = $install_directory;
		}

		$this->set_plugin_properties();
		if ( $this->active ) {
			activate_plugin( $this->basename );
		}

		return $result;
	}

	/**
	 * Purge cached updater data after update process.
	 *
	 * @return void
	 */
	public function purge( object $upgrader, array $hook_extra ): void {
		if (
			! isset( $hook_extra['action'], $hook_extra['type'], $hook_extra['plugins'] ) ||
			'update' !== (string) $hook_extra['action'] ||
			'plugin' !== (string) $hook_extra['type'] ||
			! is_array( $hook_extra['plugins'] ) ||
			! in_array( $this->basename, $hook_extra['plugins'], true )
		) {
			return;
		}

		delete_transient( 'wstp_github_release_zip_' . md5( WSTP_GITHUB_REPO ) );
	}

	/**
	 * Resolve preferred ZIP download URL from release assets.
	 *
	 * @return string
	 */
	private function resolve_release_asset_url(): string {
		if ( ! $this->github_response ) {
			return '';
		}

		$tag = isset( $this->github_response->tag_name ) ? (string) $this->github_response->tag_name : '';
		if ( '' === $tag ) {
			return '';
		}

		if ( isset( $this->github_response->assets ) && is_array( $this->github_response->assets ) ) {
			foreach ( $this->github_response->assets as $asset ) {
				if ( ! isset( $asset->name, $asset->browser_download_url ) ) {
					continue;
				}

				$name = (string) $asset->name;
				$url  = (string) $asset->browser_download_url;
				if ( 'we-subscribe-to-posts.zip' !== $name ) {
					continue;
				}
				if ( ! preg_match( '#^https://github\.com/' . preg_quote( WSTP_GITHUB_REPO, '#' ) . '/releases/download/' . preg_quote( $tag, '#' ) . '/we-subscribe-to-posts\.zip$#', $url ) ) {
					continue;
				}
				return $url;
			}
		}

		return '';
	}

	/**
	 * Read release zip plugin headers with basic safety checks.
	 *
	 * @param string $zip_url ZIP URL.
	 * @return array<string,string>|false
	 */
	private function get_plugin_data_from_zip( string $zip_url ) {
		if ( ! preg_match( '#^https://github\.com/' . preg_quote( WSTP_GITHUB_REPO, '#' ) . '/releases/download/v?\d+\.\d+\.\d+/we-subscribe-to-posts\.zip$#', $zip_url ) ) {
			return false;
		}

		$cache_key = 'wstp_github_release_zip_' . md5( $zip_url );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : false;
		}

		$temp_file = download_url( $zip_url, 300 );
		if ( is_wp_error( $temp_file ) ) {
			return false;
		}

		$file_size = filesize( $temp_file );
		if ( false === $file_size || $file_size > 50 * 1024 * 1024 ) {
			wp_delete_file( $temp_file );
			return false;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $temp_file ) ) {
			wp_delete_file( $temp_file );
			return false;
		}
		if ( $zip->numFiles > 10000 ) {
			$zip->close();
			wp_delete_file( $temp_file );
			return false;
		}

		$plugin_file = null;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( is_string( $name ) && preg_match( '#^we-subscribe-to-posts/we-subscribe-to-posts\.php$#', $name ) ) {
				$plugin_file = $name;
				break;
			}
		}

		if ( ! $plugin_file ) {
			$zip->close();
			wp_delete_file( $temp_file );
			return false;
		}

		$content = $zip->getFromName( $plugin_file, 8192 );
		$zip->close();
		wp_delete_file( $temp_file );
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}
		if ( ! preg_match( '/Plugin Name:\s*We Subscribe To Posts/mi', $content ) ) {
			return false;
		}

		$headers = $this->parse_plugin_headers( $content );
		if ( false === $headers ) {
			return false;
		}

		set_transient( $cache_key, $headers, 3600 );
		return $headers;
	}

	/**
	 * Parse plugin header fields from file content.
	 *
	 * @param string $file_content File content.
	 * @return array<string,string>|false
	 */
	private function parse_plugin_headers( string $file_content ) {
		$header = substr( $file_content, 0, 8192 );
		$end = strpos( $header, '*/' );
		if ( false !== $end ) {
			$header = substr( $header, 0, $end );
		}

		$map = array(
			'Plugin Name',
			'Version',
			'Requires at least',
			'Tested up to',
			'Requires PHP',
		);

		$parsed = array();
		foreach ( $map as $field ) {
			if ( preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $header, $matches ) && isset( $matches[1] ) ) {
				$parsed[ $field ] = trim( $matches[1] );
			}
		}

		return ! empty( $parsed ) ? $parsed : false;
	}
}
