<?php
/**
 * Frontend subscription form.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Frontend;

use WSTP\DB\Subscriber_Repository;
use WSTP\Subscription\Double_Optin_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Renders form and handles submit.
 */
final class Subscription_Form {
	/**
	 * Repository.
	 *
	 * @var Subscriber_Repository
	 */
	private Subscriber_Repository $subscriber_repository;

	/**
	 * DOI service.
	 *
	 * @var Double_Optin_Service
	 */
	private Double_Optin_Service $double_optin_service;

	/**
	 * Constructor.
	 *
	 * @param Subscriber_Repository $subscriber_repository Subscriber repo.
	 * @param Double_Optin_Service  $double_optin_service DOI service.
	 */
	public function __construct( Subscriber_Repository $subscriber_repository, Double_Optin_Service $double_optin_service ) {
		$this->subscriber_repository = $subscriber_repository;
		$this->double_optin_service  = $double_optin_service;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_shortcode( 'wstp_subscription_form', array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'admin_post_nopriv_wstp_subscribe', array( $this, 'handle_submit' ) );
		add_action( 'admin_post_wstp_subscribe', array( $this, 'handle_submit' ) );
		add_action( 'wp_footer', array( $this, 'render_global_status_notice_fallback' ), 100 );
	}

	/**
	 * Register Gutenberg block for form embedding.
	 *
	 * @return void
	 */
	public function register_block(): void {
		register_block_type(
			'wstp/subscription-form',
			array(
				'attributes'      => array(
					'compact'           => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'default_frequency' => array(
						'type'    => 'string',
						'default' => 'daily',
					),
					'button_label'      => array(
						'type'    => 'string',
						'default' => __( 'Subscribe', 'we-subscribe-to-posts' ),
					),
					'button_use_custom_style' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'button_bg_color'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'button_text_color' => array(
						'type'    => 'string',
						'default' => '',
					),
					'button_radius'     => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Enqueue editor script for form block.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		wp_enqueue_script(
			'wstp-subscription-form-block',
			WSTP_URL . 'assets/js/subscription-form-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ),
			WSTP_VERSION,
			true
		);
		wp_set_script_translations(
			'wstp-subscription-form-block',
			'we-subscribe-to-posts',
			WSTP_PATH . 'languages'
		);
	}

	/**
	 * Render shortcode output.
	 *
	 * @return string
	 */
	public function render_shortcode( array $atts = array() ): string {
		wp_enqueue_style(
			'wstp-subscription-form',
			WSTP_URL . 'assets/css/subscription-form.css',
			array(),
			WSTP_VERSION
		);

		$atts = $this->normalize_block_attributes( $atts );

		$atts = shortcode_atts(
			array(
				'compact'           => false,
				'default_frequency' => 'daily',
				'button_label'      => __( 'Subscribe', 'we-subscribe-to-posts' ),
				'button_use_custom_style' => false,
				'button_bg_color'   => '',
				'button_text_color' => '',
				'button_radius'     => 0,
			),
			$atts,
			'wstp_subscription_form'
		);

		$compact           = filter_var( $atts['compact'], FILTER_VALIDATE_BOOLEAN );
		$default_frequency = sanitize_key( (string) $atts['default_frequency'] );
		if ( ! in_array( $default_frequency, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			$default_frequency = 'daily';
		}

		$button_label      = sanitize_text_field( (string) $atts['button_label'] );
		$button_use_custom_style = filter_var( $atts['button_use_custom_style'], FILTER_VALIDATE_BOOLEAN );
		$button_bg_color   = $this->resolve_color_value( (string) $atts['button_bg_color'] );
		$button_text_color = $this->resolve_color_value( (string) $atts['button_text_color'] );
		$button_radius     = (int) $atts['button_radius'];
		if ( $button_radius < 0 ) {
			$button_radius = 0;
		}
		if ( $button_radius > 24 ) {
			$button_radius = 24;
		}

		if ( '#1d4ed8' === $button_bg_color && '#ffffff' === $button_text_color && 6 === $button_radius ) {
			$button_use_custom_style = false;
		}

		$frequency_options = array(
			'daily'   => __( 'Daily (on days with new posts)', 'we-subscribe-to-posts' ),
			'weekly'  => __( 'Weekly', 'we-subscribe-to-posts' ),
			'monthly' => __( 'Monthly', 'we-subscribe-to-posts' ),
		);

		$settings = get_option( 'wstp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$privacy_text = isset( $settings['privacy_notice'] ) && is_string( $settings['privacy_notice'] )
			? $settings['privacy_notice']
			: __( 'I agree that my email address is used only for post update notifications.', 'we-subscribe-to-posts' );
		$status_notice_style = isset( $settings['status_notice_style'] ) && is_string( $settings['status_notice_style'] ) ? sanitize_key( $settings['status_notice_style'] ) : 'toast';
		if ( ! in_array( $status_notice_style, array( 'toast', 'overlay', 'inline' ), true ) ) {
			$status_notice_style = 'toast';
		}
		$status_notice_position = isset( $settings['status_notice_position'] ) && is_string( $settings['status_notice_position'] ) ? sanitize_key( $settings['status_notice_position'] ) : 'bottom-right';
		if ( ! in_array( $status_notice_position, array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ), true ) ) {
			$status_notice_position = 'bottom-right';
		}
		$status_notice_seconds = isset( $settings['status_notice_seconds'] ) ? (int) $settings['status_notice_seconds'] : 8;
		if ( $status_notice_seconds < 0 ) {
			$status_notice_seconds = 0;
		}
		if ( $status_notice_seconds > 60 ) {
			$status_notice_seconds = 60;
		}
		$status       = isset( $_GET['wstp_status'] ) ? sanitize_key( wp_unslash( $_GET['wstp_status'] ) ) : '';
		$form_rendered_at = time();
		$form_timing_sig  = $this->create_form_timing_signature( $form_rendered_at );

		$is_compact = $compact;

		ob_start();
		include WSTP_PATH . 'templates/subscription-form.php';

		return (string) ob_get_clean();
	}

	/**
	 * Render callback for the dynamic Gutenberg block.
	 *
	 * @return string
	 */
	public function render_block( array $attributes = array() ): string {
		return $this->render_shortcode( $attributes );
	}

	/**
	 * Normalize block attributes for dynamic rendering.
	 *
	 * @param array<string,mixed> $atts Raw attributes.
	 * @return array<string,mixed>
	 */
	private function normalize_block_attributes( array $atts ): array {
		$map = array(
			'defaultFrequency' => 'default_frequency',
			'buttonLabel'      => 'button_label',
			'buttonUseCustomStyle' => 'button_use_custom_style',
			'buttonBgColor'    => 'button_bg_color',
			'buttonTextColor'  => 'button_text_color',
			'buttonRadius'     => 'button_radius',
		);

		foreach ( $map as $source => $target ) {
			if ( isset( $atts[ $source ] ) && ! isset( $atts[ $target ] ) ) {
				$atts[ $target ] = $atts[ $source ];
			}
		}

		return $atts;
	}

	/**
	 * Resolve color from hex, css rgb/hsl, or WP preset reference.
	 *
	 * @param string $value Raw color value.
	 * @return string
	 */
	private function resolve_color_value( string $value ): string {
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

		if ( str_starts_with( $value, 'var:preset|color|' ) ) {
			$parts = explode( '|', $value );
			$slug  = end( $parts );
			if ( is_string( $slug ) ) {
				$settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings() : array();
				if ( isset( $settings['color']['palette'] ) && is_array( $settings['color']['palette'] ) ) {
					foreach ( $settings['color']['palette'] as $source_palette ) {
						if ( ! is_array( $source_palette ) ) {
							continue;
						}

						foreach ( $source_palette as $entry ) {
							if ( ! is_array( $entry ) || ! isset( $entry['slug'], $entry['color'] ) ) {
								continue;
							}

							if ( $slug === $entry['slug'] && is_string( $entry['color'] ) ) {
								$resolved = sanitize_hex_color( $entry['color'] );
								if ( $resolved ) {
									return $resolved;
								}
							}
						}
					}
				}
			}
		}

		return '';
	}

	/**
	 * Register REST endpoints.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'wstp/v1',
			'/subscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rest_submit' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'wstp/v1',
			'/subscribe-nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_rest_nonce' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return a fresh subscription nonce for cached frontend pages.
	 *
	 * @return mixed
	 */
	public function handle_rest_nonce() {
		nocache_headers();
		return rest_ensure_response(
			array(
				'nonce' => wp_create_nonce( 'wstp_subscribe' ),
			)
		);
	}

	/**
	 * Handle subscription submit via REST endpoint.
	 *
	 * @param object $request Request object.
	 * @return mixed
	 */
	public function handle_rest_submit( object $request ) {
		$params = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : array();
		if ( ! is_array( $params ) ) {
			$params = method_exists( $request, 'get_params' ) ? $request->get_params() : array();
		}

		$nonce = isset( $params['wstp_nonce'] ) ? sanitize_text_field( (string) $params['wstp_nonce'] ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wstp_subscribe' ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'status'  => 'invalid_nonce',
					'message' => $this->get_status_message( 'invalid_nonce' ),
				)
			);
		}

		$email     = isset( $params['wstp_email'] ) ? sanitize_email( (string) $params['wstp_email'] ) : '';
		$name      = isset( $params['wstp_name'] ) ? sanitize_text_field( (string) $params['wstp_name'] ) : '';
		$frequency = isset( $params['wstp_frequency'] ) ? sanitize_key( (string) $params['wstp_frequency'] ) : '';
		$consent   = isset( $params['wstp_consent'] ) ? sanitize_text_field( (string) $params['wstp_consent'] ) : '';
		$honeypot  = isset( $params['wstp_website'] ) ? sanitize_text_field( (string) $params['wstp_website'] ) : '';
		$rendered_at = isset( $params['wstp_rendered_at'] ) ? (int) $params['wstp_rendered_at'] : 0;
		$timing_sig  = isset( $params['wstp_timing_sig'] ) ? sanitize_text_field( (string) $params['wstp_timing_sig'] ) : '';

		if ( ! $this->is_human_submission( $honeypot, $rendered_at, $timing_sig ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'status'  => 'bot_detected',
					'message' => $this->get_status_message( 'bot_detected' ),
				)
			);
		}

		$status = $this->process_subscription( $email, $name, $frequency, $consent );

		return rest_ensure_response(
			array(
				'success' => in_array( $status, array( 'optin_sent', 'optin_resent' ), true ),
				'status'  => $status,
				'message' => $this->get_status_message( $status ),
			)
		);
	}

	/**
	 * Process form submit.
	 *
	 * @return void
	 */
	public function handle_submit(): void {
		if ( ! isset( $_POST['wstp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wstp_nonce'] ) ), 'wstp_subscribe' ) ) {
			$this->redirect_with_status( 'invalid_nonce' );
		}

