<?php
/**
 * Convert Gutenberg email blocks to MJML.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a restricted block set to digest MJML.
 */
final class Block_To_Mjml {
	/**
	 * Default MJML body / section content width (px). Used to convert column px → %.
	 */
	private const EMAIL_CONTENT_WIDTH = 600;

	/**
	 * Branding palette slugs.
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
	 * Legacy semantic aliases → branding palette slugs.
	 *
	 * @var array<string,string>
	 */
	private const COLOR_ALIASES = array(
		'body_bg'    => 'base',
		'content_bg' => 'base-two',
		'text'       => 'accent-three',
		'muted'      => 'accent',
		'link'       => 'accent-two',
		'button'     => 'accent-two',
	);

	/**
	 * Convert serialized block markup to a full MJML document.
	 *
	 * @param string $serialized Serialized blocks.
	 * @return string
	 */
	public static function convert( string $serialized ): string {
		$blocks  = parse_blocks( $serialized );
		$body_bg = '{{wstp:palette_base}}';
		$inner   = $blocks;

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || 'wstp/email-shell' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			$attrs   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$body_bg = self::resolve_color( $attrs['backgroundColor'] ?? 'base', 'base' );
			$inner   = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			break;
		}

		$body  = self::map_blocks( $inner );
		$style = self::detect_style_hints( $inner );
		$head  = self::build_head( $style );

