<?php
/**
 * Digest email template post type.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers block-editor template entity for digest emails.
 */
final class Email_Template {
	/**
	 * Custom post type slug.
	 *
	 * @var string
	 */
	private const POST_TYPE = 'wstp_email_template';
	/**
	 * Parent admin menu slug.
	 *
	 * @var string
	 */
	private const PARENT_MENU_SLUG = 'wstp-settings';
	/**
	 * Dedicated template menu slug.
	 *
	 * @var string
	 */
	private const TEMPLATE_MENU_SLUG = 'wstp-email-template';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'default_content', array( $this, 'inject_default_content' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'prevent_multiple_templates' ) );
		add_action( 'admin_init', array( $this, 'handle_template_page_redirect' ) );
		add_action( 'admin_menu', array( $this, 'register_template_submenu' ), 99 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Register template CPT with block editor support.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => __( 'Digest Email Template', 'we-subscribe-to-posts' ),
					'singular_name' => __( 'Digest Email Template', 'we-subscribe-to-posts' ),
					'menu_name'     => __( 'Digest Email Template', 'we-subscribe-to-posts' ),
					'add_new_item'  => __( 'Add Email Template', 'we-subscribe-to-posts' ),
					'edit_item'     => __( 'Edit Email Template', 'we-subscribe-to-posts' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => self::PARENT_MENU_SLUG,
				'show_in_rest'        => true,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'map_meta_cap'        => true,
				'capability_type'     => 'post',
				'exclude_from_search' => true,
				'menu_icon'           => 'dashicons-email-alt',
			)
		);
	}

	/**
	 * Enqueue block definitions for template editor.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'wstp-email-template-blocks',
			WSTP_URL . 'assets/js/email-template-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n', 'wp-components' ),
			WSTP_VERSION,
			true
		);
		wp_set_script_translations(
			'wstp-email-template-blocks',
			'we-subscribe-to-posts',
			WSTP_PATH . 'languages'
		);
	}

	/**
	 * Create default digest template if none exists.
	 *
	 * @return void
	 */
	public static function maybe_create_default_template(): void {
		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			return;
		}

		wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => __( 'Default Digest Email Template', 'we-subscribe-to-posts' ),
				'post_content' => self::default_template_content(),
			)
		);
	}

	/**
	 * Prefill new template posts with standard block content.
	 *
	 * @param string $content Initial editor content.
	 * @param mixed  $post Current post object.
	 * @return string
	 */
	public function inject_default_content( string $content, $post ): string {
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || self::POST_TYPE !== $post->post_type ) {
			return $content;
		}

		if ( isset( $_GET['wstp_template_start'] ) && 'empty' === sanitize_key( wp_unslash( $_GET['wstp_template_start'] ) ) ) {
			return '';
		}

		return self::default_template_content();
	}

	/**
	 * Get latest published email template content.
	 *
	 * @return string
	 */
	public static function get_latest_template_content(): string {
		$templates = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		if ( empty( $templates ) ) {
			return '';
		}

		return (string) $templates[0]->post_content;
	}

	/**
	 * Get the primary template post ID.
	 *
	 * @return int
	 */
	public static function get_primary_template_id(): int {
		$templates = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		if ( empty( $templates ) ) {
			return 0;
		}

		return (int) $templates[0];
	}

	/**
	 * Prevent creating additional template posts.
	 *
	 * @return void
	 */
	public function prevent_multiple_templates(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $pagenow;
		if ( 'edit.php' === $pagenow ) {
			$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
			if ( self::POST_TYPE === $post_type ) {
				$this->redirect_to_template_editor();
			}
		}

		if ( 'post-new.php' !== $pagenow ) {
			return;
		}

		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		$template_id = self::get_primary_template_id();
		if ( $template_id <= 0 ) {
			return;
		}

		wp_safe_redirect( get_edit_post_link( $template_id, 'url' ) );
		exit;
	}

	/**
	 * Replace CPT submenus with a direct editor entry.
	 *
	 * @return void
	 */
	public function register_template_submenu(): void {
		remove_submenu_page(
			self::PARENT_MENU_SLUG,
			'edit.php?post_type=' . self::POST_TYPE
		);

		remove_submenu_page(
			self::PARENT_MENU_SLUG,
			'post-new.php?post_type=' . self::POST_TYPE
		);

		add_submenu_page(
			self::PARENT_MENU_SLUG,
			__( 'Digest Email Template', 'we-subscribe-to-posts' ),
			__( 'Digest Email Template', 'we-subscribe-to-posts' ),
			'manage_options',
			self::TEMPLATE_MENU_SLUG,
			array( $this, 'render_template_menu_page' )
		);
	}

	/**
	 * Submenu callback: redirect straight to template editor.
	 *
	 * @return void
	 */
	public function render_template_menu_page(): void {
		$this->redirect_to_template_editor();
	}

	/**
	 * Early redirect for direct admin.php?page=wstp-email-template requests.
	 *
	 * @return void
	 */
	public function handle_template_page_redirect(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::TEMPLATE_MENU_SLUG !== $page ) {
			return;
		}

		$this->redirect_to_template_editor();
	}

	/**
	 * Redirect to the single template edit screen.
	 *
	 * @return void
	 */
	private function redirect_to_template_editor(): void {
		$template_id = self::get_primary_template_id();
		if ( $template_id <= 0 ) {
			self::maybe_create_default_template();
			$template_id = self::get_primary_template_id();
		}

		if ( $template_id <= 0 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PARENT_MENU_SLUG ) );
			exit;
		}

		$edit_link = get_edit_post_link( $template_id, 'url' );
		if ( false === $edit_link ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PARENT_MENU_SLUG ) );
			exit;
		}

		wp_safe_redirect( $edit_link );
		exit;
	}

	/**
	 * Return default block template content.
	 *
	 * @return string
	 */
	public static function default_template_content(): string {
		return implode(
			"\n\n",
			array(
				'<!-- wp:wstp/greeting /-->',
				'<!-- wp:paragraph --><p>' . esc_html__( 'Here are the latest published posts:', 'we-subscribe-to-posts' ) . '</p><!-- /wp:paragraph -->',
				'<!-- wp:wstp/posts-loop -->' . self::default_loop_item_content() . '<!-- /wp:wstp/posts-loop -->',
				'<!-- wp:wstp/unsubscribe-link /-->',
				'<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->',
				'<!-- wp:paragraph --><p>' . esc_html__( 'Add your legal sender details here (company name, address, contact).', 'we-subscribe-to-posts' ) . '</p><!-- /wp:paragraph -->',
			)
		);
	}

	/**
	 * Return default inner layout for one loop item.
	 *
	 * @return string
	 */
	public static function default_loop_item_content(): string {
		return '
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"30%"} -->
<div class="wp-block-column" style="flex-basis:30%"><!-- wp:wstp/post-image /--></div>
<!-- /wp:column -->

<!-- wp:column {"width":"70%"} -->
<div class="wp-block-column" style="flex-basis:70%"><!-- wp:wstp/post-title /-->

<!-- wp:wstp/post-excerpt /-->

<!-- wp:wstp/post-read-more /--></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->';
	}
}
