<?php
/**
 * Digest template renderer.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Mailer;

use WSTP\Admin\Email_Template;

defined( 'ABSPATH' ) || exit;

/**
 * Renders digest emails from block-editor template content.
 */
final class Digest_Template_Renderer {
	/**
	 * Runtime context for rendering.
	 *
	 * @var array<string,mixed>
	 */
	private static array $context = array();

	/**
	 * Current post in loop rendering.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $current_post = null;

	/**
	 * Register dynamic blocks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_shortcode( 'wstp_greeting', array( $this, 'render_greeting_block' ) );
		add_shortcode( 'wstp_posts_loop', array( $this, 'render_posts_loop_shortcode' ) );
		add_shortcode( 'wstp_unsubscribe_link', array( $this, 'render_unsubscribe_block' ) );
		add_shortcode( 'wstp_footer', array( $this, 'render_footer_shortcode' ) );
	}

	/**
	 * Register all WSTP dynamic blocks.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		register_block_type(
			'wstp/greeting',
			array(
				'render_callback' => array( $this, 'render_greeting_block' ),
			)
		);

		register_block_type(
			'wstp/unsubscribe-link',
			array(
				'render_callback' => array( $this, 'render_unsubscribe_block' ),
			)
		);

		register_block_type(
			'wstp/posts-loop',
			array(
				'render_callback' => array( $this, 'render_posts_loop_block' ),
			)
		);

		register_block_type(
			'wstp/post-image',
			array(
				'render_callback' => array( $this, 'render_post_image_block' ),
			)
		);

		register_block_type(
			'wstp/post-title',
			array(
				'render_callback' => array( $this, 'render_post_title_block' ),
			)
		);

		register_block_type(
			'wstp/post-excerpt',
			array(
				'render_callback' => array( $this, 'render_post_excerpt_block' ),
			)
		);

		register_block_type(
			'wstp/post-read-more',
			array(
				'render_callback' => array( $this, 'render_post_read_more_block' ),
			)
		);
	}

	/**
	 * Render digest body.
	 *
	 * @param array<string,mixed> $context Digest context.
	 * @return string
	 */
	public function render_digest( array $context ): string {
		self::$context = $context;

		$template_content = Email_Template::get_latest_template_content();
		if ( '' === $template_content ) {
			$output = $this->render_fallback_template( $context );
			self::$context = array();

			return $output;
		}

		$output = do_blocks( $template_content );
		$output = (string) $output;

		self::$context = array();

		return '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #111;">' . $output . '</body></html>';
	}

	/**
	 * Render greeting block.
	 *
	 * @return string
	 */
	public function render_greeting_block( array $attributes = array() ): string {
		$greeting_name = isset( self::$context['greeting_name'] ) ? (string) self::$context['greeting_name'] : __( 'there', 'we-subscribe-to-posts' );
		$style_attr    = $this->build_style_attribute(
			$attributes,
			array(
				'margin' => '0 0 16px 0',
			)
		);

		return '<p' . $style_attr . '>' . esc_html(
			sprintf(
				/* translators: %s: subscriber name. */
				__( 'Hi %s,', 'we-subscribe-to-posts' ),
				$greeting_name
			)
		) . '</p>';
	}

