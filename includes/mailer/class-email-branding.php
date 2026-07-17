<?php
/**
 * Digest email branding settings and color resolution.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Stores header, footer, and color settings for digest emails.
 */
final class Email_Branding {
	/**
	 * Option key for branding settings.
	 */
	public const OPTION_KEY = 'wstp_digest_branding';

	/**
	 * Theme palette slugs used by this plugin.
	 *
	 * @var array<int,string>
	 */
	private const PALETTE_SLUGS = array(
		'base',
		'base-two',
		'base-three',
		'accent',
		'accent-two',
		'accent-three',
	);

	/**
	 * Get normalized branding settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings                   = wp_parse_args( $stored, self::get_defaults() );
		$settings                   = self::migrate_legacy_settings( $settings );
		$settings['palette_colors'] = self::merge_theme_into_palette(
			isset( $settings['palette_colors'] ) && is_array( $settings['palette_colors'] )
				? $settings['palette_colors']
				: array()
		);
		$settings                   = self::sync_logo_fields( $settings );

		return $settings;
	}

	/**
	 * Sanitize and persist branding settings.
	 *
	 * @param mixed $value Raw settings.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $value ): array {
		$input    = is_array( $value ) ? $value : array();
		$defaults = self::get_defaults();
		$title    = sanitize_text_field( (string) ( $input['header_title'] ?? $defaults['header_title'] ) );
		$tagline  = sanitize_text_field( (string) ( $input['header_tagline'] ?? $defaults['header_tagline'] ) );

		$settings = array(
			'color_source'             => 'custom',
			'header_logo_id'           => absint( $input['header_logo_id'] ?? 0 ),
			'header_logo_url'          => esc_url_raw( (string) ( $input['header_logo_url'] ?? $defaults['header_logo_url'] ) ),
			'header_logo_link_url'     => esc_url_raw( (string) ( $input['header_logo_link_url'] ?? $defaults['header_logo_link_url'] ) ),
			'header_logo_alt'          => sanitize_text_field( (string) ( $input['header_logo_alt'] ?? $defaults['header_logo_alt'] ) ),
			'header_logo_width'        => self::sanitize_logo_width( $input['header_logo_width'] ?? $defaults['header_logo_width'] ),
			'header_html'              => self::sanitize_header_html( self::identity_to_header_html( $title, $tagline ) ),
			'header_title'             => $title,
			'header_tagline'           => $tagline,
			'footer_identity'          => sanitize_text_field( (string) ( $input['footer_identity'] ?? $defaults['footer_identity'] ) ),
			'footer_tagline'           => sanitize_text_field( (string) ( $input['footer_tagline'] ?? $defaults['footer_tagline'] ) ),
			'footer_address'           => sanitize_textarea_field( (string) ( $input['footer_address'] ?? $defaults['footer_address'] ) ),
			'footer_phone'             => sanitize_text_field( (string) ( $input['footer_phone'] ?? $defaults['footer_phone'] ) ),
			'footer_primary_url'       => esc_url_raw( (string) ( $input['footer_primary_url'] ?? $defaults['footer_primary_url'] ) ),
			'footer_primary_label'     => sanitize_text_field( (string) ( $input['footer_primary_label'] ?? $defaults['footer_primary_label'] ) ),
			'footer_secondary_url'     => esc_url_raw( (string) ( $input['footer_secondary_url'] ?? $defaults['footer_secondary_url'] ) ),
			'footer_secondary_label'   => sanitize_text_field( (string) ( $input['footer_secondary_label'] ?? $defaults['footer_secondary_label'] ) ),
			'footer_privacy_url'       => esc_url_raw( (string) ( $input['footer_privacy_url'] ?? $defaults['footer_privacy_url'] ) ),
			'footer_imprint_url'       => esc_url_raw( (string) ( $input['footer_imprint_url'] ?? $defaults['footer_imprint_url'] ) ),
			'footer_unsubscribe_label' => sanitize_text_field( (string) ( $input['footer_unsubscribe_label'] ?? $defaults['footer_unsubscribe_label'] ) ),
		);

		$settings = self::sync_logo_fields( $settings );

		$custom_colors = isset( $input['palette_colors'] ) && is_array( $input['palette_colors'] )
			? $input['palette_colors']
			: array();

		$settings['palette_colors'] = array();
		foreach ( self::PALETTE_SLUGS as $slug ) {
			$fallback                            = (string) ( $defaults['palette_colors'][ $slug ] ?? self::fallback_palette_colors()[ $slug ] );
			$settings['palette_colors'][ $slug ] = self::sanitize_color( $custom_colors[ $slug ] ?? $fallback, $fallback );
		}

		return $settings;
	}

	/**
	 * Resolve active colors for rendering.
	 *
	 * @return array<string,string>
	 */
	public static function get_resolved_colors(): array {
		$settings   = self::get_settings();
		$palette    = self::get_active_palette_colors( $settings );
		$defaults   = self::default_colors();
		$content_bg = $palette['base-two'] ?? $defaults['content_bg'];
		$button     = $palette['accent-two'] ?? $defaults['accent'];

		return array(
			'body_bg'    => $palette['base'] ?? $defaults['body_bg'],
			'content_bg' => $content_bg,
			'text'       => self::resolve_heading_color( $palette, $content_bg, $defaults['text'] ),
			'muted'      => $palette['accent'] ?? $defaults['muted'],
			'accent'     => $button,
			'link'       => $button,
		);
	}

