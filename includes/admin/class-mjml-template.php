<?php
/**
 * MJML digest email template admin and storage.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Admin;

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
		add_action( 'admin_post_wstp_save_email_branding', array( $this, 'handle_save_branding' ) );
		add_action( 'admin_post_wstp_preview_mjml_template', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_wstp_load_mjml_starter', array( $this, 'handle_load_starter' ) );
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
		wp_enqueue_media();
		wp_enqueue_script(
			'wstp-email-header-settings',
			WSTP_URL . 'assets/js/email-header-settings.js',
			array( 'jquery' ),
			WSTP_VERSION,
			true
		);
		wp_localize_script(
			'wstp-email-header-settings',
			'wstpEmailHeaderSettings',
			array(
				'selectTitle'  => __( 'Select email header logo', 'we-subscribe-to-posts' ),
				'selectButton' => __( 'Use logo', 'we-subscribe-to-posts' ),
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

		return true;
	}

	/**
	 * Available starter templates.
	 *
	 * @return array<string,array{id:string,label:string,description:string}>
	 */
	public static function get_starters(): array {
		return array(
			'stacked' => array(
				'id'          => 'stacked',
				'label'       => __( 'Stacked', 'we-subscribe-to-posts' ),
				'description' => __( 'Featured image above title and excerpt.', 'we-subscribe-to-posts' ),
			),
			'image-left' => array(
				'id'          => 'image-left',
				'label'       => __( 'Image left', 'we-subscribe-to-posts' ),
				'description' => __( 'Two-column layout with image on the left.', 'we-subscribe-to-posts' ),
			),
			'minimal' => array(
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
				'token'       => '{{wstp:posts_intro}}',
				'description' => __( 'Intro line above the post list', 'we-subscribe-to-posts' ),
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
				'description' => __( 'Post excerpt (inside loop)', 'we-subscribe-to-posts' ),
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

		$template        = self::get_template();
		$notice          = isset( $_GET['wstp_template_notice'] ) ? sanitize_key( wp_unslash( $_GET['wstp_template_notice'] ) ) : '';
		$active_tab      = isset( $_GET['tab'] ) && 'branding' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'branding' : 'template';
		$starters        = self::get_starters();
		$branding        = Email_Branding::get_settings();
		$resolved_colors = Email_Branding::get_resolved_colors();
		$theme_palette   = Email_Branding::get_theme_palette_preview();
		$base_url        = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Digest Email Template', 'we-subscribe-to-posts' ); ?></h1>

			<?php $this->render_admin_notice( $notice ); ?>

			<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:20px;">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'template', $base_url ) ); ?>" class="nav-tab <?php echo 'template' === $active_tab ? 'nav-tab-active' : ''; ?>" data-tab="template">
					<?php esc_html_e( 'Template', 'we-subscribe-to-posts' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'branding', $base_url ) ); ?>" class="nav-tab <?php echo 'branding' === $active_tab ? 'nav-tab-active' : ''; ?>" data-tab="branding">
					<?php esc_html_e( 'Branding', 'we-subscribe-to-posts' ); ?>
				</a>
			</nav>

			<div class="wstp-template-tab-panel" data-tab="template" style="<?php echo 'template' !== $active_tab ? 'display:none;' : ''; ?>">
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: external MJML editor URL. */
						__( 'Design your email with <a href="%s" target="_blank" rel="noopener noreferrer">MJML</a>, paste the source here, and save. MJML is compiled in your browser; sent digests use the stored HTML and need no Node.js on the server.', 'we-subscribe-to-posts' ),
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
						</p>
					</form>

					<form id="wstp-mjml-preview-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=wstp_preview_mjml_template' ) ); ?>" target="_blank" style="display:none;">
						<?php wp_nonce_field( 'wstp_preview_mjml_template', 'wstp_mjml_preview_nonce' ); ?>
						<input type="hidden" name="wstp_html_template" id="wstp-mjml-preview-input" value="" />
					</form>

					<hr />

					<h2><?php esc_html_e( 'Starter templates', 'we-subscribe-to-posts' ); ?></h2>
					<div style="display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
						<?php foreach ( $starters as $starter ) : ?>
							<div class="card" style="padding:16px;">
								<h3 style="margin-top:0;"><?php echo esc_html( $starter['label'] ); ?></h3>
								<p><?php echo esc_html( $starter['description'] ); ?></p>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Replace the current template with this starter?', 'we-subscribe-to-posts' ) ); ?>');">
									<input type="hidden" name="action" value="wstp_load_mjml_starter" />
									<input type="hidden" name="wstp_starter_id" value="<?php echo esc_attr( $starter['id'] ); ?>" />
									<?php wp_nonce_field( 'wstp_load_mjml_starter', 'wstp_mjml_starter_nonce' ); ?>
									<?php submit_button( __( 'Use this starter', 'we-subscribe-to-posts' ), 'secondary', 'submit', false ); ?>
								</form>
							</div>
						<?php endforeach; ?>
					</div>

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

		$template = isset( $_POST['wstp_mjml_template'] ) ? $this->sanitize_template( wp_unslash( $_POST['wstp_mjml_template'] ) ) : '';
		$html     = isset( $_POST['wstp_html_template'] ) ? $this->sanitize_template( wp_unslash( $_POST['wstp_html_template'] ) ) : '';

		if ( '' === trim( $html ) ) {
			$this->redirect_with_notice( 'compile_required' );
		}

		update_option( self::OPTION_KEY_MJML, $template, false );
		update_option( self::OPTION_KEY_HTML, $html, false );

		$this->redirect_with_notice( 'saved', 'template' );
	}

	/**
	 * Save branding settings handler.
	 *
	 * @return void
	 */
	public function handle_save_branding(): void {
		$this->assert_admin_post( 'wstp_save_email_branding', 'wstp_email_branding_nonce' );

		$raw = isset( $_POST['wstp_branding'] ) && is_array( $_POST['wstp_branding'] )
			? wp_unslash( $_POST['wstp_branding'] )
			: array();

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

		$starter_id = isset( $_POST['wstp_starter_id'] ) ? self::sanitize_starter_id( wp_unslash( $_POST['wstp_starter_id'] ) ) : '';
		if ( ! self::install_starter( $starter_id ) ) {
			$this->redirect_with_notice( 'starter_missing' );
		}

		$this->redirect_with_notice( 'starter_loaded', 'template' );
	}

	/**
	 * Preview compiled HTML with sample posts.
	 *
	 * @return void
	 */
	public function handle_preview(): void {
		$this->assert_admin_post( 'wstp_preview_mjml_template', 'wstp_mjml_preview_nonce' );

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
	 * Build sample preview context.
	 *
	 * @return array<string,mixed>
	 */
	private function get_preview_context(): array {
		$posts = array();
		$query = new \WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 3,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$image = get_the_post_thumbnail_url( $post, 'large' );
			$posts[] = array(
				'id'                 => (int) $post->ID,
				'title'              => get_the_title( $post ),
				'permalink'          => get_permalink( $post ),
				'featured_image_url' => $image ? $image : '',
				'excerpt'            => has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 42 ),
			);
		}

		return array(
			'greeting_name'      => wp_get_current_user()->display_name ? wp_get_current_user()->display_name : 'Preview',
			'posts'              => $posts,
			'posts_truncated_by' => 0,
			'unsubscribe_url'    => home_url( '/' ),
		);
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
		$tab = 'branding' === $tab ? 'branding' : 'template';

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
			'saved'            => array( 'success', __( 'MJML template saved and compiled HTML updated.', 'we-subscribe-to-posts' ) ),
			'branding_saved'   => array( 'success', __( 'Email branding saved.', 'we-subscribe-to-posts' ) ),
			'starter_loaded'   => array( 'success', __( 'Starter template loaded.', 'we-subscribe-to-posts' ) ),
			'starter_missing'  => array( 'error', __( 'Starter template could not be loaded.', 'we-subscribe-to-posts' ) ),
			'compile_required' => array( 'error', __( 'Compiled HTML is missing. Save again from the template screen so MJML can compile in your browser.', 'we-subscribe-to-posts' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$class = 'success' === $messages[ $code ][0] ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ][1] ) . '</p></div>';
	}
}