	/**
	 * Render posts loop block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content Rendered inner block content.
	 * @param mixed               $block Parsed block object.
	 * @return string
	 */
	public function render_posts_loop_block( array $attributes, string $content, $block = null ): string {
		unset( $attributes );

		if ( empty( self::$context['posts'] ) || ! is_array( self::$context['posts'] ) ) {
			return '<p>' . esc_html__( 'No published posts available yet.', 'we-subscribe-to-posts' ) . '</p>';
		}

		$layout_blocks = array();
		if ( is_object( $block ) && isset( $block->parsed_block['innerBlocks'] ) && is_array( $block->parsed_block['innerBlocks'] ) && ! empty( $block->parsed_block['innerBlocks'] ) ) {
			$layout_blocks = $block->parsed_block['innerBlocks'];
		}

		if ( empty( $layout_blocks ) ) {
			$layout_blocks = parse_blocks( Email_Template::default_loop_item_content() );
		}

		$html       = '';
		$posts      = array_values( self::$context['posts'] );
		$post_count = count( $posts );
		foreach ( $posts as $index => $post_item ) {
			if ( ! is_array( $post_item ) ) {
				continue;
			}

			self::$current_post = $post_item;
			$html              .= '<div style="margin-bottom: 20px;">';
			$html              .= $this->render_loop_blocks( $layout_blocks );
			$html              .= '</div>';

			if ( $index < ( $post_count - 1 ) ) {
				$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse: collapse; width: 100%; margin: 8px 0 18px 0;"><tr><td style="border-top: 1px solid #e5e5e5; line-height: 1px; font-size: 1px;">&nbsp;</td></tr></table>';
			}
		}

		$truncated_by = isset( self::$context['posts_truncated_by'] ) ? (int) self::$context['posts_truncated_by'] : 0;
		if ( $truncated_by > 0 ) {
			$html .= '<p style="margin: 12px 0 0 0; color: #555;">' . esc_html(
				sprintf(
					/* translators: %d: hidden posts count. */
					_n( 'Plus %d more published post not shown due to your limit.', 'Plus %d more published posts not shown due to your limit.', $truncated_by, 'we-subscribe-to-posts' ),
					$truncated_by
				)
			) . '</p>';
		}

		self::$current_post = null;

		return $html;
	}

	/**
	 * Legacy shortcode wrapper for post loop.
	 *
	 * @return string
	 */
	public function render_posts_loop_shortcode(): string {
		return $this->render_posts_loop_block( array(), '' );
	}

	/**
	 * Render unsubscribe block.
	 *
	 * @return string
	 */
	public function render_unsubscribe_block( array $attributes = array() ): string {
		$unsubscribe_url = isset( self::$context['unsubscribe_url'] ) ? (string) self::$context['unsubscribe_url'] : '';
		if ( '' === $unsubscribe_url ) {
			return '';
		}

		$style_attr = $this->build_style_attribute(
			$attributes,
			array(
				'margin' => '12px 0 0 0',
			)
		);
		$link_color = $this->extract_text_color( $attributes );
		$link_style = 'text-decoration: underline;';
		if ( '' !== $link_color ) {
			$link_style .= ' color: ' . $link_color . ' !important;';
		}

		$link_color_attr = '' !== $link_color ? ' color="' . esc_attr( $link_color ) . '"' : '';

		return '<p' . $style_attr . '><a href="' . esc_url( $unsubscribe_url ) . '"' . $link_color_attr . ' style="' . esc_attr( $link_style ) . '"><span' . ( '' !== $link_color ? ' style="color:' . esc_attr( $link_color ) . ' !important;"' : '' ) . '>' . esc_html__( 'Unsubscribe instantly', 'we-subscribe-to-posts' ) . '</span></a></p>';
	}

	/**
	 * Render current post image block.
	 *
	 * @return string
	 */
	public function render_post_image_block( array $attributes = array() ): string {
		if ( ! is_array( self::$current_post ) ) {
			return '';
		}

		$max_width = isset( $attributes['maxWidth'] ) ? (int) $attributes['maxWidth'] : 180;
		if ( $max_width < 80 ) {
			$max_width = 80;
		}
		if ( $max_width > 1200 ) {
			$max_width = 1200;
		}

		$image = '';
		$image_id = isset( self::$current_post['featured_image_id'] ) ? (int) self::$current_post['featured_image_id'] : 0;
		if ( $image_id > 0 ) {
			$image_data = wp_get_attachment_image_src( $image_id, array( $max_width, $max_width ) );
			if ( is_array( $image_data ) && ! empty( $image_data[0] ) ) {
				$image = (string) $image_data[0];
			}
		}

		if ( '' === $image ) {
			$image = isset( self::$current_post['featured_image_url'] ) ? (string) self::$current_post['featured_image_url'] : '';
		}

		if ( '' === $image ) {
			return '';
		}

		$image_style = 'display: block; width: ' . $max_width . 'px; max-width: 100%; height: auto; border: 0;';

		return '<p style="margin: 0;"><img src="' . esc_url( $image ) . '" alt="" width="' . esc_attr( (string) $max_width ) . '" style="' . esc_attr( $image_style ) . '" /></p>';
	}

