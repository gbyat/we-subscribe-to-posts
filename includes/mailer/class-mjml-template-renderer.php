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
		$tokens = array(
			'{{wstp:greeting_name}}'           => esc_html( $greeting_name ),
			'{{wstp:greeting}}'                => esc_html( $greeting ),
			'{{wstp:posts_intro}}'             => esc_html( __( 'Here are the latest published posts:', 'we-subscribe-to-posts' ) ),
			'{{wstp:unsubscribe_url}}'           => esc_url( isset( $context['unsubscribe_url'] ) ? (string) $context['unsubscribe_url'] : home_url( '/' ) ),
			'{{wstp:unsubscribe_label}}'       => esc_html( __( 'Unsubscribe instantly', 'we-subscribe-to-posts' ) ),
			'{{wstp:read_more_label}}'         => esc_html( __( 'Read more', 'we-subscribe-to-posts' ) ),
			'{{wstp:site_name}}'               => esc_html( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'{{wstp:color_body_bg}}'           => esc_attr( $colors['body_bg'] ),
			'{{wstp:color_content_bg}}'      => esc_attr( $colors['content_bg'] ),
			'{{wstp:color_text}}'              => esc_attr( $colors['text'] ),
			'{{wstp:color_muted}}'             => esc_attr( $colors['muted'] ),
			'{{wstp:color_accent}}'            => esc_attr( $colors['accent'] ),
			'{{wstp:color_link}}'              => esc_attr( $colors['link'] ),
			'{{wstp:header_block}}'            => $this->build_header_block(),
			'{{wstp:footer_block}}'            => $this->build_footer_block( $context ),
			'{{wstp:truncation_notice_block}}' => $this->build_truncation_notice_block( $context ),
		);

		return strtr( $template, $tokens );
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
		$image   = isset( $post['featured_image_url'] ) ? (string) $post['featured_image_url'] : '';

		$tokens = array(
			'{{wstp:post_title}}'      => esc_html( $title ),
			'{{wstp:post_excerpt}}'    => esc_html( $excerpt ),
			'{{wstp:post_url}}'        => esc_url( $url ),
			'{{wstp:post_image_url}}'  => esc_url( $image ),
			'{{wstp:post_image}}'      => $this->build_post_image_tag( $image, $title ),
			'{{wstp:post_image_side}}' => $this->build_post_image_side_tag( $image, $title ),
			'{{wstp:read_more_label}}' => esc_html( __( 'Read more', 'we-subscribe-to-posts' ) ),
		);

		return strtr( $template, $tokens );
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

		return '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $title ) . '" width="560" style="display:block;width:100%;max-width:560px;height:auto;border:0;border-radius:4px;margin:0 0 8px;" />';
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

		return '<img class="wstp-post-image-side" src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $title ) . '" width="560" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:4px;margin:0;" />';
	}

	/**
	 * Build optional truncation notice HTML block.
	 *
	 * @param array<string,mixed> $context Digest context.
	 * @return string
	 */
	private function build_truncation_notice_block( array $context ): string {
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

		return Email_Branding::wrap_region(
			'<div style="font-family:' . esc_attr( $font ) . ';color:' . esc_attr( $colors['muted'] ) . ';font-size:13px;line-height:1.5;text-align:left;">' . esc_html( $message ) . '</div>',
			'notice'
		);
	}

	/**
	 * Build default header HTML from settings.
	 *
	 * @return string
	 */
	private function build_header_block(): string {
		$settings = Email_Branding::get_settings();
		$colors   = Email_Branding::get_resolved_colors();
		$font     = Email_Branding::resolve_font_family();

		$link_url = isset( $settings['header_logo_link_url'] ) ? trim( (string) $settings['header_logo_link_url'] ) : '';
		if ( '' === $link_url ) {
			$link_url = home_url( '/' );
		}

		$logo_url = isset( $settings['header_logo_url'] ) ? trim( (string) $settings['header_logo_url'] ) : '';
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

		$title = isset( $settings['header_title'] ) ? trim( (string) $settings['header_title'] ) : '';
		if ( '' === $title ) {
			return '';
		}

		$tagline = isset( $settings['header_tagline'] ) ? trim( (string) $settings['header_tagline'] ) : '';
		$title_html = '<div style="font-family:' . esc_attr( $font ) . ';font-size:24px;font-weight:bold;color:' . esc_attr( $colors['text'] ) . ';line-height:1.3;text-decoration:underline;">' . esc_html( $title ) . '</div>';
		$tagline_html = '' !== $tagline
			? '<div style="font-family:' . esc_attr( $font ) . ';font-size:13px;color:' . esc_attr( $colors['muted'] ) . ';line-height:1.5;margin-top:6px;">' . esc_html( $tagline ) . '</div>'
			: '';
		$content = '<a href="' . esc_url( $link_url ) . '" style="text-decoration:none;color:inherit;">' . $title_html . $tagline_html . '</a>';

		return Email_Branding::wrap_region( $content, 'header' );
	}

	/**
	 * Build default footer HTML from settings.
	 *
	 * @param array<string,mixed> $context Digest context.
	 * @return string
	 */
	private function build_footer_block( array $context ): string {
		$settings = Email_Branding::get_settings();
		$colors   = Email_Branding::get_resolved_colors();
		$font     = Email_Branding::resolve_font_family();

		$lines = array();

		$identity = isset( $settings['footer_identity'] ) ? trim( (string) $settings['footer_identity'] ) : '';
		if ( '' !== $identity ) {
			$lines[] = '<strong>' . esc_html( $identity ) . '</strong>';
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
			$lines[] = esc_html__( 'Phone:', 'we-subscribe-to-posts' ) . ' ' . esc_html( $phone );
		}

		$link_lines = array();
		$this->maybe_add_footer_link( $link_lines, $settings['footer_primary_label'] ?? '', $settings['footer_primary_url'] ?? '', $colors['link'] );
		$this->maybe_add_footer_link( $link_lines, $settings['footer_secondary_label'] ?? '', $settings['footer_secondary_url'] ?? '', $colors['link'] );
		$this->maybe_add_footer_link( $link_lines, __( 'Privacy', 'we-subscribe-to-posts' ), $settings['footer_privacy_url'] ?? '', $colors['link'] );
		$this->maybe_add_footer_link( $link_lines, __( 'Imprint', 'we-subscribe-to-posts' ), $settings['footer_imprint_url'] ?? '', $colors['link'] );

		if ( ! empty( $link_lines ) ) {
			$lines[] = implode( ' | ', $link_lines );
		}

		$unsubscribe_label = isset( $settings['footer_unsubscribe_label'] ) ? trim( (string) $settings['footer_unsubscribe_label'] ) : '';
		if ( '' === $unsubscribe_label ) {
			$unsubscribe_label = __( 'Unsubscribe instantly', 'we-subscribe-to-posts' );
		}

		$unsubscribe_url = isset( $context['unsubscribe_url'] ) ? (string) $context['unsubscribe_url'] : home_url( '/' );
		$lines[] = '<a href="' . esc_url( $unsubscribe_url ) . '" style="color:' . esc_attr( $colors['link'] ) . ';text-decoration:underline;">' . esc_html( $unsubscribe_label ) . '</a>';

		$inner = '<div style="font-family:' . esc_attr( $font ) . ';color:' . esc_attr( $colors['muted'] ) . ';font-size:12px;line-height:1.7;">' . implode( '<br />', $lines ) . '</div>';

		return Email_Branding::wrap_region( $inner, 'footer' );
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