	/**
	 * Get theme palette entries for admin UI.
	 *
	 * @return array<int,array{slug:string,color:string,name:string}>
	 */
	public static function get_theme_palette_preview(): array {
		$entries = array();

		foreach ( self::collect_theme_palette() as $slug => $color ) {
			$entries[] = array(
				'slug'  => $slug,
				'name'  => $slug,
				'color' => $color,
			);
		}

		return $entries;
	}

	/**
	 * Build a full palette from the active theme for form reset.
	 *
	 * @return array<string,string>
	 */
	public static function get_theme_palette_for_storage(): array {
		$theme    = self::collect_theme_palette();
		$fallback = self::fallback_palette_colors();
		$colors   = array();

		foreach ( self::PALETTE_SLUGS as $slug ) {
			$colors[ $slug ] = $theme[ $slug ] ?? $fallback[ $slug ];
		}

		$content_bg             = $colors['base-two'];
		$colors['accent-three'] = self::pick_darkest_readable(
			array(
				$theme['base-three'] ?? '',
				$theme['accent'] ?? '',
				$theme['accent-two'] ?? '',
				$theme['accent-three'] ?? '',
			),
			$content_bg,
			$fallback['accent-three']
		);

		return $colors;
	}

	/**
	 * Whether any supported theme palette colors were detected.
	 *
	 * @return bool
	 */
	public static function has_theme_palette(): bool {
		return ! empty( self::collect_theme_palette() );
	}

	/**
	 * Resolve the font family used by header/footer blocks.
	 *
	 * Reads the compiled digest HTML first, then MJML source, then falls back.
	 *
	 * @return string
	 */
	public static function resolve_font_family(): string {
		static $resolved = null;

		if ( is_string( $resolved ) ) {
			return $resolved;
		}

		$html = get_option( 'wstp_digest_html_template', '' );
		if ( is_string( $html ) && preg_match( '/font-family:\s*([^;}{]+)/i', $html, $matches ) ) {
			$font = self::sanitize_font_family( (string) $matches[1] );
			if ( '' !== $font ) {
				$resolved = $font;
				return $resolved;
			}
		}

		$mjml = get_option( 'wstp_digest_mjml_template', '' );
		if ( is_string( $mjml ) && preg_match( '/font-family="([^"]+)"/i', $mjml, $matches ) ) {
			$font = self::sanitize_font_family( (string) $matches[1] );
			if ( '' !== $font ) {
				$resolved = $font;
				return $resolved;
			}
		}

		$resolved = 'Arial, Helvetica, sans-serif';
		return $resolved;
	}

	/**
	 * Sanitize a font-family value for inline CSS.
	 *
	 * @param string $font Raw font-family value.
	 * @return string
	 */
	private static function sanitize_font_family( string $font ): string {
		$font = wp_strip_all_tags( $font );
		$font = preg_replace( '/\s+/', ' ', $font );
		$font = is_string( $font ) ? trim( $font ) : '';

		if ( '' === $font || ! preg_match( "/^[a-zA-Z0-9,'\"\\-\\s.]+$/", $font ) ) {
			return '';
		}

		return trim( $font, " \t\n\r\0\x0B," );
	}

	/**
	 * Wrap branding HTML in the shared email shell.
	 *
	 * Horizontal/vertical spacing is owned by the wrapping mj-section or visual
	 * block padding — not by this shell — so equal paddingX yields equal content width.
	 *
	 * @param string $inner_html Inner markup.
	 * @param string $region     Region identifier (header|footer|notice). Unused; kept for call-site clarity.
	 * @return string
	 */
	public static function wrap_region( string $inner_html, string $region ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( '' === trim( $inner_html ) ) {
			return '';
		}

		// No background here — the wrapping mj-section / visual block owns section colors.
		return '<!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;" width="100%"><tr><td style="padding:0;text-align:center;"><![endif]-->'
			. '<div style="margin:0;width:100%;">'
			. '<table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;background:transparent;background-color:transparent;">'
			. '<tbody><tr><td style="padding:0;text-align:center;background:transparent;">'
			. $inner_html
			. '</td></tr></tbody></table></div>'
			. '<!--[if mso | IE]></td></tr></table><![endif]-->';
	}

