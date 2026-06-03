<?php
/**
 * Double opt-in service.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Subscription;

use WSTP\DB\Subscriber_Repository;
use WSTP\Mailer\Mailer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Handles pending subscription and DOI confirmation.
 */
final class Double_Optin_Service {
	/**
	 * Repository.
	 *
	 * @var Subscriber_Repository
	 */
	private Subscriber_Repository $subscriber_repository;

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Constructor.
	 *
	 * @param Subscriber_Repository $subscriber_repository Subscriber repository.
	 * @param Mailer                $mailer Mailer service.
	 */
	public function __construct( Subscriber_Repository $subscriber_repository, Mailer $mailer ) {
		$this->subscriber_repository = $subscriber_repository;
		$this->mailer                = $mailer;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_confirm' ) );
	}

	/**
	 * Create pending subscription and send DOI email.
	 *
	 * @param string $email Email.
	 * @param string $name Name.
	 * @param string $frequency Frequency.
	 * @param string $consent_text Privacy consent snapshot.
	 * @return true|WP_Error
	 */
	public function create_pending_and_send( string $email, string $name, string $frequency, string $consent_text ) {
		$optin_token       = wp_generate_password( 32, false, false );
		$unsubscribe_token = wp_generate_password( 32, false, false );

		$optin_hash       = $this->hash_token( $optin_token );
		$unsubscribe_hash = $this->hash_token( $unsubscribe_token );

		$subscriber_id = $this->subscriber_repository->upsert_pending(
			$email,
			$name,
			$frequency,
			$optin_hash,
			$unsubscribe_hash,
			$consent_text
		);

		$confirm_url = add_query_arg(
			array(
				'wstp_action' => 'confirm',
				'wstp_token'  => rawurlencode( $optin_token ),
			),
			home_url( '/' )
		);

		$sent = $this->mailer->send_double_optin(
			$email,
			array(
				'subscriber_id' => $subscriber_id,
				'name'          => $name,
				'confirm_url'   => $confirm_url,
			)
		);

		if ( ! $sent ) {
			return new WP_Error( 'wstp_doi_send_failed', __( 'Could not send double opt-in message.', 'we-subscribe-to-posts' ) );
		}

		return true;
	}

	/**
	 * Confirm pending subscription via token.
	 *
	 * @return void
	 */
	public function maybe_confirm(): void {
		$action = isset( $_GET['wstp_action'] ) ? sanitize_key( wp_unslash( $_GET['wstp_action'] ) ) : '';
		if ( 'confirm' !== $action ) {
			return;
		}

		$token = isset( $_GET['wstp_token'] ) ? sanitize_text_field( wp_unslash( $_GET['wstp_token'] ) ) : '';
		if ( '' === $token ) {
			$this->redirect_with_status( 'invalid_token' );
		}

		$subscriber = $this->subscriber_repository->find_by_optin_hash( $this->hash_token( $token ) );
		if ( ! $subscriber || 'pending' !== $subscriber['status'] ) {
			$this->redirect_with_status( 'invalid_token' );
		}

		$this->subscriber_repository->mark_active( (int) $subscriber['id'] );
		$this->redirect_with_status( 'confirmed' );
	}

	/**
	 * Hash token for storage and lookup.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Redirect with status.
	 *
	 * @param string $status Status value.
	 * @return void
	 */
	private function redirect_with_status( string $status ): void {
		$url = add_query_arg( 'wstp_status', $status, home_url( '/' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
