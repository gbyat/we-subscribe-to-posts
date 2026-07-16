<?php
/**
 * DB schema manager.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Creates or updates plugin tables.
 */
final class Schema_Manager {
	/**
	 * Create required tables.
	 */
	public function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$subscribers     = $this->subscribers_table();
		$delivery_log    = $this->delivery_log_table();

		$sql_subscribers = "CREATE TABLE {$subscribers} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			name VARCHAR(190) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			frequency VARCHAR(20) NOT NULL DEFAULT 'daily',
			optin_token_hash VARCHAR(255) NULL,
			unsubscribe_token_hash VARCHAR(255) NULL,
			last_sent_at DATETIME NULL,
			confirmed_at DATETIME NULL,
			consent_text LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status_frequency (status, frequency)
		) {$charset_collate};";

		$sql_delivery_log = "CREATE TABLE {$delivery_log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscriber_id BIGINT UNSIGNED NOT NULL,
			frequency VARCHAR(20) NOT NULL,
			post_ids_json LONGTEXT NULL,
			mail_subject VARCHAR(255) NOT NULL,
			result VARCHAR(20) NOT NULL,
			error_message LONGTEXT NULL,
			sent_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY subscriber_id (subscriber_id),
			KEY frequency (frequency),
			KEY sent_at (sent_at)
		) {$charset_collate};";

		dbDelta( $sql_subscribers );
		dbDelta( $sql_delivery_log );
	}

	/**
	 * Table name for subscribers.
	 *
	 * @return string
	 */
	public function subscribers_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wstp_subscribers';
	}

	/**
	 * Table name for delivery log.
	 *
	 * @return string
	 */
	public function delivery_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wstp_delivery_log';
	}
}
