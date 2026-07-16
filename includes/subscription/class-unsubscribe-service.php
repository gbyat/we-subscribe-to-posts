<?php
/**
 * Unsubscribe handler.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Subscription;

use WSTP\DB\Subscriber_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Handles one-click unsubscribe.
 */
final class Unsubscribe_Service {
	/**
	 * Repository.
	 *
	 * @var Subscriber_Repository
	 */
	private Subscriber_Repository $subscriber_repository;

	/**
	 * Constructor.
	 *
	 * @param Subscriber_Repository $subscriber_repository Subscriber repository.
	 */
	public function __construct( Subscriber_Repository $subscriber_repository ) {
		$this->subscriber_repository = $subscriber_repository;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_unsubscribe' ) );
	}

	/**
	 * Process one-click unsubscribe.
	 *
	 * @return void
	 */
	public function maybe_unsubscribe(): void {
		$action = isset( $_GET['wstp_action'] ) ? sanitize_key( wp_unslash( $_GET['wstp_action'] ) ) : '';
		if ( 'unsubscribe' !== $action ) {
			return;
		}

		$token = isset( $_GET['wstp_token'] ) ? sanitize_text_field( wp_unslash( $_GET['wstp_token'] ) ) : '';
		if ( '' === $token ) {
			$this->redirect_with_status( 'invalid_token' );
		}

		$subscriber = $this->subscriber_repository->find_by_unsubscribe_hash( $this->hash_token( $token ) );
		if ( ! $subscriber ) {
			$this->redirect_with_status( 'invalid_token' );
		}

		$this->subscriber_repository->mark_unsubscribed( (int) $subscriber['id'] );
		$this->redirect_with_status( 'unsubscribed' );
	}

	/**
	 * Hash token for lookup.
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
	 * @param string $status Status.
	 * @return void
	 */
	private function redirect_with_status( string $status ): void {
		$url = add_query_arg( 'wstp_status', $status, home_url( '/' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
