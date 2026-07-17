<?php
/**
 * Delivery event log repository.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Persists delivery outcomes.
 */
final class Event_Log_Repository {
	/**
	 * DB handle.
	 *
	 * @var mixed
	 */
	private $wpdb;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'wstp_delivery_log';
	}

	/**
	 * Insert one log row.
	 *
	 * @param int         $subscriber_id Subscriber ID.
	 * @param string      $frequency Frequency.
	 * @param array<int>  $post_ids Included post IDs.
	 * @param string      $mail_subject Subject line.
	 * @param string      $result sent|failed.
	 * @param string|null $error_message Error details.
	 * @return void
	 */
	public function log_send( int $subscriber_id, string $frequency, array $post_ids, string $mail_subject, string $result, ?string $error_message = null ): void {
		$this->wpdb->insert(
			$this->table,
			array(
				'subscriber_id' => $subscriber_id,
				'frequency'     => $frequency,
				'post_ids_json' => wp_json_encode( array_values( $post_ids ) ),
				'mail_subject'  => $mail_subject,
				'result'        => $result,
				'error_message' => $error_message,
				'sent_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get delivery stats for subscriber IDs.
	 *
	 * @param array<int> $subscriber_ids Subscriber IDs.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_stats_for_subscribers( array $subscriber_ids ): array {
		$subscriber_ids = array_values( array_filter( array_map( 'intval', $subscriber_ids ) ) );
		if ( empty( $subscriber_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $subscriber_ids ), '%d' ) );
		$sql          = "
			SELECT
				subscriber_id,
				SUM(CASE WHEN result = 'sent' THEN 1 ELSE 0 END) AS sent_count,
				SUM(CASE WHEN result = 'failed' THEN 1 ELSE 0 END) AS failed_count,
				MAX(sent_at) AS last_sent_at
			FROM {$this->table}
			WHERE subscriber_id IN ({$placeholders})
			GROUP BY subscriber_id
		";
		$query        = $this->wpdb->prepare( $sql, $subscriber_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared here.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared immediately above.
		$rows = $this->wpdb->get_results( $query, 'ARRAY_A' );

		$stats = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$subscriber_id           = (int) $row['subscriber_id'];
				$stats[ $subscriber_id ] = array(
					'sent_count'   => (int) $row['sent_count'],
					'failed_count' => (int) $row['failed_count'],
					'last_sent_at' => (string) $row['last_sent_at'],
					'last_result'  => '',
				);
			}
		}

		$last_sql   = "
			SELECT l.subscriber_id, l.result
			FROM {$this->table} l
			INNER JOIN (
				SELECT subscriber_id, MAX(id) AS max_id
				FROM {$this->table}
				WHERE subscriber_id IN ({$placeholders})
				GROUP BY subscriber_id
			) last_row
				ON l.id = last_row.max_id
		";
		$last_query = $this->wpdb->prepare( $last_sql, array_merge( $subscriber_ids, $subscriber_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared here.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared immediately above.
		$last_rows = $this->wpdb->get_results( $last_query, 'ARRAY_A' );

		if ( is_array( $last_rows ) ) {
			foreach ( $last_rows as $row ) {
				$subscriber_id = (int) $row['subscriber_id'];
				if ( isset( $stats[ $subscriber_id ] ) ) {
					$stats[ $subscriber_id ]['last_result'] = (string) $row['result'];
				}
			}
		}

		return $stats;
	}

	/**
	 * Get last successful send timestamp for subscriber+frequency.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $frequency Frequency key.
	 * @return string
	 */
	public function get_last_success_sent_at( int $subscriber_id, string $frequency ): string {
		$sql   = "
			SELECT sent_at
			FROM {$this->table}
			WHERE subscriber_id = %d
				AND frequency = %s
				AND result = 'sent'
			ORDER BY id DESC
			LIMIT 1
		";
		$query = $this->wpdb->prepare( $sql, $subscriber_id, $frequency ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared here.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared immediately above.
		$value = $this->wpdb->get_var( $query );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Get post IDs included in the latest successful digest send.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $frequency Frequency key.
	 * @return array<int>
	 */
	public function get_last_success_post_ids( int $subscriber_id, string $frequency ): array {
		$sql   = "
			SELECT post_ids_json
			FROM {$this->table}
			WHERE subscriber_id = %d
				AND frequency = %s
				AND result = 'sent'
			ORDER BY id DESC
			LIMIT 1
		";
		$query = $this->wpdb->prepare( $sql, $subscriber_id, $frequency ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared here.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared immediately above.
		$value = $this->wpdb->get_var( $query );

		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}

		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'intval', $decoded ),
				static fn( int $post_id ): bool => $post_id > 0
			)
		);
	}

	/**
	 * Get aggregate delivery stats for dashboard widget.
	 *
	 * @return array<string,mixed>
	 */
	public function get_dashboard_summary(): array {
		$now_mysql = current_time( 'mysql' );
		$sql       = "
			SELECT
				SUM(CASE WHEN result = 'sent' THEN 1 ELSE 0 END) AS sent_total,
				SUM(CASE WHEN result = 'failed' THEN 1 ELSE 0 END) AS failed_total,
				SUM(CASE WHEN result = 'sent' AND sent_at >= DATE_SUB(%s, INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS sent_last_7_days,
				SUM(CASE WHEN result = 'failed' AND sent_at >= DATE_SUB(%s, INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS failed_last_7_days,
				COUNT(DISTINCT CASE WHEN result = 'sent' THEN subscriber_id END) AS distinct_sent_subscribers,
				MAX(sent_at) AS last_sent_at
			FROM {$this->table}
		";
		$query     = $this->wpdb->prepare( $sql, $now_mysql, $now_mysql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared here.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query values are prepared immediately above.
		$row = $this->wpdb->get_row( $query, 'ARRAY_A' );

		$summary = array(
			'sent_total'                => 0,
			'failed_total'              => 0,
			'sent_last_7_days'          => 0,
			'failed_last_7_days'        => 0,
			'distinct_sent_subscribers' => 0,
			'last_sent_at'              => '',
			'by_frequency'              => array(
				'daily'   => array(
					'sent'   => 0,
					'failed' => 0,
				),
				'weekly'  => array(
					'sent'   => 0,
					'failed' => 0,
				),
				'monthly' => array(
					'sent'   => 0,
					'failed' => 0,
				),
			),
		);

		if ( is_array( $row ) ) {
			$summary['sent_total']                = isset( $row['sent_total'] ) ? (int) $row['sent_total'] : 0;
			$summary['failed_total']              = isset( $row['failed_total'] ) ? (int) $row['failed_total'] : 0;
			$summary['sent_last_7_days']          = isset( $row['sent_last_7_days'] ) ? (int) $row['sent_last_7_days'] : 0;
			$summary['failed_last_7_days']        = isset( $row['failed_last_7_days'] ) ? (int) $row['failed_last_7_days'] : 0;
			$summary['distinct_sent_subscribers'] = isset( $row['distinct_sent_subscribers'] ) ? (int) $row['distinct_sent_subscribers'] : 0;
			$summary['last_sent_at']              = isset( $row['last_sent_at'] ) ? (string) $row['last_sent_at'] : '';
		}

		$freq_sql = "
			SELECT
				frequency,
				SUM(CASE WHEN result = 'sent' THEN 1 ELSE 0 END) AS sent_count,
				SUM(CASE WHEN result = 'failed' THEN 1 ELSE 0 END) AS failed_count
			FROM {$this->table}
			GROUP BY frequency
		";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query has no variable values; table name is a trusted plugin table property.
		$freq_rows = $this->wpdb->get_results( $freq_sql, 'ARRAY_A' );
		if ( is_array( $freq_rows ) ) {
			foreach ( $freq_rows as $freq_row ) {
				$frequency = isset( $freq_row['frequency'] ) ? (string) $freq_row['frequency'] : '';
				if ( ! isset( $summary['by_frequency'][ $frequency ] ) ) {
					continue;
				}

				$summary['by_frequency'][ $frequency ]['sent']   = isset( $freq_row['sent_count'] ) ? (int) $freq_row['sent_count'] : 0;
				$summary['by_frequency'][ $frequency ]['failed'] = isset( $freq_row['failed_count'] ) ? (int) $freq_row['failed_count'] : 0;
			}
		}

		return $summary;
	}
}