	/**
	 * Render current post title block.
	 *
	 * @return string
	 */
	public function render_post_title_block( array $attributes = array() ): string {
		if ( ! is_array( self::$current_post ) ) {
			return '';
		}

		$title     = isset( self::$current_post['title'] ) ? (string) self::$current_post['title'] : '';
		$permalink = isset( self::$current_post['permalink'] ) ? (string) self::$current_post['permalink'] : '';

		if ( '' === $title || '' === $permalink ) {
			return '';
		}

		$color      = $this->extract_text_color( $attributes );
		$style_attr = $this->build_style_attribute(
			$attributes,
			array(
				'margin' => '0 0 8px 0',
			)
		);
		$link_style = 'text-decoration: none;' . ( $color ? ' color: ' . $color . ';' : ' color: #111;' );

		return '<h3' . $style_attr . '><a href="' . esc_url( $permalink ) . '" style="' . esc_attr( $link_style ) . '">' . esc_html( $title ) . '</a></h3>';
	}

	/**
	 * Render current post excerpt block.
	 *
	 * @return string
	 */
	public function render_post_excerpt_block( array $attributes = array() ): string {
		if ( ! is_array( self::$current_post ) ) {
			return '';
		}

		$excerpt = isset( self::$current_post['excerpt'] ) ? (string) self::$current_post['excerpt'] : '';
		if ( '' === $excerpt ) {
			return '';
		}

		$style_attr = $this->build_style_attribute(
			$attributes,
			array(
				'margin' => '0 0 10px 0',
			)
		);

		return '<p' . $style_attr . '>' . esc_html( $excerpt ) . '</p>';
	}

	/**
	 * Render current post read-more block.
	 *
	 * @return string
	 */
	public function render_post_read_more_block( array $attributes = array() ): string {
		if ( ! is_array( self::$current_post ) ) {
			return '';
		}

		$permalink = isset( self::$current_post['permalink'] ) ? (string) self::$current_post['permalink'] : '';
		if ( '' === $permalink ) {
			return '';
		}

		$style_attr = $this->build_style_attribute(
			$attributes,
			array(
				'margin' => '0',
			)
		);
		$link_color = $this->extract_text_color( $attributes );
		$link_style = '' !== $link_color ? 'color: ' . $link_color . ' !important;' : '';
		$link_color_attr = '' !== $link_color ? ' color="' . esc_attr( $link_color ) . '"' : '';

		return '<p' . $style_attr . '><a href="' . esc_url( $permalink ) . '"' . $link_color_attr . ' style="' . esc_attr( $link_style ) . '"><span' . ( '' !== $link_color ? ' style="color:' . esc_attr( $link_color ) . ' !important;"' : '' ) . '>' . esc_html__( 'Read more', 'we-subscribe-to-posts' ) . '</span></a></p>';
	}

	/**
	 * Legacy footer shortcode callback.
	 *
	 * @return string
	 */
	public function render_footer_shortcode(): string {
		return '';
	}

	/**
	 * Render parsed loop blocks with email-safe conversions.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return string
	 */
	private function render_loop_blocks( array $blocks ): string {
		$html = '';
		foreach ( $blocks as $parsed_block ) {
			if ( ! is_array( $parsed_block ) ) {
				continue;
			}

			$html .= $this->render_single_loop_block( $parsed_block );
		}

		return $html;
	}

	/**
	 * Render one parsed loop block.
	 *
	 * @param array<string,mixed> $parsed_block Parsed block.
	 * @return string
	 */
	private function render_single_loop_block( array $parsed_block ): string {
		$block_name = isset( $parsed_block['blockName'] ) ? (string) $parsed_block['blockName'] : '';
		if ( 'core/columns' === $block_name ) {
			return $this->render_columns_as_table( $parsed_block );
		}

		return render_block( $parsed_block );
	}

