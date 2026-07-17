<?php
/**
 * MJML digest template renderer.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Mailer;

use WSTP\Admin\Mjml_Template;

defined( 'ABSPATH' ) || exit;

/**
 * Expands stored HTML email templates with digest placeholders.
 */
final class Mjml_Template_Renderer {
	/**
	 * Register hooks (reserved for future use).
	 *
	 * @return void
	 */
	public function register(): void {
	}

	/**
	 * Render digest email HTML.
	 *
	 * @param array<string,mixed> $context Digest context.
	 * @return string
	 */
	public function render_digest( array $context ): string {
		$html = Mjml_Template::get_html_template();
		if ( '' === trim( $html ) ) {
			return $this->render_fallback_template( $context );
		}

		return $this->expand_template( $html, $context );
	}

	/**
	 * Expand global and loop placeholders in an HTML template.
	 *
	 * @param string              $template Compiled HTML template.
	 * @param array<string,mixed> $context Digest context.
	 * @return string
	 */
	public function expand_template( string $template, array $context ): string {
		// Some MJML/HTML pipelines entity-encode mustache braces; normalize first.
		$template = str_replace(
			array( '&#123;', '&#125;', '&lbrace;', '&rbrace;' ),
			array( '{', '}', '{', '}' ),
			$template
		);

		$posts    = isset( $context['posts'] ) && is_array( $context['posts'] ) ? $context['posts'] : array();
		$template = $this->expand_posts_loop( $template, $posts );

		return $this->replace_global_tokens( $template, $context );
	}

	/**
	 * Duplicate loop block for each post.
	 *
	 * @param string                         $template HTML source.
	 * @param array<int,array<string,mixed>> $posts Post rows.
	 * @return string
	 */
	private function expand_posts_loop( string $template, array $posts ): string {
		if ( ! preg_match( '/\{\{wstp:posts_loop\}\}(.*?)\{\{\/wstp:posts_loop\}\}/s', $template, $matches ) ) {
			return $template;
		}

		$loop_template = (string) $matches[1];
		$rendered      = '';

		foreach ( $posts as $post ) {
			if ( ! is_array( $post ) ) {
				continue;
			}
			$rendered .= $this->replace_post_tokens( $loop_template, $post );
		}

		return (string) preg_replace(
			'/\{\{wstp:posts_loop\}\}.*?\{\{\/wstp:posts_loop\}\}/s',
			$rendered,
			$template,
			1
		);
	}

