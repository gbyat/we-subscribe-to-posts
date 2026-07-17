<?php
/**
 * Plugin uninstall handler.
 *
 * @package WeSubscribeToPosts
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Clear plugin options.
$option_names = array(
	'wstp_settings',
	'wstp_mail_settings',
	'wstp_legacy_single_options_purged',
	'wstp_admin_subscriber_notification_queue',
	'wstp_digest_mjml_template',
	'wstp_digest_html_template',
	'wstp_digest_blocks',
	'wstp_digest_template_source',
	'wstp_digest_branding',
	'wstp_digest_layout_library',
	'wstp_digest_active_layout_id',
);

foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
	delete_site_option( $option_name );
}

// Clear scheduled hooks.
wp_clear_scheduled_hook( 'wstp_daily_digest_event' );
wp_clear_scheduled_hook( 'wstp_weekly_digest_event' );
wp_clear_scheduled_hook( 'wstp_monthly_digest_event' );
wp_clear_scheduled_hook( 'wstp_cleanup_pending_event' );
wp_clear_scheduled_hook( 'wstp_admin_subscriber_summary_event' );

// Drop plugin tables.
$subscribers_table  = $wpdb->prefix . 'wstp_subscribers';
$delivery_log_table = $wpdb->prefix . 'wstp_delivery_log';

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names are static with WP table prefix.
$wpdb->query( "DROP TABLE IF EXISTS `{$subscribers_table}`" );
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names are static with WP table prefix.
$wpdb->query( "DROP TABLE IF EXISTS `{$delivery_log_table}`" );

// Remove template posts.
$template_ids = get_posts(
	array(
		'post_type'      => 'wstp_email_template',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $template_ids as $template_id ) {
	wp_delete_post( (int) $template_id, true );
}
