<?php
/**
 * Preview email controller.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Admin;

use WSTP\Mailer\Digest_Builder;
use WSTP\Mailer\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles preview-send action.
 */
final class Preview_Controller {
	/**
	 * Digest builder.
	 *
	 * @var Digest_Builder
	 */
	private Digest_Builder $digest_builder;

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Constructor.
	 *
	 * @param Digest_Builder $digest_builder Builder.
	 * @param Mailer         $mailer Mailer.
	 */
	public function __construct( Digest_Builder $digest_builder, Mailer $mailer ) {
		$this->digest_builder = $digest_builder;
		$this->mailer         = $mailer;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_wstp_send_preview', array( $this, 'send_preview' ) );
	}

	/**
	 * Send preview digest to configured address.
	 *
	 * @return void
	 */
	public function send_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'we-subscribe-to-posts' ) );
		}

		if ( ! isset( $_POST['wstp_preview_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wstp_preview_nonce'] ) ), 'wstp_send_preview' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'we-subscribe-to-posts' ) );
		}

		$general_settings = get_option( 'wstp_settings', array() );
		if ( ! is_array( $general_settings ) ) {
			$general_settings = array();
		}
		$preview_email = isset( $general_settings['preview_email'] ) && is_string( $general_settings['preview_email'] )
			? $general_settings['preview_email']
			: (string) get_option( 'admin_email' );
		if ( ! is_email( $preview_email ) ) {
			$this->redirect_with_notice( 'preview_invalid_email' );
		}

		$subscriber = array(
			'email'        => $preview_email,
			'name'         => wp_get_current_user()->display_name,
			'last_sent_at' => null,
		);
		$payload    = $this->digest_builder->build_payload( $subscriber, 'daily', true );

		$context = array(
			'greeting_name'   => wp_get_current_user()->display_name ? wp_get_current_user()->display_name : 'Admin',
			'posts'           => $payload['posts'],
			'unsubscribe_url' => home_url( '/' ),
		);

		$sent = $this->mailer->send_digest( $preview_email, (string) $payload['subject'], $context );
		$this->redirect_with_notice( $sent ? 'preview_sent' : 'preview_failed' );
	}

	/**
	 * Redirect back to settings page with notice code.
	 *
	 * @param string $code Status code.
	 * @return void
	 */
	private function redirect_with_notice( string $code ): void {
		$url = add_query_arg(
			array(
				'page'             => 'wstp-settings',
				'wstp_admin_notice'=> $code,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
