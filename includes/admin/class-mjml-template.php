<?php
/**
 * MJML digest email template admin and storage.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Admin;

use WSTP\Mailer\Block_To_Mjml;
use WSTP\Mailer\Email_Branding;
use WSTP\Mailer\Mjml_Template_Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Manages MJML digest template source and admin UI.
 */
final class Mjml_Template {
	/**
	 * Option key for stored MJML source.
	 */
	public const OPTION_KEY_MJML = 'wstp_digest_mjml_template';

	/**
	 * Option key for compiled HTML used when sending digests.
	 */
	public const OPTION_KEY_HTML = 'wstp_digest_html_template';

	/**
	 * Option key for serialized Gutenberg blocks (visual editor).
	 */
	public const OPTION_KEY_BLOCKS = 'wstp_digest_blocks';

	/**
	 * Option key for template editing source: visual|mjml.
	 */
	public const OPTION_KEY_SOURCE = 'wstp_digest_template_source';

	/**
	 * Option key for saved named visual layouts (library).
	 */
	public const OPTION_KEY_LAYOUTS = 'wstp_digest_layout_library';

	/**
	 * Option key for the library layout currently loaded into the active template.
	 */
	public const OPTION_KEY_ACTIVE_LAYOUT = 'wstp_digest_active_layout_id';

	/**
	 * Max named layouts stored in the library.
	 */
	private const LAYOUT_LIBRARY_MAX = 30;

	/**
	 * Default starter template slug.
	 */
	public const DEFAULT_STARTER = 'stacked';

	/**
	 * Parent admin menu slug.
	 */
	private const PARENT_MENU_SLUG = 'wstp-settings';

