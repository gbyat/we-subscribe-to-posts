<?php
/**
 * Subscriber repository.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\DB;

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes subscriber records.
 */
final class Subscriber_Repository {
	/**
	 * DB handle.
	 *
	 * @var object
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
		$this->table = $wpdb->prefix . 'wstp_subscribers';
	}

	/**
	 * Create or update a pending subscriber by email.
	 *
	 * @param string $email Sanitized email.
	 * @param string $name Sanitized name.
	 * @param string $frequency Frequency value.
	 * @param string $optin_token_hash Hashed token.
	 * @param string $unsubscribe_token_hash Hashed token.
	 * @param string $consent_text Consent text snapshot.
	 * @return int Subscriber ID.
	 */
	public function upsert_pending( string $email, string $name, string $frequency, string $optin_token_hash, string $unsubscribe_token_hash, string $consent_text ): int {
		$now      = current_time( 'mysql' );
		$existing = $this->find_by_email( $email );

		$data = array(
			'email'                  => $email,
			'name'                   => $name,
			'status'                 => 'pending',
			'frequency'              => $frequency,
			'optin_token_hash'       => $optin_token_hash,
			'unsubscribe_token_hash' => $unsubscribe_token_hash,
			'consent_text'           => $consent_text,
			'updated_at'             => $now,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			$this->wpdb->update(
				$this->table,
				$data,
				array( 'id' => (int) $existing['id'] ),
				$formats,
				array( '%d' )
			);

			return (int) $existing['id'];
		}

		$data['created_at'] = $now;
		$formats[]          = '%s';

		$this->wpdb->insert( $this->table, $data, $formats );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Find a subscriber by e-mail.
	 *
	 * @param string $email Email.
	 * @return array<string,mixed>|null
	 */
	public function find_by_email( string $email ): ?array {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE email = %s LIMIT 1",
			$email
		);

		$record = $this->wpdb->get_row( $sql, 'ARRAY_A' );
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Find subscriber by id.
	 *
	 * @param int $subscriber_id Subscriber id.
	 * @return array<string,mixed>|null
	 */
	public function find_by_id( int $subscriber_id ): ?array {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
			$subscriber_id
		);

		$record = $this->wpdb->get_row( $sql, 'ARRAY_A' );
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Find subscriber by hashed opt-in token.
	 *
	 * @param string $token_hash Token hash.
	 * @return array<string,mixed>|null
	 */
	public function find_by_optin_hash( string $token_hash ): ?array {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE optin_token_hash = %s LIMIT 1",
			$token_hash
		);

		$record = $this->wpdb->get_row( $sql, 'ARRAY_A' );
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Find subscriber by hashed unsubscribe token.
	 *
	 * @param string $token_hash Token hash.
	 * @return array<string,mixed>|null
	 */
	public function find_by_unsubscribe_hash( string $token_hash ): ?array {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE unsubscribe_token_hash = %s LIMIT 1",
			$token_hash
		);

		$record = $this->wpdb->get_row( $sql, 'ARRAY_A' );
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Mark subscriber active.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return bool
	 */
	public function mark_active( int $subscriber_id ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array(
				'status'           => 'active',
				'confirmed_at'     => current_time( 'mysql' ),
				'optin_token_hash' => null,
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $subscriber_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Mark subscriber unsubscribed.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return bool
	 */
	public function mark_unsubscribed( int $subscriber_id ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array(
				'status'           => 'unsubscribed',
				'optin_token_hash' => null,
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $subscriber_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get active subscribers by frequency.
	 *
	 * @param string $frequency Frequency.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_active_by_frequency( string $frequency ): array {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE status = %s AND frequency = %s",
			'active',
			$frequency
		);

		$rows = $this->wpdb->get_results( $sql, 'ARRAY_A' );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Update last sent timestamp.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $sent_at Mysql datetime.
	 * @return void
	 */
	public function touch_last_sent_at( int $subscriber_id, string $sent_at ): void {
		$this->wpdb->update(
			$this->table,
			array(
				'last_sent_at' => $sent_at,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $subscriber_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Replace stored unsubscribe token hash.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $unsubscribe_token_hash Hash.
	 * @return void
	 */
	public function update_unsubscribe_token_hash( int $subscriber_id, string $unsubscribe_token_hash ): void {
		$this->wpdb->update(
			$this->table,
			array(
				'unsubscribe_token_hash' => $unsubscribe_token_hash,
				'updated_at'             => current_time( 'mysql' ),
			),
			array( 'id' => $subscriber_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Set subscriber status directly.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $status Status value.
	 * @return bool
	 */
	public function set_status( int $subscriber_id, string $status ): bool {
		$allowed = array( 'pending', 'active', 'unsubscribed', 'suppressed' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		$result = $this->wpdb->update(
			$this->table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $subscriber_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Add or update a suppressed email entry.
	 *
	 * @param string $email Email address.
	 * @return int Subscriber ID.
	 */
	public function upsert_suppressed( string $email ): int {
		$now      = current_time( 'mysql' );
		$existing = $this->find_by_email( $email );

		$data = array(
			'status'                 => 'suppressed',
			'optin_token_hash'       => null,
			'unsubscribe_token_hash' => null,
			'updated_at'             => $now,
		);

		if ( $existing ) {
			$this->wpdb->update(
				$this->table,
				$data,
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return (int) $existing['id'];
		}

		$insert_data = array(
			'email'                  => $email,
			'name'                   => '',
			'status'                 => 'suppressed',
			'frequency'              => 'daily',
			'optin_token_hash'       => null,
			'unsubscribe_token_hash' => null,
			'consent_text'           => '',
			'created_at'             => $now,
			'updated_at'             => $now,
		);

		$this->wpdb->insert(
			$this->table,
			$insert_data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Delete subscriber record.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return bool
	 */
	public function delete_by_id( int $subscriber_id ): bool {
		$result = $this->wpdb->delete(
			$this->table,
			array( 'id' => $subscriber_id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Fetch paginated subscribers for admin list.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return array<string,mixed>
	 */
	public function get_admin_list( array $args ): array {
		$page      = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$per_page  = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$status    = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$frequency = isset( $args['frequency'] ) ? sanitize_key( (string) $args['frequency'] ) : '';
		$search    = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		$where_clauses = array( '1=1' );
		$params        = array();

		if ( in_array( $status, array( 'pending', 'active', 'unsubscribed', 'suppressed' ), true ) ) {
			$where_clauses[] = 'status = %s';
			$params[]        = $status;
		}

		if ( in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			$where_clauses[] = 'frequency = %s';
			$params[]        = $frequency;
		}

		if ( '' !== $search ) {
			$where_clauses[] = '(email LIKE %s OR name LIKE %s)';
			$search_like     = '%' . $this->wpdb->esc_like( $search ) . '%';
			$params[]        = $search_like;
			$params[]        = $search_like;
		}

		$where_sql = implode( ' AND ', $where_clauses );
		$offset    = ( $page - 1 ) * $per_page;

		$count_sql   = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_sql}";
		$count_query = ! empty( $params ) ? $this->wpdb->prepare( $count_sql, $params ) : $count_sql;
		$total       = (int) $this->wpdb->get_var( $count_query );

		$list_sql = "SELECT * FROM {$this->table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$list_query  = $this->wpdb->prepare( $list_sql, $list_params );
		$rows        = $this->wpdb->get_results( $list_query, 'ARRAY_A' );

		return array(
			'total'    => $total,
			'rows'     => is_array( $rows ) ? $rows : array(),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Delete pending subscribers older than cutoff date.
	 *
	 * @param string $cutoff_mysql Mysql datetime cutoff.
	 * @return int Number of deleted rows.
	 */
	public function delete_pending_older_than( string $cutoff_mysql ): int {
		$sql = $this->wpdb->prepare(
			"DELETE FROM {$this->table} WHERE status = %s AND created_at < %s",
			'pending',
			$cutoff_mysql
		);

		$result = $this->wpdb->query( $sql );
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Get aggregate subscriber stats for dashboard widget.
	 *
	 * @return array<string,mixed>
	 */
	public function get_dashboard_counts(): array {
		$sql  = "SELECT status, frequency, COUNT(*) AS total FROM {$this->table} GROUP BY status, frequency";
		$rows = $this->wpdb->get_results( $sql, 'ARRAY_A' );

		$stats = array(
			'total'      => 0,
			'by_status'  => array(
				'active'       => 0,
				'pending'      => 0,
				'unsubscribed' => 0,
				'suppressed'   => 0,
			),
			'by_frequency' => array(
				'daily'   => 0,
				'weekly'  => 0,
				'monthly' => 0,
			),
		);

		if ( ! is_array( $rows ) ) {
			return $stats;
		}

		foreach ( $rows as $row ) {
			$status    = isset( $row['status'] ) ? (string) $row['status'] : '';
			$frequency = isset( $row['frequency'] ) ? (string) $row['frequency'] : '';
			$total     = isset( $row['total'] ) ? (int) $row['total'] : 0;
			if ( $total < 1 ) {
				continue;
			}

			$stats['total'] += $total;
			if ( isset( $stats['by_status'][ $status ] ) ) {
				$stats['by_status'][ $status ] += $total;
			}

			if ( 'active' === $status && isset( $stats['by_frequency'][ $frequency ] ) ) {
				$stats['by_frequency'][ $frequency ] += $total;
			}
		}

		return $stats;
	}

	/**
	 * Get signup trend for the dashboard widget.
	 *
	 * Compares the recent 7-day window with the previous 7-day window.
	 *
	 * @return array<string,int|float|null>
	 */
	public function get_signup_trend(): array {
		$now_mysql = current_time( 'mysql' );
		$sql       = "
			SELECT
				SUM(CASE WHEN created_at >= DATE_SUB(%s, INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS recent_count,
				SUM(
					CASE
						WHEN created_at >= DATE_SUB(%s, INTERVAL 14 DAY)
							AND created_at < DATE_SUB(%s, INTERVAL 7 DAY)
						THEN 1
						ELSE 0
					END
				) AS previous_count
			FROM {$this->table}
			WHERE status <> 'suppressed'
		";
		$query     = $this->wpdb->prepare( $sql, $now_mysql, $now_mysql, $now_mysql );
		$row       = $this->wpdb->get_row( $query, 'ARRAY_A' );

		$recent_count   = is_array( $row ) && isset( $row['recent_count'] ) ? (int) $row['recent_count'] : 0;
		$previous_count = is_array( $row ) && isset( $row['previous_count'] ) ? (int) $row['previous_count'] : 0;
		$delta          = $recent_count - $previous_count;
		$percent_change = null;

		if ( $previous_count > 0 ) {
			$percent_change = ( ( $recent_count - $previous_count ) / $previous_count ) * 100;
		}

		return array(
			'recent_count'   => $recent_count,
			'previous_count' => $previous_count,
			'delta'          => $delta,
			'percent_change' => $percent_change,
		);
	}
}