	/**
	 * Default branding settings.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_defaults(): array {
		return array(
			'color_source'             => 'theme',
			'palette_colors'           => self::fallback_palette_colors(),
			'header_logo_id'           => 0,
			'header_logo_url'          => '',
			'header_logo_link_url'     => home_url( '/' ),
			'header_logo_alt'          => '',
			'header_logo_width'        => 280,
			'header_html'              => '',
			'header_title'             => '',
			'header_tagline'           => '',
			'footer_identity'          => '',
			'footer_tagline'           => '',
			'footer_address'           => '',
			'footer_phone'             => '',
			'footer_primary_url'       => home_url( '/' ),
			'footer_primary_label'     => __( 'Website', 'we-subscribe-to-posts' ),
			'footer_secondary_url'     => '',
			'footer_secondary_label'   => '',
			'footer_privacy_url'       => '',
			'footer_imprint_url'       => '',
			'footer_unsubscribe_label' => __( 'Unsubscribe instantly', 'we-subscribe-to-posts' ),
		);
	}

	/**
	 * Default fallback colors for email roles.
	 *
	 * @return array<string,string>
	 */
	private static function default_colors(): array {
		$palette = self::fallback_palette_colors();

		return array(
			'body_bg'    => $palette['base'],
			'content_bg' => $palette['base-two'],
			'text'       => $palette['accent-three'],
			'muted'      => $palette['accent'],
			'accent'     => $palette['accent-two'],
			'link'       => $palette['accent-two'],
		);
	}

	/**
	 * Plugin fallback palette when theme colors are unavailable.
	 *
	 * @return array<string,string>
	 */
	private static function fallback_palette_colors(): array {
		return array(
			'base'         => '#f3f4f6',
			'base-two'     => '#ffffff',
			'base-three'   => '#111111',
			'accent'       => '#0073aa',
			'accent-two'   => '#555555',
			'accent-three' => '#1a1a1a',
		);
	}

	/**
	 * Resolve palette colors for the active color source.
	 *
	 * @param array<string,mixed> $settings Branding settings.
	 * @return array<string,string>
	 */
	private static function get_active_palette_colors( array $settings ): array {
		$fallback = self::fallback_palette_colors();
		$theme    = self::collect_theme_palette();
		$stored   = isset( $settings['palette_colors'] ) && is_array( $settings['palette_colors'] )
			? $settings['palette_colors']
			: array();
		$colors   = array();

		foreach ( self::PALETTE_SLUGS as $slug ) {
			$raw = isset( $stored[ $slug ] ) ? (string) $stored[ $slug ] : '';
			if ( '' !== trim( $raw ) ) {
				$colors[ $slug ] = self::sanitize_color( $raw, $fallback[ $slug ] );
				continue;
			}

			if ( isset( $theme[ $slug ] ) ) {
				$colors[ $slug ] = $theme[ $slug ];
				continue;
			}

			$colors[ $slug ] = $fallback[ $slug ];
		}

		return $colors;
	}

	/**
	 * Prefill empty palette slots from the active theme.
	 *
	 * @param array<string,string> $palette Stored palette colors.
	 * @return array<string,string>
	 */
	private static function merge_theme_into_palette( array $palette ): array {
		$fallback = self::fallback_palette_colors();
		$theme    = self::collect_theme_palette();
		$merged   = array();

		foreach ( self::PALETTE_SLUGS as $slug ) {
			$raw = isset( $palette[ $slug ] ) ? (string) $palette[ $slug ] : '';
			if ( '' !== trim( $raw ) ) {
				$merged[ $slug ] = self::sanitize_color( $raw, $fallback[ $slug ] );
				continue;
			}

			$merged[ $slug ] = $theme[ $slug ] ?? $fallback[ $slug ];
		}

		$content_bg = $merged['base-two'] ?? $fallback['base-two'];
		if ( ! self::is_dark_enough( $merged['accent-three'] ?? '', $content_bg ) ) {
			$merged['accent-three'] = self::pick_darkest_readable(
				array(
					$theme['base-three'] ?? '',
					$theme['accent'] ?? '',
					$theme['accent-two'] ?? '',
					$merged['base-three'] ?? '',
					$merged['accent'] ?? '',
					$merged['accent-two'] ?? '',
				),
				$content_bg,
				$fallback['accent-three']
			);
		}

		return $merged;
	}

	/**
	 * Resolve a dark heading color from the palette / theme.
	 *
	 * Prefers accent-three when it already has enough contrast; otherwise
	 * picks the darkest readable theme/palette color.
	 *
	 * @param array<string,string> $palette Active palette colors.
	 * @param string               $content_bg Content background.
	 * @param string               $fallback Fallback heading color.
	 * @return string
	 */
	private static function resolve_heading_color( array $palette, string $content_bg, string $fallback ): string {
		$heading = isset( $palette['accent-three'] ) ? (string) $palette['accent-three'] : '';
		if ( self::is_dark_enough( $heading, $content_bg ) ) {
			return self::sanitize_color( $heading, $fallback );
		}

		return self::pick_darkest_readable(
			array(
				$palette['base-three'] ?? '',
				$palette['accent'] ?? '',
				$palette['accent-two'] ?? '',
				$heading,
			),
			$content_bg,
			$fallback
		);
	}