	/**
	 * Template page slug.
	 */
	private const MENU_SLUG = 'wstp-email-template';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 99 );
		add_action( 'admin_init', array( self::class, 'maybe_install_default' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_wstp_save_mjml_template', array( $this, 'handle_save' ) );
		add_action( 'admin_post_wstp_save_visual_template', array( $this, 'handle_save_visual' ) );
		add_action( 'admin_post_wstp_save_visual_as', array( $this, 'handle_save_visual_as' ) );
		add_action( 'admin_post_wstp_load_saved_layout', array( $this, 'handle_load_saved_layout' ) );
		add_action( 'admin_post_wstp_delete_saved_layout', array( $this, 'handle_delete_saved_layout' ) );
		add_action( 'admin_post_wstp_save_email_branding', array( $this, 'handle_save_branding' ) );
		add_action( 'admin_post_wstp_preview_mjml_template', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_wstp_load_mjml_starter', array( $this, 'handle_load_starter' ) );
		add_action( 'wp_ajax_wstp_map_blocks_to_mjml', array( $this, 'ajax_map_blocks_to_mjml' ) );
	}

	/**
	 * Register submenu page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			self::PARENT_MENU_SLUG,
			__( 'Digest Email Template', 'we-subscribe-to-posts' ),
			__( 'Digest Email Template', 'we-subscribe-to-posts' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets for MJML editing.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_ends_with( $hook_suffix, '_page_' . self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
		wp_enqueue_script(
			'wstp-mjml-browser',
			WSTP_URL . 'assets/js/mjml-browser.min.js',
			array(),
			WSTP_VERSION,
			true
		);
		wp_enqueue_script(
			'wstp-mjml-template-admin',
			WSTP_URL . 'assets/js/mjml-template-admin.js',
			array( 'wstp-mjml-browser' ),
			WSTP_VERSION,
			true
		);
		wp_enqueue_editor();
		wp_enqueue_media();
		$header_settings_js = WSTP_PATH . 'assets/js/email-header-settings.js';
		wp_enqueue_script(
			'wstp-email-header-settings',
			WSTP_URL . 'assets/js/email-header-settings.js',
			array( 'jquery', 'media-editor', 'editor' ),
			is_readable( $header_settings_js ) ? (string) filemtime( $header_settings_js ) : WSTP_VERSION,
			true
		);
		wp_localize_script(
			'wstp-email-header-settings',
			'wstpEmailHeaderSettings',
			array(
				'selectTitle'      => __( 'Select email header logo', 'we-subscribe-to-posts' ),
				'selectButton'     => __( 'Use logo', 'we-subscribe-to-posts' ),
				'mediaUnavailable' => __( 'Media library is not available. Please reload the page.', 'we-subscribe-to-posts' ),
			)
		);
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style(
			'wstp-email-branding-admin',
			WSTP_URL . 'assets/css/email-branding-admin.css',
			array( 'wp-color-picker' ),
			WSTP_VERSION
		);
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script(
			'wstp-email-branding-admin',
			WSTP_URL . 'assets/js/email-branding-admin.js',
			array( 'jquery', 'wp-color-picker' ),
			WSTP_VERSION,
			true
		);

		wp_enqueue_style( 'wp-edit-blocks' );
		wp_enqueue_style( 'wp-block-editor' );
		wp_enqueue_style( 'wp-block-library' );
		wp_enqueue_style( 'wp-format-library' );
		$visual_css = WSTP_PATH . 'assets/css/email-visual-editor.css';
		$visual_js  = WSTP_PATH . 'assets/js/email-visual-editor.js';
		wp_enqueue_style(
			'wstp-email-visual-editor',
			WSTP_URL . 'assets/css/email-visual-editor.css',
			array( 'wp-edit-blocks' ),
			is_readable( $visual_css ) ? (string) filemtime( $visual_css ) : WSTP_VERSION
		);
		wp_enqueue_script(
			'wstp-email-visual-editor',
			WSTP_URL . 'assets/js/email-visual-editor.js',
			array(
				'wp-element',
				'wp-blocks',
				'wp-block-editor',
				'wp-components',
				'wp-data',
				'wp-i18n',
				'wp-block-library',
				'wp-format-library',
				'wp-keyboard-shortcuts',
				'wp-editor',
				'wp-hooks',
				'wstp-mjml-browser',
			),
			is_readable( $visual_js ) ? (string) filemtime( $visual_js ) : WSTP_VERSION,
			true
		);
		$branding_for_editor = Email_Branding::get_settings();
		$header_identity     = Email_Branding::resolve_header_identity( $branding_for_editor );
		wp_localize_script(
			'wstp-email-visual-editor',
			'wstpEmailVisualEditor',
			array(
				'blocks'        => self::get_blocks_template(),
				'source'        => self::get_template_source(),
				'mapAjaxUrl'    => admin_url( 'admin-ajax.php' ),
				'mapNonce'      => wp_create_nonce( 'wstp_map_blocks_to_mjml' ),
				'allowedBlocks' => self::get_allowed_visual_blocks(),
				'palette'       => self::get_visual_editor_palette(),
				'homeUrl'       => home_url( '/' ),
				'branding'      => array(
					'logoUrl'    => Email_Branding::resolve_logo_url_for_preview( $branding_for_editor ),
					'logoAlt'    => (string) ( $branding_for_editor['header_logo_alt'] ?? '' ),
					'logoWidth'  => (int) ( $branding_for_editor['header_logo_width'] ?? 280 ),
					'logoLink'   => (string) ( $branding_for_editor['header_logo_link_url'] ?? home_url( '/' ) ),
					'headerHtml' => Email_Branding::resolve_header_text_html( $branding_for_editor ),
					'title'      => $header_identity['title'],
					'tagline'    => $header_identity['tagline'],
				),
				'samplePost'    => $this->get_visual_editor_sample_post(),
				'i18n'          => array(
					'emailCanvas'          => __( 'Email canvas', 'we-subscribe-to-posts' ),
					'emailCanvasHelp'      => __( 'Outer email background and container for all sections.', 'we-subscribe-to-posts' ),
					'outerBackground'      => __( 'Outer background', 'we-subscribe-to-posts' ),
					'header'               => __( 'Email header', 'we-subscribe-to-posts' ),
					'footer'               => __( 'Email footer (from Branding)', 'we-subscribe-to-posts' ),
					'headerHelp'           => __( 'Add a heading, text, or button — each with its own colors. Or use a logo instead.', 'we-subscribe-to-posts' ),
					'headerPlaceholder'    => __( 'Your brand', 'we-subscribe-to-posts' ),
					'headerLogo'           => __( 'Logo (optional)', 'we-subscribe-to-posts' ),
					'headerLogoHelp'       => __( 'Leave empty to add a heading, text, or button in the header — each with its own colors.', 'we-subscribe-to-posts' ),
					'selectLogo'           => __( 'Select logo', 'we-subscribe-to-posts' ),
					'replaceLogo'          => __( 'Replace logo', 'we-subscribe-to-posts' ),
					'removeLogo'           => __( 'Remove logo', 'we-subscribe-to-posts' ),
					'logoLink'             => __( 'Logo link URL', 'we-subscribe-to-posts' ),
					'logoWidth'            => __( 'Logo max width (px)', 'we-subscribe-to-posts' ),
					'logoAlt'              => __( 'Logo alt text', 'we-subscribe-to-posts' ),
					'contentLink'          => __( 'Link URL', 'we-subscribe-to-posts' ),
					'contentLinkHelp'      => __( 'Makes the whole header text clickable. Or select text and use the link control in the toolbar.', 'we-subscribe-to-posts' ),
					'contentLinkPrompt'    => __( 'Link URL for the header text:', 'we-subscribe-to-posts' ),
					'linkedTo'             => __( 'Links to:', 'we-subscribe-to-posts' ),
					'underlineLinks'       => __( 'Underline links', 'we-subscribe-to-posts' ),
					'underlineLinksHelp'   => __( 'Off by default so linked brand text stays clean.', 'we-subscribe-to-posts' ),
					'contentGap'           => __( 'Space between lines (px)', 'we-subscribe-to-posts' ),
					'contentGapHelp'       => __( 'Gap between heading and paragraph lines inside the header.', 'we-subscribe-to-posts' ),
					'footerHelp'           => __( 'Content comes from the Branding tab.', 'we-subscribe-to-posts' ),
					'intro'                => __( 'Greeting & intro', 'we-subscribe-to-posts' ),
					'introHelp'            => __( 'The greeting is personalized at send time. Add paragraph or heading blocks below for an optional intro or announcement.', 'we-subscribe-to-posts' ),
					'greetingLabel'        => __( 'Greeting (personalized)', 'we-subscribe-to-posts' ),
					/* translators: %s: subscriber name. */
					'greetingSample'       => __( 'Hi %s,', 'we-subscribe-to-posts' ),
					'postsLoop'            => __( 'Posts loop', 'we-subscribe-to-posts' ),
					'truncation'           => __( 'Truncation notice', 'we-subscribe-to-posts' ),
					'truncationHelp'       => __( 'Appears only when the digest post limit hides extra posts. Omitted from the email when unused; spacing applies only then.', 'we-subscribe-to-posts' ),
					'postTitle'            => __( 'Post title', 'we-subscribe-to-posts' ),
					'postTitleHelp'        => __( 'Shows each post title (linked). Style with color, size, and spacing.', 'we-subscribe-to-posts' ),
					'postExcerpt'          => __( 'Post excerpt', 'we-subscribe-to-posts' ),
					'postMeta'             => __( 'Post meta', 'we-subscribe-to-posts' ),
					'postMetaEmpty'        => __( 'Enable date and/or author in the sidebar.', 'we-subscribe-to-posts' ),
					'postField'            => __( 'Post field', 'we-subscribe-to-posts' ),
					'wordCount'            => __( 'Word count', 'we-subscribe-to-posts' ),
					'wordCountHelp'        => __( 'Maximum words per post. Uses the post excerpt when set, otherwise the content.', 'we-subscribe-to-posts' ),
					/* translators: %d: word count. */
					'wordCountLabel'       => __( '%d words', 'we-subscribe-to-posts' ),
					'showDate'             => __( 'Show date', 'we-subscribe-to-posts' ),
					'showAuthor'           => __( 'Show author', 'we-subscribe-to-posts' ),
					'metaSeparator'        => __( 'Separator', 'we-subscribe-to-posts' ),
					'sampleDate'           => __( 'March 15, 2026', 'we-subscribe-to-posts' ),
					'sampleAuthor'         => __( 'Alex', 'we-subscribe-to-posts' ),
					'postImage'            => __( 'Post image', 'we-subscribe-to-posts' ),
					'postImageSide'        => __( 'Post image (side)', 'we-subscribe-to-posts' ),
					'imageSettings'        => __( 'Image', 'we-subscribe-to-posts' ),
					'postReadMore'         => __( 'Read more', 'we-subscribe-to-posts' ),
					'spacing'              => __( 'Spacing', 'we-subscribe-to-posts' ),
					'colors'               => __( 'Colors', 'we-subscribe-to-posts' ),
					'separatorColor'       => __( 'Line color', 'we-subscribe-to-posts' ),
					'separatorSpacingHelp' => __( 'Add top padding so the line does not sit against the button above.', 'we-subscribe-to-posts' ),
					'borders'              => __( 'Borders', 'we-subscribe-to-posts' ),
					'borderTop'            => __( 'Top (px)', 'we-subscribe-to-posts' ),
					'borderRight'          => __( 'Right (px)', 'we-subscribe-to-posts' ),
					'borderBottom'         => __( 'Bottom (px)', 'we-subscribe-to-posts' ),
					'borderLeft'           => __( 'Left (px)', 'we-subscribe-to-posts' ),
					'borderColor'          => __( 'Border color', 'we-subscribe-to-posts' ),
					'typography'           => __( 'Typography', 'we-subscribe-to-posts' ),
					'background'           => __( 'Background', 'we-subscribe-to-posts' ),
					'textColor'            => __( 'Text', 'we-subscribe-to-posts' ),
					'mutedColor'           => __( 'Secondary text', 'we-subscribe-to-posts' ),
					'linkColor'            => __( 'Links', 'we-subscribe-to-posts' ),
					'fontSize'             => __( 'Font size (px)', 'we-subscribe-to-posts' ),
					'fontFamily'           => __( 'Font', 'we-subscribe-to-posts' ),
					'emailFontHelp'        => __( 'Email-safe font stack (Outlook and most clients).', 'we-subscribe-to-posts' ),
					'align'                => __( 'Align', 'we-subscribe-to-posts' ),
					'alignLeft'            => __( 'Left', 'we-subscribe-to-posts' ),
					'alignCenter'          => __( 'Center', 'we-subscribe-to-posts' ),
					'alignRight'           => __( 'Right', 'we-subscribe-to-posts' ),
					'widthPercent'         => __( 'Width (% of column)', 'we-subscribe-to-posts' ),
					'widthPercentHelp'     => __( 'Percent of the column. Compiled to pixels for Outlook (column % × this % × 600px). On mobile the column stacks to full width.', 'we-subscribe-to-posts' ),
					'columnWidth'          => __( 'Column width (%)', 'we-subscribe-to-posts' ),
					'columnWidthHelp'      => __( 'Share of the email width (600px). Example: 34% ≈ 204px in Outlook. Prefer this over dragging — drag can store pixel widths.', 'we-subscribe-to-posts' ),
					'listView'             => __( 'List view', 'we-subscribe-to-posts' ),
					'listViewUnavailable'  => __( 'List view is not available in this WordPress version.', 'we-subscribe-to-posts' ),
					'gapAfter'             => __( 'Gap after (px)', 'we-subscribe-to-posts' ),
					'gapAfterHelp'         => __( 'Space below this field in the email. Shown in the editor canvas as you change it.', 'we-subscribe-to-posts' ),
					'borderRadius'         => __( 'Border radius (px)', 'we-subscribe-to-posts' ),
					'paddingTop'           => __( 'Padding top (px)', 'we-subscribe-to-posts' ),
					'paddingBottom'        => __( 'Padding bottom (px)', 'we-subscribe-to-posts' ),
					'paddingX'             => __( 'Padding left/right (px)', 'we-subscribe-to-posts' ),
					'columnSpacing'        => __( 'Column spacing', 'we-subscribe-to-posts' ),
					'columnGap'            => __( 'Gap between columns (px)', 'we-subscribe-to-posts' ),
					'columnGapHelp'        => __( 'Horizontal when columns sit side by side; vertical when stacked on mobile.', 'we-subscribe-to-posts' ),
					'buttonStyle'          => __( 'Button style', 'we-subscribe-to-posts' ),
					'readMoreStyle'        => __( 'Style', 'we-subscribe-to-posts' ),
					'styleButton'          => __( 'Button', 'we-subscribe-to-posts' ),
					'styleLink'            => __( 'Link', 'we-subscribe-to-posts' ),
					'addBlock'             => __( 'Add block', 'we-subscribe-to-posts' ),
					'bodyPlaceholder'      => __( 'Add email content…', 'we-subscribe-to-posts' ),
					'editorHint'           => __( 'In the Header, add a heading, text, or button. Open the Styles tab (circle icon) for colors and spacing on each block.', 'we-subscribe-to-posts' ),
					'customParagraph'      => __( 'Custom text', 'we-subscribe-to-posts' ),
					'customHeading'        => __( 'Custom heading', 'we-subscribe-to-posts' ),
					'customTextHelp'       => __( 'Your own static copy in the digest (links, bold, and italic are supported).', 'we-subscribe-to-posts' ),
					'blockSettings'        => __( 'Block', 'we-subscribe-to-posts' ),
					'sampleTitle'          => __( 'Sample post title', 'we-subscribe-to-posts' ),
					'sampleExcerpt'        => __( 'Short excerpt of the post…', 'we-subscribe-to-posts' ),
					'readMore'             => __( 'Read more', 'we-subscribe-to-posts' ),
					'mapFailed'            => __( 'Could not convert blocks to MJML.', 'we-subscribe-to-posts' ),
					'compileFail'          => __( 'MJML compilation failed.', 'we-subscribe-to-posts' ),
					'saveAsPrompt'         => __( 'Name for this layout:', 'we-subscribe-to-posts' ),
					'saveAsDefault'        => __( 'My layout', 'we-subscribe-to-posts' ),
					'saveAsEmpty'          => __( 'Please enter a layout name.', 'we-subscribe-to-posts' ),
				),
			)
		);
	}

	/**
	 * Get stored MJML template or default starter source.
	 *
	 * @return string
	 */
	public static function get_template(): string {
		$stored = get_option( self::OPTION_KEY_MJML, '' );
		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return $stored;
		}

		return self::get_starter_mjml( self::DEFAULT_STARTER );
	}

	/**
	 * Get compiled HTML template used for sending.
	 *
	 * @return string
	 */
	public static function get_html_template(): string {
		$stored = get_option( self::OPTION_KEY_HTML, '' );
		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return $stored;
		}

		return self::get_starter_html( self::DEFAULT_STARTER );
	}

	/**
	 * Install default starter templates when none exist.
	 *
	 * @return void
	 */
	public static function maybe_install_default(): void {
		$html = get_option( self::OPTION_KEY_HTML, '' );
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			$starter_html = self::get_starter_html( self::DEFAULT_STARTER );
			if ( '' !== $starter_html ) {
				update_option( self::OPTION_KEY_HTML, $starter_html, false );
			}
		}

		$mjml = get_option( self::OPTION_KEY_MJML, '' );
		if ( ! is_string( $mjml ) || '' === trim( $mjml ) ) {
			$starter_mjml = self::get_starter_mjml( self::DEFAULT_STARTER );
			if ( '' !== $starter_mjml ) {
				update_option( self::OPTION_KEY_MJML, $starter_mjml, false );
			}
		}

		$blocks = get_option( self::OPTION_KEY_BLOCKS, '' );
		if ( ! is_string( $blocks ) || '' === trim( $blocks ) ) {
			update_option( self::OPTION_KEY_BLOCKS, Block_To_Mjml::default_blocks_for_layout( self::DEFAULT_STARTER ), false );
		}

		$source = get_option( self::OPTION_KEY_SOURCE, '' );
		if ( ! is_string( $source ) || ! in_array( $source, array( 'visual', 'mjml' ), true ) ) {
			update_option( self::OPTION_KEY_SOURCE, 'visual', false );
		}
	}

	/**
	 * Install a starter template into options.
	 *
	 * @param string $starter_id Starter slug.
	 * @return bool
	 */
	public static function install_starter( string $starter_id ): bool {
		$starter_id = self::sanitize_starter_id( $starter_id );
		if ( '' === $starter_id ) {
			return false;
		}

		$mjml = self::get_starter_mjml( $starter_id );
		$html = self::get_starter_html( $starter_id );
		if ( '' === $mjml || '' === $html ) {
			return false;
		}

		update_option( self::OPTION_KEY_MJML, $mjml, false );
		update_option( self::OPTION_KEY_HTML, $html, false );
		update_option( self::OPTION_KEY_BLOCKS, Block_To_Mjml::default_blocks_for_layout( $starter_id ), false );
		update_option( self::OPTION_KEY_SOURCE, 'visual', false );
		delete_option( self::OPTION_KEY_ACTIVE_LAYOUT );

		return true;
	}

	/**
	 * Get serialized blocks for the visual editor.
	 *
	 * @return string
	 */
	public static function get_blocks_template(): string {
		$stored = get_option( self::OPTION_KEY_BLOCKS, '' );
		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return Block_To_Mjml::hydrate_header_block_from_branding( $stored );
		}

		return Block_To_Mjml::default_blocks_for_layout( self::DEFAULT_STARTER );
	}

	/**
	 * Get active template source.
	 *
	 * @return string visual|mjml
	 */
	public static function get_template_source(): string {
		$source = get_option( self::OPTION_KEY_SOURCE, 'visual' );
		return in_array( $source, array( 'visual', 'mjml' ), true ) ? $source : 'visual';
	}

	/**
	 * Default admin tab.
	 *
	 * @return string
	 */
	public static function get_default_tab(): string {
		return 'visual' === self::get_template_source() ? 'visual' : 'template';
	}

	/**
	 * Allowed block names for the visual email editor.
	 *
	 * @return array<int,string>
	 */
	public static function get_allowed_visual_blocks(): array {
		return array(
			'wstp/email-shell',
			'wstp/email-header',
			'wstp/email-footer',
			'wstp/intro',
			'wstp/truncation-notice',
			'wstp/posts-loop',
			'wstp/post-title',
			'wstp/post-excerpt',
			'wstp/post-meta',
			'wstp/post-image',
			'wstp/post-image-side',
			'wstp/post-read-more',
			'core/paragraph',
			'core/heading',
			'core/image',
			'core/buttons',
			'core/button',
			'core/columns',
			'core/column',
			'core/separator',
		);
	}

	/**
	 * Color palette for the visual editor (branding palette — unique hex values).
	 *
	 * @return array<int,array{slug:string,name:string,color:string}>
	 */
	public static function get_visual_editor_palette(): array {
		$settings = Email_Branding::get_settings();
		$colors   = isset( $settings['palette_colors'] ) && is_array( $settings['palette_colors'] )
			? $settings['palette_colors']
			: array();

		$labels = array(
			'base'         => __( 'Outer background', 'we-subscribe-to-posts' ),
			'base-two'     => __( 'Content, header, footer, posts', 'we-subscribe-to-posts' ),
			'base-three'   => __( 'Theme surface (optional)', 'we-subscribe-to-posts' ),
			'accent'       => __( 'Body and footer text', 'we-subscribe-to-posts' ),
			'accent-two'   => __( 'Buttons and links', 'we-subscribe-to-posts' ),
			'accent-three' => __( 'Headings (darkest readable theme color)', 'we-subscribe-to-posts' ),
		);

		$order   = array( 'base', 'base-two', 'base-three', 'accent', 'accent-two', 'accent-three' );
		$palette = array();

		foreach ( $order as $slug ) {
			$hex = isset( $colors[ $slug ] ) ? (string) $colors[ $slug ] : '';
			if ( '' === $hex ) {
				continue;
			}
			$palette[] = array(
				'slug'  => $slug,
				'name'  => $labels[ $slug ] ?? $slug,
				'color' => $hex,
			);
		}

		return $palette;
	}

	/**
	 * Available starter templates.
	 *
	 * @return array<string,array{id:string,label:string,description:string}>
	 */
	public static function get_starters(): array {
		return array(
			'stacked'    => array(
				'id'          => 'stacked',
				'label'       => __( 'Stacked', 'we-subscribe-to-posts' ),
				'description' => __( 'Featured image above title and excerpt.', 'we-subscribe-to-posts' ),
			),
			'image-left' => array(
				'id'          => 'image-left',
				'label'       => __( 'Image left', 'we-subscribe-to-posts' ),
				'description' => __( 'Two-column layout with image on the left.', 'we-subscribe-to-posts' ),
			),
			'minimal'    => array(
				'id'          => 'minimal',
				'label'       => __( 'Minimal', 'we-subscribe-to-posts' ),
				'description' => __( 'Text-only digest with a simple serif style.', 'we-subscribe-to-posts' ),
			),
		);
	}

	/**
	 * Load starter MJML file.
	 *
	 * @param string $starter_id Starter slug.
	 * @return string
	 */
	public static function get_starter_mjml( string $starter_id ): string {
		$starter_id = self::sanitize_starter_id( $starter_id );
		if ( '' === $starter_id ) {
			return '';
		}

		$path = WSTP_PATH . 'templates/emails/starters/' . $starter_id . '.mjml';
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin template file.
		return is_string( $content ) ? $content : '';
	}

	/**
	 * Load precompiled starter HTML file shipped with the plugin.
	 *
	 * @param string $starter_id Starter slug.
	 * @return string
	 */
	public static function get_starter_html( string $starter_id ): string {
		$starter_id = self::sanitize_starter_id( $starter_id );
		if ( '' === $starter_id ) {
			return '';
		}

		$path = WSTP_PATH . 'templates/emails/starters/' . $starter_id . '.html';
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin template file.
		return is_string( $content ) ? $content : '';
	}

	/**
	 * Placeholder documentation for admin UI.
	 *
	 * @return array<int,array{token:string,description:string}>
	 */
	public static function get_placeholder_help(): array {
		return array(
			array(
				'token'       => '{{wstp:greeting}}',
				'description' => __( 'Personalized greeting line, e.g. “Hi Anna,”', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:greeting_name}}',
				'description' => __( 'Subscriber name only', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:posts_loop}} ... {{/wstp:posts_loop}}',
				'description' => __( 'Repeat the block inside for each post in the digest', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:post_title}}',
				'description' => __( 'Post title (inside loop)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:post_excerpt}}',
				'description' => __( 'Post excerpt (inside loop; visual editor sets word count)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:post_date}}',
				'description' => __( 'Post date (inside loop)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:post_author}}',
				'description' => __( 'Post author (inside loop)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:post_url}}',
				'description' => __( 'Post permalink (inside loop)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:post_image}}',
				'description' => __( 'Featured image HTML for stacked layouts (inside loop)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:post_image_side}}',
				'description' => __( 'Featured image HTML for side-by-side layouts (inside loop)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:post_image_url}}',
				'description' => __( 'Featured image URL only (inside loop)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:read_more_label}}',
				'description' => __( 'Read-more button label', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:header_block}}',
				'description' => __( 'Ready-made header HTML built from the branding settings below', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:footer_block}}',
				'description' => __( 'Ready-made footer HTML built from the branding settings below', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:color_body_bg}}',
				'description' => __( 'Outer email background (theme: base)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:color_content_bg}}',
				'description' => __( 'Content card background (theme: base-two)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:color_text}}',
				'description' => __( 'Heading text color (theme: darkest readable accent/base-three)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:color_muted}}',
				'description' => __( 'Body/footer text color (theme: accent)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:color_accent}}',
				'description' => __( 'Button background color (theme: accent-two)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:color_link}}',
				'description' => __( 'Link color (theme: accent-two)', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:truncation_notice_block}}',
				'description' => __( 'Optional notice when post limit hides additional posts', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:unsubscribe_url}}',
				'description' => __( 'One-click unsubscribe URL', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:unsubscribe_label}}',
				'description' => __( 'Unsubscribe link label', 'we-subscribe-to-posts' ),
			),
			array(
				'token'       => '{{wstp:site_name}}',
				'description' => __( 'Site name', 'we-subscribe-to-posts' ),
			),
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::ensure_branding_option();
		self::maybe_install_default();

		$template = self::get_template();
		// Read-only admin GET query args for notices/tabs (capability checked above).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only GET params.
		$notice = isset( $_GET['wstp_template_notice'] ) ? sanitize_key( wp_unslash( $_GET['wstp_template_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only GET params.
		$requested_tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::get_default_tab();
		$active_tab         = in_array( $requested_tab, array( 'visual', 'template', 'branding' ), true ) ? $requested_tab : self::get_default_tab();
		$starters           = self::get_starters();
		$saved_layouts      = self::get_layout_library();
		$active_layout      = self::get_active_layout_id();
		$branding           = Email_Branding::get_settings();
		$resolved_colors    = Email_Branding::get_resolved_colors();
		$theme_palette      = Email_Branding::get_theme_palette_preview();
		$template_source    = self::get_template_source();
		$base_url           = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
		$active_layout_name = '';
		if ( '' !== $active_layout ) {
			foreach ( $saved_layouts as $layout_row ) {
				if ( (string) ( $layout_row['id'] ?? '' ) === $active_layout ) {
					$active_layout_name = (string) ( $layout_row['name'] ?? '' );
					break;
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Digest Email Template', 'we-subscribe-to-posts' ); ?></h1>

			<?php $this->render_admin_notice( $notice ); ?>
			<?php $this->render_send_preview_form(); ?>

			<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:20px;">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'visual', $base_url ) ); ?>" class="nav-tab <?php echo 'visual' === $active_tab ? 'nav-tab-active' : ''; ?>" data-tab="visual">
					<?php esc_html_e( 'Visual', 'we-subscribe-to-posts' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'template', $base_url ) ); ?>" class="nav-tab <?php echo 'template' === $active_tab ? 'nav-tab-active' : ''; ?>" data-tab="template">
					<?php esc_html_e( 'MJML', 'we-subscribe-to-posts' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'branding', $base_url ) ); ?>" class="nav-tab <?php echo 'branding' === $active_tab ? 'nav-tab-active' : ''; ?>" data-tab="branding">
					<?php esc_html_e( 'Branding', 'we-subscribe-to-posts' ); ?>
				</a>
			</nav>

			<div class="wstp-template-tab-panel" data-tab="visual" style="<?php echo 'visual' !== $active_tab ? 'display:none;' : ''; ?>">
				<?php if ( 'mjml' === $template_source ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'The template was last saved from the MJML tab. Saving Visual will regenerate MJML from blocks and overwrite advanced MJML edits.', 'we-subscribe-to-posts' ); ?></p></div>
				<?php endif; ?>

				<p class="description"><?php esc_html_e( 'Compose the digest from placeholder blocks (header, intro, posts loop fields, truncation, footer). Use Save as… to keep named copies while you try different layouts. Digests always use the currently saved active template.', 'we-subscribe-to-posts' ); ?></p>

				<?php if ( '' !== $active_layout_name ) : ?>
					<p class="description" style="margin-top:0;">
						<?php esc_html_e( 'Editing saved layout:', 'we-subscribe-to-posts' ); ?>
						<strong><?php echo esc_html( $active_layout_name ); ?></strong>
					</p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wstp-visual-save-form">
					<input type="hidden" name="action" value="wstp_save_visual_template" />
					<input type="hidden" name="wstp_blocks_template" id="wstp-blocks-template" value="" />
					<input type="hidden" name="wstp_mjml_template" id="wstp-visual-mjml-template" value="" />
					<input type="hidden" name="wstp_html_template" id="wstp-visual-html-template" value="" />
					<?php wp_nonce_field( 'wstp_save_visual_template', 'wstp_visual_template_nonce' ); ?>

					<div id="wstp-email-visual-root" class="wstp-email-visual-root"></div>
					<p id="wstp-visual-compile-error" class="notice notice-error inline" style="display:none;margin-top:12px;"></p>
					<p class="submit" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
						<?php submit_button( __( 'Save visual template', 'we-subscribe-to-posts' ), 'primary', 'wstp_save_visual', false ); ?>
						<button type="button" class="button" id="wstp-save-visual-as">
							<?php esc_html_e( 'Save as…', 'we-subscribe-to-posts' ); ?>
						</button>
						<button type="button" class="button" id="wstp-preview-visual">
							<?php esc_html_e( 'Preview HTML', 'we-subscribe-to-posts' ); ?>
						</button>
						<?php $this->render_send_preview_button(); ?>
					</p>
				</form>

				<form id="wstp-visual-save-as-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none;">
					<input type="hidden" name="action" value="wstp_save_visual_as" />
					<input type="hidden" name="wstp_layout_name" id="wstp-save-as-name" value="" />
					<input type="hidden" name="wstp_blocks_template" id="wstp-save-as-blocks" value="" />
					<input type="hidden" name="wstp_mjml_template" id="wstp-save-as-mjml" value="" />
					<input type="hidden" name="wstp_html_template" id="wstp-save-as-html" value="" />
					<?php wp_nonce_field( 'wstp_save_visual_as', 'wstp_save_visual_as_nonce' ); ?>
				</form>

				<form id="wstp-visual-preview-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=wstp_preview_mjml_template' ) ); ?>" target="_blank" style="display:none;">
					<?php wp_nonce_field( 'wstp_preview_mjml_template', 'wstp_mjml_preview_nonce' ); ?>
					<input type="hidden" name="wstp_html_template" id="wstp-visual-preview-input" value="" />
				</form>

				<hr />
				<h2><?php esc_html_e( 'My layouts', 'we-subscribe-to-posts' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Named copies of your visual work. Load one to continue editing it; digests use whatever is currently saved as the active template.', 'we-subscribe-to-posts' ); ?></p>
				<?php if ( empty( $saved_layouts ) ) : ?>
					<p class="description"><?php esc_html_e( 'No saved layouts yet. Use Save as… to keep a copy before trying another starter.', 'we-subscribe-to-posts' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:720px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'we-subscribe-to-posts' ); ?></th>
								<th><?php esc_html_e( 'Updated', 'we-subscribe-to-posts' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'we-subscribe-to-posts' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $saved_layouts as $layout_row ) : ?>
								<?php
								$layout_id   = (string) ( $layout_row['id'] ?? '' );
								$layout_name = (string) ( $layout_row['name'] ?? '' );
								$updated_at  = isset( $layout_row['updated'] ) ? (int) $layout_row['updated'] : 0;
								$is_active   = $layout_id === $active_layout;
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $layout_name ); ?></strong>
										<?php if ( $is_active ) : ?>
											<span class="description"> — <?php esc_html_e( 'loaded', 'we-subscribe-to-posts' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php
										echo $updated_at > 0
											? esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated_at ) )
											: '—';
										?>
									</td>
									<td style="white-space:nowrap;">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<input type="hidden" name="action" value="wstp_load_saved_layout" />
											<input type="hidden" name="wstp_layout_id" value="<?php echo esc_attr( $layout_id ); ?>" />
											<?php wp_nonce_field( 'wstp_load_saved_layout', 'wstp_load_saved_layout_nonce' ); ?>
											<?php submit_button( __( 'Load', 'we-subscribe-to-posts' ), 'secondary', 'submit', false ); ?>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:4px;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this saved layout?', 'we-subscribe-to-posts' ) ); ?>');">
											<input type="hidden" name="action" value="wstp_delete_saved_layout" />
											<input type="hidden" name="wstp_layout_id" value="<?php echo esc_attr( $layout_id ); ?>" />
											<?php wp_nonce_field( 'wstp_delete_saved_layout', 'wstp_delete_saved_layout_nonce' ); ?>
											<?php submit_button( __( 'Delete', 'we-subscribe-to-posts' ), 'delete', 'submit', false ); ?>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<hr />
				<h2><?php esc_html_e( 'Starter templates', 'we-subscribe-to-posts' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Starters replace the active template. Save as… first if you want to keep your current work.', 'we-subscribe-to-posts' ); ?></p>
				<div style="display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
					<?php foreach ( $starters as $starter ) : ?>
						<div class="card" style="padding:16px;">
							<h3 style="margin-top:0;"><?php echo esc_html( $starter['label'] ); ?></h3>
							<p><?php echo esc_html( $starter['description'] ); ?></p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Replace the current active template with this starter? Use Save as… first if you want to keep your work.', 'we-subscribe-to-posts' ) ); ?>');">
								<input type="hidden" name="action" value="wstp_load_mjml_starter" />
								<input type="hidden" name="wstp_starter_id" value="<?php echo esc_attr( $starter['id'] ); ?>" />
								<?php wp_nonce_field( 'wstp_load_mjml_starter', 'wstp_mjml_starter_nonce' ); ?>
								<?php submit_button( __( 'Use this starter', 'we-subscribe-to-posts' ), 'secondary', 'submit', false ); ?>
							</form>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="wstp-template-tab-panel" data-tab="template" style="<?php echo 'template' !== $active_tab ? 'display:none;' : ''; ?>">
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: external MJML editor URL. */
						__( 'Advanced: edit MJML directly. Design with <a href="%s" target="_blank" rel="noopener noreferrer">MJML</a>, paste the source here, and save. Compiled HTML is used when sending digests.', 'we-subscribe-to-posts' ),
						'https://mjml.io/try-it-live'
					)
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wstp-mjml-save-form">
						<input type="hidden" name="action" value="wstp_save_mjml_template" />
						<input type="hidden" name="wstp_html_template" id="wstp-html-template" value="" />
						<?php wp_nonce_field( 'wstp_save_mjml_template', 'wstp_mjml_template_nonce' ); ?>

						<p>
							<label for="wstp-mjml-template"><strong><?php esc_html_e( 'MJML source', 'we-subscribe-to-posts' ); ?></strong></label>
						</p>
						<textarea id="wstp-mjml-template" name="wstp_mjml_template" rows="28" class="large-text code" style="font-family:monospace;width:100%;"><?php echo esc_textarea( $template ); ?></textarea>

						<p id="wstp-mjml-compile-error" class="notice notice-error inline" style="display:none;margin-top:12px;"></p>

						<p class="submit" style="display:flex; gap:8px; flex-wrap:wrap;align-items:center;">
							<?php submit_button( __( 'Save template', 'we-subscribe-to-posts' ), 'primary', 'submit', false ); ?>
							<button type="button" class="button" id="wstp-preview-mjml">
								<?php esc_html_e( 'Preview HTML', 'we-subscribe-to-posts' ); ?>
							</button>
							<?php $this->render_send_preview_button(); ?>
						</p>
					</form>

					<form id="wstp-mjml-preview-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=wstp_preview_mjml_template' ) ); ?>" target="_blank" style="display:none;">
						<?php wp_nonce_field( 'wstp_preview_mjml_template', 'wstp_mjml_preview_nonce' ); ?>
						<input type="hidden" name="wstp_html_template" id="wstp-mjml-preview-input" value="" />
					</form>

			<h2><?php esc_html_e( 'Placeholders', 'we-subscribe-to-posts' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Token', 'we-subscribe-to-posts' ); ?></th>
						<th><?php esc_html_e( 'Description', 'we-subscribe-to-posts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::get_placeholder_help() as $item ) : ?>
						<tr>
							<td><code><?php echo esc_html( $item['token'] ); ?></code></td>
							<td><?php echo esc_html( $item['description'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<div class="wstp-template-tab-panel" data-tab="branding" style="<?php echo 'branding' !== $active_tab ? 'display:none;' : ''; ?>">
				<?php include WSTP_PATH . 'templates/admin/email-branding-form.php'; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Save template handler.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->assert_admin_post( 'wstp_save_mjml_template', 'wstp_mjml_template_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$template = isset( $_POST['wstp_mjml_template'] ) ? $this->sanitize_template( wp_unslash( $_POST['wstp_mjml_template'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$html = isset( $_POST['wstp_html_template'] ) ? $this->sanitize_template( wp_unslash( $_POST['wstp_html_template'] ) ) : '';

		if ( '' === trim( $html ) ) {
			$this->redirect_with_notice( 'compile_required' );
		}

		update_option( self::OPTION_KEY_MJML, $template, false );
		update_option( self::OPTION_KEY_HTML, $html, false );
		update_option( self::OPTION_KEY_SOURCE, 'mjml', false );

		$this->redirect_with_notice( 'saved', 'template' );
	}

	/**
	 * Save visual (blocks) template handler.
	 *
	 * @return void
	 */
	public function handle_save_visual(): void {
		$this->assert_admin_post( 'wstp_save_visual_template', 'wstp_visual_template_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$blocks = isset( $_POST['wstp_blocks_template'] ) ? $this->sanitize_template( wp_unslash( $_POST['wstp_blocks_template'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$mjml = isset( $_POST['wstp_mjml_template'] ) ? $this->sanitize_template( wp_unslash( $_POST['wstp_mjml_template'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$html = isset( $_POST['wstp_html_template'] ) ? $this->sanitize_template( wp_unslash( $_POST['wstp_html_template'] ) ) : '';

		if ( '' === trim( $blocks ) ) {
			$blocks = Block_To_Mjml::default_blocks_for_layout( self::DEFAULT_STARTER );
		}

		if ( '' === trim( $mjml ) ) {
			$mjml = Block_To_Mjml::convert( $blocks );
		}

		if ( '' === trim( $html ) ) {
			$this->redirect_with_notice( 'compile_required', 'visual' );
		}

		update_option( self::OPTION_KEY_BLOCKS, $blocks, false );
		update_option( self::OPTION_KEY_MJML, $mjml, false );
		update_option( self::OPTION_KEY_HTML, $html, false );
		update_option( self::OPTION_KEY_SOURCE, 'visual', false );

		$active_id = self::get_active_layout_id();
		if ( '' !== $active_id ) {
			self::upsert_layout(
				$active_id,
				self::get_layout_name( $active_id ),
				$blocks,
				$mjml,
				$html
			);
		}

		$this->redirect_with_notice( 'visual_saved', 'visual' );
	}

	/**
	 * Save current visual template as a named layout in the library.
	 *
	 * @return void
	 */
	public function handle_save_visual_as(): void {
		$this->assert_admin_post( 'wstp_save_visual_as', 'wstp_save_visual_as_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$name = isset( $_POST['wstp_layout_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wstp_layout_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$blocks = isset( $_POST['wstp_blocks_template'] ) ? $this->sanitize_template( wp_unslash( $_POST['wstp_blocks_template'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$mjml = isset( $_POST['wstp_mjml_template'] ) ? $this->sanitize_template( wp_unslash( $_POST['wstp_mjml_template'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$html = isset( $_POST['wstp_html_template'] ) ? $this->sanitize_template( wp_unslash( $_POST['wstp_html_template'] ) ) : '';

		if ( '' === $name ) {
			$this->redirect_with_notice( 'layout_name_required', 'visual' );
		}

		if ( '' === trim( $blocks ) ) {
			$blocks = Block_To_Mjml::default_blocks_for_layout( self::DEFAULT_STARTER );
		}
		if ( '' === trim( $mjml ) ) {
			$mjml = Block_To_Mjml::convert( $blocks );
		}
		if ( '' === trim( $html ) ) {
			$this->redirect_with_notice( 'compile_required', 'visual' );
		}

		$layout_id = self::create_layout_id();
		$saved     = self::upsert_layout( $layout_id, $name, $blocks, $mjml, $html );
		if ( ! $saved ) {
			$this->redirect_with_notice( 'layout_limit', 'visual' );
		}

		update_option( self::OPTION_KEY_BLOCKS, $blocks, false );
		update_option( self::OPTION_KEY_MJML, $mjml, false );
		update_option( self::OPTION_KEY_HTML, $html, false );
		update_option( self::OPTION_KEY_SOURCE, 'visual', false );
		update_option( self::OPTION_KEY_ACTIVE_LAYOUT, $layout_id, false );

		$this->redirect_with_notice( 'layout_saved_as', 'visual' );
	}

	/**
	 * Load a named layout into the active template.
	 *
	 * @return void
	 */
	public function handle_load_saved_layout(): void {
		$this->assert_admin_post( 'wstp_load_saved_layout', 'wstp_load_saved_layout_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$layout_id = isset( $_POST['wstp_layout_id'] ) ? sanitize_key( wp_unslash( $_POST['wstp_layout_id'] ) ) : '';
		$layout    = self::get_layout( $layout_id );
		if ( null === $layout ) {
			$this->redirect_with_notice( 'layout_missing', 'visual' );
		}

		update_option( self::OPTION_KEY_BLOCKS, (string) $layout['blocks'], false );
		update_option( self::OPTION_KEY_MJML, (string) $layout['mjml'], false );
		update_option( self::OPTION_KEY_HTML, (string) $layout['html'], false );
		update_option( self::OPTION_KEY_SOURCE, 'visual', false );
		update_option( self::OPTION_KEY_ACTIVE_LAYOUT, $layout_id, false );

		$this->redirect_with_notice( 'layout_loaded', 'visual' );
	}

	/**
	 * Delete a named layout from the library.
	 *
	 * @return void
	 */
	public function handle_delete_saved_layout(): void {
		$this->assert_admin_post( 'wstp_delete_saved_layout', 'wstp_delete_saved_layout_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$layout_id = isset( $_POST['wstp_layout_id'] ) ? sanitize_key( wp_unslash( $_POST['wstp_layout_id'] ) ) : '';
		if ( '' === $layout_id || ! self::delete_layout( $layout_id ) ) {
			$this->redirect_with_notice( 'layout_missing', 'visual' );
		}

		if ( self::get_active_layout_id() === $layout_id ) {
			delete_option( self::OPTION_KEY_ACTIVE_LAYOUT );
		}

		$this->redirect_with_notice( 'layout_deleted', 'visual' );
	}

	/**
	 * AJAX: map serialized blocks to MJML.
	 *
	 * @return void
	 */
	public function ajax_map_blocks_to_mjml(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'we-subscribe-to-posts' ) ), 403 );
		}

		check_ajax_referer( 'wstp_map_blocks_to_mjml', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$blocks = isset( $_POST['blocks'] ) ? $this->sanitize_template( wp_unslash( $_POST['blocks'] ) ) : '';
		if ( '' === trim( $blocks ) ) {
			wp_send_json_error( array( 'message' => __( 'No blocks provided.', 'we-subscribe-to-posts' ) ), 400 );
		}

		$mjml = Block_To_Mjml::convert( $blocks );
		wp_send_json_success( array( 'mjml' => $mjml ) );
	}

	/**
	 * Save branding settings handler.
	 *
	 * @return void
	 */
	public function handle_save_branding(): void {
		$this->assert_admin_post( 'wstp_save_email_branding', 'wstp_email_branding_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$branding_data = isset( $_POST['wstp_branding'] ) ? $_POST['wstp_branding'] : array();
		$raw           = is_array( $branding_data ) ? wp_unslash( $branding_data ) : array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		if ( ! empty( $_POST['wstp_reload_palette_from_theme'] ) ) {
			$raw['palette_colors'] = Email_Branding::get_theme_palette_for_storage();
		}

		update_option(
			Email_Branding::OPTION_KEY,
			Email_Branding::sanitize_settings( $raw ),
			false
		);

		$this->redirect_with_notice( 'branding_saved', 'branding' );
	}

	/**
	 * Persist migrated branding settings on first visit.
	 *
	 * @return void
	 */
	private static function ensure_branding_option(): void {
		if ( null !== get_option( Email_Branding::OPTION_KEY, null ) ) {
			return;
		}

		update_option(
			Email_Branding::OPTION_KEY,
			Email_Branding::sanitize_settings( Email_Branding::get_settings() ),
			false
		);
	}

	/**
	 * Load a bundled starter template.
	 *
	 * @return void
	 */
	public function handle_load_starter(): void {
		$this->assert_admin_post( 'wstp_load_mjml_starter', 'wstp_mjml_starter_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$starter_id = isset( $_POST['wstp_starter_id'] ) ? self::sanitize_starter_id( wp_unslash( $_POST['wstp_starter_id'] ) ) : '';
		if ( ! self::install_starter( $starter_id ) ) {
			$this->redirect_with_notice( 'starter_missing' );
		}

		$this->redirect_with_notice( 'starter_loaded', 'visual' );
	}

	/**
	 * Preview compiled HTML with sample posts.
	 *
	 * @return void
	 */
	public function handle_preview(): void {
		$this->assert_admin_post( 'wstp_preview_mjml_template', 'wstp_mjml_preview_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$html = isset( $_POST['wstp_html_template'] ) ? $this->sanitize_template( wp_unslash( $_POST['wstp_html_template'] ) ) : self::get_html_template();
		if ( '' === trim( $html ) ) {
			wp_die(
				esc_html__( 'No compiled HTML template available for preview.', 'we-subscribe-to-posts' ),
				esc_html__( 'Preview failed', 'we-subscribe-to-posts' ),
				array( 'response' => 400 )
			);
		}

		$renderer = new Mjml_Template_Renderer();
		$output   = $renderer->expand_template( $html, $this->get_preview_context() );

		header( 'Content-Type: text/html; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin-only preview of email HTML.
		echo $output;
		exit;
	}

	/**
	 * Sample post data for the visual editor canvas (titles/images).
	 *
	 * @return array<string,string>
	 */
	private function get_visual_editor_sample_post(): array {
		$posts = $this->get_preview_posts( 1 );
		$post  = isset( $posts[0] ) && is_array( $posts[0] ) ? $posts[0] : array();

		$title          = isset( $post['title'] ) ? (string) $post['title'] : __( 'Sample post title', 'we-subscribe-to-posts' );
		$excerpt_source = isset( $post['excerpt_source'] )
			? (string) $post['excerpt_source']
			: ( isset( $post['excerpt'] ) ? (string) $post['excerpt'] : __( 'Short excerpt of the post…', 'we-subscribe-to-posts' ) );
		$excerpt_source = wp_specialchars_decode( $excerpt_source, ENT_QUOTES );
		$excerpt_source = html_entity_decode( $excerpt_source, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$excerpt        = isset( $post['excerpt'] ) ? (string) $post['excerpt'] : wp_trim_words( $excerpt_source, 42 );
		$excerpt        = wp_specialchars_decode( $excerpt, ENT_QUOTES );
		$excerpt        = html_entity_decode( $excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$image = isset( $post['featured_image_url'] ) ? trim( (string) $post['featured_image_url'] ) : '';
		if ( '' === $image ) {
			$image = self::preview_placeholder_image_url();
		}

		return array(
			'title'         => $title,
			'excerpt'       => $excerpt,
			'excerptSource' => $excerpt_source,
			'image'         => $image,
			'date'          => isset( $post['date'] ) ? (string) $post['date'] : wp_date( get_option( 'date_format' ) ),
			'author'        => isset( $post['author'] ) ? (string) $post['author'] : (
				wp_get_current_user()->display_name
					? wp_get_current_user()->display_name
					: __( 'Alex', 'we-subscribe-to-posts' )
			),
			'name'          => wp_get_current_user()->display_name
				? wp_get_current_user()->display_name
				: __( 'Alex', 'we-subscribe-to-posts' ),
		);
	}

	/**
	 * Build sample preview context.
	 *
	 * @return array<string,mixed>
	 */
	private function get_preview_context(): array {
		$posts = $this->get_preview_posts( 3 );

		return array(
			'greeting_name'      => wp_get_current_user()->display_name ? wp_get_current_user()->display_name : 'Preview',
			'posts'              => $posts,
			'posts_truncated_by' => 0,
			'unsubscribe_url'    => home_url( '/' ),
		);
	}

	/**
	 * Latest posts for HTML preview — prefer posts that have a featured image.
	 *
	 * @param int $limit Max posts.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_preview_posts( int $limit = 3 ): array {
		$limit = max( 1, min( 10, $limit ) );
		$posts = array();
		$seen  = array();

		$queries = array(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin preview only.
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'EXISTS',
					),
				),
			),
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			),
		);

		foreach ( $queries as $args ) {
			if ( count( $posts ) >= $limit ) {
				break;
			}
			$query = new \WP_Query( $args );
			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof \WP_Post || isset( $seen[ $post->ID ] ) ) {
					continue;
				}
				$seen[ $post->ID ] = true;
				$posts[]           = $this->map_preview_post( $post );
				if ( count( $posts ) >= $limit ) {
					break;
				}
			}
			wp_reset_postdata();
		}

		if ( empty( $posts ) ) {
			$posts[] = array(
				'id'                 => 0,
				'title'              => __( 'Sample post title', 'we-subscribe-to-posts' ),
				'permalink'          => home_url( '/' ),
				'featured_image_url' => self::preview_placeholder_image_url(),
				'excerpt'            => __( 'Short excerpt of the post…', 'we-subscribe-to-posts' ),
				'excerpt_source'     => __( 'Short excerpt of the post…', 'we-subscribe-to-posts' ),
				'date'               => wp_date( get_option( 'date_format' ) ),
				'author'             => __( 'Alex', 'we-subscribe-to-posts' ),
			);
		}

		return $posts;
	}

	/**
	 * Map a WP_Post to preview digest row data.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	private function map_preview_post( \WP_Post $post ): array {
		$image          = self::resolve_preview_featured_image_url( $post );
		$excerpt_source = has_excerpt( $post )
			? (string) get_the_excerpt( $post )
			: wp_strip_all_tags( (string) $post->post_content );
		$excerpt_source = wp_specialchars_decode( $excerpt_source, ENT_QUOTES );
		$excerpt_source = html_entity_decode( $excerpt_source, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$excerpt        = wp_trim_words( $excerpt_source, 42 );

		return array(
			'id'                 => (int) $post->ID,
			'title'              => get_the_title( $post ),
			'permalink'          => get_permalink( $post ),
			'featured_image_id'  => (int) get_post_thumbnail_id( $post ),
			'featured_image_url' => $image,
			'excerpt'            => $excerpt,
			'excerpt_source'     => $excerpt_source,
			'date'               => get_the_date( '', $post ),
			'author'             => get_the_author_meta( 'display_name', (int) $post->post_author ),
		);
	}

	/**
	 * Absolute featured image URL for preview, with placeholder fallback.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function resolve_preview_featured_image_url( \WP_Post $post ): string {
		$thumb_id = (int) get_post_thumbnail_id( $post );
		if ( $thumb_id > 0 ) {
			foreach ( array( 'large', 'medium_large', 'medium', 'full' ) as $size ) {
				$url = wp_get_attachment_image_url( $thumb_id, $size );
				if ( is_string( $url ) && '' !== $url ) {
					return esc_url_raw( set_url_scheme( $url ) );
				}
			}
		}

		$url = get_the_post_thumbnail_url( $post, 'large' );
		if ( is_string( $url ) && '' !== $url ) {
			return esc_url_raw( set_url_scheme( $url ) );
		}

		return self::preview_placeholder_image_url();
	}

	/**
	 * Neutral placeholder used when a preview post has no featured image.
	 *
	 * Prefer PNG over SVG so HTML email previews render reliably.
	 *
	 * @return string
	 */
	private static function preview_placeholder_image_url(): string {
		$png = WSTP_PATH . 'assets/images/preview-post.png';
		if ( is_readable( $png ) ) {
			return esc_url_raw( set_url_scheme( WSTP_URL . 'assets/images/preview-post.png' ) );
		}
		return esc_url_raw( set_url_scheme( WSTP_URL . 'assets/images/preview-post.svg' ) );
	}

	/**
	 * Sanitize MJML/HTML template source.
	 *
	 * @param string $value Raw template.
	 * @return string
	 */
	private function sanitize_template( string $value ): string {
		$value = wp_check_invalid_utf8( $value );
		return str_replace( "\0", '', $value );
	}

	/**
	 * Sanitize starter slug.
	 *
	 * @param string $starter_id Starter slug.
	 * @return string
	 */
	private static function sanitize_starter_id( string $starter_id ): string {
		$starter_id = sanitize_key( $starter_id );
		return isset( self::get_starters()[ $starter_id ] ) ? $starter_id : '';
	}

	/**
	 * Saved named layouts (newest first).
	 *
	 * @return array<int,array{id:string,name:string,blocks:string,mjml:string,html:string,updated:int}>
	 */
	public static function get_layout_library(): array {
		$raw = get_option( self::OPTION_KEY_LAYOUTS, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}
			$out[] = array(
				'id'      => $id,
				'name'    => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'blocks'  => is_string( $row['blocks'] ?? null ) ? (string) $row['blocks'] : '',
				'mjml'    => is_string( $row['mjml'] ?? null ) ? (string) $row['mjml'] : '',
				'html'    => is_string( $row['html'] ?? null ) ? (string) $row['html'] : '',
				'updated' => isset( $row['updated'] ) ? (int) $row['updated'] : 0,
			);
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return (int) $b['updated'] <=> (int) $a['updated'];
			}
		);

		return $out;
	}

	/**
	 * Active library layout id (empty when not linked).
	 *
	 * @return string
	 */
	public static function get_active_layout_id(): string {
		$id = get_option( self::OPTION_KEY_ACTIVE_LAYOUT, '' );
		return is_string( $id ) ? sanitize_key( $id ) : '';
	}

	/**
	 * Get one layout by id.
	 *
	 * @param string $layout_id Layout id.
	 * @return array{id:string,name:string,blocks:string,mjml:string,html:string,updated:int}|null
	 */
	public static function get_layout( string $layout_id ): ?array {
		$layout_id = sanitize_key( $layout_id );
		if ( '' === $layout_id ) {
			return null;
		}
		foreach ( self::get_layout_library() as $row ) {
			if ( $row['id'] === $layout_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Display name for a layout id.
	 *
	 * @param string $layout_id Layout id.
	 * @return string
	 */
	public static function get_layout_name( string $layout_id ): string {
		$layout = self::get_layout( $layout_id );
		if ( null === $layout ) {
			return __( 'Untitled layout', 'we-subscribe-to-posts' );
		}
		$name = trim( $layout['name'] );
		return '' !== $name ? $name : __( 'Untitled layout', 'we-subscribe-to-posts' );
	}

	/**
	 * Create a unique layout id.
	 *
	 * @return string
	 */
	private static function create_layout_id(): string {
		return 'layout_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
	}

	/**
	 * Insert or update a layout in the library.
	 *
	 * @param string $layout_id Layout id.
	 * @param string $name      Display name.
	 * @param string $blocks    Block markup.
	 * @param string $mjml      MJML source.
	 * @param string $html      Compiled HTML.
	 * @return bool False when library is full and id is new.
	 */
	private static function upsert_layout( string $layout_id, string $name, string $blocks, string $mjml, string $html ): bool {
		$layout_id = sanitize_key( $layout_id );
		$name      = sanitize_text_field( $name );
		if ( '' === $layout_id ) {
			return false;
		}
		if ( '' === $name ) {
			$name = __( 'Untitled layout', 'we-subscribe-to-posts' );
		}

		$library = self::get_layout_library();
		$found   = false;
		foreach ( $library as $index => $row ) {
			if ( $row['id'] === $layout_id ) {
				$library[ $index ] = array(
					'id'      => $layout_id,
					'name'    => $name,
					'blocks'  => $blocks,
					'mjml'    => $mjml,
					'html'    => $html,
					'updated' => time(),
				);
				$found             = true;
				break;
			}
		}

		if ( ! $found ) {
			if ( count( $library ) >= self::LAYOUT_LIBRARY_MAX ) {
				return false;
			}
			$library[] = array(
				'id'      => $layout_id,
				'name'    => $name,
				'blocks'  => $blocks,
				'mjml'    => $mjml,
				'html'    => $html,
				'updated' => time(),
			);
		}

		update_option( self::OPTION_KEY_LAYOUTS, array_values( $library ), false );
		return true;
	}

	/**
	 * Remove a layout from the library.
	 *
	 * @param string $layout_id Layout id.
	 * @return bool
	 */
	private static function delete_layout( string $layout_id ): bool {
		$layout_id = sanitize_key( $layout_id );
		$library   = self::get_layout_library();
		$next      = array();
		$removed   = false;
		foreach ( $library as $row ) {
			if ( $row['id'] === $layout_id ) {
				$removed = true;
				continue;
			}
			$next[] = $row;
		}
		if ( ! $removed ) {
			return false;
		}
		update_option( self::OPTION_KEY_LAYOUTS, $next, false );
		return true;
	}

	/**
	 * Verify admin capability and nonce.
	 *
	 * @param string $action Action name.
	 * @param string $nonce_field Nonce field.
	 * @return void
	 */
	private function assert_admin_post( string $action, string $nonce_field ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'we-subscribe-to-posts' ) );
		}

		if ( ! isset( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'we-subscribe-to-posts' ) );
		}
	}

	/**
	 * Redirect back to template page with notice code.
	 *
	 * @param string $code Notice code.
	 * @param string $tab Active tab slug.
	 * @return void
	 */
	private function redirect_with_notice( string $code, string $tab = 'template' ): void {
		if ( ! in_array( $tab, array( 'visual', 'template', 'branding' ), true ) ) {
			$tab = 'template';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => self::MENU_SLUG,
					'tab'                  => $tab,
					'wstp_template_notice' => $code,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Hidden form for “Send preview now” (shared; buttons use form="…").
	 *
	 * @return void
	 */
	private function render_send_preview_form(): void {
		?>
		<form id="wstp-send-preview-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none;">
			<input type="hidden" name="action" value="wstp_send_preview" />
			<?php wp_nonce_field( 'wstp_send_preview', 'wstp_preview_nonce' ); ?>
		</form>
		<?php
	}

	/**
	 * Send-preview control next to HTML preview (recipient from general settings).
	 *
	 * @return void
	 */
	private function render_send_preview_button(): void {
		$settings = get_option( 'wstp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$preview_email = isset( $settings['preview_email'] ) && is_email( (string) $settings['preview_email'] )
			? (string) $settings['preview_email']
			: (string) get_option( 'admin_email' );

		$title = sprintf(
			/* translators: %s: preview recipient email */
			__( 'Send a preview digest to %s (latest 3 posts)', 'we-subscribe-to-posts' ),
			$preview_email
		);
		?>
		<button type="submit" class="button" form="wstp-send-preview-form" title="<?php echo esc_attr( $title ); ?>">
			<?php esc_html_e( 'Send preview now', 'we-subscribe-to-posts' ); ?>
		</button>
		<?php
	}

	/**
	 * Render admin notice by code.
	 *
	 * @param string $code Notice code.
	 * @return void
	 */
	private function render_admin_notice( string $code ): void {
		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'saved'                 => array( 'success', __( 'MJML template saved and compiled HTML updated.', 'we-subscribe-to-posts' ) ),
			'visual_saved'          => array( 'success', __( 'Visual template saved and compiled HTML updated.', 'we-subscribe-to-posts' ) ),
			'layout_saved_as'       => array( 'success', __( 'Layout saved to My layouts and set as active.', 'we-subscribe-to-posts' ) ),
			'layout_loaded'         => array( 'success', __( 'Saved layout loaded into the editor.', 'we-subscribe-to-posts' ) ),
			'layout_deleted'        => array( 'success', __( 'Saved layout deleted.', 'we-subscribe-to-posts' ) ),
			'layout_missing'        => array( 'error', __( 'Saved layout could not be found.', 'we-subscribe-to-posts' ) ),
			'layout_name_required'  => array( 'error', __( 'Please enter a name for Save as…', 'we-subscribe-to-posts' ) ),
			'layout_limit'          => array( 'error', __( 'Layout library is full. Delete an old layout first.', 'we-subscribe-to-posts' ) ),
			'branding_saved'        => array( 'success', __( 'Email branding saved.', 'we-subscribe-to-posts' ) ),
			'starter_loaded'        => array( 'success', __( 'Starter template loaded.', 'we-subscribe-to-posts' ) ),
			'starter_missing'       => array( 'error', __( 'Starter template could not be loaded.', 'we-subscribe-to-posts' ) ),
			'compile_required'      => array( 'error', __( 'Compiled HTML is missing. Save again so MJML can compile in your browser.', 'we-subscribe-to-posts' ) ),
			'preview_sent'          => array( 'success', __( 'Preview email sent successfully.', 'we-subscribe-to-posts' ) ),
			'preview_failed'        => array( 'error', __( 'Preview email could not be sent.', 'we-subscribe-to-posts' ) ),
			'preview_invalid_email' => array( 'error', __( 'Preview recipient email is invalid. Set it under Post Subscriptions → Settings.', 'we-subscribe-to-posts' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$class = 'success' === $messages[ $code ][0] ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ][1] ) . '</p></div>';
	}
}
