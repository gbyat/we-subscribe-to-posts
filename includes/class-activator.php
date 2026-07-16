<?php
/**
 * Plugin activator.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP;

use WSTP\Admin\Mjml_Template;
use WSTP\DB\Schema_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Creates required schema and scheduled events.
 */
final class Activator {
	/**
	 * Run activation tasks.
	 */
	public function activate(): void {
		$schema_manager = new Schema_Manager();
		$schema_manager->create_tables();
		Mjml_Template::maybe_install_default();

		if ( ! wp_next_scheduled( 'wstp_daily_digest_event' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'wstp_daily_digest_event' );
		}

		if ( ! wp_next_scheduled( 'wstp_weekly_digest_event' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'wstp_weekly_digest_event' );
		}

		if ( ! wp_next_scheduled( 'wstp_monthly_digest_event' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'wstp_monthly_digest_event' );
		}

		if ( ! wp_next_scheduled( 'wstp_cleanup_pending_event' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'wstp_cleanup_pending_event' );
		}

		if ( ! wp_next_scheduled( 'wstp_admin_subscriber_summary_event' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'wstp_admin_subscriber_summary_event' );
		}
	}
}