	/**
	 * Pull legacy branding values from wstp_settings once.
	 *
	 * Only runs before the branding option exists. Otherwise intentionally
	 * cleared fields (e.g. removed logo) would be restored from legacy data.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 * @return array<string,mixed>
	 */
	private static function migrate_legacy_settings( array $settings ): array {
		// Branding option already saved — never rehydrate cleared fields from legacy.
		if ( false !== get_option( self::OPTION_KEY, false ) ) {
			return self::migrate_legacy_color_fields( $settings );
		}

		$legacy = get_option( 'wstp_settings', array() );
		if ( ! is_array( $legacy ) ) {
			return self::migrate_legacy_color_fields( $settings );
		}

		$map = array(
			'header_logo_url'          => 'header_logo_url',
			'header_logo_link_url'     => 'header_logo_link_url',
			'header_logo_alt'          => 'header_logo_alt',
			'header_logo_width'        => 'header_logo_width',
			'header_title'             => 'header_title',
			'header_tagline'           => 'header_tagline',
			'footer_identity'          => 'footer_identity',
			'footer_tagline'           => 'footer_tagline',
			'footer_address'           => 'footer_address',
			'footer_phone'             => 'footer_phone',
			'footer_primary_url'       => 'footer_primary_url',
			'footer_primary_label'     => 'footer_primary_label',
			'footer_secondary_url'     => 'footer_secondary_url',
			'footer_secondary_label'   => 'footer_secondary_label',
			'footer_privacy_url'       => 'footer_privacy_url',
			'footer_imprint_url'       => 'footer_imprint_url',
			'footer_unsubscribe_label' => 'footer_unsubscribe_intro',
		);

		foreach ( $map as $target => $legacy_key ) {
			if ( isset( $settings[ $target ] ) && '' !== (string) $settings[ $target ] ) {
				continue;
			}
			if ( isset( $legacy[ $legacy_key ] ) && '' !== (string) $legacy[ $legacy_key ] ) {
				$settings[ $target ] = $legacy[ $legacy_key ];
			}
		}

		return self::migrate_legacy_color_fields( $settings );
	}

	/**
	 * Map old semantic color fields to palette slots.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 * @return array<string,mixed>
	 */
	private static function migrate_legacy_color_fields( array $settings ): array {
		if ( ! isset( $settings['palette_colors'] ) || ! is_array( $settings['palette_colors'] ) ) {
			$settings['palette_colors'] = self::fallback_palette_colors();
		}

		$legacy_map = array(
			'base'         => 'color_body_bg',
			'base-two'     => 'color_content_bg',
			'accent-three' => 'color_text',
			'accent-two'   => 'color_muted',
			'accent'       => 'color_accent',
			'base-three'   => 'color_text',
		);

		foreach ( $legacy_map as $slug => $legacy_key ) {
			if ( isset( $settings['palette_colors'][ $slug ] ) && '' !== (string) $settings['palette_colors'][ $slug ] ) {
				continue;
			}
			if ( isset( $settings[ $legacy_key ] ) && '' !== (string) $settings[ $legacy_key ] ) {
				$settings['palette_colors'][ $slug ] = (string) $settings[ $legacy_key ];
			}
		}

		return $settings;
	}