		$email     = isset( $_POST['wstp_email'] ) ? sanitize_email( wp_unslash( $_POST['wstp_email'] ) ) : '';
		$name      = isset( $_POST['wstp_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wstp_name'] ) ) : '';
		$frequency = isset( $_POST['wstp_frequency'] ) ? sanitize_key( wp_unslash( $_POST['wstp_frequency'] ) ) : '';
		$consent   = isset( $_POST['wstp_consent'] ) ? sanitize_text_field( wp_unslash( $_POST['wstp_consent'] ) ) : '';
		$honeypot  = isset( $_POST['wstp_website'] ) ? sanitize_text_field( wp_unslash( $_POST['wstp_website'] ) ) : '';
		$rendered_at = isset( $_POST['wstp_rendered_at'] ) ? (int) $_POST['wstp_rendered_at'] : 0;
		$timing_sig  = isset( $_POST['wstp_timing_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['wstp_timing_sig'] ) ) : '';

		if ( ! $this->is_human_submission( $honeypot, $rendered_at, $timing_sig ) ) {
			$this->redirect_with_status( 'bot_detected' );
		}

		$status = $this->process_subscription( $email, $name, $frequency, $consent );
		$this->redirect_with_status( $status );
	}

	/**
	 * Process subscription with shared validation logic.
	 *
	 * @param string $email Email value.
	 * @param string $name Name value.
	 * @param string $frequency Frequency value.
	 * @param string $consent Consent checkbox value.
	 * @return string
	 */
	private function process_subscription( string $email, string $name, string $frequency, string $consent ): string {
		if ( ! is_email( $email ) ) {
			return 'invalid_email';
		}

		if ( ! $this->is_valid_subscriber_name( $name ) ) {
			return 'invalid_name';
		}

		if ( ! in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			return 'invalid_frequency';
		}

		if ( 'yes' !== $consent ) {
			return 'missing_consent';
		}
		if ( $this->is_rate_limited( $email ) ) {
			return 'rate_limited';
		}

		$settings = get_option( 'wstp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$consent_text = isset( $settings['privacy_notice'] ) && is_string( $settings['privacy_notice'] ) ? $settings['privacy_notice'] : '';
		$consent_text = wp_json_encode(
			array(
				'text'          => $consent_text,
				'consented_at'  => current_time( 'mysql' ),
				'consented_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'ip'            => $this->get_request_ip(),
				'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			)
		);

		$existing = $this->subscriber_repository->find_by_email( $email );
		if ( is_array( $existing ) ) {
			$existing_status    = isset( $existing['status'] ) ? (string) $existing['status'] : '';
			$existing_frequency = isset( $existing['frequency'] ) ? (string) $existing['frequency'] : '';

			if ( 'active' === $existing_status && $existing_frequency === $frequency ) {
				return 'already_subscribed';
			}

			if ( 'pending' === $existing_status && $existing_frequency === $frequency ) {
				if ( ! $this->can_resend_double_optin( $existing ) ) {
					return 'optin_recently_sent';
				}

				$result = $this->double_optin_service->create_pending_and_send( $email, $name, $frequency, $consent_text );
				if ( is_wp_error( $result ) ) {
					return 'send_error';
				}
				return 'optin_resent';
			}

			if ( 'suppressed' === $existing_status ) {
				return 'suppressed';
			}
		}
		$result       = $this->double_optin_service->create_pending_and_send( $email, $name, $frequency, $consent_text );

		if ( is_wp_error( $result ) ) {
			return 'send_error';
		}

		return 'optin_sent';
	}

	/**
	 * Redirect user to referrer with status.
	 *
	 * @param string $status Status token.
	 * @return void
	 */
	private function redirect_with_status( string $status ): void {
		$referrer = wp_get_referer();
		if ( ! $referrer ) {
			$referrer = home_url( '/' );
		}

		$url = add_query_arg( 'wstp_status', $status, $referrer );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render global JS fallback for status notices.
	 *
	 * Ensures confirmation feedback appears even when the form shortcode
	 * is not present on the current page (e.g. cached/theme footer differences).
	 *
	 * @return void
	 */
	public function render_global_status_notice_fallback(): void {
		if ( is_admin() ) {
			return;
		}

		$status = isset( $_GET['wstp_status'] ) ? sanitize_key( wp_unslash( $_GET['wstp_status'] ) ) : '';
		if ( '' === $status ) {
			return;
		}

		$messages = $this->get_status_messages();
		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}

		$ui = $this->get_status_ui_settings();
		?>
		<script>
			(function () {
				if (window.__wstpStatusShown) {
					return;
				}

				var status = <?php echo wp_json_encode( $status ); ?>;
				var messages = <?php echo wp_json_encode( $messages ); ?>;
				if (!messages[status]) {
					return;
				}

				var style = <?php echo wp_json_encode( $ui['style'] ); ?>;
				var position = <?php echo wp_json_encode( $ui['position'] ); ?>;
				var autoCloseSeconds = <?php echo (int) $ui['seconds']; ?>;
				var text = messages[status];
				if (window.history && window.history.replaceState) {
					var url = new URL(window.location.href);
					if (url.searchParams.has('wstp_status')) {
						url.searchParams.delete('wstp_status');
						window.history.replaceState({}, document.title, url.toString());
					}
				}

				if (style === 'inline') {
					// Fallback pages may not contain the form; degrade to slide-in.
					style = 'toast';
				}

				var overlay = document.createElement('div');
				overlay.id = 'wstp-status-overlay';

				var closeOverlay = function () {
					if (style === 'overlay') {
						overlay.style.display = 'none';
						document.body.style.overflow = '';
					} else {
						overlay.style.transform = 'translateY(130%)';
						overlay.style.opacity = '0';
						window.setTimeout(function () {
							overlay.style.display = 'none';
						}, 260);
					}
				};

				if (style === 'overlay') {
					overlay.setAttribute('style', 'position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.55); display:flex; align-items:center; justify-content:center; padding:24px;');
					overlay.innerHTML = '<div role="dialog" aria-modal="true" style="max-width:520px; width:100%; background:#ffffff; color:#111111; border-radius:10px; padding:22px;"><p style="margin:0 0 16px 0; font-size:18px; line-height:1.4;"></p><button type="button" style="display:inline-block; border:0; padding:10px 14px; cursor:pointer; background:#1d4ed8; color:#ffffff; border-radius:6px;"><?php echo esc_js( __( 'Continue to website', 'we-subscribe-to-posts' ) ); ?></button></div>';
					overlay.querySelector('p').textContent = text;
					overlay.querySelector('button').addEventListener('click', closeOverlay);
					overlay.addEventListener('click', function (event) {
						if (event.target === overlay) {
							closeOverlay();
						}
					});
					document.body.appendChild(overlay);
					document.body.style.overflow = 'hidden';
				} else {
					var positionCss = 'right:16px; bottom:16px;';
					if (position === 'bottom-left') {
						positionCss = 'left:16px; bottom:16px;';
					} else if (position === 'top-right') {
						positionCss = 'right:16px; top:16px;';
					} else if (position === 'top-left') {
						positionCss = 'left:16px; top:16px;';
					}
					overlay.setAttribute('role', 'status');
					overlay.setAttribute('aria-live', 'polite');
					overlay.setAttribute('style', 'position:fixed; ' + positionCss + ' z-index:99999; max-width:420px; width:calc(100% - 32px); background:#ffffff; color:#111111; border-radius:10px; box-shadow:0 12px 32px rgba(0,0,0,0.22); border:1px solid #d9dbe1; padding:16px 16px 14px 16px; transform:translateY(130%); opacity:0; transition:transform .26s ease, opacity .26s ease;');
					overlay.innerHTML = '<p style="margin:0 0 10px 0; font-size:15px; line-height:1.45;"></p><button type="button" style="display:inline-block; border:0; padding:8px 12px; cursor:pointer; background:#1d4ed8; color:#ffffff; border-radius:6px;"><?php echo esc_js( __( 'OK', 'we-subscribe-to-posts' ) ); ?></button>';
					overlay.querySelector('p').textContent = text;
					overlay.querySelector('button').addEventListener('click', closeOverlay);
					document.body.appendChild(overlay);
					window.requestAnimationFrame(function () {
						overlay.style.transform = 'translateY(0)';
						overlay.style.opacity = '1';
					});
					if (autoCloseSeconds > 0) {
						window.setTimeout(closeOverlay, autoCloseSeconds * 1000);
					}
				}

				window.__wstpStatusShown = true;
			})();
		</script>
		<?php
	}

	/**
	 * Status message map.
	 *
	 * @return array<string,string>
	 */
	private function get_status_messages(): array {
		return array(
			'optin_sent'         => __( 'Please check your inbox and confirm your subscription.', 'we-subscribe-to-posts' ),
			'optin_resent'       => __( 'We sent a fresh confirmation email. Please check your inbox.', 'we-subscribe-to-posts' ),
			'optin_recently_sent' => __( 'A confirmation email was sent recently. Please wait a few minutes before requesting another one.', 'we-subscribe-to-posts' ),
			'confirmed'          => __( 'Subscription confirmed. You are now subscribed.', 'we-subscribe-to-posts' ),
			'unsubscribed'       => __( 'You have been unsubscribed successfully.', 'we-subscribe-to-posts' ),
			'invalid_nonce'      => __( 'Security check failed. Please try again.', 'we-subscribe-to-posts' ),
			'invalid_email'      => __( 'Please enter a valid email address.', 'we-subscribe-to-posts' ),
			'invalid_name'       => __( 'Please enter a valid name without links or suspicious patterns.', 'we-subscribe-to-posts' ),
			'invalid_frequency'  => __( 'Please select a valid delivery frequency.', 'we-subscribe-to-posts' ),
			'missing_consent'    => __( 'Please accept the privacy section before subscribing.', 'we-subscribe-to-posts' ),
			'bot_detected'       => __( 'Submission could not be processed. Please try again.', 'we-subscribe-to-posts' ),
			'rate_limited'       => __( 'Too many subscribe attempts in a short time. Please wait a moment and try again.', 'we-subscribe-to-posts' ),
			'send_error'         => __( 'We could not send the confirmation email right now. Please try again later.', 'we-subscribe-to-posts' ),
			'invalid_token'      => __( 'This confirmation or unsubscribe link is invalid or expired.', 'we-subscribe-to-posts' ),
			'already_subscribed' => __( 'This email is already subscribed with the selected frequency.', 'we-subscribe-to-posts' ),
			'optin_already_sent' => __( 'A confirmation email was already sent for this address. Please check your inbox.', 'we-subscribe-to-posts' ),
			'suppressed'         => __( 'This email is blocked from subscriptions and cannot be added again.', 'we-subscribe-to-posts' ),
		);
	}

	/**
	 * Resolve single status message with safe fallback.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function get_status_message( string $status ): string {
		$messages = $this->get_status_messages();
		if ( isset( $messages[ $status ] ) ) {
			return $messages[ $status ];
		}

		return __( 'An unknown status occurred.', 'we-subscribe-to-posts' );
	}

	/**
	 * Read and sanitize status-notice UI settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_status_ui_settings(): array {
		$settings = get_option( 'wstp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$style = isset( $settings['status_notice_style'] ) && is_string( $settings['status_notice_style'] ) ? sanitize_key( $settings['status_notice_style'] ) : 'toast';
		if ( ! in_array( $style, array( 'toast', 'overlay', 'inline' ), true ) ) {
			$style = 'toast';
		}

		$position = isset( $settings['status_notice_position'] ) && is_string( $settings['status_notice_position'] ) ? sanitize_key( $settings['status_notice_position'] ) : 'bottom-right';
		if ( ! in_array( $position, array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ), true ) ) {
			$position = 'bottom-right';
		}

		$seconds = isset( $settings['status_notice_seconds'] ) ? (int) $settings['status_notice_seconds'] : 8;
		if ( $seconds < 0 ) {
			$seconds = 0;
		}
		if ( $seconds > 60 ) {
			$seconds = 60;
		}

		return array(
			'style'    => $style,
			'position' => $position,
			'seconds'  => $seconds,
		);
	}

	/**
	 * Basic anti-spam rate limit for subscribe attempts.
	 *
	 * Limits by email and (if available) request IP using transients.
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	private function is_rate_limited( string $email ): bool {
		$settings = get_option( 'wstp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$window_seconds_default = isset( $settings['subscribe_rate_limit_window_seconds'] ) ? (int) $settings['subscribe_rate_limit_window_seconds'] : 600;
		$max_attempts_default   = isset( $settings['subscribe_rate_limit_max_attempts'] ) ? (int) $settings['subscribe_rate_limit_max_attempts'] : 6;
		$window_seconds         = (int) apply_filters( 'wstp_subscribe_rate_limit_window_seconds', $window_seconds_default );
		$max_attempts_email     = (int) apply_filters( 'wstp_subscribe_rate_limit_max_attempts', $max_attempts_default );
		$max_attempts_ip        = (int) apply_filters( 'wstp_subscribe_rate_limit_ip_max_attempts', max( 30, $max_attempts_email * 5 ) );
		if ( $window_seconds <= 0 || $max_attempts_email <= 0 || $max_attempts_ip <= 0 ) {
			return false;
		}

		$email_key     = $this->build_rate_limit_key( 'email', strtolower( $email ) );
		$email_current = (int) get_transient( $email_key );
		if ( $email_current >= $max_attempts_email ) {
			return true;
		}

		$ip = $this->get_request_ip();
		$ip_key = '';
		if ( '' !== $ip ) {
			$ip_key     = $this->build_rate_limit_key( 'ip', $ip );
			$ip_current = (int) get_transient( $ip_key );
			if ( $ip_current >= $max_attempts_ip ) {
				return true;
			}
		}

		set_transient( $email_key, $email_current + 1, $window_seconds );
		if ( '' !== $ip_key ) {
			$ip_current = (int) get_transient( $ip_key );
			set_transient( $ip_key, $ip_current + 1, $window_seconds );
		}

		return false;
	}

	/**
	 * Determine whether a pending subscriber can receive a fresh DOI email.
	 *
	 * @param array<string,mixed> $existing Existing subscriber row.
	 * @return bool
	 */
	private function can_resend_double_optin( array $existing ): bool {
		$cooldown_minutes = $this->get_doi_resend_cooldown_minutes();
		if ( $cooldown_minutes <= 0 ) {
			return true;
		}

		$reference_time = '';
		if ( isset( $existing['updated_at'] ) && is_string( $existing['updated_at'] ) ) {
			$reference_time = $existing['updated_at'];
		}
		if ( '' === $reference_time && isset( $existing['created_at'] ) && is_string( $existing['created_at'] ) ) {
			$reference_time = $existing['created_at'];
		}
		if ( '' === $reference_time ) {
			return true;
		}

		$reference_timestamp = strtotime( $reference_time );
		if ( false === $reference_timestamp ) {
			return true;
		}

		return ( time() - $reference_timestamp ) >= ( $cooldown_minutes * 60 );
	}

	/**
	 * Resolve DOI resend cooldown in minutes from plugin settings.
	 *
	 * @return int
	 */
	private function get_doi_resend_cooldown_minutes(): int {
		$settings = get_option( 'wstp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$minutes = isset( $settings['doi_resend_cooldown_minutes'] ) ? (int) $settings['doi_resend_cooldown_minutes'] : 10;
		$minutes = (int) apply_filters( 'wstp_doi_resend_cooldown_minutes', $minutes );
		if ( $minutes < 0 ) {
			return 0;
		}
		if ( $minutes > 1440 ) {
			return 1440;
		}

		return $minutes;
	}

	/**
	 * Build namespaced transient key for rate limit storage.
	 *
	 * @param string $type Key scope.
	 * @param string $value Key value.
	 * @return string
	 */
	private function build_rate_limit_key( string $type, string $value ): string {
		return 'wstp_rl_' . sanitize_key( $type ) . '_' . md5( $value );
	}

	/**
	 * Validate optional subscriber name.
	 *
	 * @param string $name Name value from form.
	 * @return bool
	 */
	private function is_valid_subscriber_name( string $name ): bool {
		$name = trim( $name );
		if ( '' === $name ) {
			return true;
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
		if ( $length > 80 ) {
			return false;
		}

		if ( preg_match( '/(?:https?:\/\/|www\.|ftp:\/\/|mailto:)/i', $name ) ) {
			return false;
		}

		if ( preg_match( '/\b[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}\b/i', $name ) ) {
			return false;
		}

		if ( preg_match( '/\b[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.[a-z]{2,}(?:\/\S*)?\b/i', $name ) ) {
			return false;
		}

		// Allow letters/numbers/space plus a few common name separators only.
		if ( preg_match( "/[^\p{L}\p{N}\s\-\'.]/u", $name ) ) {
			return false;
		}

		// Reject obvious repeated spam patterns.
		if ( preg_match( '/(.)\1{4,}/u', $name ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Create HMAC signature for render timestamp.
	 *
	 * @param int $rendered_at Form render timestamp.
	 * @return string
	 */
	private function create_form_timing_signature( int $rendered_at ): string {
		return hash_hmac( 'sha256', (string) $rendered_at, wp_salt( 'nonce' ) );
	}

	/**
	 * Validate lightweight bot heuristics.
	 *
	 * @param string $honeypot Honeypot field value.
	 * @param int    $rendered_at Timestamp from form render.
	 * @param string $timing_sig HMAC signature for timestamp.
	 * @return bool
	 */
	private function is_human_submission( string $honeypot, int $rendered_at, string $timing_sig ): bool {
		if ( '' !== trim( $honeypot ) ) {
			return false;
		}

		if ( $rendered_at <= 0 || '' === $timing_sig ) {
			return false;
		}

		$expected = $this->create_form_timing_signature( $rendered_at );
		if ( ! hash_equals( $expected, $timing_sig ) ) {
			return false;
		}

		$min_seconds = (int) apply_filters( 'wstp_subscribe_min_submit_seconds', 2 );
		if ( $min_seconds > 0 && ( time() - $rendered_at ) < $min_seconds ) {
			return false;
		}

		return true;
	}

	/**
	 * Best effort client IP extraction.
	 *
	 * @return string
	 */
	private function get_request_ip(): string {
		$ip_keys = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' );
		foreach ( $ip_keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$raw = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
			if ( 'HTTP_X_FORWARDED_FOR' === $key ) {
				$parts = explode( ',', $raw );
				$raw   = trim( (string) $parts[0] );
			}

			if ( filter_var( $raw, FILTER_VALIDATE_IP ) ) {
				return $raw;
			}
		}

		return '';
	}
}
