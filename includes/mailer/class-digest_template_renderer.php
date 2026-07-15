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
	 * Whether digest email rendering is active.
	 *
	 * @var bool
	 */
	private static bool $is_rendering_digest = false;

	/**
	 * Text color inherited from a parent column/group block.
	 *
	 * @var string
	 */
	private static string $inherited_text_color = '';

	/**
	 * Target content width for fluid email columns.
	 *
	 * @var int
	 */
	private const EMAIL_CONTENT_WIDTH = 600;

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
		self::$context           = $context;
		self::$is_rendering_digest = true;
		add_filter( 'render_block', array( $this, 'filter_render_block_for_email' ), 10, 2 );

		$template_content = Email_Template::get_latest_template_content();
		if ( '' === $template_content ) {
			$output = $this->render_fallback_template( $context );
			remove_filter( 'render_block', array( $this, 'filter_render_block_for_email' ), 10 );
			self::$is_rendering_digest = false;
			self::$context             = array();

			return $output;
		}

		$output = do_blocks( $template_content );
		$output = (string) $output;

		remove_filter( 'render_block', array( $this, 'filter_render_block_for_email' ), 10 );
		self::$is_rendering_digest = false;
		self::$context             = array();

		return $this->wrap_email_document( $output );
	}

	/**
	 * Wrap rendered digest markup in an email-safe HTML document.
	 *
	 * @param string $body_html Rendered body markup.
	 * @return string
	 */
	private function wrap_email_document( string $body_html ): string {
		$styles = $this->get_email_responsive_styles();

		return '<!DOCTYPE html>'
			. '<html><head>'
			. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0" />'
			. '<style type="text/css">' . $styles . '</style>'
			. '</head><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #111; margin: 0; padding: 0;">'
			. '<style type="text/css">' . $styles . '</style>'
			. $body_html
			. '</body></html>';
	}

	/**
	 * Responsive CSS for stacked columns in supporting mail clients.
	 *
	 * @return string
	 */
	private function get_email_responsive_styles(): string {
		return '@media only screen and (max-width: 620px) {'
			. '.wstp-stack-table, .wstp-stack-table tbody, .wstp-stack-table tr, .wstp-stack-table td { display: block !important; width: 100% !important; max-width: 100% !important; }'
			. '.wstp-stack-cell { display: block !important; width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; padding-right: 0 !important; padding-bottom: 12px !important; }'
			. '.wstp-stack-cell:last-child { padding-bottom: 0 !important; }'
			. '.wstp-stack-table img, .wstp-stack-cell img { max-width: 100% !important; width: auto !important; height: auto !important; }'
			. '}';
	}

	/**
	 * Convert blocks to email-safe markup during digest rendering.
	 *
	 * @param string               $html Block HTML.
	 * @param array<string,mixed>  $block Parsed block.
	 * @return string
	 */
	public function filter_render_block_for_email( string $html, array $block ): string {
		if ( ! self::$is_rendering_digest || '' === $html ) {
			return $html;
		}

		$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		if ( 'core/columns' === $block_name ) {
			return $this->render_columns_as_table( $block );
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$attrs = $this->merge_color_from_inner_html( $attrs, $block );

		return $this->ensure_inline_block_colors( $html, $attrs );
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
		$text_color = $this->extract_text_color( $attributes );
		$greeting   = esc_html(
			sprintf(
				/* translators: %s: subscriber name. */
				__( 'Hi %s,', 'we-subscribe-to-posts' ),
				$greeting_name
			)
		);
		if ( '' !== $text_color ) {
			$greeting = $this->wrap_email_colored_text( $greeting, $text_color );
		}

		return '<p' . $style_attr . '>' . $greeting . '</p>';
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

		$layout_blocks = $this->resolve_layout_blocks( $block, $content );

		$html       = '';
		$posts      = array_values( self::$context['posts'] );
		$post_count = count( $posts );
		$saved_inherited_color = self::$inherited_text_color;

		foreach ( $posts as $index => $post_item ) {
			if ( ! is_array( $post_item ) ) {
				continue;
			}

			self::$current_post      = $post_item;
			self::$inherited_text_color = $saved_inherited_color;
			$html                   .= '<div style="margin-bottom: 20px;">';
			$html                   .= $this->render_loop_blocks( $layout_blocks );
			$html                   .= '</div>';

			if ( $index < ( $post_count - 1 ) ) {
				$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse: collapse; width: 100%; margin: 8px 0 18px 0;"><tr><td style="border-top: 1px solid #e5e5e5; line-height: 1px; font-size: 1px;">&nbsp;</td></tr></table>';
			}
		}

		self::$inherited_text_color = $saved_inherited_color;

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

		return '<p' . $style_attr . '>' . $this->build_email_link(
			$unsubscribe_url,
			esc_html__( 'Unsubscribe instantly', 'we-subscribe-to-posts' ),
			$link_color,
			'text-decoration: underline;'
		) . '</p>';
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

		return '<h3' . $style_attr . '>' . $this->build_email_link(
			$permalink,
			esc_html( $title ),
			'' !== $color ? $color : '#111111',
			'text-decoration: none;'
		) . '</h3>';
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

		$text_color = $this->extract_text_color( $attributes );
		$excerpt_html = esc_html( $excerpt );
		if ( '' !== $text_color ) {
			$excerpt_html = $this->wrap_email_colored_text( $excerpt_html, $text_color );
		}

		return '<p' . $style_attr . '>' . $excerpt_html . '</p>';
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

		return '<p' . $style_attr . '>' . $this->build_email_link(
			$permalink,
			esc_html__( 'Read more', 'we-subscribe-to-posts' ),
			$link_color
		) . '</p>';
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

		$attributes = $this->resolve_block_attributes( $parsed_block );

		return match ( $block_name ) {
			'wstp/post-image'    => $this->render_post_image_block( $attributes ),
			'wstp/post-title'    => $this->render_post_title_block( $attributes ),
			'wstp/post-excerpt'  => $this->render_post_excerpt_block( $attributes ),
			'wstp/post-read-more'=> $this->render_post_read_more_block( $attributes ),
			default              => render_block( $parsed_block ),
		};
	}

	/**
	 * Resolve loop layout blocks from saved posts-loop content.
	 *
	 * @param mixed  $block Block instance.
	 * @param string $content Serialized inner block markup.
	 * @return array<int,array<string,mixed>>
	 */
	private function resolve_layout_blocks( $block, string $content ): array {
		$layout_blocks = array();

		if ( is_object( $block ) && isset( $block->inner_blocks ) && ! empty( $block->inner_blocks ) ) {
			foreach ( $block->inner_blocks as $inner_block ) {
				if ( is_object( $inner_block ) && isset( $inner_block->parsed_block ) && is_array( $inner_block->parsed_block ) && ! empty( $inner_block->parsed_block['blockName'] ) ) {
					$layout_blocks[] = $inner_block->parsed_block;
				}
			}
		}

		if ( empty( $layout_blocks ) && is_object( $block ) && isset( $block->parsed_block['innerBlocks'] ) && is_array( $block->parsed_block['innerBlocks'] ) ) {
			$layout_blocks = $this->filter_parsed_blocks( $block->parsed_block['innerBlocks'] );
		}

		if ( empty( $layout_blocks ) && '' !== trim( $content ) ) {
			$layout_blocks = $this->filter_parsed_blocks( parse_blocks( $content ) );
		}

		if ( empty( $layout_blocks ) ) {
			$layout_blocks = $this->filter_parsed_blocks( parse_blocks( Email_Template::default_loop_item_content() ) );
		}

		return $layout_blocks;
	}

	/**
	 * Remove empty parser entries.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_parsed_blocks( array $blocks ): array {
		return array_values(
			array_filter(
				$blocks,
				static fn( $block ): bool => is_array( $block ) && ! empty( $block['blockName'] )
			)
		);
	}

	/**
	 * Merge saved attrs, innerHTML class hints, and inherited colors.
	 *
	 * @param array<string,mixed> $parsed_block Parsed block.
	 * @return array<string,mixed>
	 */
	private function resolve_block_attributes( array $parsed_block ): array {
		$attributes = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();
		$attributes = $this->merge_color_from_inner_html( $attributes, $parsed_block );

		if ( '' === $this->extract_text_color_from_attributes( $attributes ) && '' !== self::$inherited_text_color ) {
			if ( ! isset( $attributes['style'] ) || ! is_array( $attributes['style'] ) ) {
				$attributes['style'] = array();
			}
			if ( ! isset( $attributes['style']['color'] ) || ! is_array( $attributes['style']['color'] ) ) {
				$attributes['style']['color'] = array();
			}
			$attributes['style']['color']['text'] = self::$inherited_text_color;
		}

		return $attributes;
	}

	/**
	 * Recover palette colors from serialized block class names.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param array<string,mixed> $parsed_block Parsed block.
	 * @return array<string,mixed>
	 */
	private function merge_color_from_inner_html( array $attributes, array $parsed_block ): array {
		if ( '' !== $this->extract_text_color_from_attributes( $attributes ) ) {
			return $attributes;
		}

		$inner_html = isset( $parsed_block['innerHTML'] ) ? (string) $parsed_block['innerHTML'] : '';
		if ( '' === $inner_html && isset( $parsed_block['innerContent'] ) && is_array( $parsed_block['innerContent'] ) ) {
			$inner_html = implode( '', array_map( 'strval', $parsed_block['innerContent'] ) );
		}

		if ( '' === $inner_html ) {
			return $attributes;
		}

		if ( preg_match( '/\bhas-([a-z0-9-]+)-color\b/i', $inner_html, $matches ) ) {
			$attributes['textColor'] = sanitize_key( $matches[1] );
		}

		if ( preg_match( '/\bstyle="[^"]*?\bcolor\s*:\s*(#[0-9a-fA-F]{3,8}|rgba?\([^"\)]+)\)/i', $inner_html, $matches ) ) {
			if ( ! isset( $attributes['style'] ) || ! is_array( $attributes['style'] ) ) {
				$attributes['style'] = array();
			}
			if ( ! isset( $attributes['style']['color'] ) || ! is_array( $attributes['style']['color'] ) ) {
				$attributes['style']['color'] = array();
			}
			$attributes['style']['color']['text'] = trim( $matches[1] );
		}

		return $attributes;
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
		$stack_on_mobile     = ! array_key_exists( 'isStackedOnMobile', $columns_attrs ) || ! empty( $columns_attrs['isStackedOnMobile'] );
		$count               = count( $columns );

		if ( $stack_on_mobile ) {
			return $this->render_columns_fluid_stack( $columns, $parent_vertical, $count );
		}

		$html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse: collapse; width: 100%;"><tr>';

		foreach ( $columns as $index => $column ) {
			$width         = $this->normalize_column_width( $column, $count );
			$column_blocks = isset( $column['innerBlocks'] ) && is_array( $column['innerBlocks'] ) ? $column['innerBlocks'] : array();
			$cell_content  = $this->render_column_blocks( $column, $column_blocks );
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
	 * Render columns using inline-block stacking for narrow screens.
	 *
	 * @param array<int,array<string,mixed>> $columns Parsed column blocks.
	 * @param string                         $parent_vertical Parent vertical alignment.
	 * @param int                            $count Number of columns.
	 * @return string
	 */
	private function render_columns_fluid_stack( array $columns, string $parent_vertical, int $count ): string {
		$html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="wstp-stack-table" style="border-collapse: collapse; width: 100%;"><tr><td align="left" style="font-size: 0; line-height: 0; mso-line-height-rule: exactly;">';

		foreach ( $columns as $index => $column ) {
			$width_pct     = $this->normalize_column_width_value( $column, $count );
			$max_width_px  = max( 120, (int) round( self::EMAIL_CONTENT_WIDTH * ( $width_pct / 100 ) ) );
			$column_blocks = isset( $column['innerBlocks'] ) && is_array( $column['innerBlocks'] ) ? $column['innerBlocks'] : array();
			$cell_content  = $this->render_column_blocks( $column, $column_blocks );
			$column_attrs  = isset( $column['attrs'] ) && is_array( $column['attrs'] ) ? $column['attrs'] : array();
			$vertical_raw  = isset( $column_attrs['verticalAlignment'] ) && is_string( $column_attrs['verticalAlignment'] ) ? $column_attrs['verticalAlignment'] : $parent_vertical;
			$vertical_css  = $this->normalize_vertical_alignment( $vertical_raw );
			$padding_right = $index < ( $count - 1 ) ? '16px' : '0';
			$column_style  = 'display: inline-block; vertical-align: ' . $vertical_css . '; width: 100%; max-width: ' . $max_width_px . 'px; font-size: 16px; line-height: 1.6; box-sizing: border-box; padding-right: ' . $padding_right . ';';

			$html .= '<div class="wstp-stack-cell" style="' . esc_attr( $column_style ) . '">';
			$html .= $cell_content;
			$html .= '</div>';
		}

		$html .= '</td></tr></table>';

		return $html;
	}

	/**
	 * Render blocks inside a column while inheriting column text color.
	 *
	 * @param array<string,mixed>            $column Parsed column block.
	 * @param array<int,array<string,mixed>> $column_blocks Parsed inner blocks.
	 * @return string
	 */
	private function render_column_blocks( array $column, array $column_blocks ): string {
		$column_attrs   = isset( $column['attrs'] ) && is_array( $column['attrs'] ) ? $column['attrs'] : array();
		$previous_color = self::$inherited_text_color;
		$column_color   = $this->extract_text_color( $column_attrs );

		if ( '' !== $column_color ) {
			self::$inherited_text_color = $column_color;
		}

		$html = $this->render_loop_blocks( $column_blocks );

		self::$inherited_text_color = $previous_color;

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
		$value = $this->normalize_column_width_value( $column, $count );

		return number_format( $value, 2, '.', '' ) . '%';
	}

	/**
	 * Normalize column width to numeric percentage.
	 *
	 * @param array<string,mixed> $column Parsed column block.
	 * @param int                 $count Number of columns.
	 * @return float
	 */
	private function normalize_column_width_value( array $column, int $count ): float {
		$attrs = isset( $column['attrs'] ) && is_array( $column['attrs'] ) ? $column['attrs'] : array();
		if ( isset( $attrs['width'] ) && is_string( $attrs['width'] ) && str_ends_with( trim( $attrs['width'] ), '%' ) ) {
			return (float) rtrim( trim( $attrs['width'] ), '%' );
		}

		return 100 / max( 1, $count );
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
			$styles['color'] = $text_color . ' !important';
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
		$color = $this->extract_text_color_from_attributes( $attributes );
		if ( '' !== $color ) {
			return $color;
		}

		return self::$inherited_text_color;
	}

	/**
	 * Extract text color only from explicit block attributes.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	private function extract_text_color_from_attributes( array $attributes ): string {
		if ( isset( $attributes['style'] ) && is_array( $attributes['style'] ) ) {
			$style = $attributes['style'];

			if ( isset( $style['color'] ) && is_array( $style['color'] ) && isset( $style['color']['text'] ) && is_string( $style['color']['text'] ) ) {
				$resolved = $this->resolve_color_value( $style['color']['text'] );
				if ( '' !== $resolved ) {
					return $resolved;
				}
			}

			if ( isset( $style['elements']['link']['color']['text'] ) && is_string( $style['elements']['link']['color']['text'] ) ) {
				$resolved = $this->resolve_color_value( $style['elements']['link']['color']['text'] );
				if ( '' !== $resolved ) {
					return $resolved;
				}
			}
		}

		if ( isset( $attributes['textColor'] ) && is_string( $attributes['textColor'] ) ) {
			if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $attributes['textColor'] ) ) {
				return $attributes['textColor'];
			}

			$resolved = $this->resolve_color_slug( $attributes['textColor'] );
			if ( '' !== $resolved ) {
				return $resolved;
			}
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

		foreach ( $this->get_color_palette_entries() as $entry ) {
			if ( isset( $entry['slug'], $entry['color'] ) && $slug === $entry['slug'] && is_string( $entry['color'] ) ) {
				return $entry['color'];
			}
		}

		return '';
	}

	/**
	 * Collect palette entries from theme.json and legacy theme supports.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_color_palette_entries(): array {
		$palette = array();

		if ( function_exists( 'wp_get_global_settings' ) ) {
			$settings = wp_get_global_settings();
			if ( isset( $settings['color']['palette'] ) && is_array( $settings['color']['palette'] ) ) {
				foreach ( $settings['color']['palette'] as $source_palette ) {
					if ( is_array( $source_palette ) ) {
						$palette = array_merge( $palette, $source_palette );
					}
				}
			}
		}

		if ( class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			$theme_json = \WP_Theme_JSON_Resolver::get_merged_data();
			$settings   = $theme_json->get_settings();
			if ( isset( $settings['color']['palette'] ) && is_array( $settings['color']['palette'] ) ) {
				foreach ( $settings['color']['palette'] as $entry ) {
					if ( is_array( $entry ) ) {
						$palette[] = $entry;
					}
				}
			}
		}

		$theme_palette = get_theme_support( 'editor-color-palette' );
		if ( is_array( $theme_palette ) && isset( $theme_palette[0] ) && is_array( $theme_palette[0] ) ) {
			$palette = array_merge( $palette, $theme_palette[0] );
		}

		$palette = array_merge( $palette, $this->get_default_wordpress_palette_entries() );

		return $palette;
	}

	/**
	 * Default WordPress editor palette slugs.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_default_wordpress_palette_entries(): array {
		$defaults = array(
			'black'                 => '#000000',
			'cyan-bluish-gray'      => '#abb8c3',
			'white'                 => '#ffffff',
			'pale-pink'             => '#f78da7',
			'vivid-red'             => '#cf2e2e',
			'luminous-vivid-orange' => '#ff6900',
			'luminous-vivid-amber'  => '#fcb900',
			'light-green-cyan'      => '#7bdcb5',
			'vivid-green-cyan'      => '#00d084',
			'pale-cyan-blue'        => '#8ed1fc',
			'vivid-cyan-blue'       => '#0693e3',
			'vivid-purple'          => '#9b51e0',
		);

		$entries = array();
		foreach ( $defaults as $slug => $color ) {
			$entries[] = array(
				'slug'  => $slug,
				'color' => $color,
			);
		}

		return $entries;
	}

	/**
	 * Build an email-safe link with inline and legacy color attributes.
	 *
	 * @param string $url Link URL.
	 * @param string $label Link label HTML (already escaped).
	 * @param string $color Text color.
	 * @param string $extra_link_style Additional link CSS declarations.
	 * @return string
	 */
	private function build_email_link( string $url, string $label, string $color = '', string $extra_link_style = '' ): string {
		$link_style = trim( $extra_link_style );
		if ( '' !== $color ) {
			$link_style .= ( '' !== $link_style ? ' ' : '' ) . 'color: ' . $color . ' !important;';
		}

		$color_attr = '' !== $color ? ' color="' . esc_attr( $color ) . '"' : '';
		$style_attr = '' !== $link_style ? ' style="' . esc_attr( $link_style ) . '"' : '';
		$label_html = '' !== $color ? $this->wrap_email_colored_text( $label, $color ) : $label;

		return '<a href="' . esc_url( $url ) . '"' . $color_attr . $style_attr . '>' . $label_html . '</a>';
	}

	/**
	 * Wrap text with email-client-safe color markup.
	 *
	 * @param string $html Inner HTML (already escaped).
	 * @param string $color Text color.
	 * @return string
	 */
	private function wrap_email_colored_text( string $html, string $color ): string {
		if ( '' === $color ) {
			return $html;
		}

		return '<span style="color:' . esc_attr( $color ) . ' !important;"><font color="' . esc_attr( $color ) . '">' . $html . '</font></span>';
	}

	/**
	 * Ensure core block output includes inline text colors for mail clients.
	 *
	 * @param string               $html Block HTML.
	 * @param array<string,mixed>  $attributes Block attributes.
	 * @return string
	 */
	private function ensure_inline_block_colors( string $html, array $attributes ): string {
		$text_color = $this->extract_text_color( $attributes );
		if ( '' === $text_color ) {
			return $html;
		}

		if ( preg_match( '/\bcolor\s*:\s*[^;"\'\s]/i', $html ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/^(\<[a-z0-9]+)([^>]*>)/i',
			function ( array $matches ) use ( $text_color ): string {
				$tag   = $matches[1];
				$rest  = $matches[2];
				$color = 'color: ' . $text_color . ' !important;';

				if ( preg_match( '/\sstyle=(["\'])(.*?)\1/i', $rest, $style_match ) ) {
					$new_style = rtrim( $style_match[2], '; ' ) . '; ' . $color;
					$rest      = (string) preg_replace(
						'/\sstyle=(["\']).*?\1/i',
						' style="' . esc_attr( $new_style ) . '"',
						$rest,
						1
					);
				} else {
					$rest = ' style="' . esc_attr( $color ) . '"' . $rest;
				}

				return $tag . $rest;
			},
			$html,
			1
		);
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