	/**
	 * Render core columns block as table for better mail-client support.
	 *
	 * @param array<string,mixed> $parsed_block Parsed columns block.
	 * @return string
	 */
	private function render_columns_as_table( array $parsed_block ): string {
		$inner_blocks = isset( $parsed_block['innerBlocks'] ) && is_array( $parsed_block['innerBlocks'] ) ? $parsed_block['innerBlocks'] : array();
		$columns      = array_values(
			array_filter(
				$inner_blocks,
				static fn( $inner ): bool => is_array( $inner ) && isset( $inner['blockName'] ) && 'core/column' === $inner['blockName']
			)
		);

		if ( empty( $columns ) ) {
			return render_block( $parsed_block );
		}

		$columns_attrs       = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();
		$parent_vertical_raw = isset( $columns_attrs['verticalAlignment'] ) && is_string( $columns_attrs['verticalAlignment'] ) ? $columns_attrs['verticalAlignment'] : 'top';
		$parent_vertical     = $this->normalize_vertical_alignment( $parent_vertical_raw );
		$count = count( $columns );
		$html  = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse: collapse; width: 100%;"><tr>';

		foreach ( $columns as $index => $column ) {
			$width         = $this->normalize_column_width( $column, $count );
			$column_blocks = isset( $column['innerBlocks'] ) && is_array( $column['innerBlocks'] ) ? $column['innerBlocks'] : array();
			$cell_content  = $this->render_loop_blocks( $column_blocks );
			$padding_right = $index < ( $count - 1 ) ? '16px' : '0';
			$column_attrs  = isset( $column['attrs'] ) && is_array( $column['attrs'] ) ? $column['attrs'] : array();
			$vertical_raw  = isset( $column_attrs['verticalAlignment'] ) && is_string( $column_attrs['verticalAlignment'] ) ? $column_attrs['verticalAlignment'] : $parent_vertical;
			$vertical_css  = $this->normalize_vertical_alignment( $vertical_raw );
			$vertical_attr = $this->vertical_align_to_valign( $vertical_css );

			$html .= '<td valign="' . esc_attr( $vertical_attr ) . '" width="' . esc_attr( $width ) . '" style="vertical-align: ' . esc_attr( $vertical_css ) . '; width: ' . esc_attr( $width ) . '; padding-right: ' . esc_attr( $padding_right ) . ';">';
			$html .= $cell_content;
			$html .= '</td>';
		}

		$html .= '</tr></table>';

		return $html;
	}

	/**
	 * Normalize column width to percentage string.
	 *
	 * @param array<string,mixed> $column Parsed column block.
	 * @param int                 $count Number of columns.
	 * @return string
	 */
	private function normalize_column_width( array $column, int $count ): string {
		$attrs = isset( $column['attrs'] ) && is_array( $column['attrs'] ) ? $column['attrs'] : array();
		if ( isset( $attrs['width'] ) && is_string( $attrs['width'] ) && str_ends_with( trim( $attrs['width'] ), '%' ) ) {
			return trim( $attrs['width'] );
		}

		$default = 100 / max( 1, $count );
		return number_format( $default, 2, '.', '' ) . '%';
	}

	/**
	 * Normalize block-editor vertical alignment value.
	 *
	 * @param string $alignment Raw alignment value.
	 * @return string
	 */
	private function normalize_vertical_alignment( string $alignment ): string {
		$value = strtolower( trim( $alignment ) );
		if ( in_array( $value, array( 'top', 'middle', 'bottom' ), true ) ) {
			return $value;
		}

		if ( 'center' === $value ) {
			return 'middle';
		}

		return 'top';
	}

	/**
	 * Convert CSS vertical-align value to HTML valign value.
	 *
	 * @param string $vertical_align CSS vertical-align.
	 * @return string
	 */
	private function vertical_align_to_valign( string $vertical_align ): string {
		return 'middle' === $vertical_align ? 'middle' : $vertical_align;
	}

	/**
	 * Build style attribute from block typography/color options.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param array<string,string> $base Base style declarations.
	 * @return string
	 */
	private function build_style_attribute( array $attributes, array $base = array() ): string {
		$styles = $base;
		$style  = isset( $attributes['style'] ) && is_array( $attributes['style'] ) ? $attributes['style'] : array();

		if ( isset( $style['typography'] ) && is_array( $style['typography'] ) ) {
			$typography = $style['typography'];
			if ( isset( $typography['fontSize'] ) && is_string( $typography['fontSize'] ) ) {
				$styles['font-size'] = $typography['fontSize'];
			}

			if ( isset( $typography['lineHeight'] ) && is_string( $typography['lineHeight'] ) ) {
				$styles['line-height'] = $typography['lineHeight'];
			}
		}

		$text_color = $this->extract_text_color( $attributes );
		if ( '' !== $text_color ) {
			$styles['color'] = $text_color;
		}

		$background_color = $this->extract_background_color( $attributes );
		if ( '' !== $background_color ) {
			$styles['background-color'] = $background_color;
		}

		if ( isset( $attributes['textAlign'] ) && is_string( $attributes['textAlign'] ) ) {
			$styles['text-align'] = $attributes['textAlign'];
		}

		if ( empty( $styles ) ) {
			return '';
		}

		$declarations = '';
		foreach ( $styles as $property => $value ) {
			$declarations .= $property . ': ' . $value . '; ';
		}

		return ' style="' . esc_attr( trim( $declarations ) ) . '"';
	}

