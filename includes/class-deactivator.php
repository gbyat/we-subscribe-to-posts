<?php
/**
 * Plugin deactivator.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP;

defined( 'ABSPATH' ) || exit;

/**
 * Clears scheduled events.
 */
final class Deactivator {
	/**
	 * Run deactivation tasks.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( 'wstp_daily_digest_event' );
		wp_clear_scheduled_hook( 'wstp_weekly_digest_event' );
		wp_clear_scheduled_hook( 'wstp_monthly_digest_event' );
		wp_clear_scheduled_hook( 'wstp_cleanup_pending_event' );
	}
}
