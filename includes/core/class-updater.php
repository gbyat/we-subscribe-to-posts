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
	 * Transient key for cached GitHub release payload.
	 */
	private const RELEASE_TRANSIENT = 'wstp_github_latest_release';

	/**
	 * Cache TTL for GitHub release payload (12 hours).
	 */
	private const RELEASE_CACHE_TTL = 43200;

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
	 * Plugin directory slug.
	 *
	 * @var string
	 */
	private $slug;

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
		$this->slug     = dirname( $this->basename );
		$this->active   = function_exists( 'is_plugin_active' ) ? is_plugin_active( $this->basename ) : true;

		add_action( 'admin_init', array( $this, 'set_plugin_properties' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_transient' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_popup' ), 20, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'purge' ), 10, 2 );
		add_action( 'in_plugin_update_message-' . $this->basename, array( $this, 'render_update_message' ), 10, 2 );
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
		$this->plugin = get_plugin_data( $this->file, false, false );
	}

	/**
	 * Ensure plugin headers are available.
	 *
	 * @return void
	 */
	private function ensure_plugin_properties(): void {
		if ( ! empty( $this->plugin ) ) {
			return;
		}
		$this->set_plugin_properties();
	}

	/**
	 * Ensure GitHub release payload is loaded (cached).
	 *
	 * @param bool $force_refresh Bypass transient cache.
	 * @return void
	 */
	private function ensure_github_response( bool $force_refresh = false ): void {
		if ( $this->github_response instanceof \stdClass ) {
			return;
		}

		if ( ! $force_refresh ) {
			$cached = get_transient( self::RELEASE_TRANSIENT );
			if ( is_object( $cached ) && isset( $cached->tag_name ) ) {
				$this->github_response = $cached;
				return;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . WSTP_GITHUB_REPO . '/releases/latest',
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
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
		set_transient( self::RELEASE_TRANSIENT, $parsed, self::RELEASE_CACHE_TTL );
	}

	/**
	 * Whether the given plugins_api slug refers to this plugin.
	 *
	 * @param string $request_slug Requested slug.
	 * @return bool
	 */
	private function is_our_slug( string $request_slug ): bool {
		$accepted = array(
			$this->slug,
			$this->basename,
			'we-subscribe-to-posts',
		);

		return in_array( $request_slug, $accepted, true );
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

		$this->ensure_plugin_properties();
		$this->ensure_github_response();

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

		$compat       = $this->get_plugin_data_from_zip( $download_url );
		$tested       = is_array( $compat ) && isset( $compat['Tested up to'] ) ? (string) $compat['Tested up to'] : (string) ( $this->plugin['Tested up to'] ?? '6.6' );
		$requires     = is_array( $compat ) && isset( $compat['Requires at least'] ) ? (string) $compat['Requires at least'] : (string) ( $this->plugin['Requires at least'] ?? '6.6' );
		$requires_php = is_array( $compat ) && isset( $compat['Requires PHP'] ) ? (string) $compat['Requires PHP'] : (string) ( $this->plugin['Requires PHP'] ?? '8.1' );
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$upgrade_notice = $this->extract_upgrade_notice( $this->get_release_body() );

		$transient->response[ $this->basename ] = (object) array(
			'slug'           => $this->slug,
			'plugin'         => $this->basename,
			'new_version'    => $new_version,
			'url'            => 'https://github.com/' . WSTP_GITHUB_REPO,
			'package'        => $download_url,
			'tested'         => $tested,
			'requires'       => $requires,
			'requires_php'   => $requires_php,
			'upgrade_notice' => $upgrade_notice,
		);

		return $transient;
	}

	/**
	 * Provide plugin-information popup data for updater UI.
	 *
	 * Never falls through to wordpress.org for this plugin when updates are enabled,
	 * so the details modal does not show "Plugin not found."
	 *
	 * @param false|object|array $result Plugin info.
	 * @param string             $action Current plugins API action.
	 * @param object             $args Plugin info args.
	 * @return false|object|array
	 */
	public function plugin_popup( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) ) {
			return $result;
		}
		if ( ! $this->is_our_slug( (string) $args->slug ) ) {
			return $result;
		}
		if ( ! $this->is_updates_enabled() ) {
			return $result;
		}

		$this->ensure_plugin_properties();
		$this->ensure_github_response();

		$version = ! empty( $this->plugin['Version'] ) ? (string) $this->plugin['Version'] : WSTP_VERSION;
		if ( $this->github_response && isset( $this->github_response->tag_name ) ) {
			$version = ltrim( (string) $this->github_response->tag_name, 'v' );
		}

		$download_link  = $this->resolve_release_asset_url();
		$changelog      = $this->build_changelog_html();
		$description    = (string) ( $this->plugin['Description'] ?? '' );
		$upgrade_notice = $this->extract_upgrade_notice( $this->get_release_body() );

		$sections = array(
			'description' => '' !== $description ? wpautop( esc_html( $description ) ) : '',
			'changelog'   => $changelog,
		);
		if ( '' !== $upgrade_notice ) {
			$sections['upgrade_notice'] = wpautop( esc_html( $upgrade_notice ) );
		}

		return (object) array(
			'name'              => (string) ( $this->plugin['Name'] ?? 'WE Subscribe To Posts' ),
			'slug'              => $this->slug,
			'version'           => $version,
			'author'            => '<a href="' . esc_url( (string) ( $this->plugin['AuthorURI'] ?? 'https://webentwicklerin.at' ) ) . '">' . esc_html( (string) ( $this->plugin['AuthorName'] ?? $this->plugin['Author'] ?? 'webentwicklerin' ) ) . '</a>',
			'author_profile'    => (string) ( $this->plugin['AuthorURI'] ?? 'https://webentwicklerin.at' ),
			'homepage'          => 'https://github.com/' . WSTP_GITHUB_REPO,
			'short_description' => $description,
			'sections'          => $sections,
			'download_link'     => $download_link,
			'tested'            => (string) ( $this->plugin['Tested up to'] ?? '' ),
			'requires'          => (string) ( $this->plugin['Requires at least'] ?? '' ),
			'requires_php'      => (string) ( $this->plugin['Requires PHP'] ?? '' ),
			'last_updated'      => $this->get_release_published_at(),
			'upgrade_notice'    => $upgrade_notice,
		);
	}

	/**
	 * Render upgrade notice under the plugin update row.
	 *
	 * @param array<string,mixed> $plugin_data Plugin data.
	 * @param object              $response Update response object.
	 * @return void
	 */
	public function render_update_message( $plugin_data, $response ): void {
		unset( $plugin_data );

		$notice = '';
		if ( is_object( $response ) && ! empty( $response->upgrade_notice ) ) {
			$notice = (string) $response->upgrade_notice;
		}
		if ( '' === $notice ) {
			$this->ensure_github_response();
			$notice = $this->extract_upgrade_notice( $this->get_release_body() );
		}
		if ( '' === $notice ) {
			return;
		}

		echo '<br /><strong>' . esc_html__( 'Upgrade notice:', 'we-subscribe-to-posts' ) . '</strong> ';
		echo esc_html( $notice );
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
	 * @param object              $upgrader Upgrader instance.
	 * @param array<string,mixed> $hook_extra Extra data.
	 * @return void
	 */
	public function purge( $upgrader, array $hook_extra ): void {
		unset( $upgrader );

		if (
			! isset( $hook_extra['action'], $hook_extra['type'], $hook_extra['plugins'] ) ||
			'update' !== (string) $hook_extra['action'] ||
			'plugin' !== (string) $hook_extra['type'] ||
			! is_array( $hook_extra['plugins'] ) ||
			! in_array( $this->basename, $hook_extra['plugins'], true )
		) {
			return;
		}

		delete_transient( self::RELEASE_TRANSIENT );
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
	 * Raw release body from GitHub.
	 *
	 * @return string
	 */
	private function get_release_body(): string {
		if ( ! $this->github_response || ! isset( $this->github_response->body ) || ! is_string( $this->github_response->body ) ) {
			return '';
		}

		return trim( $this->github_response->body );
	}

	/**
	 * Published-at timestamp from GitHub release.
	 *
	 * @return string
	 */
	private function get_release_published_at(): string {
		if ( ! $this->github_response || empty( $this->github_response->published_at ) ) {
			return '';
		}

		return gmdate( 'Y-m-d', strtotime( (string) $this->github_response->published_at ) );
	}

	/**
	 * Build HTML changelog for the plugin details modal.
	 *
	 * @return string
	 */
	private function build_changelog_html(): string {
		$body = $this->normalize_release_notes( $this->get_release_body() );

		if ( '' === $body ) {
			$body = $this->get_local_changelog_excerpt();
		}

		if ( '' === $body ) {
			$release_url = 'https://github.com/' . WSTP_GITHUB_REPO . '/releases';
			if ( $this->github_response && ! empty( $this->github_response->html_url ) ) {
				$release_url = (string) $this->github_response->html_url;
			}

			return '<p>' . esc_html__( 'No changelog available for this release.', 'we-subscribe-to-posts' ) . '</p>'
				. '<p><a href="' . esc_url( $release_url ) . '" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'View release on GitHub', 'we-subscribe-to-posts' )
				. '</a></p>';
		}

		return $this->format_notes_html( $body );
	}

	/**
	 * Drop empty/footer-only release note payloads.
	 *
	 * @param string $body Raw release body.
	 * @return string
	 */
	private function normalize_release_notes( string $body ): string {
		$body = trim( $body );
		if ( '' === $body ) {
			return '';
		}

		// Ignore Keep-a-Changelog footer-only lines such as "[1.1.2]: https://...".
		$without_refs = preg_replace( '/^\[[^\]]+\]:\s*https?:\/\/\S+\s*$/mi', '', $body );
		$without_refs = is_string( $without_refs ) ? trim( $without_refs ) : '';

		if ( '' === $without_refs || preg_match( '/^Release v?\d+\.\d+\.\d+$/i', $without_refs ) ) {
			return '';
		}

		return $without_refs;
	}

	/**
	 * Read a local CHANGELOG.md excerpt for the release version when GitHub body is empty.
	 *
	 * @return string
	 */
	private function get_local_changelog_excerpt(): string {
		$path = trailingslashit( dirname( $this->file ) ) . 'CHANGELOG.md';
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		if ( ! is_string( $content ) || '' === $content ) {
			return '';
		}

		$version = WSTP_VERSION;
		if ( $this->github_response && isset( $this->github_response->tag_name ) ) {
			$version = ltrim( (string) $this->github_response->tag_name, 'v' );
		}

		$escaped = preg_quote( $version, '/' );
		if ( ! preg_match( '/## \\[' . $escaped . '\\] - [0-9-]+\\s*([\\s\\S]*?)(?=## \\[|$)/', $content, $matches ) ) {
			return '';
		}

		return $this->normalize_release_notes( trim( (string) $matches[1] ) );
	}

	/**
	 * Extract an Upgrade Notice section from release notes.
	 *
	 * @param string $body Release body markdown.
	 * @return string
	 */
	private function extract_upgrade_notice( string $body ): string {
		$body = $this->normalize_release_notes( $body );
		if ( '' === $body ) {
			return '';
		}

		if ( ! preg_match( '/^#{2,3}\\s*Upgrade Notice\\s*$([\\s\\S]*?)(?=^#{2,3}\\s|\\z)/mi', $body, $matches ) ) {
			return '';
		}

		$notice = trim( (string) $matches[1] );
		$notice = preg_replace( '/^[-*]\\s+/m', '', $notice );
		$notice = is_string( $notice ) ? trim( preg_replace( '/\\s+/', ' ', $notice ) ?? '' ) : '';

		return $notice;
	}

	/**
	 * Convert plain/markdown-ish notes to safe HTML.
	 *
	 * @param string $notes Notes text.
	 * @return string
	 */
	private function format_notes_html( string $notes ): string {
		$lines  = preg_split( '/\\r\\n|\\r|\\n/', $notes );
		$output = '';

		if ( ! is_array( $lines ) ) {
			return '<p>' . esc_html( $notes ) . '</p>';
		}

		$in_list = false;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				if ( $in_list ) {
					$output .= '</ul>';
					$in_list = false;
				}
				continue;
			}

			if ( preg_match( '/^(#{2,4})\\s+(.+)$/', $line, $heading ) ) {
				if ( $in_list ) {
					$output .= '</ul>';
					$in_list = false;
				}
				$level   = min( 4, max( 2, strlen( $heading[1] ) ) );
				$output .= '<h' . $level . '>' . esc_html( $heading[2] ) . '</h' . $level . '>';
				continue;
			}

			if ( preg_match( '/^[-*]\\s+(.+)$/', $line, $item ) ) {
				if ( ! $in_list ) {
					$output .= '<ul>';
					$in_list = true;
				}
				$output .= '<li>' . esc_html( $item[1] ) . '</li>';
				continue;
			}

			if ( $in_list ) {
				$output .= '</ul>';
				$in_list = false;
			}
			$output .= '<p>' . esc_html( $line ) . '</p>';
		}

		if ( $in_list ) {
			$output .= '</ul>';
		}

		return $output;
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
		$cached    = get_transient( $cache_key );
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
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive public API.
		if ( $zip->numFiles > 10000 ) {
			$zip->close();
			wp_delete_file( $temp_file );
			return false;
		}

		$plugin_file = null;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive public API.
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
		if ( ! preg_match( '/Plugin Name:\\s*WE Subscribe To Posts/mi', $content ) ) {
			return false;
		}

		$headers = $this->parse_plugin_headers( $content );
		if ( false === $headers ) {
			return false;
		}

		set_transient( $cache_key, $headers, HOUR_IN_SECONDS );
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
		$end    = strpos( $header, '*/' );
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
			if ( preg_match( '/^' . preg_quote( $field, '/' ) . ':\\s*(.+)$/mi', $header, $matches ) && isset( $matches[1] ) ) {
				$parsed[ $field ] = trim( $matches[1] );
			}
		}

		return ! empty( $parsed ) ? $parsed : false;
	}
}
