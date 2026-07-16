<?php
/**
 * Pending subscriber cleanup service.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Subscription;

use WSTP\DB\Subscriber_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans stale unconfirmed subscriptions.
 */
final class Pending_Cleanup {
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
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wstp_cleanup_pending_event', array( $this, 'cleanup' ) );
	}

	/**
	 * Delete pending rows older than retention window.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		$settings = get_option( 'wstp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$retention_days = isset( $settings['pending_cleanup_days'] ) ? (int) $settings['pending_cleanup_days'] : 7;
		$retention_days = (int) apply_filters( 'wstp_pending_cleanup_days', $retention_days );
		if ( $retention_days < 1 ) {
			$retention_days = 1;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $retention_days . ' days' ) );
		$this->subscriber_repository->delete_pending_older_than( $cutoff );
	}
}