	/**
	 * Replace global digest tokens.
	 *
	 * @param string              $template HTML source.
	 * @param array<string,mixed> $context Digest context.
	 * @return string
	 */
	private function replace_global_tokens( string $template, array $context ): string {
		$greeting_name = isset( $context['greeting_name'] ) ? (string) $context['greeting_name'] : __( 'there', 'we-subscribe-to-posts' );
		$greeting      = sprintf(
			/* translators: %s: subscriber name. */
			__( 'Hi %s,', 'we-subscribe-to-posts' ),
			$greeting_name
		);

		$colors = Email_Branding::get_resolved_colors();
		$settings = Email_Branding::get_settings();
		$palette  = isset( $settings['palette_colors'] ) && is_array( $settings['palette_colors'] )
			? $settings['palette_colors']
			: array();

		// Parameterized truncation token first (styles live on the token so empty = no gap).
		$template = (string) preg_replace_callback(
			'/\{\{wstp:truncation_notice_block(?::(\d+):(\d+):(\d+):([a-z0-9_-]+)(?::(\d+):(\d+):(\d+):(\d+):([a-z0-9_-]+))?)?\}\}/i',
			function ( array $matches ) use ( $context ): string {
				$styles = array(
					'paddingTop'      => isset( $matches[1] ) ? (int) $matches[1] : 0,
					'paddingBottom'   => isset( $matches[2] ) ? (int) $matches[2] : 12,
					'paddingX'        => isset( $matches[3] ) ? (int) $matches[3] : 24,
					'backgroundColor' => isset( $matches[4] ) ? (string) $matches[4] : 'base-two',
					'borderTop'       => isset( $matches[5] ) ? (int) $matches[5] : 0,
					'borderRight'     => isset( $matches[6] ) ? (int) $matches[6] : 0,
					'borderBottom'    => isset( $matches[7] ) ? (int) $matches[7] : 0,
					'borderLeft'      => isset( $matches[8] ) ? (int) $matches[8] : 0,
					'borderColor'     => isset( $matches[9] ) && '-' !== $matches[9] ? (string) $matches[9] : '',
				);
				return $this->build_truncation_notice_block( $context, $styles );
			},
			$template
		);

		// Header/footer color overrides from visual section attrs.
		$template = (string) preg_replace_callback(
			'/\{\{wstp:header_block(?::([a-z0-9_-]+):([a-z0-9_-]+):([a-z0-9_-]+))?\}\}/i',
			function ( array $matches ): string {
				$styles = $this->parse_branding_color_matches( $matches );
				return $this->build_header_block( $styles );
			},
			$template
		);
		$template = (string) preg_replace_callback(
			'/\{\{wstp:footer_block(?::([a-z0-9_-]+):([a-z0-9_-]+):([a-z0-9_-]+))?\}\}/i',
			function ( array $matches ) use ( $context ): string {
				$styles = $this->parse_branding_color_matches( $matches );
				return $this->build_footer_block( $context, $styles );
			},
			$template
		);

		$tokens = array(
			'{{wstp:greeting_name}}'           => esc_html( $greeting_name ),
			'{{wstp:greeting}}'                => esc_html( $greeting ),
			'{{wstp:unsubscribe_url}}'           => esc_url( isset( $context['unsubscribe_url'] ) ? (string) $context['unsubscribe_url'] : home_url( '/' ) ),
			'{{wstp:unsubscribe_label}}'       => esc_html( __( 'Unsubscribe instantly', 'we-subscribe-to-posts' ) ),
			'{{wstp:read_more_label}}'         => esc_html( __( 'Read more', 'we-subscribe-to-posts' ) ),
			'{{wstp:site_name}}'               => esc_html( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'{{wstp:color_body_bg}}'           => esc_attr( $colors['body_bg'] ),
			'{{wstp:color_content_bg}}'        => esc_attr( $colors['content_bg'] ),
			'{{wstp:color_text}}'              => esc_attr( $colors['text'] ),
			'{{wstp:color_muted}}'             => esc_attr( $colors['muted'] ),
			'{{wstp:color_accent}}'            => esc_attr( $colors['accent'] ),
			'{{wstp:color_link}}'              => esc_attr( $colors['link'] ),
			'{{wstp:palette_base}}'            => esc_attr( (string) ( $palette['base'] ?? $colors['body_bg'] ) ),
			'{{wstp:palette_base_two}}'        => esc_attr( (string) ( $palette['base-two'] ?? $colors['content_bg'] ) ),
			'{{wstp:palette_base_three}}'      => esc_attr( (string) ( $palette['base-three'] ?? $colors['text'] ) ),
			'{{wstp:palette_accent}}'          => esc_attr( (string) ( $palette['accent'] ?? $colors['muted'] ) ),
			'{{wstp:palette_accent_two}}'      => esc_attr( (string) ( $palette['accent-two'] ?? $colors['accent'] ) ),
			'{{wstp:palette_accent_three}}'    => esc_attr( (string) ( $palette['accent-three'] ?? $colors['text'] ) ),
		);

		return strtr( $template, $tokens );
	}

	/**
	 * Parse optional text:muted:link color slugs from a branding token match.
	 *
	 * @param array<int,string> $matches Regex matches.
	 * @return array<string,string>
	 */
	private function parse_branding_color_matches( array $matches ): array {
		return array(
			'textColor'  => isset( $matches[1] ) && '-' !== $matches[1] ? (string) $matches[1] : '',
			'mutedColor' => isset( $matches[2] ) && '-' !== $matches[2] ? (string) $matches[2] : '',
			'linkColor'  => isset( $matches[3] ) && '-' !== $matches[3] ? (string) $matches[3] : '',
		);
	}

	/**
	 * Replace per-post tokens inside loop markup.
	 *
	 * @param string              $template Loop HTML fragment.
	 * @param array<string,mixed> $post Post data.
	 * @return string
	 */
	private function replace_post_tokens( string $template, array $post ): string {
		$title   = isset( $post['title'] ) ? (string) $post['title'] : '';
		$excerpt = isset( $post['excerpt'] ) ? (string) $post['excerpt'] : '';
		$url     = isset( $post['permalink'] ) ? (string) $post['permalink'] : '';
		$image   = isset( $post['featured_image_url'] ) ? trim( (string) $post['featured_image_url'] ) : '';
		$post_id = isset( $post['id'] ) ? (int) $post['id'] : 0;
		$date    = isset( $post['date'] ) ? (string) $post['date'] : '';
		$author  = isset( $post['author'] ) ? (string) $post['author'] : '';
		$excerpt_source = isset( $post['excerpt_source'] ) ? (string) $post['excerpt_source'] : $excerpt;

		if ( '' === $image ) {
			$image_id = isset( $post['featured_image_id'] ) ? (int) $post['featured_image_id'] : 0;
			if ( $image_id <= 0 && $post_id > 0 ) {
				$image_id = (int) get_post_thumbnail_id( $post_id );
			}
			if ( $image_id > 0 ) {
				$from_id = wp_get_attachment_image_url( $image_id, 'large' );
				if ( ! is_string( $from_id ) || '' === $from_id ) {
					$from_id = wp_get_attachment_image_url( $image_id, 'full' );
				}
				if ( is_string( $from_id ) && '' !== $from_id ) {
					$image = set_url_scheme( $from_id );
				}
			}
		}

		if ( '' === $image && $post_id > 0 ) {
			$thumb = get_the_post_thumbnail_url( $post_id, 'large' );
			if ( is_string( $thumb ) && '' !== $thumb ) {
				$image = set_url_scheme( $thumb );
			}
		}

		$template = (string) preg_replace_callback(
			'/\{\{\s*wstp:post_image:(full|side):(\d+):(\d+):(left|center|right):(\d+):(\d+):(\d+)\s*\}\}/',
			function ( array $matches ) use ( $image, $title ): string {
				return $this->build_styled_post_image(
					$image,
					$title,
					'side' === $matches[1],
					(int) $matches[2],
					(int) $matches[3],
					(string) $matches[4],
					(int) $matches[5],
					(int) $matches[6],
					(int) $matches[7]
				);
			},
			$template
		);

		$tokens = array(
			'{{wstp:post_title}}'      => esc_html( $title ),
			'{{wstp:post_excerpt}}'    => esc_html( wp_trim_words( $excerpt_source, 42 ) ),
			'{{wstp:post_url}}'        => esc_url( $url ),
			'{{wstp:post_image_url}}'  => esc_url( $image ),
			'{{wstp:post_image}}'      => $this->build_post_image_tag( $image, $title ),
			'{{wstp:post_image_side}}' => $this->build_post_image_side_tag( $image, $title ),
			'{{wstp:post_date}}'       => esc_html( $date ),
			'{{wstp:post_author}}'     => esc_html( $author ),
			'{{wstp:read_more_label}}' => esc_html( __( 'Read more', 'we-subscribe-to-posts' ) ),
		);

		$html = strtr( $template, $tokens );

		$html = (string) preg_replace_callback(
			'/\{\{\s*wstp:post_excerpt:(\d+)\s*\}\}/',
			static function ( array $matches ) use ( $excerpt_source ): string {
				$words = max( 5, min( 100, (int) $matches[1] ) );
				return esc_html( wp_trim_words( $excerpt_source, $words ) );
			},
			$html
		);

		if ( '' === $image ) {
			// Drop leftover img tags that resolved to an empty src (legacy templates / mj-image tokens).
			$html = (string) preg_replace( '/<img\b[^>]*\bsrc=["\']["\'][^>]*>/i', '', $html );
			$html = (string) preg_replace( '/<tr>\s*<td\b[^>]*>\s*<\/td>\s*<\/tr>/i', '', $html );
		}

		return $html;
	}

	/**
	 * Build a configured post image from a visual-editor token.
	 *
	 * @param string $image_url Image URL.
	 * @param string $title     Post title.
	 * @param bool   $side      Side-column variant.
	 * @param int    $px        Max width in px.
	 * @param int    $radius    Border radius.
	 * @param string $align     left|center|right.
	 * @param int    $top       Padding top.
	 * @param int    $bottom    Padding bottom.
	 * @param int    $x         Padding left/right.
	 * @return string
	 */
	private function build_styled_post_image(
		string $image_url,
		string $title,
		bool $side,
		int $px,
		int $radius,
		string $align,
		int $top,
		int $bottom,
		int $x
	): string {
		if ( '' === $image_url ) {
			return '';
		}

		$px     = max( 80, min( 600, $px ) );
		$radius = max( 0, min( 24, $radius ) );
		$align  = in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : 'left';
		$top    = max( 0, $top );
		$bottom = max( 0, $bottom );
		$x      = max( 0, $x );

		$margin = '0';
		if ( 'center' === $align ) {
			$margin = '0 auto';
		} elseif ( 'right' === $align ) {
			$margin = '0 0 0 auto';
		}

		$class = $side ? ' class="wstp-post-image-side"' : '';
		$style = sprintf(
			'display:block;width:100%%;max-width:%dpx;height:auto;border:0;border-radius:%dpx;margin:%s;',
			$px,
			$radius,
			$margin
		);
		$img = '<img' . $class . ' src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $title ) . '" width="' . esc_attr( (string) $px ) . '" style="' . esc_attr( $style ) . '" />';

		// MJML places mj-raw inside a column <tbody>; images must be table rows.
		$align_attr = 'left' === $align ? 'left' : ( 'right' === $align ? 'right' : 'center' );
		$td_pad     = ( 0 === $top && 0 === $bottom && 0 === $x )
			? ''
			: ' style="padding:' . esc_attr( $top . 'px ' . $x . 'px ' . $bottom . 'px' ) . ';"';

		return '<tr><td align="' . esc_attr( $align_attr ) . '"' . $td_pad . '>' . $img . '</td></tr>';
	}

	/**
	 * Build HTML image markup for a post thumbnail.
	 *
	 * @param string $image_url Image URL.
	 * @param string $title Post title.
	 * @return string
	 */
	private function build_post_image_tag( string $image_url, string $title ): string {
		if ( '' === $image_url ) {
			return '';
		}

		$img = '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $title ) . '" width="560" style="display:block;width:100%;max-width:560px;height:auto;border:0;border-radius:4px;margin:0 0 8px;" />';
		return '<tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;">' . $img . '</td></tr>';
	}

	/**
	 * Build HTML image markup for a side-column layout.
	 *
	 * @param string $image_url Image URL.
	 * @param string $title Post title.
	 * @return string
	 */
	private function build_post_image_side_tag( string $image_url, string $title ): string {
		if ( '' === $image_url ) {
			return '';
		}

		$img = '<img class="wstp-post-image-side" src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $title ) . '" width="560" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:4px;margin:0;" />';
		return '<tr><td align="left" style="font-size:0px;padding:0;word-break:break-word;">' . $img . '</td></tr>';
	}

	/**
	 * Build optional truncation notice HTML block.
	 *
	 * Returns an empty string when the digest is not truncated, so spacing from
	 * block padding only appears when the notice has content.
	 *
	 * @param array<string,mixed> $context Digest context.
	 * @param array<string,mixed> $styles  Optional padding/background from the visual block.
	 * @return string
	 */
	private function build_truncation_notice_block( array $context, array $styles = array() ): string {
		$truncated = isset( $context['posts_truncated_by'] ) ? (int) $context['posts_truncated_by'] : 0;
		if ( $truncated <= 0 ) {
			return '';
		}

		$message = sprintf(
			/* translators: %d: hidden posts count. */
			_n(
				'Plus %d more published post not shown due to your limit.',
				'Plus %d more published posts not shown due to your limit.',
				$truncated,
				'we-subscribe-to-posts'
			),
			$truncated
		);

		$colors = Email_Branding::get_resolved_colors();
		$font   = Email_Branding::resolve_font_family();
		$top    = isset( $styles['paddingTop'] ) ? max( 0, (int) $styles['paddingTop'] ) : 0;
		$bottom = isset( $styles['paddingBottom'] ) ? max( 0, (int) $styles['paddingBottom'] ) : 12;
		$x      = isset( $styles['paddingX'] ) ? max( 0, (int) $styles['paddingX'] ) : 24;
		$bg     = $this->resolve_truncation_background( isset( $styles['backgroundColor'] ) ? (string) $styles['backgroundColor'] : 'base-two', $colors );
		$pad    = $top . 'px ' . $x . 'px ' . $bottom . 'px';
		$border = $this->css_border_shorthand( $styles, $colors );

		$inner = '<div style="font-family:' . esc_attr( $font ) . ';color:' . esc_attr( $colors['muted'] ) . ';font-size:13px;line-height:1.5;text-align:left;">' . esc_html( $message ) . '</div>';

		$td_style = 'padding:' . esc_attr( $pad ) . ';';

		return '<!--[if mso | IE]><table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;" width="100%" bgcolor="' . esc_attr( $bg ) . '"><tr><td style="' . $td_style . $border . '"><![endif]-->'
			. '<div style="background:' . esc_attr( $bg ) . ';background-color:' . esc_attr( $bg ) . ';margin:0;width:100%;box-sizing:border-box;' . esc_attr( $border ) . '">'
			. '<table align="center" border="0" cellpadding="0" cellspacing="0" role="presentation" style="background:' . esc_attr( $bg ) . ';background-color:' . esc_attr( $bg ) . ';width:100%;">'
			. '<tbody><tr><td style="padding:' . esc_attr( $pad ) . ';">'
			. $inner
			. '</td></tr></tbody></table></div>'
			. '<!--[if mso | IE]></td></tr></table><![endif]-->';
	}

	/**
	 * Build CSS border declarations for truncation HTML (outer wrapper).
	 *
	 * @param array<string,mixed>  $styles Style bag.
	 * @param array<string,string> $colors Resolved colors.
	 * @return string
	 */
	private function css_border_shorthand( array $styles, array $colors ): string {
		$color = $this->resolve_style_color( isset( $styles['borderColor'] ) ? (string) $styles['borderColor'] : '', $colors, 'muted' );
		$css   = '';
		$map   = array(
			'borderTop'    => 'border-top',
			'borderRight'  => 'border-right',
			'borderBottom' => 'border-bottom',
			'borderLeft'   => 'border-left',
		);
		foreach ( $map as $key => $prop ) {
			$width = isset( $styles[ $key ] ) ? max( 0, min( 12, (int) $styles[ $key ] ) ) : 0;
			if ( $width > 0 ) {
				$css .= $prop . ':' . $width . 'px solid ' . $color . ';';
			}
		}
		return $css;
	}

	/**
	 * Resolve a palette slug / legacy color key to a hex background.
	 *
	 * @param string               $slug   Palette slug or legacy key.
	 * @param array<string,string> $colors Resolved semantic colors.
	 * @return string
	 */
	private function resolve_truncation_background( string $slug, array $colors ): string {
		return $this->resolve_style_color( $slug, $colors, 'content_bg' );
	}

	/**
	 * Resolve a palette slug to a hex color, falling back to a semantic color key.
	 *
	 * @param string               $slug         Palette slug (may be empty).
	 * @param array<string,string> $colors       Resolved semantic colors.
	 * @param string               $fallback_key Semantic key (text|muted|link|content_bg|…).
	 * @return string
	 */
	private function resolve_style_color( string $slug, array $colors, string $fallback_key ): string {
		$slug     = sanitize_key( $slug );
		$settings = Email_Branding::get_settings();
		$palette  = isset( $settings['palette_colors'] ) && is_array( $settings['palette_colors'] )
			? $settings['palette_colors']
			: array();

		$legacy = array(
			'content_bg' => 'content_bg',
			'body_bg'    => 'body_bg',
			'text'       => 'text',
			'muted'      => 'muted',
			'link'       => 'link',
			'accent'     => 'accent',
			'base'       => 'body_bg',
			'base-two'   => 'content_bg',
			'base_two'   => 'content_bg',
			'base-three' => 'text',
			'base_three' => 'text',
			'accent-two' => 'accent',
			'accent_two' => 'accent',
			'accent-three' => 'text',
			'accent_three' => 'text',
		);

		if ( '' !== $slug && isset( $palette[ $slug ] ) && is_string( $palette[ $slug ] ) && '' !== $palette[ $slug ] ) {
			return (string) $palette[ $slug ];
		}

		if ( '' !== $slug && isset( $legacy[ $slug ] ) ) {
			$key = $legacy[ $slug ];
			if ( isset( $colors[ $key ] ) ) {
				return (string) $colors[ $key ];
			}
		}

		return isset( $colors[ $fallback_key ] ) ? (string) $colors[ $fallback_key ] : (string) ( $colors['content_bg'] ?? '#ffffff' );
	}

	/**
	 * Build default header HTML from settings.
	 *
	 * @param array<string,string> $styles Optional text/muted/link color slugs from the visual block.
	 * @return string
	 */
	private function build_header_block( array $styles = array() ): string {
		$settings = Email_Branding::get_settings();
		$colors   = Email_Branding::get_resolved_colors();
		$font     = Email_Branding::resolve_font_family();
		$text     = $this->resolve_style_color( isset( $styles['textColor'] ) ? (string) $styles['textColor'] : '', $colors, 'text' );

		$link_url = isset( $settings['header_logo_link_url'] ) ? trim( (string) $settings['header_logo_link_url'] ) : '';
		if ( '' === $link_url ) {
			$link_url = home_url( '/' );
		}

		$logo_url = trim( Email_Branding::resolve_logo_url_for_preview( $settings ) );
		if ( '' !== $logo_url ) {
			$logo_width = isset( $settings['header_logo_width'] ) ? (int) $settings['header_logo_width'] : 280;
			if ( $logo_width < 80 ) {
				$logo_width = 80;
			}
			if ( $logo_width > 560 ) {
				$logo_width = 560;
			}

			$alt = isset( $settings['header_logo_alt'] ) ? trim( (string) $settings['header_logo_alt'] ) : '';
			if ( '' === $alt ) {
				$alt = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			}

			$image = '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $alt ) . '" width="' . esc_attr( (string) $logo_width ) . '" style="display:block;width:100%;max-width:' . esc_attr( (string) $logo_width ) . 'px;height:auto;border:0;margin:0 auto;" />';
			$content = '<a href="' . esc_url( $link_url ) . '" style="text-decoration:none;">' . $image . '</a>';

			return Email_Branding::wrap_region( $content, 'header' );
		}

		$text_html = Email_Branding::resolve_header_text_html( $settings );
		if ( '' === $text_html ) {
			return '';
		}

		$link  = $this->resolve_style_color( isset( $styles['linkColor'] ) ? (string) $styles['linkColor'] : '', $colors, 'link' );
		$align = 'center';
		if ( isset( $styles['align'] ) && in_array( (string) $styles['align'], array( 'left', 'center', 'right' ), true ) ) {
			$align = (string) $styles['align'];
		}
		$inner = Email_Branding::style_header_html_for_email( $text_html, $font, $text, $link, $align );

		return Email_Branding::wrap_region( $inner, 'header' );
	}

	/**
	 * Build default footer HTML from settings.
	 *
	 * @param array<string,mixed>  $context Digest context.
	 * @param array<string,string> $styles  Optional text/muted/link color slugs from the visual block.
	 * @return string
	 */
	private function build_footer_block( array $context, array $styles = array() ): string {
		$settings = Email_Branding::get_settings();
		$colors   = Email_Branding::get_resolved_colors();
		$font     = Email_Branding::resolve_font_family();
		$text     = $this->resolve_style_color( isset( $styles['textColor'] ) ? (string) $styles['textColor'] : '', $colors, 'text' );
		$muted    = $this->resolve_style_color( isset( $styles['mutedColor'] ) ? (string) $styles['mutedColor'] : '', $colors, 'muted' );
		$link     = $this->resolve_style_color( isset( $styles['linkColor'] ) ? (string) $styles['linkColor'] : '', $colors, 'link' );

		$lines = array();

		$identity = isset( $settings['footer_identity'] ) ? trim( (string) $settings['footer_identity'] ) : '';
		if ( '' !== $identity ) {
			$lines[] = '<strong style="color:' . esc_attr( $text ) . ';">' . esc_html( $identity ) . '</strong>';
		}

		$tagline = isset( $settings['footer_tagline'] ) ? trim( (string) $settings['footer_tagline'] ) : '';
		if ( '' !== $tagline ) {
			$lines[] = esc_html( $tagline );
		}

		$address = isset( $settings['footer_address'] ) ? trim( (string) $settings['footer_address'] ) : '';
		if ( '' !== $address ) {
			$address_lines = preg_split( '/\r\n|\r|\n/', $address );
			if ( is_array( $address_lines ) ) {
				foreach ( $address_lines as $address_line ) {
					$address_line = trim( (string) $address_line );
					if ( '' !== $address_line ) {
						$lines[] = esc_html( $address_line );
					}
				}
			}
		}

		$phone = isset( $settings['footer_phone'] ) ? trim( (string) $settings['footer_phone'] ) : '';
		if ( '' !== $phone ) {
			// Explicit tel: link with footer link color — stops iOS/Android auto-linking as system blue.
			$tel_href = $this->footer_tel_href( $phone );
			$lines[]  = esc_html__( 'Phone:', 'we-subscribe-to-posts' ) . ' <a href="' . esc_attr( $tel_href ) . '" style="color:' . esc_attr( $link ) . ';text-decoration:underline;">' . esc_html( $phone ) . '</a>';
		}

		$link_lines = array();
		$this->maybe_add_footer_link( $link_lines, $settings['footer_primary_label'] ?? '', $settings['footer_primary_url'] ?? '', $link );
		$this->maybe_add_footer_link( $link_lines, $settings['footer_secondary_label'] ?? '', $settings['footer_secondary_url'] ?? '', $link );
		$this->maybe_add_footer_link( $link_lines, __( 'Privacy', 'we-subscribe-to-posts' ), $settings['footer_privacy_url'] ?? '', $link );
		$this->maybe_add_footer_link( $link_lines, __( 'Imprint', 'we-subscribe-to-posts' ), $settings['footer_imprint_url'] ?? '', $link );

		if ( ! empty( $link_lines ) ) {
			$lines[] = implode( ' | ', $link_lines );
		}

		$unsubscribe_label = isset( $settings['footer_unsubscribe_label'] ) ? trim( (string) $settings['footer_unsubscribe_label'] ) : '';
		if ( '' === $unsubscribe_label ) {
			$unsubscribe_label = __( 'Unsubscribe instantly', 'we-subscribe-to-posts' );
		}

		$unsubscribe_url = isset( $context['unsubscribe_url'] ) ? (string) $context['unsubscribe_url'] : home_url( '/' );
		$lines[] = '<a href="' . esc_url( $unsubscribe_url ) . '" style="color:' . esc_attr( $link ) . ';text-decoration:underline;">' . esc_html( $unsubscribe_label ) . '</a>';

		// Catch remaining auto-detected links (address/phone) that clients may still inject.
		$detector_css = '<style type="text/css">.wstp-email-footer a,.wstp-email-footer a[x-apple-data-detectors],.wstp-email-footer .x-apple-data-detectors{color:' . esc_attr( $link ) . ' !important;text-decoration:underline !important;}</style>';

		$inner = $detector_css
			. '<div class="wstp-email-footer" style="font-family:' . esc_attr( $font ) . ';color:' . esc_attr( $muted ) . ';font-size:12px;line-height:1.7;">'
			. implode( '<br />', $lines )
			. '</div>';

		return Email_Branding::wrap_region( $inner, 'footer' );
	}

	/**
	 * Build a dialable tel: href from a display phone string.
	 *
	 * @param string $phone Display phone number.
	 * @return string
	 */
	private function footer_tel_href( string $phone ): string {
		$digits = preg_replace( '/[^\d+]/', '', $phone );
		if ( ! is_string( $digits ) || '' === $digits || '+' === $digits ) {
			return 'tel:';
		}
		return 'tel:' . $digits;
	}

	/**
	 * Add a footer link when both label and URL are present.
	 *
	 * @param array<int,string> $links Link accumulator.
	 * @param mixed             $label Raw label.
	 * @param mixed             $url Raw URL.
	 * @param string            $color Link color.
	 * @return void
	 */
	private function maybe_add_footer_link( array &$links, $label, $url, string $color ): void {
		$label = trim( (string) $label );
		$url   = trim( (string) $url );

		if ( '' === $label || '' === $url ) {
			return;
		}

		$links[] = '<a href="' . esc_url( $url ) . '" style="color:' . esc_attr( $color ) . ';text-decoration:underline;">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Render legacy PHP fallback when no compiled template exists.
	 *
	 * @param array<string,mixed> $context Digest context.
	 * @return string
	 */
	private function render_fallback_template( array $context ): string {
		$template_file = WSTP_PATH . 'templates/emails/digest.php';
		if ( ! file_exists( $template_file ) ) {
			return '';
		}

		$greeting_name      = isset( $context['greeting_name'] ) ? (string) $context['greeting_name'] : '';
		$posts              = isset( $context['posts'] ) && is_array( $context['posts'] ) ? $context['posts'] : array();
		$unsubscribe_url    = isset( $context['unsubscribe_url'] ) ? (string) $context['unsubscribe_url'] : '';
		$posts_truncated_by = isset( $context['posts_truncated_by'] ) ? (int) $context['posts_truncated_by'] : 0;

		ob_start();
		include $template_file;

		return (string) ob_get_clean();
	}
}