		return "<mjml>\n{$head}\n  <mj-body background-color=\"{$body_bg}\">\n{$body}  </mj-body>\n</mjml>\n";
	}

	/**
	 * One-time hydrate of legacy empty header blocks from branding settings.
	 *
	 * @param string $blocks Serialized blocks.
	 * @return string
	 */
	public static function hydrate_header_block_from_branding( string $blocks ): string {
		if ( false === strpos( $blocks, 'wp:wstp/email-header' ) ) {
			return $blocks;
		}

		$parsed = parse_blocks( $blocks );
		if ( empty( $parsed ) ) {
			return $blocks;
		}

		$changed = false;
		$walk    = static function ( array &$list ) use ( &$walk, &$changed ): void {
			foreach ( $list as &$block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
				if ( 'wstp/email-header' === $name ) {
					$attrs           = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
					$has_inners      = ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] );
					$has_content_key = array_key_exists( 'content', $attrs );
					$logo_already    = ( ! empty( $attrs['logoUrl'] ) ) || ( ! empty( $attrs['logoId'] ) );

					if ( $has_inners || $logo_already ) {
						continue;
					}

					$branding = Email_Branding::get_settings();
					$logo_url = Email_Branding::resolve_logo_url( $branding );
					$logo_id  = isset( $branding['header_logo_id'] ) ? absint( $branding['header_logo_id'] ) : 0;

					// Branding logo only when this header has never been customized with text.
					if ( '' !== $logo_url && ! $has_content_key ) {
						$attrs['logoUrl']   = $logo_url;
						$attrs['logoId']    = $logo_id;
						$attrs['logoAlt']   = (string) ( $branding['header_logo_alt'] ?? '' );
						$attrs['logoWidth'] = (int) ( $branding['header_logo_width'] ?? 280 );
						$attrs['logoLink']  = (string) ( $branding['header_logo_link_url'] ?? '' );
						$attrs['content']   = '';
						$block['attrs']     = $attrs;
						$changed            = true;
						continue;
					}

					if ( $has_content_key && '' !== trim( (string) $attrs['content'] ) ) {
						$html = (string) $attrs['content'];
						$link = isset( $attrs['contentLink'] ) ? (string) $attrs['contentLink'] : '';
						$text = trim( wp_strip_all_tags( $html ) );
						if ( '' === $text ) {
							$text = 'Your brand';
						}
						$inner_html = $text;
						if ( '' !== $link && ! preg_match( '/<a\b/i', $html ) ) {
							$inner_html = '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>';
						} elseif ( preg_match( '/<a\b/i', $html ) ) {
							$inner_html = Email_Branding::sanitize_header_html( $html );
						} else {
							$inner_html = esc_html( $text );
						}

						$heading_html         = '<h2 class="wp-block-heading has-text-align-center">' . $inner_html . '</h2>';
						$block['attrs']       = array_merge(
							$attrs,
							array(
								'content'     => '',
								'contentLink' => '',
								'align'       => 'center',
							)
						);
						$block['innerBlocks'] = array(
							array(
								'blockName'    => 'core/heading',
								'attrs'        => array(
									'level'         => 2,
									'textAlign'     => 'center',
									'align'         => 'center',
									'textColor'     => isset( $attrs['textColor'] ) ? (string) $attrs['textColor'] : 'accent-three',
									'fontSize'      => isset( $attrs['fontSize'] ) ? (int) $attrs['fontSize'] : 22,
									'paddingTop'    => 0,
									'paddingBottom' => 4,
									'paddingX'      => 0,
									'content'       => $text,
								),
								'innerBlocks'  => array(),
								'innerHTML'    => $heading_html,
								'innerContent' => array( $heading_html ),
							),
						);
						$block['innerContent'] = array( null );
						$block['innerHTML']    = '';
						$changed               = true;
						continue;
					}

					// Default text header: name (linked) + optional slogan.
					$identity = Email_Branding::resolve_header_identity( $branding );
					$title    = $identity['title'] !== '' ? $identity['title'] : 'Your brand';
					$tagline  = $identity['tagline'];
					$link     = (string) ( $branding['header_logo_link_url'] ?? '' );
					if ( '' === $link ) {
						$link = home_url( '/' );
					}

					$heading_inner = '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
					$heading_html  = '<h2 class="wp-block-heading has-text-align-center">' . $heading_inner . '</h2>';
					$inners        = array(
						array(
							'blockName'    => 'core/heading',
							'attrs'        => array(
								'level'         => 2,
								'textAlign'     => 'center',
								'align'         => 'center',
								'textColor'     => 'accent-three',
								'fontSize'      => 22,
								'paddingTop'    => 0,
								'paddingBottom' => 4,
								'paddingX'      => 0,
								'content'       => $heading_inner,
							),
							'innerBlocks'  => array(),
							'innerHTML'    => $heading_html,
							'innerContent' => array( $heading_html ),
						),
					);

					if ( '' !== $tagline ) {
						$para_html = '<p class="has-text-align-center">' . esc_html( $tagline ) . '</p>';
						$inners[]  = array(
							'blockName'    => 'core/paragraph',
							'attrs'        => array(
								'align'         => 'center',
								'textAlign'     => 'center',
								'textColor'     => 'accent',
								'fontSize'      => 15,
								'paddingTop'    => 0,
								'paddingBottom' => 0,
								'paddingX'      => 0,
								'content'       => $tagline,
							),
							'innerBlocks'  => array(),
							'innerHTML'    => $para_html,
							'innerContent' => array( $para_html ),
						);
					}

					$block['attrs']        = array_merge(
						$attrs,
						array(
							'content'     => '',
							'contentLink' => '',
							'align'       => 'center',
						)
					);
					$block['innerBlocks']  = $inners;
					$block['innerContent'] = array_fill( 0, count( $inners ), null );
					$block['innerHTML']    = '';
					$changed               = true;
					continue;
				}
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};

		$walk( $parsed );
		if ( ! $changed ) {
			return $blocks;
		}

		$serialized = serialize_blocks( $parsed );
		return is_string( $serialized ) && '' !== $serialized ? $serialized : $blocks;
	}

	/**
	 * Default visual block template for a starter layout.
	 *
	 * @param string $layout stacked|image-left|minimal.
	 * @return string
	 */
	public static function default_blocks_for_layout( string $layout ): string {
		$layout = in_array( $layout, array( 'stacked', 'image-left', 'minimal' ), true ) ? $layout : 'stacked';

		$identity = Email_Branding::resolve_header_identity();
		$brand    = '' !== $identity['title'] ? $identity['title'] : 'Your brand';
		$slogan   = $identity['tagline'];
		$home     = home_url( '/' );
		$heading_inner = '<a href="' . esc_url( $home ) . '">' . esc_html( $brand ) . '</a>';
		// Keep core block comments minimal — only attrs that match core save() HTML.
		// Email spacing lives on our padding* attrs (do not affect save markup).
		$heading_attrs = wp_json_encode(
			array(
				'level'         => 2,
				'textAlign'     => 'center',
				'paddingTop'    => 0,
				'paddingBottom' => 4,
				'paddingX'      => 0,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		// phpcs:ignore WordPress.WP.EnqueuedResources -- Block markup, not a front-end asset.
		$heading = '<!-- wp:heading ' . $heading_attrs . ' -->'
			. '<h2 class="wp-block-heading has-text-align-center">'
			. $heading_inner
			. '</h2>'
			. '<!-- /wp:heading -->';

		$slogan_block = '';
		if ( '' !== $slogan ) {
			$para_attrs = wp_json_encode(
				array(
					'align'         => 'center',
					'paddingTop'    => 0,
					'paddingBottom' => 0,
					'paddingX'      => 0,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			$slogan_block = '<!-- wp:paragraph ' . $para_attrs . ' -->'
				. '<p class="has-text-align-center">' . esc_html( $slogan ) . '</p>'
				. '<!-- /wp:paragraph -->';
		}

		$header_attrs = wp_json_encode(
			array(
				'paddingTop'      => 24,
				'paddingBottom'   => 12,
				'paddingX'        => 24,
				'backgroundColor' => 'base-two',
				'align'           => 'center',
			)
		);
		$header = '<!-- wp:wstp/email-header ' . $header_attrs . ' -->' . $heading . $slogan_block . '<!-- /wp:wstp/email-header -->';
		$intro  = '<!-- wp:wstp/intro {"paddingTop":28,"paddingBottom":12,"paddingX":24,"backgroundColor":"base-two","textColor":"accent-three","mutedColor":"accent"} /-->';
		$notice = '<!-- wp:wstp/truncation-notice {"paddingTop":8,"paddingBottom":8,"paddingX":24,"backgroundColor":"base-two"} /-->';
		$footer = '<!-- wp:wstp/email-footer {"paddingTop":16,"paddingBottom":28,"paddingX":24,"backgroundColor":"base-two"} /-->';

		if ( 'image-left' === $layout ) {
			$loop = <<<'BLOCKS'
<!-- wp:wstp/posts-loop {"paddingTop":0,"paddingBottom":0,"paddingX":0,"backgroundColor":"base-two"} -->
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"34%"} -->
<div class="wp-block-column" style="flex-basis:34%"><!-- wp:wstp/post-image-side -->
<div class="wstp-email-post-image-side" aria-hidden="true"></div>
<!-- /wp:wstp/post-image-side --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"66%"} -->
<div class="wp-block-column" style="flex-basis:66%"><!-- wp:wstp/post-title {"textColor":"accent-three","fontSize":20} /-->

<!-- wp:wstp/post-meta {"textColor":"accent","fontSize":13,"paddingBottom":6} /-->

<!-- wp:wstp/post-excerpt {"textColor":"accent","wordCount":42} /-->

<!-- wp:wstp/post-read-more {"style":"button","backgroundColor":"accent-two","textColor":"#ffffff"} /--></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->
<!-- /wp:wstp/posts-loop -->
BLOCKS;
		} elseif ( 'minimal' === $layout ) {
			$loop = <<<'BLOCKS'
<!-- wp:wstp/posts-loop {"paddingTop":0,"paddingBottom":0,"paddingX":0,"backgroundColor":"base-two"} -->
<!-- wp:wstp/post-title {"textColor":"accent-three","fontSize":20} /-->

<!-- wp:wstp/post-meta {"textColor":"accent","fontSize":13,"paddingBottom":6} /-->

<!-- wp:wstp/post-excerpt {"textColor":"accent","wordCount":42} /-->

<!-- wp:wstp/post-read-more {"style":"link","textColor":"accent-two"} /-->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->
<!-- /wp:wstp/posts-loop -->
BLOCKS;
		} else {
			$loop = <<<'BLOCKS'
<!-- wp:wstp/posts-loop {"paddingTop":0,"paddingBottom":0,"paddingX":0,"backgroundColor":"base-two"} -->
<!-- wp:wstp/post-image -->
<div class="wstp-email-post-image" aria-hidden="true"></div>
<!-- /wp:wstp/post-image -->

<!-- wp:wstp/post-title {"textColor":"accent-three","fontSize":20} /-->

<!-- wp:wstp/post-meta {"textColor":"accent","fontSize":13,"paddingBottom":6} /-->

<!-- wp:wstp/post-excerpt {"textColor":"accent","wordCount":42} /-->

<!-- wp:wstp/post-read-more {"style":"button","backgroundColor":"accent-two","textColor":"#ffffff"} /-->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->
<!-- /wp:wstp/posts-loop -->
BLOCKS;
		}

		$inner = $header . "\n\n" . $intro . "\n\n" . $loop . "\n\n" . $notice . "\n\n" . $footer;

		return '<!-- wp:wstp/email-shell {"backgroundColor":"base"} -->' . "\n"
			. $inner . "\n"
			. '<!-- /wp:wstp/email-shell -->' . "\n";
	}

	/**
	 * Build mj-head from style hints.
	 *
	 * @param array{layout:string,needs_image_side_mobile:bool} $style Style hints.
	 * @return string
	 */
	private static function build_head( array $style ): string {
		$layout = $style['layout'];
		$font   = 'minimal' === $layout
			? "Georgia, 'Times New Roman', serif"
			: 'Arial, Helvetica, sans-serif';

		$button_radius = 'minimal' === $layout ? '0' : '4px';
		$line_height   = 'minimal' === $layout ? '1.7' : '1.6';
		$font_size     = 'minimal' === $layout ? '16px' : '15px';

		$mobile = '';
		if ( ! empty( $style['needs_image_side_mobile'] ) ) {
			// Desktop keeps Outlook-safe px widths; on stack, force fluid full-column images.
			$mobile = <<<'CSS'

    <mj-style>
      @media only screen and (max-width: 479px) {
        .wstp-post-image-col {
          width: 100% !important;
          max-width: 100% !important;
        }
        .wstp-post-image-side,
        .wstp-post-image {
          width: 100% !important;
          max-width: 100% !important;
        }
        .wstp-post-image-side table,
        .wstp-post-image table,
        .wstp-post-image-side td,
        .wstp-post-image td {
          width: 100% !important;
          max-width: 100% !important;
        }
        .wstp-post-image-side img,
        .wstp-post-image img,
        img.wstp-post-image-side,
        img.wstp-post-image {
          width: 100% !important;
          max-width: 100% !important;
          height: auto !important;
        }
      }
    </mj-style>
CSS;
		}

		return <<<HEAD
  <mj-head>
    <mj-attributes>
      <mj-all font-family="{$font}" />
      <mj-text line-height="{$line_height}" color="{{wstp:color_muted}}" font-size="{$font_size}" padding="0" />
      <mj-image padding="0" align="left" />
      <mj-button background-color="{{wstp:color_accent}}" color="#ffffff" border-radius="{$button_radius}" font-size="14px" inner-padding="10px 18px" padding="0" align="left" />
      <mj-column padding="0" />
      <mj-section padding="0" />
    </mj-attributes>
    <mj-style inline="inline">
      a { color: {{wstp:color_link}}; }
    </mj-style>{$mobile}
  </mj-head>
HEAD;
	}

	/**
	 * Detect layout font hint and whether image-side mobile CSS is needed.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return array{layout:string,needs_image_side_mobile:bool}
	 */
	private static function detect_style_hints( array $blocks ): array {
		$layout     = 'stacked';
		$has_side   = self::tree_has_block( $blocks, 'wstp/post-image-side' );
		$has_image  = $has_side || self::tree_has_block( $blocks, 'wstp/post-image' );

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || 'wstp/posts-loop' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			$attr_layout = isset( $block['attrs']['layout'] ) ? (string) $block['attrs']['layout'] : '';
			if ( in_array( $attr_layout, array( 'stacked', 'image-left', 'minimal' ), true ) ) {
				$layout = $attr_layout;
			} elseif ( $has_side ) {
				$layout = 'image-left';
			} elseif ( ! $has_image ) {
				$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
				if ( ! empty( $inner ) ) {
					$layout = 'minimal';
				}
			}
			break;
		}

		return array(
			'layout'                  => $layout,
			'needs_image_side_mobile' => $has_image || 'image-left' === $layout,
		);
	}

	/**
	 * Whether a block name exists anywhere in the tree.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @param string                         $name   Block name.
	 * @return bool
	 */
	private static function tree_has_block( array $blocks, string $name ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( $name === ( $block['blockName'] ?? '' ) ) {
				return true;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && self::tree_has_block( $block['innerBlocks'], $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve a stored color (palette slug, legacy alias, or hex) to an MJML color value.
	 *
	 * @param mixed  $value         Stored value.
	 * @param string $default_token Fallback slug or alias.
	 * @return string
	 */
	public static function resolve_color( $value, string $default_token ): string {
		$raw = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $raw ) {
			$raw = $default_token;
		}

		// Branding palette slugs first (unique hex values).
		if ( in_array( $raw, self::PALETTE_SLUGS, true ) ) {
			return '{{wstp:palette_' . str_replace( '-', '_', $raw ) . '}}';
		}

		if ( isset( self::COLOR_ALIASES[ $raw ] ) ) {
			$mapped = self::COLOR_ALIASES[ $raw ];
			return '{{wstp:palette_' . str_replace( '-', '_', $mapped ) . '}}';
		}

		if ( preg_match( '/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $raw ) ) {
			return $raw;
		}

		$fallback = isset( self::COLOR_ALIASES[ $default_token ] )
			? self::COLOR_ALIASES[ $default_token ]
			: ( in_array( $default_token, self::PALETTE_SLUGS, true ) ? $default_token : 'base-two' );

		return '{{wstp:palette_' . str_replace( '-', '_', $fallback ) . '}}';
	}

	/**
	 * Map a list of blocks to MJML body fragments.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 * @return string
	 */
	private static function map_blocks( array $blocks ): string {
		$out            = '';
		$has_truncation = false;

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}
			if ( 'wstp/truncation-notice' === $block['blockName'] ) {
				$has_truncation = true;
			}
			$out .= self::map_block( $block );
		}

		if ( ! $has_truncation ) {
			$out .= self::map_truncation_notice(
				array(
					'paddingTop'      => 8,
					'paddingBottom'   => 8,
					'paddingX'        => 24,
					'backgroundColor' => 'base-two',
				)
			);
		}

		return $out;
	}

	/**
	 * Map one block to MJML.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private static function map_block( array $block ): string {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = self::style_attrs( $block );

		switch ( $name ) {
			case 'wstp/email-header':
				return self::map_email_header( $block, $attrs );

			case 'wstp/email-footer':
				$pad = self::ensure_branding_section_pad( $attrs, 'footer' );
				return self::wrap_raw_token( self::branding_color_token( 'footer_block', $pad ), $pad );

			case 'wstp/truncation-notice':
				// Styles travel with the token; empty notice emits nothing (no ghost padded section).
				return self::map_truncation_notice( self::ensure_branding_section_pad( $attrs, 'notice' ) );

			case 'wstp/intro':
				$bg      = self::resolve_color( $attrs['backgroundColor'], 'content_bg' );
				$heading = self::resolve_color( $attrs['textColor'], 'text' );
				$p       = self::format_padding( (int) $attrs['paddingTop'], (int) $attrs['paddingBottom'], (int) $attrs['paddingX'] );
				$align   = self::align_attr( $attrs['align'] );
				$border  = self::border_attrs_mjml( $attrs );
				$inners  = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
				$intro   = self::map_header_inners( $inners, $align );
				return '    <mj-section background-color="' . $bg . '" padding="' . $p . '"' . $border . '>' . "\n"
					. '      <mj-column padding="0">' . "\n"
					. '        <mj-text font-size="22px" font-weight="bold" color="' . $heading . '" padding="0 0 8px" align="' . $align . '">{{wstp:greeting}}</mj-text>' . "\n"
					. $intro
					. '      </mj-column>' . "\n"
					. '    </mj-section>' . "\n";

			case 'wstp/posts-loop':
				return self::map_posts_loop( $block );

			case 'core/paragraph':
				$html = self::block_inner_rich_html( $block );
				if ( '' === $html ) {
					return '';
				}
				$bg    = self::resolve_color( $attrs['backgroundColor'], 'content_bg' );
				$color = self::resolve_color( $attrs['textColor'] !== '' ? $attrs['textColor'] : 'muted', 'muted' );
				$html  = self::style_anchors_in_html( $html, $color, false );
				$size  = max( 10, min( 48, (int) ( $attrs['fontSize'] ?: 15 ) ) );
				$font  = self::font_family_attr( (string) ( $attrs['fontFamily'] ?? '' ) );
				$p     = self::format_padding( (int) $attrs['paddingTop'], (int) $attrs['paddingBottom'], (int) $attrs['paddingX'] );
				$align = self::align_attr( $attrs['align'] );
				return self::open_section( $bg, $p, $attrs )
					. '      <mj-column padding="0">' . "\n"
					. '        <mj-text font-family="' . esc_attr( $font ) . '" color="' . $color . '" font-size="' . $size . 'px" padding="0" align="' . $align . '">' . $html . '</mj-text>' . "\n"
					. '      </mj-column>' . "\n"
					. '    </mj-section>' . "\n";

			case 'core/heading':
				$html = self::block_inner_rich_html( $block );
				if ( '' === $html ) {
					return '';
				}
				$bg    = self::resolve_color( $attrs['backgroundColor'], 'content_bg' );
				$color = self::resolve_color( $attrs['textColor'] !== '' ? $attrs['textColor'] : 'text', 'text' );
				$html  = self::style_anchors_in_html( $html, $color, false );
				$size  = max( 12, min( 48, (int) ( $attrs['fontSize'] ?: 20 ) ) );
				$font  = self::font_family_attr( (string) ( $attrs['fontFamily'] ?? '' ) );
				$p     = self::format_padding( (int) $attrs['paddingTop'], (int) $attrs['paddingBottom'], (int) $attrs['paddingX'] );
				$align = self::align_attr( $attrs['align'] );
				return self::open_section( $bg, $p, $attrs )
					. '      <mj-column padding="0">' . "\n"
					. '        <mj-text font-family="' . esc_attr( $font ) . '" font-size="' . $size . 'px" font-weight="bold" color="' . $color . '" padding="0" align="' . $align . '">' . $html . '</mj-text>' . "\n"
					. '      </mj-column>' . "\n"
					. '    </mj-section>' . "\n";

			case 'core/image':
				$url = isset( $block['attrs']['url'] ) ? esc_url( (string) $block['attrs']['url'] ) : '';
				if ( '' === $url ) {
					return '';
				}
				$alt   = isset( $block['attrs']['alt'] ) ? esc_attr( (string) $block['attrs']['alt'] ) : '';
				$bg    = self::resolve_color( $attrs['backgroundColor'], 'content_bg' );
				$p     = self::format_padding( (int) $attrs['paddingTop'], (int) $attrs['paddingBottom'], (int) $attrs['paddingX'] );
				$align = self::align_attr( $attrs['align'] );
				return '    <mj-section background-color="' . $bg . '" padding="' . $p . '">' . "\n"
					. '      <mj-column padding="0">' . "\n"
					. '        <mj-image src="' . $url . '" alt="' . $alt . '" padding="0" align="' . $align . '" />' . "\n"
					. '      </mj-column>' . "\n"
					. '    </mj-section>' . "\n";

			case 'core/separator':
				return self::map_separator_block( $block );

			case 'core/buttons':
				return self::map_buttons_block( $block );

			case 'core/columns':
				return self::map_columns_block( $block, false, null );

			default:
				return '';
		}
	}

	/**
	 * Map posts-loop container.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private static function map_posts_loop( array $block ): string {
		$inners = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		$attrs  = self::style_attrs( $block );

		if ( empty( $inners ) ) {
			$layout = isset( $block['attrs']['layout'] ) ? (string) $block['attrs']['layout'] : 'stacked';
			return self::posts_loop_preset_mjml( $layout );
		}

		$body = self::map_loop_body( $inners, $attrs );

		return "    <mj-raw>{{wstp:posts_loop}}</mj-raw>\n" . $body . "    <mj-raw>{{/wstp:posts_loop}}</mj-raw>\n";
	}

	/**
	 * Map posts-loop inner blocks into MJML sections.
	 *
	 * @param array<int,array<string,mixed>> $blocks Inner blocks.
	 * @param array<string,mixed>            $loop   Loop style attrs.
	 * @return string
	 */
	private static function map_loop_body( array $blocks, array $loop ): string {
		$out              = '';
		$buffer           = '';
		$is_first_section = true;
		$bg               = self::resolve_color( $loop['backgroundColor'], 'content_bg' );

		$flush = function () use ( &$out, &$buffer, &$is_first_section, $loop, $bg ): void {
			if ( '' === $buffer ) {
				return;
			}
			// Respect explicit 0 — do not substitute defaults with ?: (0 is falsy).
			$top    = $is_first_section ? (int) ( $loop['paddingTop'] ?? 0 ) : 0;
			$bottom = 0;
			$x      = (int) ( $loop['paddingX'] ?? 0 );
			$p      = self::format_padding( $top, $bottom, $x );
			$out   .= self::open_section( $bg, $p, $loop )
				. '      <mj-column padding="0">' . "\n"
				. $buffer
				. '      </mj-column>' . "\n"
				. '    </mj-section>' . "\n";
			$buffer           = '';
			$is_first_section = false;
		};

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}
			$name = (string) $block['blockName'];

			if ( 'core/columns' === $name ) {
				$flush();
				$out             .= self::map_columns_block( $block, true, $is_first_section ? $loop : null );
				$is_first_section = false;
				continue;
			}

			if ( 'core/separator' === $name ) {
				$flush();
				$out             .= self::map_separator_block( $block, $bg );
				$is_first_section = false;
				continue;
			}

			$buffer .= self::map_loop_field( $block );
		}

		$flush();

		return $out;
	}

	/**
	 * Map a post image field to mj-image.
	 *
	 * Width is emitted in px (column % × image % × 600) so Outlook gets a real pixel width
	 * instead of a percent that some clients resolve against the full body.
	 *
	 * @param array<string,mixed> $block      Block.
	 * @param bool                $side       Side image variant.
	 * @param float               $column_pct Parent column width as % of email body.
	 * @return string
	 */
	private static function map_post_image_field( array $block, bool $side, float $column_pct = 100.0 ): string {
		$attrs  = self::style_attrs( $block );
		$a      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$width  = isset( $a['widthPercent'] ) ? max( 20, min( 100, (int) $a['widthPercent'] ) ) : 100;
		$radius = isset( $a['borderRadius'] ) ? max( 0, min( 24, (int) $a['borderRadius'] ) ) : 4;
		$top    = max( 0, (int) ( $attrs['paddingTop'] ?? 0 ) );
		$bottom = max( 0, (int) ( $attrs['paddingBottom'] ?? 0 ) );
		$x      = max( 0, (int) ( $attrs['paddingX'] ?? 0 ) );
		$pad    = self::format_padding( $top, $bottom, $x );
		$css    = $side ? 'wstp-post-image-side' : 'wstp-post-image';
		$col_pct    = max( 1.0, min( 100.0, $column_pct ) );
		$col_px     = (int) round( self::EMAIL_CONTENT_WIDTH * ( $col_pct / 100 ) );
		$img_px     = max( 40, (int) round( $col_px * ( $width / 100 ) ) );
		$width_attr = $img_px . 'px';
		$align      = self::align_attr(
			isset( $a['align'] ) && is_string( $a['align'] ) && '' !== $a['align']
				? (string) $a['align']
				: 'center'
		);

		return '        <mj-image css-class="' . $css . '" src="{{wstp:post_image_url}}" alt="{{wstp:post_title}}" width="' . $width_attr . '" padding="' . $pad . '" align="' . $align . '" border-radius="' . $radius . 'px" />' . "\n";
	}

	/**
	 * Detect Gutenberg separator style from block attrs or saved markup.
	 *
	 * @param array<string,mixed> $block Separator block.
	 * @return string dots|wide|default
	 */
	private static function separator_visual_style( array $block ): string {
		$a     = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$class = isset( $a['className'] ) ? (string) $a['className'] : '';
		$html  = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
		$hay   = $class . ' ' . $html;
		if ( false !== strpos( $hay, 'is-style-dots' ) ) {
			return 'dots';
		}
		if ( false !== strpos( $hay, 'is-style-wide' ) ) {
			return 'wide';
		}
		return 'default';
	}

	/**
	 * Map core/separator to MJML (line or centered dots).
	 *
	 * @param array<string,mixed> $block   Separator block.
	 * @param string              $loop_bg Optional loop section background.
	 * @return string
	 */
	private static function map_separator_block( array $block, string $loop_bg = '' ): string {
		$attrs = self::style_attrs( $block );
		$bg    = '' !== $loop_bg ? $loop_bg : self::resolve_color( $attrs['backgroundColor'], 'content_bg' );
		$line  = self::separator_border_color( $block );
		$top   = max( 0, (int) $attrs['paddingTop'] );
		$bottom = max( 0, (int) $attrs['paddingBottom'] );
		$x     = max( 0, (int) $attrs['paddingX'] );
		$pad   = self::format_padding( $top, $bottom, $x );
		$style = self::separator_visual_style( $block );

		if ( 'dots' === $style ) {
			return '    <mj-section background-color="' . esc_attr( $bg ) . '" padding="0">' . "\n"
				. '      <mj-column padding="0">' . "\n"
				. '        <mj-text align="center" color="' . esc_attr( $line ) . '" font-size="18px" line-height="1" letter-spacing="0.35em" padding="' . esc_attr( $pad ) . '">&#8226; &#8226; &#8226;</mj-text>' . "\n"
				. '      </mj-column>' . "\n"
				. '    </mj-section>' . "\n";
		}

		$border_width = 'wide' === $style ? '2px' : '1px';

		return '    <mj-section background-color="' . esc_attr( $bg ) . '" padding="0">' . "\n"
			. '      <mj-column padding="0">' . "\n"
			. '        <mj-divider border-color="' . esc_attr( $line ) . '" border-style="solid" border-width="' . esc_attr( $border_width ) . '" padding="' . esc_attr( $pad ) . '" />' . "\n"
			. '      </mj-column>' . "\n"
			. '    </mj-section>' . "\n";
	}

	/**
	 * Resolve separator line color for mj-divider.
	 *
	 * @param array<string,mixed> $block Separator block.
	 * @return string
	 */
	private static function separator_border_color( array $block ): string {
		$a = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		if ( ! empty( $a['wstpColor'] ) && is_string( $a['wstpColor'] ) ) {
			return self::resolve_color( $a['wstpColor'], 'accent' );
		}

		if ( ! empty( $a['backgroundColor'] ) && is_string( $a['backgroundColor'] ) ) {
			return self::resolve_color( $a['backgroundColor'], 'accent' );
		}

		if ( isset( $a['style']['color']['background'] ) && is_string( $a['style']['color']['background'] ) ) {
			return self::resolve_color( $a['style']['color']['background'], 'accent' );
		}

		return '#e5e7eb';
	}

	/**
	 * Map a single loop field block to MJML column children.
	 *
	 * @param array<string,mixed> $block      Block.
	 * @param float               $column_pct Parent column width as % of email body.
	 * @return string
	 */
	private static function map_loop_field( array $block, float $column_pct = 100.0 ): string {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = self::style_attrs( $block );
		// Use stored spacing only — never invent gaps when paddingBottom is 0/unset.
		$gap   = max( 0, (int) ( $attrs['paddingBottom'] ?? 0 ) );
		$top   = max( 0, (int) ( $attrs['paddingTop'] ?? 0 ) );
		$x     = max( 0, (int) ( $attrs['paddingX'] ?? 0 ) );
		$pad   = self::format_padding( $top, $gap, $x );
		$align = self::align_attr( $attrs['align'] );

		switch ( $name ) {
			case 'wstp/post-title':
				$color = self::resolve_color( $attrs['textColor'], 'text' );
				$size  = max( 12, min( 48, (int) ( $attrs['fontSize'] ?: 20 ) ) );
				return '        <mj-text font-size="' . $size . 'px" font-weight="bold" color="' . $color . '" padding="' . $pad . '" align="' . $align . '">' . "\n"
					. '          <a href="{{wstp:post_url}}" style="color:' . $color . ';text-decoration:none;">{{wstp:post_title}}</a>' . "\n"
					. '        </mj-text>' . "\n";

			case 'wstp/post-excerpt':
				$color = self::resolve_color( $attrs['textColor'], 'muted' );
				$size  = max( 10, min( 36, (int) ( $attrs['fontSize'] ?: 15 ) ) );
				$words = isset( $block['attrs']['wordCount'] ) ? (int) $block['attrs']['wordCount'] : 42;
				$words = max( 5, min( 100, $words ) );
				return '        <mj-text color="' . $color . '" font-size="' . $size . 'px" padding="' . $pad . '" align="' . $align . '">{{wstp:post_excerpt:' . $words . '}}</mj-text>' . "\n";

			case 'wstp/post-meta':
				$color      = self::resolve_color( $attrs['textColor'], 'muted' );
				$size       = max( 10, min( 24, (int) ( $attrs['fontSize'] ?: 13 ) ) );
				$show_date  = ! isset( $block['attrs']['showDate'] ) || ! empty( $block['attrs']['showDate'] );
				$show_author = ! isset( $block['attrs']['showAuthor'] ) || ! empty( $block['attrs']['showAuthor'] );
				$separator  = isset( $block['attrs']['separator'] ) ? (string) $block['attrs']['separator'] : ' · ';
				$parts      = array();
				if ( $show_date ) {
					$parts[] = '{{wstp:post_date}}';
				}
				if ( $show_author ) {
					$parts[] = '{{wstp:post_author}}';
				}
				if ( empty( $parts ) ) {
					return '';
				}
				$meta_html = implode( esc_html( $separator ), $parts );
				return '        <mj-text color="' . $color . '" font-size="' . $size . 'px" padding="' . $pad . '" align="' . $align . '">' . $meta_html . '</mj-text>' . "\n";

			case 'wstp/post-image':
				return self::map_post_image_field( $block, false, $column_pct );

			case 'wstp/post-image-side':
				return self::map_post_image_field( $block, true, $column_pct );

			case 'wstp/post-read-more':
				$style = isset( $block['attrs']['style'] ) ? (string) $block['attrs']['style'] : 'button';
				if ( 'link' === $style ) {
					$color = self::resolve_color( $attrs['textColor'], 'link' );
					$size  = max( 10, min( 24, (int) ( $attrs['fontSize'] ?: 14 ) ) );
					return '        <mj-text color="' . $color . '" font-size="' . $size . 'px" padding="' . $pad . '" align="' . $align . '">' . "\n"
						. '          <a href="{{wstp:post_url}}" style="color:' . $color . ';text-decoration:underline;">{{wstp:read_more_label}}</a>' . "\n"
						. '        </mj-text>' . "\n";
				}
				$bg     = self::resolve_color( $attrs['backgroundColor'], 'button' );
				$text_v = (string) $attrs['textColor'];
				$color  = '' === $text_v ? '#ffffff' : self::resolve_color( $text_v, 'button' );
				if ( in_array( strtolower( $text_v ), array( '#fff', '#ffffff', 'ffffff', 'fff' ), true ) ) {
					$color = '#ffffff';
				}
				$radius = max( 0, min( 24, (int) ( $attrs['borderRadius'] ?: 4 ) ) );
				return '        <mj-button href="{{wstp:post_url}}" background-color="' . $bg . '" color="' . $color . '" border-radius="' . $radius . 'px" align="' . $align . '" padding="' . $pad . '">{{wstp:read_more_label}}</mj-button>' . "\n";

			case 'core/paragraph':
				$html = self::block_inner_rich_html( $block );
				if ( '' === $html ) {
					return '';
				}
				$color = self::resolve_color( $attrs['textColor'] !== '' ? $attrs['textColor'] : 'muted', 'muted' );
				$size  = max( 10, min( 36, (int) ( $attrs['fontSize'] ?: 15 ) ) );
				$font  = self::font_family_attr( (string) ( $attrs['fontFamily'] ?? '' ) );
				return '        <mj-text font-family="' . esc_attr( $font ) . '" color="' . $color . '" font-size="' . $size . 'px" padding="' . $pad . '" align="' . $align . '">' . $html . '</mj-text>' . "\n";

			case 'core/heading':
				$html = self::block_inner_rich_html( $block );
				if ( '' === $html ) {
					return '';
				}
				$color = self::resolve_color( $attrs['textColor'] !== '' ? $attrs['textColor'] : 'text', 'text' );
				$size  = max( 12, min( 48, (int) ( $attrs['fontSize'] ?: 18 ) ) );
				$font  = self::font_family_attr( (string) ( $attrs['fontFamily'] ?? '' ) );
				return '        <mj-text font-family="' . esc_attr( $font ) . '" font-size="' . $size . 'px" font-weight="bold" color="' . $color . '" padding="' . $pad . '" align="' . $align . '">' . $html . '</mj-text>' . "\n";

			default:
				return '';
		}
	}

	/**
	 * Legacy preset loop MJML when posts-loop has no inner blocks.
	 *
	 * @param string $layout Layout slug.
	 * @return string
	 */
	private static function posts_loop_preset_mjml( string $layout ): string {
		if ( 'image-left' === $layout ) {
			return <<<'MJML'
    <mj-raw>{{wstp:posts_loop}}</mj-raw>
    <mj-section background-color="{{wstp:color_content_bg}}" padding="0" text-align="left">
      <mj-column css-class="wstp-post-image-col" width="34%" vertical-align="top" padding="0">
        <mj-image css-class="wstp-post-image-side" src="{{wstp:post_image_url}}" alt="{{wstp:post_title}}" width="100%" padding="0" align="center" border-radius="4px" />
      </mj-column>
      <mj-column width="66%" vertical-align="top" padding="0">
        <mj-text font-size="20px" font-weight="bold" color="{{wstp:color_text}}" padding="0">
          <a href="{{wstp:post_url}}" style="color:{{wstp:color_text}};text-decoration:none;">{{wstp:post_title}}</a>
        </mj-text>
        <mj-text color="{{wstp:color_muted}}" padding="0">{{wstp:post_excerpt}}</mj-text>
        <mj-button href="{{wstp:post_url}}">{{wstp:read_more_label}}</mj-button>
      </mj-column>
    </mj-section>
    <mj-section background-color="{{wstp:color_content_bg}}" padding="0">
      <mj-column padding="0">
        <mj-divider border-color="#e5e7eb" border-width="1px" padding="0" />
      </mj-column>
    </mj-section>
    <mj-raw>{{/wstp:posts_loop}}</mj-raw>

MJML;
		}

		if ( 'minimal' === $layout ) {
			return <<<'MJML'
    <mj-raw>{{wstp:posts_loop}}</mj-raw>
    <mj-section background-color="{{wstp:color_content_bg}}" padding="0">
      <mj-column padding="0">
        <mj-text font-size="20px" font-weight="bold" color="{{wstp:color_text}}" padding="0">
          <a href="{{wstp:post_url}}" style="color:{{wstp:color_text}};text-decoration:none;">{{wstp:post_title}}</a>
        </mj-text>
        <mj-text color="{{wstp:color_muted}}" padding="0">{{wstp:post_excerpt}}</mj-text>
        <mj-text color="{{wstp:color_text}}" font-size="14px" padding="0">
          <a href="{{wstp:post_url}}" style="color:{{wstp:color_link}};text-decoration:underline;">{{wstp:read_more_label}}</a>
        </mj-text>
        <mj-divider border-color="#dddddd" border-width="1px" padding="0" />
      </mj-column>
    </mj-section>
    <mj-raw>{{/wstp:posts_loop}}</mj-raw>

MJML;
		}

		return <<<'MJML'
    <mj-raw>{{wstp:posts_loop}}</mj-raw>
    <mj-section background-color="{{wstp:color_content_bg}}" padding="0">
      <mj-column padding="0">
        <mj-image css-class="wstp-post-image" src="{{wstp:post_image_url}}" alt="{{wstp:post_title}}" width="100%" padding="0" align="center" border-radius="4px" />
        <mj-text font-size="20px" font-weight="bold" color="{{wstp:color_text}}" padding="0">
          <a href="{{wstp:post_url}}" style="color:{{wstp:color_text}};text-decoration:none;">{{wstp:post_title}}</a>
        </mj-text>
        <mj-text color="{{wstp:color_muted}}" padding="0">{{wstp:post_excerpt}}</mj-text>
        <mj-button href="{{wstp:post_url}}">{{wstp:read_more_label}}</mj-button>
        <mj-divider border-color="#e5e7eb" border-width="1px" padding="0" />
      </mj-column>
    </mj-section>
    <mj-raw>{{/wstp:posts_loop}}</mj-raw>

MJML;
	}

	/**
	 * Apply former branding-shell paddings when a block still has all-zero spacing
	 * (legacy visual templates relied on Email_Branding::wrap_region padding).
	 *
	 * @param array<string,mixed> $attrs Style attrs.
	 * @param string              $kind  header|footer|notice.
	 * @return array<string,mixed>
	 */
	private static function ensure_branding_section_pad( array $attrs, string $kind ): array {
		$top    = (int) ( $attrs['paddingTop'] ?? 0 );
		$bottom = (int) ( $attrs['paddingBottom'] ?? 0 );
		$x      = (int) ( $attrs['paddingX'] ?? 0 );

		if ( 0 !== $top || 0 !== $bottom || 0 !== $x ) {
			return $attrs;
		}

		if ( 'header' === $kind ) {
			$attrs['paddingTop']    = 24;
			$attrs['paddingBottom'] = 12;
			$attrs['paddingX']      = 24;
		} elseif ( 'footer' === $kind ) {
			$attrs['paddingTop']    = 16;
			$attrs['paddingBottom'] = 28;
			$attrs['paddingX']      = 24;
		} else {
			$attrs['paddingTop']    = 0;
			$attrs['paddingBottom'] = 12;
			$attrs['paddingX']      = 24;
		}

		if ( '' === (string) ( $attrs['backgroundColor'] ?? '' ) ) {
			$attrs['backgroundColor'] = 'base-two';
		}

		return $attrs;
	}

	/**
	 * Map editable visual header block (logo or rich text) to MJML.
	 *
	 * Falls back to branding token only for legacy empty headers.
	 *
	 * @param array<string,mixed> $block Full block.
	 * @param array<string,mixed> $attrs Normalized style attrs.
	 * @return string
	 */
	private static function map_email_header( array $block, array $attrs ): string {
		$attrs   = self::ensure_branding_section_pad( $attrs, 'header' );
		$raw     = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$logo_url = isset( $raw['logoUrl'] ) ? trim( (string) $raw['logoUrl'] ) : '';
		$logo_id  = isset( $raw['logoId'] ) ? absint( $raw['logoId'] ) : 0;
		if ( $logo_id > 0 ) {
			$from_id = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( is_string( $from_id ) && '' !== $from_id ) {
				$logo_url = $from_id;
			}
		}

		$bg    = self::resolve_color( $attrs['backgroundColor'], 'content_bg' );
		$p     = self::format_padding( (int) $attrs['paddingTop'], (int) $attrs['paddingBottom'], (int) $attrs['paddingX'] );
		$align = self::align_attr( (string) ( $attrs['align'] ?? 'center' ) );

		if ( '' !== $logo_url ) {
			$width = isset( $raw['logoWidth'] ) ? (int) $raw['logoWidth'] : 280;
			if ( $width < 80 ) {
				$width = 80;
			}
			if ( $width > 560 ) {
				$width = 560;
			}
			$alt  = isset( $raw['logoAlt'] ) ? esc_attr( (string) $raw['logoAlt'] ) : '';
			$href = isset( $raw['logoLink'] ) ? esc_url( (string) $raw['logoLink'] ) : '';
			$img  = '<mj-image src="' . esc_url( $logo_url ) . '" alt="' . $alt . '" width="' . $width . 'px" padding="0" align="' . $align . '" />';
			if ( '' !== $href ) {
				$img = '<mj-image href="' . $href . '" src="' . esc_url( $logo_url ) . '" alt="' . $alt . '" width="' . $width . 'px" padding="0" align="' . $align . '" />';
			}
			return self::open_section( $bg, $p, $attrs )
				. '      <mj-column padding="0">' . "\n"
				. '        ' . $img . "\n"
				. '      </mj-column>' . "\n"
				. '    </mj-section>' . "\n";
		}

		$inners = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		$body   = self::map_header_inners( $inners, $align );
		if ( '' !== $body ) {
			return self::open_section( $bg, $p, $attrs )
				. '      <mj-column padding="0">' . "\n"
				. $body
				. '      </mj-column>' . "\n"
				. '    </mj-section>' . "\n";
		}

		$content = isset( $raw['content'] ) ? Email_Branding::sanitize_header_html( (string) $raw['content'] ) : '';
		// Legacy header blocks had no content key — keep branding token.
		if ( '' === $content && ! array_key_exists( 'content', $raw ) && ! array_key_exists( 'logoUrl', $raw ) ) {
			return self::wrap_raw_token( self::branding_color_token( 'header_block', $pad ), $pad );
		}
		if ( '' === $content ) {
			return '';
		}

		$content_link = isset( $raw['contentLink'] ) ? esc_url( (string) $raw['contentLink'] ) : '';
		if ( '' !== $content_link && ! preg_match( '/<a\b/i', $content ) ) {
			$content = '<a href="' . $content_link . '">' . $content . '</a>';
		}

		$color = self::resolve_color( ! empty( $raw['textColor'] ) ? (string) $raw['textColor'] : 'text', 'text' );
		$link  = $color;
		$font  = self::font_family_attr( isset( $raw['fontFamily'] ) ? (string) $raw['fontFamily'] : '' );
		$tag   = isset( $raw['tagName'] ) ? (string) $raw['tagName'] : 'h2';
		if ( ! in_array( $tag, array( 'h1', 'h2', 'h3', 'p' ), true ) ) {
			$tag = 'h2';
		}
		if ( ! preg_match( '/<(h[1-3]|p)\b/i', $content ) ) {
			$content = '<' . $tag . '>' . $content . '</' . $tag . '>';
		}
		$defaults = array(
			'h1' => 28,
			'h2' => 22,
			'h3' => 18,
			'p'  => 15,
		);
		$size   = max( 10, min( 48, (int) ( ( $raw['fontSize'] ?? 0 ) ?: $defaults[ $tag ] ) ) );
		$weight = 'p' === $tag ? '400' : '700';
		$html   = Email_Branding::style_header_html_for_email( $content, $font, $color, $link, $align, false, 4 );
		return self::open_section( $bg, $p, $attrs )
			. '      <mj-column padding="0">' . "\n"
			. '        <mj-text font-family="' . esc_attr( $font ) . '" font-size="' . $size . 'px" font-weight="' . $weight . '" color="' . $color . '" padding="0" align="' . $align . '">' . $html . '</mj-text>' . "\n"
			. '      </mj-column>' . "\n"
			. '    </mj-section>' . "\n";
	}

	/**
	 * Map heading/paragraph/button children inside the header section (no nested sections).
	 *
	 * @param array<int,array<string,mixed>> $blocks Inner blocks.
	 * @param string                         $fallback_align Header align fallback.
	 * @return string
	 */
	private static function map_header_inners( array $blocks, string $fallback_align = 'center' ): string {
		$out = '';
		foreach ( $blocks as $child ) {
			if ( ! is_array( $child ) || empty( $child['blockName'] ) ) {
				continue;
			}
			$name  = (string) $child['blockName'];
			$attrs = self::style_attrs( $child );
			// style_attrs already prefers textAlign; do not fall back to header center when
			// the block is intentionally left (empty / default Gutenberg alignment).
			$align = self::align_attr( (string) ( $attrs['align'] ?: 'left' ) );
			// Use the block's own Gap after — never invent 8px between header children.
			$gap   = max( 0, (int) ( $attrs['paddingBottom'] ?? 0 ) );

			if ( 'core/heading' === $name || 'core/paragraph' === $name ) {
				$html = self::block_inner_rich_html( $child );
				if ( '' === $html ) {
					continue;
				}
				$is_heading = 'core/heading' === $name;
				$color      = self::resolve_color(
					$attrs['textColor'] !== '' ? $attrs['textColor'] : ( $is_heading ? 'accent-three' : 'accent' ),
					$is_heading ? 'text' : 'muted'
				);
				$html   = self::style_anchors_in_html( $html, $color, false );
				$size   = max( 10, min( 48, (int) ( $attrs['fontSize'] ?: ( $is_heading ? 22 : 15 ) ) ) );
				$font   = self::font_family_attr( (string) ( $attrs['fontFamily'] ?? '' ) );
				$weight = $is_heading ? ' font-weight="bold"' : '';
				$top    = max( 0, (int) ( $attrs['paddingTop'] ?? 0 ) );
				$x      = max( 0, (int) ( $attrs['paddingX'] ?? 0 ) );
				$pad    = self::format_padding( $top, $gap, $x );
				$out   .= '        <mj-text font-family="' . esc_attr( $font ) . '"' . $weight . ' font-size="' . $size . 'px" color="' . $color . '" padding="' . $pad . '" align="' . $align . '">' . $html . '</mj-text>' . "\n";
				continue;
			}

			if ( 'core/buttons' === $name ) {
				$out .= self::map_buttons_inners( $child, $align, $gap );
				continue;
			}

			if ( 'core/button' === $name ) {
				$out .= self::map_single_button( $child, $align, $gap );
			}
		}
		return $out;
	}

	/**
	 * Map buttons inside an existing column.
	 *
	 * @param array<string,mixed> $block Buttons block.
	 * @param string              $align Align.
	 * @param int                 $gap   Bottom padding.
	 * @return string
	 */
	private static function map_buttons_inners( array $block, string $align, int $gap = 0 ): string {
		$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		$out   = '';
		foreach ( $inner as $child ) {
			if ( ! is_array( $child ) || 'core/button' !== ( $child['blockName'] ?? '' ) ) {
				continue;
			}
			$out .= self::map_single_button( $child, $align, $gap );
		}
		return $out;
	}

	/**
	 * Map one core/button to mj-button.
	 *
	 * @param array<string,mixed> $block Button block.
	 * @param string              $align Align.
	 * @param int                 $gap   Bottom padding.
	 * @return string
	 */
	private static function map_single_button( array $block, string $align = 'center', int $gap = 0 ): string {
		$attrs = self::style_attrs( $block );
		$url   = isset( $block['attrs']['url'] ) ? esc_url( (string) $block['attrs']['url'] ) : '#';
		$label = self::block_inner_text( $block );
		if ( '' === $label ) {
			$label = esc_html__( 'Learn more', 'we-subscribe-to-posts' );
		}
		$btn_bg = self::resolve_color(
			isset( $block['attrs']['backgroundColor'] ) ? (string) $block['attrs']['backgroundColor'] : 'accent-two',
			'button'
		);
		$btn_fg = '#ffffff';
		if ( ! empty( $block['attrs']['textColor'] ) ) {
			$raw_fg = (string) $block['attrs']['textColor'];
			if ( 0 === strpos( $raw_fg, '#' ) ) {
				$btn_fg = sanitize_hex_color( $raw_fg ) ? $raw_fg : '#ffffff';
			} else {
				$btn_fg = self::resolve_color( $raw_fg, 'content_bg' );
			}
		}
		$btn_align = self::align_attr( (string) ( $attrs['align'] ?: $align ) );
		$pad       = '0 0 ' . max( 0, $gap ) . 'px';
		return '        <mj-button href="' . $url . '" background-color="' . $btn_bg . '" color="' . $btn_fg . '" align="' . $btn_align . '" padding="' . $pad . '">' . $label . '</mj-button>' . "\n";
	}

	/**
	 * Encode text/muted/link color slugs into a branding token.
	 *
	 * @param string              $name  header_block|footer_block.
	 * @param array<string,mixed> $attrs Style attrs.
	 * @return string
	 */
	private static function branding_color_token( string $name, array $attrs ): string {
		$text  = self::token_color_slug( isset( $attrs['textColor'] ) ? (string) $attrs['textColor'] : '' );
		$muted = self::token_color_slug( isset( $attrs['mutedColor'] ) ? (string) $attrs['mutedColor'] : '' );
		$link  = self::token_color_slug( isset( $attrs['linkColor'] ) ? (string) $attrs['linkColor'] : '' );

		return '{{wstp:' . $name . ':' . $text . ':' . $muted . ':' . $link . '}}';
	}

	/**
	 * Sanitize a palette slug for token payloads (`-` = use branding default).
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	private static function token_color_slug( string $slug ): string {
		$slug = sanitize_key( $slug );
		return '' !== $slug ? $slug : '-';
	}

	/**
	 * Emit truncation notice as a self-styled token (no mj-section wrapper).
	 *
	 * When the digest is not truncated the renderer replaces this with an empty
	 * string, so padding must not live in a surrounding MJML section.
	 *
	 * @param array<string,mixed> $attrs Style attrs.
	 * @return string
	 */
	private static function map_truncation_notice( array $attrs ): string {
		$top    = max( 0, (int) ( $attrs['paddingTop'] ?? 0 ) );
		$bottom = max( 0, (int) ( $attrs['paddingBottom'] ?? 0 ) );
		$x      = max( 0, (int) ( $attrs['paddingX'] ?? 0 ) );
		$bg     = sanitize_key( (string) ( $attrs['backgroundColor'] ?? 'base-two' ) );
		if ( '' === $bg ) {
			$bg = 'base-two';
		}
		$bt = max( 0, min( 12, (int) ( $attrs['borderTop'] ?? 0 ) ) );
		$br = max( 0, min( 12, (int) ( $attrs['borderRight'] ?? 0 ) ) );
		$bb = max( 0, min( 12, (int) ( $attrs['borderBottom'] ?? 0 ) ) );
		$bl = max( 0, min( 12, (int) ( $attrs['borderLeft'] ?? 0 ) ) );
		$bc = self::token_color_slug( isset( $attrs['borderColor'] ) ? (string) $attrs['borderColor'] : '' );

		return '    <mj-raw>{{wstp:truncation_notice_block:' . $top . ':' . $bottom . ':' . $x . ':' . $bg . ':' . $bt . ':' . $br . ':' . $bb . ':' . $bl . ':' . $bc . '}}</mj-raw>' . "\n";
	}

	/**
	 * Whether any section border width is set.
	 *
	 * @param array<string,mixed> $attrs Style attrs.
	 * @return bool
	 */
	private static function has_border( array $attrs ): bool {
		foreach ( array( 'borderTop', 'borderRight', 'borderBottom', 'borderLeft' ) as $key ) {
			if ( (int) ( $attrs[ $key ] ?? 0 ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build mj-section border-* attributes from style attrs.
	 *
	 * @param array<string,mixed> $attrs Style attrs.
	 * @return string
	 */
	private static function border_attrs_mjml( array $attrs ): string {
		if ( ! self::has_border( $attrs ) ) {
			return '';
		}

		$color = self::resolve_color( isset( $attrs['borderColor'] ) ? (string) $attrs['borderColor'] : '', 'accent' );
		$out   = '';
		$map   = array(
			'borderTop'    => 'border-top',
			'borderRight'  => 'border-right',
			'borderBottom' => 'border-bottom',
			'borderLeft'   => 'border-left',
		);

		foreach ( $map as $key => $attr ) {
			$width = max( 0, min( 12, (int) ( $attrs[ $key ] ?? 0 ) ) );
			if ( $width > 0 ) {
				$out .= ' ' . $attr . '="' . $width . 'px solid ' . $color . '"';
			}
		}

		return $out;
	}

	/**
	 * Open an mj-section with background, padding, and optional borders.
	 *
	 * @param string              $bg      Background color value.
	 * @param string              $padding Padding value.
	 * @param array<string,mixed> $attrs   Style attrs (for borders).
	 * @return string
	 */
	private static function open_section( string $bg, string $padding, array $attrs = array(), string $text_align = '' ): string {
		$align = '';
		if ( in_array( $text_align, array( 'left', 'center', 'right' ), true ) ) {
			$align = ' text-align="' . $text_align . '"';
		}

		return '    <mj-section background-color="' . $bg . '" padding="' . $padding . '"' . $align . self::border_attrs_mjml( $attrs ) . '>' . "\n";
	}

	/**
	 * Wrap a raw token in an optional padded/colored section.
	 *
	 * @param string              $token Token markup.
	 * @param array<string,mixed> $attrs Style attrs.
	 * @return string
	 */
	private static function wrap_raw_token( string $token, array $attrs ): string {
		$top    = (int) ( $attrs['paddingTop'] ?? 0 );
		$bottom = (int) ( $attrs['paddingBottom'] ?? 0 );
		$x      = (int) ( $attrs['paddingX'] ?? 0 );
		$bg_raw = isset( $attrs['backgroundColor'] ) ? (string) $attrs['backgroundColor'] : '';

		if ( 0 === $top && 0 === $bottom && 0 === $x && '' === $bg_raw && ! self::has_border( $attrs ) ) {
			return '    <mj-raw>' . $token . '</mj-raw>' . "\n";
		}

		$bg = '' !== $bg_raw ? self::resolve_color( $bg_raw, 'content_bg' ) : 'transparent';
		$p  = self::format_padding( $top, $bottom, $x );
		return self::open_section( $bg, $p, $attrs )
			. '      <mj-column padding="0">' . "\n"
			. '        <mj-raw>' . $token . '</mj-raw>' . "\n"
			. '      </mj-column>' . "\n"
			. '    </mj-section>' . "\n";
	}

	/**
	 * Normalize style attributes from a block.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return array<string,mixed>
	 */
	private static function style_attrs( array $block ): array {
		$a = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		return array(
			'paddingTop'      => isset( $a['paddingTop'] ) ? max( 0, (int) $a['paddingTop'] ) : 0,
			'paddingBottom'   => isset( $a['paddingBottom'] ) ? max( 0, (int) $a['paddingBottom'] ) : 0,
			'paddingX'        => isset( $a['paddingX'] ) ? max( 0, (int) $a['paddingX'] ) : 0,
			'backgroundColor' => self::extract_color_attr( $a, 'background' ),
			'textColor'       => self::extract_color_attr( $a, 'text' ),
			'mutedColor'      => isset( $a['mutedColor'] ) ? (string) $a['mutedColor'] : '',
			'linkColor'       => isset( $a['linkColor'] ) ? (string) $a['linkColor'] : '',
			'borderTop'       => isset( $a['borderTop'] ) ? max( 0, min( 12, (int) $a['borderTop'] ) ) : 0,
			'borderRight'     => isset( $a['borderRight'] ) ? max( 0, min( 12, (int) $a['borderRight'] ) ) : 0,
			'borderBottom'    => isset( $a['borderBottom'] ) ? max( 0, min( 12, (int) $a['borderBottom'] ) ) : 0,
			'borderLeft'      => isset( $a['borderLeft'] ) ? max( 0, min( 12, (int) $a['borderLeft'] ) ) : 0,
			'borderColor'     => isset( $a['borderColor'] ) ? (string) $a['borderColor'] : '',
			'fontSize'        => self::extract_font_size_px( $a ),
			'fontFamily'      => isset( $a['fontFamily'] ) ? (string) $a['fontFamily'] : '',
			'align'           => self::align_attr(
				// Prefer Gutenberg textAlign; custom `align` is only a legacy mirror.
				isset( $a['textAlign'] ) && '' !== (string) $a['textAlign']
					? (string) $a['textAlign']
					: ( isset( $a['align'] ) ? (string) $a['align'] : 'left' )
			),
			'borderRadius'    => isset( $a['borderRadius'] ) ? (int) $a['borderRadius'] : 0,
		);
	}

	/**
	 * Read text or background color from core/native or branding attrs.
	 *
	 * @param array<string,mixed> $a     Block attrs.
	 * @param string              $which text|background.
	 * @return string Slug or hex (may be empty).
	 */
	private static function extract_color_attr( array $a, string $which ): string {
		if ( 'text' === $which ) {
			if ( ! empty( $a['textColor'] ) && is_string( $a['textColor'] ) ) {
				return (string) $a['textColor'];
			}
			if ( isset( $a['style']['color']['text'] ) && is_string( $a['style']['color']['text'] ) ) {
				return (string) $a['style']['color']['text'];
			}
			return '';
		}

		if ( ! empty( $a['backgroundColor'] ) && is_string( $a['backgroundColor'] ) ) {
			return (string) $a['backgroundColor'];
		}
		if ( isset( $a['style']['color']['background'] ) && is_string( $a['style']['color']['background'] ) ) {
			return (string) $a['style']['color']['background'];
		}
		return '';
	}

	/**
	 * Font size in px from native typography or a numeric attribute.
	 *
	 * @param array<string,mixed> $a Block attrs.
	 * @return int 0 when unset.
	 */
	private static function extract_font_size_px( array $a ): int {
		if ( isset( $a['style']['typography']['fontSize'] ) ) {
			$raw = (string) $a['style']['typography']['fontSize'];
			if ( preg_match( '/([\d.]+)/', $raw, $m ) ) {
				return max( 0, (int) round( (float) $m[1] ) );
			}
		}
		if ( isset( $a['fontSize'] ) && is_numeric( $a['fontSize'] ) ) {
			return max( 0, (int) $a['fontSize'] );
		}
		if ( isset( $a['fontSize'] ) && is_string( $a['fontSize'] ) && preg_match( '/^([\d.]+)px$/i', $a['fontSize'], $m ) ) {
			return max( 0, (int) round( (float) $m[1] ) );
		}
		return 0;
	}

	/**
	 * Sanitize email-safe font-family stack for MJML.
	 *
	 * @param string $family Raw family.
	 * @return string
	 */
	private static function font_family_attr( string $family ): string {
		$allowed = array(
			'Arial, Helvetica, sans-serif',
			'Helvetica, Arial, sans-serif',
			'Verdana, Geneva, sans-serif',
			"'Trebuchet MS', Helvetica, sans-serif",
			"Georgia, 'Times New Roman', serif",
			"'Times New Roman', Times, serif",
			"'Courier New', Courier, monospace",
		);
		$family = trim( $family );
		if ( in_array( $family, $allowed, true ) ) {
			return $family;
		}
		return 'Arial, Helvetica, sans-serif';
	}

	/**
	 * Sanitize mj-text/mj-button align.
	 *
	 * @param string $align Align.
	 * @return string
	 */
	private static function align_attr( string $align ): string {
		return in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'left';
	}

	/**
	 * Format CSS-like MJML padding.
	 *
	 * @param int $top Top.
	 * @param int $bottom Bottom.
	 * @param int $x Horizontal.
	 * @return string
	 */
	private static function format_padding( int $top, int $bottom, int $x ): string {
		return $top . 'px ' . $x . 'px ' . $bottom . 'px';
	}

	/**
	 * Map core/buttons.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private static function map_buttons_block( array $block ): string {
		$attrs = self::style_attrs( $block );
		$align = self::align_attr( (string) ( $attrs['align'] ?? 'center' ) );
		$body  = self::map_buttons_inners( $block, $align, 0 );
		if ( '' === $body ) {
			return '';
		}

		$section_bg = self::resolve_color( 'content_bg', 'content_bg' );
		$p          = self::format_padding( (int) $attrs['paddingTop'], (int) $attrs['paddingBottom'], (int) $attrs['paddingX'] );

		return '    <mj-section background-color="' . $section_bg . '" padding="' . $p . '">' . "\n"
			. '      <mj-column padding="0">' . "\n"
			. $body
			. '      </mj-column>' . "\n"
			. '    </mj-section>' . "\n";
	}

	/**
	 * Map core/columns (max 2).
	 *
	 * @param array<string,mixed>      $block    Block.
	 * @param bool                     $in_loop  Inside posts loop.
	 * @param array<string,mixed>|null $loop_pad Loop spacing for first section.
	 * @return string
	 */
	private static function map_columns_block( array $block, bool $in_loop = false, ?array $loop_pad = null ): string {
		$columns = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		$columns = array_values(
			array_filter(
				$columns,
				static function ( $col ) {
					return is_array( $col ) && 'core/column' === ( $col['blockName'] ?? '' );
				}
			)
		);

		if ( count( $columns ) > 2 ) {
			$columns = array_slice( $columns, 0, 2 );
		}

		$count = count( $columns );
		if ( 0 === $count ) {
			return '';
		}

		$attrs = self::style_attrs( $block );
		if ( null !== $loop_pad ) {
			$bg         = self::resolve_color( $loop_pad['backgroundColor'] ?? 'content_bg', 'content_bg' );
			$p          = self::format_padding(
				(int) ( $loop_pad['paddingTop'] ?? 0 ),
				0,
				(int) ( $loop_pad['paddingX'] ?? 0 )
			);
			$border_src = $loop_pad;
		} else {
			$bg         = self::resolve_color( $attrs['backgroundColor'], 'content_bg' );
			$p          = $in_loop
				? self::format_padding( 0, 0, 0 )
				: self::format_padding(
					(int) ( $attrs['paddingTop'] ?? 0 ),
					(int) ( $attrs['paddingBottom'] ?? 0 ),
					(int) ( $attrs['paddingX'] ?? 0 )
				);
			$border_src = $attrs;
		}

		$mjml = self::open_section( $bg, $p, $border_src, $in_loop ? 'left' : '' );

		$percents = array();
		$nulls    = 0;
		$set_sum  = 0.0;
		foreach ( $columns as $index => $column ) {
			$width_attr = isset( $column['attrs']['width'] ) ? trim( (string) $column['attrs']['width'] ) : '';
			if ( '' === $width_attr ) {
				$percents[ $index ] = null;
				++$nulls;
				continue;
			}
			$percents[ $index ] = self::column_width_percent( $width_attr, $count );
			$set_sum           += $percents[ $index ];
		}
		if ( $nulls > 0 ) {
			$each = max( 1.0, ( 100.0 - $set_sum ) / $nulls );
			foreach ( $percents as $index => $pct ) {
				if ( null === $pct ) {
					$percents[ $index ] = self::clamp_column_percent( $each, 100.0 / $count );
				}
			}
		}

		foreach ( $columns as $index => $column ) {
			$col_pct  = isset( $percents[ $index ] ) ? (float) $percents[ $index ] : ( 100.0 / $count );
			$width    = self::format_column_width_attr( $col_pct );
			$col_a    = isset( $column['attrs'] ) && is_array( $column['attrs'] ) ? $column['attrs'] : array();
			$col_attrs = self::style_attrs( $column );
			$col_top   = (int) $col_attrs['paddingTop'];
			$col_bot   = (int) $col_attrs['paddingBottom'];
			$col_x     = (int) $col_attrs['paddingX'];
			// Only emit column padding when explicitly > 0 (avoids leftover 24px from old defaults).
			$has_pad = ( array_key_exists( 'paddingTop', $col_a ) && $col_top > 0 )
				|| ( array_key_exists( 'paddingBottom', $col_a ) && $col_bot > 0 )
				|| ( array_key_exists( 'paddingX', $col_a ) && $col_x > 0 );
			$pad_col = $has_pad ? self::format_padding( $col_top, $col_bot, $col_x ) : '0';

			$css   = '';
			$inner = isset( $column['innerBlocks'] ) && is_array( $column['innerBlocks'] ) ? $column['innerBlocks'] : array();
			$has_side_image = $in_loop && self::tree_has_block( $inner, 'wstp/post-image-side' );
			$has_full_image = $in_loop && self::tree_has_block( $inner, 'wstp/post-image' );
			if ( $has_side_image ) {
				$css = ' css-class="wstp-post-image-col"';
			}
			$mjml .= '      <mj-column' . $css . ' width="' . esc_attr( $width ) . '%" vertical-align="top" padding="' . $pad_col . '">' . "\n";
			$col_body = $in_loop ? self::map_loop_column_inners( $inner, $col_pct ) : self::map_column_inners( $inner );
			// Recover when the image block is present in the tree but was not mapped (e.g. unexpected nesting).
			if ( $in_loop && '' === trim( $col_body ) && ( $has_side_image || $has_full_image ) ) {
				$col_body = self::map_post_image_field( array( 'attrs' => array() ), $has_side_image, $col_pct );
			}
			$mjml .= $col_body;
			$mjml .= '      </mj-column>' . "\n";
		}

		$mjml .= '    </mj-section>' . "\n";
		return $mjml;
	}

	/**
	 * Parse a Gutenberg column width into % of the email body (600px).
	 *
	 * Gutenberg may store "34%", "220px", or a bare number. Values above 100 without a
	 * unit are treated as pixels — never as 220% (which produced mj-column-per-220).
	 *
	 * @param string $raw   Raw width attribute.
	 * @param int    $count Column count for equal-split fallback.
	 * @return float
	 */
	private static function column_width_percent( string $raw, int $count ): float {
		$fallback = 100.0 / max( 1, $count );
		$raw      = trim( $raw );
		if ( '' === $raw ) {
			return $fallback;
		}

		if ( preg_match( '/^([\d.]+)\s*%$/u', $raw, $m ) ) {
			return self::clamp_column_percent( (float) $m[1], $fallback );
		}

		if ( preg_match( '/^([\d.]+)\s*px$/iu', $raw, $m ) ) {
			return self::clamp_column_percent( ( (float) $m[1] / self::EMAIL_CONTENT_WIDTH ) * 100.0, $fallback );
		}

		if ( preg_match( '/^([\d.]+)$/u', $raw, $m ) ) {
			$n = (float) $m[1];
			if ( $n > 100 ) {
				return self::clamp_column_percent( ( $n / self::EMAIL_CONTENT_WIDTH ) * 100.0, $fallback );
			}
			return self::clamp_column_percent( $n, $fallback );
		}

		return $fallback;
	}

	/**
	 * Clamp a column percentage into a usable range.
	 *
	 * @param float $pct      Candidate percent.
	 * @param float $fallback Fallback when out of range.
	 * @return float
	 */
	private static function clamp_column_percent( float $pct, float $fallback ): float {
		if ( $pct <= 0 || $pct > 100 ) {
			return round( $fallback, 1 );
		}
		return round( $pct, 1 );
	}

	/**
	 * Format a column percent for the MJML width attribute (without %).
	 *
	 * @param float $pct Percent.
	 * @return string
	 */
	private static function format_column_width_attr( float $pct ): string {
		if ( abs( $pct - round( $pct ) ) < 0.05 ) {
			return (string) (int) round( $pct );
		}
		return rtrim( rtrim( number_format( $pct, 1, '.', '' ), '0' ), '.' );
	}

	/**
	 * Map blocks inside a loop column (recurses so nested image/title blocks still emit).
	 *
	 * @param array<int,array<string,mixed>> $blocks     Inner blocks.
	 * @param float                          $column_pct Parent column width as % of email body.
	 * @return string
	 */
	private static function map_loop_column_inners( array $blocks, float $column_pct = 100.0 ): string {
		$out = '';
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}
			$mapped = self::map_loop_field( $block, $column_pct );
			if ( '' !== $mapped ) {
				$out .= $mapped;
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$out .= self::map_loop_column_inners( $block['innerBlocks'], $column_pct );
			}
		}
		return $out;
	}

	/**
	 * Map blocks inside a column without wrapping extra sections.
	 *
	 * @param array<int,array<string,mixed>> $blocks Inner blocks.
	 * @return string
	 */
	private static function map_column_inners( array $blocks ): string {
		$out = '';
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}
			$name  = (string) $block['blockName'];
			$attrs = self::style_attrs( $block );
			if ( 'core/paragraph' === $name || 'core/heading' === $name ) {
				$html = self::block_inner_rich_html( $block );
				if ( '' === $html ) {
					continue;
				}
				$color  = self::resolve_color( $attrs['textColor'], 'core/heading' === $name ? 'text' : 'muted' );
				$html   = self::style_anchors_in_html( $html, $color, false );
				$weight = 'core/heading' === $name ? ' font-weight="bold"' : '';
				$align  = self::align_attr( (string) ( $attrs['align'] ?? 'left' ) );
				$font   = self::font_family_attr( (string) ( $attrs['fontFamily'] ?? '' ) );
				$out   .= '        <mj-text font-family="' . esc_attr( $font ) . '"' . $weight . ' color="' . $color . '" padding="0 0 8px" align="' . $align . '">' . $html . '</mj-text>' . "\n";
			} elseif ( 'core/image' === $name ) {
				$url = isset( $block['attrs']['url'] ) ? esc_url( (string) $block['attrs']['url'] ) : '';
				if ( '' !== $url ) {
					$alt  = isset( $block['attrs']['alt'] ) ? esc_attr( (string) $block['attrs']['alt'] ) : '';
					$out .= '        <mj-image src="' . $url . '" alt="' . $alt . '" padding="0 0 8px" />' . "\n";
				}
			} elseif ( 'core/button' === $name ) {
				$url   = isset( $block['attrs']['url'] ) ? esc_url( (string) $block['attrs']['url'] ) : '#';
				$label = self::block_inner_text( $block );
				if ( '' === $label ) {
					$label = esc_html__( 'Read more', 'we-subscribe-to-posts' );
				}
				$out .= '        <mj-button href="' . $url . '">' . $label . '</mj-button>' . "\n";
			} elseif ( 0 === strpos( $name, 'wstp/post-' ) ) {
				$out .= self::map_loop_field( $block );
			}
		}
		return $out;
	}

	/**
	 * Extract plain/escaped text from a block's inner HTML.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private static function block_inner_text( array $block ): string {
		$html = self::raw_inner_html( $block );
		$text = wp_strip_all_tags( $html );
		$text = trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return '' === $text ? '' : esc_html( $text );
	}

	/**
	 * Ensure anchors keep the block text color in email clients (no browser-blue links).
	 *
	 * @param string $html       Rich HTML fragment.
	 * @param string $color      Hex color.
	 * @param bool   $underline  Whether links are underlined.
	 * @return string
	 */
	private static function style_anchors_in_html( string $html, string $color, bool $underline = false ): string {
		if ( '' === $html || '' === $color ) {
			return $html;
		}
		$decoration = $underline ? 'underline' : 'none';
		$styled     = preg_replace_callback(
			'/<a\b([^>]*)>/i',
			static function ( array $matches ) use ( $color, $decoration ): string {
				$attrs = isset( $matches[1] ) ? (string) $matches[1] : '';
				$attrs = preg_replace( '/\sstyle=(["\']).*?\1/i', '', $attrs ) ?? $attrs;
				return '<a' . $attrs . ' style="color:' . esc_attr( $color ) . ';text-decoration:' . esc_attr( $decoration ) . ';">';
			},
			$html
		);
		return is_string( $styled ) ? $styled : $html;
	}

	/**
	 * Extract email-safe rich HTML from a paragraph/heading (links, bold, italic, breaks).
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private static function block_inner_rich_html( array $block ): string {
		$html = self::raw_inner_html( $block );
		if ( '' === trim( $html ) ) {
			return '';
		}

		// Drop outer wrapping tags from core saves.
		$html = preg_replace( '/^<(p|h[1-6])(\s[^>]*)?>/i', '', $html ) ?? $html;
		$html = preg_replace( '/<\/(p|h[1-6])>\s*$/i', '', $html ) ?? $html;

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
			'br'     => array(),
			'u'      => array(),
			'span'   => array(
				'style' => true,
			),
		);

		return trim( wp_kses( $html, $allowed ) );
	}

	/**
	 * Raw inner HTML string from a parsed block.
	 *
	 * @param array<string,mixed> $block Block.
	 * @return string
	 */
	private static function raw_inner_html( array $block ): string {
		$html = '';
		if ( ! empty( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$html = $block['innerHTML'];
		} elseif ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $chunk ) {
				if ( is_string( $chunk ) ) {
					$html .= $chunk;
				}
			}
		}
		return $html;
	}
}