	/**
	 * Collect raw theme palette entries from all supported sources.
	 *
	 * @return array<int,array{slug:string,name:string,color:string}>
	 */
	private static function collect_theme_palette_entries(): array {
		$entries = array();

		$append_entries = static function ( array $palette_entries ) use ( &$entries ): void {
			foreach ( $palette_entries as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['color'] ) ) {
					continue;
				}

				$slug = isset( $entry['slug'] ) ? (string) $entry['slug'] : '';
				if ( '' === trim( $slug ) ) {
					continue;
				}

				$entries[] = array(
					'slug'  => $slug,
					'name'  => isset( $entry['name'] ) ? (string) $entry['name'] : $slug,
					'color' => (string) $entry['color'],
				);
			}
		};

		if ( function_exists( 'wp_get_global_settings' ) ) {
			$settings = wp_get_global_settings();
			if ( isset( $settings['color']['palette']['theme'] ) && is_array( $settings['color']['palette']['theme'] ) ) {
				$append_entries( $settings['color']['palette']['theme'] );
			}
		}

		if ( class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			$theme_json = \WP_Theme_JSON_Resolver::get_theme_data();
			$settings   = $theme_json->get_settings();
			if ( isset( $settings['color']['palette'] ) && is_array( $settings['color']['palette'] ) ) {
				$append_entries( $settings['color']['palette'] );
			}
		}

		if ( current_theme_supports( 'editor-color-palette' ) ) {
			$support = get_theme_support( 'editor-color-palette' );
			if ( is_array( $support ) && isset( $support[0] ) && is_array( $support[0] ) ) {
				$append_entries( $support[0] );
			}
		}

		$theme_json_paths = array(
			get_stylesheet_directory() . '/theme.json',
			get_template_directory() . '/theme.json',
		);

		foreach ( array_unique( $theme_json_paths ) as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}

			// Local theme.json on disk (not a remote URL).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$decoded = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			if ( isset( $decoded['settings']['color']['palette'] ) && is_array( $decoded['settings']['color']['palette'] ) ) {
				$append_entries( $decoded['settings']['color']['palette'] );
			}
		}

		return $entries;
	}

	/**
	 * Collect theme palette colors keyed by slug.
	 *
	 * @return array<string,string>
	 */
	private static function collect_theme_palette(): array {
		$entries = self::collect_theme_palette_entries();
		$by_slug = array();

		foreach ( $entries as $entry ) {
			$slug = self::canonical_palette_slug( $entry['slug'] );
			if ( ! self::is_supported_palette_slug( $slug ) ) {
				continue;
			}

			$color = self::normalize_color_direct( $entry['color'] );
			if ( '' !== $color ) {
				$by_slug[ $slug ] = $color;
			}
		}

		foreach ( $entries as $entry ) {
			$slug = self::canonical_palette_slug( $entry['slug'] );
			if ( ! self::is_supported_palette_slug( $slug ) || isset( $by_slug[ $slug ] ) ) {
				continue;
			}

			$resolved = self::resolve_preset_color_reference( $entry['color'], $by_slug );
			if ( '' !== $resolved ) {
				$by_slug[ $slug ] = $resolved;
			}
		}

		return $by_slug;
	}

	/**
	 * Map theme palette slug variants to supported plugin slugs.
	 *
	 * @param string $slug Raw palette slug.
	 * @return string
	 */
	private static function canonical_palette_slug( string $slug ): string {
		$slug = sanitize_key( str_replace( array( '_', ' ' ), '-', $slug ) );

		$aliases = array(
			'base-2'   => 'base-two',
			'base2'    => 'base-two',
			'base-3'   => 'base-three',
			'base3'    => 'base-three',
			'accent-2' => 'accent-two',
			'accent2'  => 'accent-two',
			'accent-3' => 'accent-three',
			'accent3'  => 'accent-three',
		);

		return $aliases[ $slug ] ?? $slug;
	}

	/**
	 * Resolve a theme.json preset color reference.
	 *
	 * @param string                $value Raw color value.
	 * @param array<string,string>  $palette Known palette colors.
	 * @return string
	 */
	private static function resolve_preset_color_reference( string $value, array $palette ): string {
		$value = trim( $value );
		if ( ! str_starts_with( $value, 'var:preset|color|' ) ) {
			return '';
		}

		$parts = explode( '|', $value );
		$slug  = end( $parts );
		if ( ! is_string( $slug ) || '' === $slug ) {
			return '';
		}

		$canonical = self::canonical_palette_slug( $slug );
		return $palette[ $canonical ] ?? '';
	}

	/**
	 * Check whether a palette slug belongs to the supported theme set.
	 *
	 * @param string $slug Palette slug.
	 * @return bool
	 */
	private static function is_supported_palette_slug( string $slug ): bool {
		return in_array( $slug, self::PALETTE_SLUGS, true );
	}

	/**
	 * Normalize a color value.
	 *
	 * @param string $value Raw color.
	 * @return string
	 */
	private static function normalize_color( string $value ): string {
		$direct = self::normalize_color_direct( $value );
		if ( '' !== $direct ) {
			return $direct;
		}

		return self::resolve_preset_color_reference( $value, self::collect_theme_palette() );
	}

	/**
	 * Normalize a direct color value without preset resolution.
	 *
	 * @param string $value Raw color.
	 * @return string
	 */
	private static function normalize_color_direct( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$hex = sanitize_hex_color( $value );
		if ( $hex ) {
			return $hex;
		}

		if ( preg_match( '/^(rgb|rgba|hsl|hsla)\(/', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Sanitize a color value with fallback.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $fallback Fallback color.
	 * @return string
	 */
	private static function sanitize_color( $value, string $fallback ): string {
		$normalized = self::normalize_color( (string) $value );
		if ( '' !== $normalized ) {
			return $normalized;
		}

		$fallback_normalized = self::normalize_color( $fallback );
		return '' !== $fallback_normalized ? $fallback_normalized : '#000000';
	}

	/**
	 * Whether a foreground color is dark enough on the given background.
	 *
	 * @param string $foreground Foreground color.
	 * @param string $background Background color.
	 * @param float  $min_ratio Minimum contrast ratio.
	 * @return bool
	 */
	private static function is_dark_enough( string $foreground, string $background, float $min_ratio = 4.5 ): bool {
		$foreground = self::normalize_color( $foreground );
		$background = self::normalize_color( $background );
		if ( '' === $foreground || '' === $background ) {
			return false;
		}

		$ratio = self::contrast_ratio( $foreground, $background );
		return null !== $ratio && $ratio >= $min_ratio;
	}

	/**
	 * Pick the darkest candidate that still has enough contrast.
	 *
	 * @param array<int,string> $candidates Color candidates.
	 * @param string            $background Background color.
	 * @param string            $fallback Fallback color.
	 * @param float             $min_ratio Minimum WCAG contrast ratio.
	 * @return string
	 */
	private static function pick_darkest_readable( array $candidates, string $background, string $fallback, float $min_ratio = 4.5 ): string {
		$background = self::normalize_color( $background );
		if ( '' === $background ) {
			$background = '#ffffff';
		}

		$best     = '';
		$best_lum = null;

		foreach ( $candidates as $candidate ) {
			$color = self::normalize_color( (string) $candidate );
			if ( '' === $color ) {
				continue;
			}

			$ratio = self::contrast_ratio( $color, $background );
			if ( null === $ratio || $ratio < $min_ratio ) {
				continue;
			}

			$luminance = self::relative_luminance( $color );
			if ( null === $luminance ) {
				continue;
			}

			if ( null === $best_lum || $luminance < $best_lum ) {
				$best     = $color;
				$best_lum = $luminance;
			}
		}

		if ( '' !== $best ) {
			return $best;
		}

		$fallback_color = self::normalize_color( $fallback );
		return '' !== $fallback_color ? $fallback_color : '#111111';
	}

	/**
	 * Calculate WCAG contrast ratio between two colors.
	 *
	 * @param string $foreground Foreground color.
	 * @param string $background Background color.
	 * @return float|null
	 */
	private static function contrast_ratio( string $foreground, string $background ): ?float {
		$foreground_lum = self::relative_luminance( $foreground );
		$background_lum = self::relative_luminance( $background );

		if ( null === $foreground_lum || null === $background_lum ) {
			return null;
		}

		$lighter = max( $foreground_lum, $background_lum );
		$darker  = min( $foreground_lum, $background_lum );

		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}

	/**
	 * Calculate relative luminance for a color.
	 *
	 * @param string $color Color value.
	 * @return float|null
	 */
	private static function relative_luminance( string $color ): ?float {
		$rgb = self::color_to_rgb( $color );
		if ( null === $rgb ) {
			return null;
		}

		$channels = array();
		foreach ( $rgb as $channel ) {
			$normalized = $channel / 255;
			$channels[] = $normalized <= 0.03928
				? $normalized / 12.92
				: pow( ( $normalized + 0.055 ) / 1.055, 2.4 );
		}

		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}

	/**
	 * Convert a supported color format to RGB components.
	 *
	 * @param string $color Color value.
	 * @return array{0:int,1:int,2:int}|null
	 */
	private static function color_to_rgb( string $color ): ?array {
		$color = self::normalize_color_direct( $color );
		if ( '' === $color ) {
			return null;
		}

		if ( str_starts_with( $color, '#' ) ) {
			$hex = ltrim( $color, '#' );
			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			if ( 6 !== strlen( $hex ) ) {
				return null;
			}

			return array(
				(int) hexdec( substr( $hex, 0, 2 ) ),
				(int) hexdec( substr( $hex, 2, 2 ) ),
				(int) hexdec( substr( $hex, 4, 2 ) ),
			);
		}

		if ( preg_match( '/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/', $color, $matches ) ) {
			return array(
				(int) round( (float) $matches[1] ),
				(int) round( (float) $matches[2] ),
				(int) round( (float) $matches[3] ),
			);
		}

		return null;
	}

	/**
	 * Build header HTML from name + slogan (no site fallbacks).
	 *
	 * @param string $title   Brand name.
	 * @param string $tagline Slogan / tagline.
	 * @return string
	 */
	public static function identity_to_header_html( string $title, string $tagline ): string {
		$title   = trim( $title );
		$tagline = trim( $tagline );
		if ( '' === $title && '' === $tagline ) {
			return '';
		}

		$parts = array();
		if ( '' !== $title ) {
			$parts[] = '<h2>' . esc_html( $title ) . '</h2>';
		}
		if ( '' !== $tagline ) {
			$parts[] = '<p>' . esc_html( $tagline ) . '</p>';
		}

		return implode( '', $parts );
	}

	/**
	 * Resolve header text HTML when no logo.
	 *
	 * Prefers stored header_html; otherwise builds from name + slogan
	 * (site name / description when branding fields are empty).
	 *
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @return string
	 */
	public static function resolve_header_text_html( ?array $settings = null ): string {
		$settings = is_array( $settings ) ? $settings : self::get_settings();
		$html     = isset( $settings['header_html'] ) ? trim( (string) $settings['header_html'] ) : '';
		if ( '' !== $html ) {
			return self::sanitize_header_html( $html );
		}

		$identity = self::resolve_header_identity( $settings );
		return self::sanitize_header_html(
			self::identity_to_header_html( $identity['title'], $identity['tagline'] )
		);
	}

	/**
	 * Name + slogan for text headers (fills from site identity when unset).
	 *
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @return array{title:string,tagline:string}
	 */
	public static function resolve_header_identity( ?array $settings = null ): array {
		$settings = is_array( $settings ) ? $settings : self::get_settings();
		$title    = isset( $settings['header_title'] ) ? trim( (string) $settings['header_title'] ) : '';
		$tagline  = isset( $settings['header_tagline'] ) ? trim( (string) $settings['header_tagline'] ) : '';

		if ( '' === $title ) {
			$title = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
		if ( '' === $tagline ) {
			$tagline = wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES );
		}

		return array(
			'title'   => $title,
			'tagline' => $tagline,
		);
	}

	/**
	 * Sanitize email-safe rich HTML for the header text.
	 *
	 * @param mixed $html Raw HTML.
	 * @return string
	 */
	public static function sanitize_header_html( $html ): string {
		$html = is_string( $html ) ? $html : '';
		$html = wp_check_invalid_utf8( $html );

		$allowed = array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
				'style'  => true,
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'u'      => array(),
			'br'     => array(),
			'p'      => array(
				'style' => true,
				'align' => true,
			),
			'h1'     => array(
				'style' => true,
				'align' => true,
			),
			'h2'     => array(
				'style' => true,
				'align' => true,
			),
			'h3'     => array(
				'style' => true,
				'align' => true,
			),
			'span'   => array(
				'style' => true,
			),
		);

		return trim( wp_kses( $html, $allowed ) );
	}

	/**
	 * Inline email-safe styles for header rich text (headings, paragraphs, links).
	 *
	 * @param string $html            Sanitized HTML.
	 * @param string $font            Font family.
	 * @param string $text            Text color.
	 * @param string $link            Link color.
	 * @param string $align           left|center|right.
	 * @param bool   $underline_links Whether links use text-decoration:underline.
	 * @param int    $block_gap       Margin below each heading/paragraph (px), except last.
	 * @return string
	 */
	public static function style_header_html_for_email( string $html, string $font, string $text, string $link, string $align = 'center', bool $underline_links = false, int $block_gap = 4 ): string {
		$html  = self::sanitize_header_html( $html );
		$align = in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'center';
		if ( '' === $html ) {
			return '';
		}

		$block_gap  = max( 0, min( 48, $block_gap ) );
		$decoration = $underline_links ? 'underline' : 'none';
		$margin     = 'margin:0 0 ' . $block_gap . 'px;';

		$sizes = array(
			'h1' => array( 28, 700, '1.25' ),
			'h2' => array( 22, 700, '1.3' ),
			'h3' => array( 18, 700, '1.35' ),
			'p'  => array( 15, 400, '1.5' ),
		);

		foreach ( $sizes as $tag => $meta ) {
			list( $size, $weight, $line ) = $meta;
			$html                         = preg_replace_callback(
				'/<(' . $tag . ')(\s[^>]*)?>(.*?)<\/' . $tag . '>/is',
				static function ( array $matches ) use ( $tag, $size, $weight, $line, $font, $text, $align, $margin ): string {
					$attrs     = isset( $matches[2] ) ? (string) $matches[2] : '';
					$inner     = isset( $matches[3] ) ? (string) $matches[3] : '';
					$tag_align = $align;
					if ( preg_match( '/\balign=(["\'])(left|center|right)\1/i', $attrs, $am ) ) {
						$tag_align = strtolower( (string) $am[2] );
					} elseif ( preg_match( '/text-align\s*:\s*(left|center|right)/i', $attrs, $am ) ) {
						$tag_align = strtolower( (string) $am[1] );
					}
					$style = $margin . 'padding:0;font-family:' . esc_attr( $font ) . ';font-size:' . (int) $size . 'px;font-weight:' . (int) $weight . ';line-height:' . esc_attr( $line ) . ';color:' . esc_attr( $text ) . ';text-align:' . esc_attr( $tag_align ) . ';';
					return '<' . $tag . ' style="' . $style . '">' . $inner . '</' . $tag . '>';
				},
				$html
			);
			if ( ! is_string( $html ) ) {
				return '';
			}
		}

		// Zero margin on the last styled block so trailing gap does not inflate section padding.
		$needle = 'margin:0 0 ' . $block_gap . 'px;';
		$pos    = strrpos( $html, $needle );
		if ( false !== $pos ) {
			$html = substr_replace( $html, 'margin:0;', $pos, strlen( $needle ) );
		}

		// Bare text / inline-only fragments: wrap once.
		if ( ! preg_match( '/<(h[1-3]|p)\b/i', $html ) ) {
			$html = '<p style="margin:0;padding:0;font-family:' . esc_attr( $font ) . ';font-size:18px;font-weight:700;line-height:1.35;color:' . esc_attr( $text ) . ';text-align:' . esc_attr( $align ) . ';">' . $html . '</p>';
		}

		$styled = preg_replace_callback(
			'/<a\b([^>]*)>/i',
			static function ( array $matches ) use ( $link, $decoration ): string {
				$attrs = isset( $matches[1] ) ? (string) $matches[1] : '';
				$attrs = preg_replace( '/\sstyle=(["\']).*?\1/i', '', $attrs ) ?? $attrs;
				return '<a' . $attrs . ' style="color:' . esc_attr( $link ) . ';text-decoration:' . esc_attr( $decoration ) . ';">';
			},
			$html
		);

		return is_string( $styled ) ? $styled : $html;
	}

	/**
	 * Resolve the current header logo URL (prefers attachment ID so moves stay valid).
	 *
	 * @param array<string,mixed>|null $settings Optional settings bag.
	 * @return string
	 */
	public static function resolve_logo_url( ?array $settings = null ): string {
		$settings = is_array( $settings ) ? $settings : self::get_settings();
		$synced   = self::sync_logo_fields( $settings );
		return isset( $synced['header_logo_url'] ) ? (string) $synced['header_logo_url'] : '';
	}

	/**
	 * Keep logo attachment ID and URL in sync.
	 *
	 * A stale attachment ID must never override a newly posted logo URL (e.g. when
	 * the media picker updated the URL but the hidden ID field still had the old value).
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	private static function sync_logo_fields( array $settings ): array {
		$id  = isset( $settings['header_logo_id'] ) ? absint( $settings['header_logo_id'] ) : 0;
		$url = isset( $settings['header_logo_url'] ) ? trim( (string) $settings['header_logo_url'] ) : '';

		// Explicitly cleared — keep empty (do not resurrect from a stale ID).
		if ( $id <= 0 && '' === $url ) {
			$settings['header_logo_id']  = 0;
			$settings['header_logo_url'] = '';
			return $settings;
		}

		// Prefer an attachment resolved from the posted URL when it disagrees with $id.
		if ( '' !== $url ) {
			$url_id = attachment_url_to_postid( $url );
			if ( $url_id > 0 && $url_id !== $id ) {
				$id = $url_id;
			} elseif ( $id > 0 ) {
				$from_id = wp_get_attachment_image_url( $id, 'full' );
				if ( is_string( $from_id ) && '' !== $from_id && ! self::logo_urls_match( $url, $from_id ) ) {
					// URL changed but could not be mapped to an ID — trust the URL.
					$id = 0;
				}
			}
		}

		if ( $id > 0 ) {
			$from_id = wp_get_attachment_image_url( $id, 'full' );
			if ( is_string( $from_id ) && '' !== $from_id ) {
				$settings['header_logo_id']  = $id;
				$settings['header_logo_url'] = $from_id;
				return $settings;
			}
			$settings['header_logo_id'] = 0;
		}

		if ( '' !== $url ) {
			$maybe_id = attachment_url_to_postid( $url );
			if ( $maybe_id > 0 ) {
				$settings['header_logo_id'] = $maybe_id;
				$fresh                      = wp_get_attachment_image_url( $maybe_id, 'full' );
				if ( is_string( $fresh ) && '' !== $fresh ) {
					$settings['header_logo_url'] = $fresh;
					return $settings;
				}
			}
		}

		$settings['header_logo_id']  = 0;
		$settings['header_logo_url'] = '' !== $url ? esc_url_raw( $url ) : '';

		return $settings;
	}

	/**
	 * Whether two logo URLs refer to the same file (ignore size suffixes / query args).
	 *
	 * @param string $left Left URL.
	 * @param string $right Right URL.
	 * @return bool
	 */
	private static function logo_urls_match( string $left, string $right ): bool {
		$normalize = static function ( string $url ): string {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$path = rawurldecode( strtolower( $path ) );
			// Strip WP intermediate / scaled suffixes: image-300x200.jpg, image-scaled.jpg
			$path = preg_replace( '/-scaled(\.[a-z0-9]+)$/i', '$1', $path ) ?? $path;
			$path = preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $path ) ?? $path;
			return untrailingslashit( $path );
		};

		return $normalize( $left ) === $normalize( $right );
	}

	/**
	 * Logo URL for display (cache-busted when backed by an attachment).
	 *
	 * @param array<string,mixed>|null $settings Optional settings.
	 * @return string
	 */
	public static function resolve_logo_url_for_preview( ?array $settings = null ): string {
		$settings = is_array( $settings ) ? $settings : self::get_settings();
		$synced   = self::sync_logo_fields( $settings );
		$url      = isset( $synced['header_logo_url'] ) ? trim( (string) $synced['header_logo_url'] ) : '';
		if ( '' === $url ) {
			return '';
		}

		$id = isset( $synced['header_logo_id'] ) ? absint( $synced['header_logo_id'] ) : 0;
		if ( $id <= 0 ) {
			return $url;
		}

		$modified = get_post_modified_time( 'U', true, $id );
		if ( ! is_numeric( $modified ) ) {
			return $url;
		}

		return add_query_arg( 'wstp_ver', (string) (int) $modified, $url );
	}

	/**
	 * Sanitize logo width.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function sanitize_logo_width( $value ): int {
		$width = is_numeric( $value ) ? (int) $value : 280;
		if ( $width < 80 ) {
			return 80;
		}
		if ( $width > 560 ) {
			return 560;
		}
		return $width;
	}
}