	/**
	 * Extract text color from block attributes.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function extract_text_color( array $attributes ): string {
		if ( isset( $attributes['style'] ) && is_array( $attributes['style'] ) && isset( $attributes['style']['color'] ) && is_array( $attributes['style']['color'] ) && isset( $attributes['style']['color']['text'] ) && is_string( $attributes['style']['color']['text'] ) ) {
			return $this->resolve_color_value( $attributes['style']['color']['text'] );
		}

		if ( isset( $attributes['textColor'] ) && is_string( $attributes['textColor'] ) ) {
			return $this->resolve_color_slug( $attributes['textColor'] );
		}

		if ( isset( $attributes['customTextColor'] ) && is_string( $attributes['customTextColor'] ) ) {
			return $this->resolve_color_value( $attributes['customTextColor'] );
		}

		return '';
	}

	/**
	 * Extract background color from block attributes.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function extract_background_color( array $attributes ): string {
		if ( isset( $attributes['style'] ) && is_array( $attributes['style'] ) && isset( $attributes['style']['color'] ) && is_array( $attributes['style']['color'] ) && isset( $attributes['style']['color']['background'] ) && is_string( $attributes['style']['color']['background'] ) ) {
			return $this->resolve_color_value( $attributes['style']['color']['background'] );
		}

		if ( isset( $attributes['backgroundColor'] ) && is_string( $attributes['backgroundColor'] ) ) {
			return $this->resolve_color_slug( $attributes['backgroundColor'] );
		}

		if ( isset( $attributes['customBackgroundColor'] ) && is_string( $attributes['customBackgroundColor'] ) ) {
			return $this->resolve_color_value( $attributes['customBackgroundColor'] );
		}

		return '';
	}

	/**
	 * Resolve raw color values or preset references to real color values.
	 *
	 * @param string $value Raw color value.
	 * @return string
	 */
	private function resolve_color_value( string $value ): string {
		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			return '';
		}

		if ( str_starts_with( $trimmed, 'var:preset|color|' ) ) {
			$parts = explode( '|', $trimmed );
			$slug  = end( $parts );
			return is_string( $slug ) ? $this->resolve_color_slug( $slug ) : '';
		}

		return $trimmed;
	}

	/**
	 * Resolve color slug from active WP palette.
	 *
	 * @param string $slug Palette slug.
	 * @return string
	 */
	private function resolve_color_slug( string $slug ): string {
		if ( '' === $slug ) {
			return '';
		}

		$settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings() : array();
		$palette  = array();

		if ( isset( $settings['color']['palette'] ) && is_array( $settings['color']['palette'] ) ) {
			foreach ( $settings['color']['palette'] as $source_palette ) {
				if ( is_array( $source_palette ) ) {
					$palette = array_merge( $palette, $source_palette );
				}
			}
		}

		foreach ( $palette as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( isset( $entry['slug'], $entry['color'] ) && $slug === $entry['slug'] && is_string( $entry['color'] ) ) {
				return $entry['color'];
			}
		}

		return '';
	}

	/**
	 * Keep existing fallback template behavior.
	 *
	 * @param array<string,mixed> $context Template context.
	 * @return string
	 */
	private function render_fallback_template( array $context ): string {
		$template_file = WSTP_PATH . 'templates/emails/digest.php';
		if ( ! file_exists( $template_file ) ) {
			return '';
		}

		extract( $context, EXTR_SKIP );
		ob_start();
		include $template_file;

		return (string) ob_get_clean();
	}
}
