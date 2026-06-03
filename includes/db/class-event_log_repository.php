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
		$query        = $this->wpdb->prepare( $sql, $subscriber_ids );
		$rows         = $this->wpdb->get_results( $query, 'ARRAY_A' );

		$stats = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$subscriber_id = (int) $row['subscriber_id'];
				$stats[ $subscriber_id ] = array(
					'sent_count'   => (int) $row['sent_count'],
					'failed_count' => (int) $row['failed_count'],
					'last_sent_at' => (string) $row['last_sent_at'],
					'last_result'  => '',
				);
			}
		}

		$last_sql = "
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
		$last_query = $this->wpdb->prepare( $last_sql, array_merge( $subscriber_ids, $subscriber_ids ) );
		$last_rows  = $this->wpdb->get_results( $last_query, 'ARRAY_A' );

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
}
