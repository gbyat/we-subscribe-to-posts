<?php
/**
 * Subscribers admin page.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Admin;

use WSTP\DB\Event_Log_Repository;
use WSTP\DB\Subscriber_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Renders subscriber management UI.
 */
final class Subscribers_Page {
	/**
	 * Parent plugin menu.
	 *
	 * @var string
	 */
	private const PARENT_MENU_SLUG = 'wstp-settings';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'wstp-subscribers';

	/**
	 * Repo.
	 *
	 * @var Subscriber_Repository
	 */
	private Subscriber_Repository $subscriber_repository;

	/**
	 * Delivery log repo.
	 *
	 * @var Event_Log_Repository
	 */
	private Event_Log_Repository $event_log_repository;

	/**
	 * Constructor.
	 *
	 * @param Subscriber_Repository $subscriber_repository Subscriber repository.
	 * @param Event_Log_Repository  $event_log_repository Event repository.
	 */
	public function __construct( Subscriber_Repository $subscriber_repository, Event_Log_Repository $event_log_repository ) {
		$this->subscriber_repository = $subscriber_repository;
		$this->event_log_repository  = $event_log_repository;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_submenu' ), 60 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_post_wstp_export_subscribers_csv', array( $this, 'export_csv' ) );
		add_action( 'admin_post_wstp_add_suppression', array( $this, 'handle_add_suppression' ) );
		add_action( 'admin_post_wstp_bulk_subscribers_action', array( $this, 'handle_bulk_action' ) );
	}

	/**
	 * Register subscribers submenu.
	 *
	 * @return void
	 */
	public function register_submenu(): void {
		add_submenu_page(
			self::PARENT_MENU_SLUG,
			__( 'Subscribers', 'we-subscribe-to-posts' ),
			__( 'Subscribers', 'we-subscribe-to-posts' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle row actions from list view.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		$action = isset( $_GET['wstp_subscriber_action'] ) ? sanitize_key( wp_unslash( $_GET['wstp_subscriber_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'wstp_subscriber_action' );

		$subscriber_id = isset( $_GET['subscriber_id'] ) ? (int) $_GET['subscriber_id'] : 0;
		if ( $subscriber_id <= 0 ) {
			$this->redirect_with_notice( 'subscriber_action_failed' );
		}

		$success = $this->perform_subscriber_action( $action, $subscriber_id );

		$this->redirect_with_notice( $success ? 'subscriber_action_success' : 'subscriber_action_failed' );
	}

	/**
	 * Handle bulk subscriber actions from subscribers table.
	 *
	 * @return void
	 */
	public function handle_bulk_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'we-subscribe-to-posts' ) );
		}

		if ( ! isset( $_POST['wstp_bulk_action_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wstp_bulk_action_nonce'] ) ), 'wstp_bulk_subscribers_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'we-subscribe-to-posts' ) );
		}

		$action = isset( $_POST['wstp_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['wstp_bulk_action'] ) ) : '';
		$ids    = isset( $_POST['wstp_subscriber_ids'] ) && is_array( $_POST['wstp_subscriber_ids'] ) ? array_map( 'intval', wp_unslash( $_POST['wstp_subscriber_ids'] ) ) : array();
		$ids    = array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );

		if ( '' === $action || empty( $ids ) ) {
			$this->redirect_with_notice( 'bulk_action_invalid' );
		}

		$success_count = 0;
		foreach ( $ids as $subscriber_id ) {
			if ( $this->perform_subscriber_action( $action, $subscriber_id ) ) {
				++$success_count;
			}
		}

		if ( 0 === $success_count ) {
			$this->redirect_with_notice( 'subscriber_action_failed' );
		}

		if ( $success_count < count( $ids ) ) {
			$this->redirect_with_notice( 'bulk_action_partial' );
		}

		$this->redirect_with_notice( 'bulk_action_success' );
	}

	/**
	 * Render subscribers list page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$frequency = isset( $_GET['frequency'] ) ? sanitize_key( wp_unslash( $_GET['frequency'] ) ) : '';
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page      = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page  = 25;

		$data = $this->subscriber_repository->get_admin_list(
			array(
				'status'    => $status,
				'frequency' => $frequency,
				'search'    => $search,
				'page'      => $page,
				'per_page'  => $per_page,
			)
		);

		$rows           = $data['rows'];
		$total          = (int) $data['total'];
		$total_pages    = (int) ceil( $total / $per_page );
		$subscriber_ids = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
		$delivery_stats = $this->event_log_repository->get_stats_for_subscribers( $subscriber_ids );
		$notice         = isset( $_GET['wstp_notice'] ) ? sanitize_key( wp_unslash( $_GET['wstp_notice'] ) ) : '';
		?>
		<div class="wrap wstp-subscribers-screen">
			<h1><?php esc_html_e( 'Subscribers', 'we-subscribe-to-posts' ); ?></h1>

			<?php $this->render_notice( $notice ); ?>
			<style>
				.wstp-subscribers-screen .wstp-table-scroll {
					position: relative;
					max-width: 100%;
					overflow-x: auto;
					-webkit-overflow-scrolling: touch;
				}
				.wstp-subscribers-screen .wstp-subscribers-table {
					min-width: 980px;
				}
				.wstp-subscribers-screen .wstp-subscribers-table .check-column {
					position: sticky;
					left: 0;
					z-index: 2;
					background: #fff;
				}
				.wstp-subscribers-screen .wstp-subscribers-table thead .check-column {
					z-index: 3;
					background: #f6f7f7;
				}
				.wstp-subscribers-screen .wstp-filter-row label {
					margin-right: 6px;
				}
				.wstp-subscribers-screen .wstp-filter-row select {
					margin-right: 10px;
				}
				@media (max-width: 782px) {
					.wstp-subscribers-screen .search-box {
						float: none;
						max-width: none;
						width: 100%;
						margin: 0 0 10px 0;
					}
					.wstp-subscribers-screen .search-box input[type="search"] {
						width: 100%;
						max-width: none;
					}
					.wstp-subscribers-screen .wstp-filter-row {
						display: grid;
						gap: 8px;
					}
					.wstp-subscribers-screen .wstp-filter-row label {
						display: block;
						margin: 0;
					}
					.wstp-subscribers-screen .wstp-filter-row select {
						width: 100%;
						margin: 0;
					}
					.wstp-subscribers-screen .tablenav.top,
					.wstp-subscribers-screen .tablenav.bottom {
						height: auto;
						display: flex;
						flex-wrap: wrap;
						gap: 8px;
						align-items: center;
					}
					.wstp-subscribers-screen .tablenav .actions.bulkactions {
						float: none;
						width: 100%;
						display: flex;
						flex-wrap: wrap;
						gap: 8px;
						align-items: center;
					}
					.wstp-subscribers-screen .tablenav .actions.bulkactions select {
						flex: 1 1 180px;
						max-width: none;
					}
					.wstp-subscribers-screen .tablenav .tablenav-pages {
						margin-left: 0;
					}
				}
			</style>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 10px 0 18px 0; padding: 10px 12px; border: 1px solid #dcdcde; background: #fff;">
				<input type="hidden" name="action" value="wstp_add_suppression" />
				<?php wp_nonce_field( 'wstp_add_suppression', 'wstp_add_suppression_nonce' ); ?>
				<label for="wstp-suppress-email"><strong><?php esc_html_e( 'Add email to suppression list (never send again)', 'we-subscribe-to-posts' ); ?></strong></label><br />
				<input type="email" id="wstp-suppress-email" name="wstp_suppress_email" class="regular-text" required />
				<?php submit_button( __( 'Add to suppression list', 'we-subscribe-to-posts' ), 'secondary', '', false ); ?>
			</form>

			<form method="get" action="" class="wstp-subscribers-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<p class="search-box">
					<label class="screen-reader-text" for="wstp-search"><?php esc_html_e( 'Search subscribers', 'we-subscribe-to-posts' ); ?></label>
					<input type="search" id="wstp-search" name="s" value="<?php echo esc_attr( $search ); ?>" />
					<?php submit_button( __( 'Search', 'we-subscribe-to-posts' ), '', '', false ); ?>
				</p>
				<p class="wstp-filter-row">
					<label for="wstp-status-filter"><?php esc_html_e( 'Status', 'we-subscribe-to-posts' ); ?></label>
					<select id="wstp-status-filter" name="status">
						<option value=""><?php esc_html_e( 'All', 'we-subscribe-to-posts' ); ?></option>
						<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'we-subscribe-to-posts' ); ?></option>
						<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'we-subscribe-to-posts' ); ?></option>
						<option value="unsubscribed" <?php selected( $status, 'unsubscribed' ); ?>><?php esc_html_e( 'Unsubscribed', 'we-subscribe-to-posts' ); ?></option>
						<option value="suppressed" <?php selected( $status, 'suppressed' ); ?>><?php esc_html_e( 'Suppressed', 'we-subscribe-to-posts' ); ?></option>
					</select>

					<label for="wstp-frequency-filter"><?php esc_html_e( 'Frequency', 'we-subscribe-to-posts' ); ?></label>
					<select id="wstp-frequency-filter" name="frequency">
						<option value=""><?php esc_html_e( 'All', 'we-subscribe-to-posts' ); ?></option>
						<option value="daily" <?php selected( $frequency, 'daily' ); ?>><?php esc_html_e( 'Daily', 'we-subscribe-to-posts' ); ?></option>
						<option value="weekly" <?php selected( $frequency, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'we-subscribe-to-posts' ); ?></option>
						<option value="monthly" <?php selected( $frequency, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'we-subscribe-to-posts' ); ?></option>
					</select>

					<?php submit_button( __( 'Filter', 'we-subscribe-to-posts' ), 'secondary', '', false ); ?>
				</p>
			</form>

			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $this->get_export_url( $status, $frequency, $search ) ); ?>">
					<?php esc_html_e( 'Export CSV', 'we-subscribe-to-posts' ); ?>
				</a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wstp_bulk_subscribers_action" />
				<?php wp_nonce_field( 'wstp_bulk_subscribers_action', 'wstp_bulk_action_nonce' ); ?>
				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<label for="wstp-bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'we-subscribe-to-posts' ); ?></label>
						<select id="wstp-bulk-action-selector-top" name="wstp_bulk_action">
							<option value=""><?php esc_html_e( 'Bulk actions', 'we-subscribe-to-posts' ); ?></option>
							<option value="unsubscribe"><?php esc_html_e( 'Unsubscribe', 'we-subscribe-to-posts' ); ?></option>
							<option value="suppress"><?php esc_html_e( 'Suppress', 'we-subscribe-to-posts' ); ?></option>
							<option value="reactivate"><?php esc_html_e( 'Reactivate', 'we-subscribe-to-posts' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete permanently', 'we-subscribe-to-posts' ); ?></option>
						</select>
						<?php submit_button( __( 'Apply', 'we-subscribe-to-posts' ), 'action', '', false ); ?>
					</div>
					<div class="tablenav-pages one-page">
						<span class="displaying-num"><?php echo esc_html( sprintf( /* translators: %d: subscriber count. */ _n( '%d subscriber', '%d subscribers', $total, 'we-subscribe-to-posts' ), $total ) ); ?></span>
					</div>
				</div>

				<div class="wstp-table-scroll">
				<table class="widefat striped wstp-subscribers-table">
				<thead>
				<tr>
					<th class="check-column"><input type="checkbox" id="wstp-select-all" /></th>
					<th><?php esc_html_e( 'Email', 'we-subscribe-to-posts' ); ?></th>
					<th><?php esc_html_e( 'Name', 'we-subscribe-to-posts' ); ?></th>
					<th><?php esc_html_e( 'Status', 'we-subscribe-to-posts' ); ?></th>
					<th><?php esc_html_e( 'Frequency', 'we-subscribe-to-posts' ); ?></th>
					<th><?php esc_html_e( 'Created', 'we-subscribe-to-posts' ); ?></th>
					<th><?php esc_html_e( 'Deliveries', 'we-subscribe-to-posts' ); ?></th>
					<th><?php esc_html_e( 'Last Result', 'we-subscribe-to-posts' ); ?></th>
					<th><?php esc_html_e( 'Consent Proof', 'we-subscribe-to-posts' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'we-subscribe-to-posts' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="10"><?php esc_html_e( 'No subscribers found.', 'we-subscribe-to-posts' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$subscriber_id = (int) $row['id'];
						$stat          = $delivery_stats[ $subscriber_id ] ?? array(
							'sent_count'   => 0,
							'failed_count' => 0,
							'last_sent_at' => '',
							'last_result'  => '',
						);
						?>
						<tr>
							<th scope="row" class="check-column">
								<input type="checkbox" class="wstp-row-select" name="wstp_subscriber_ids[]" value="<?php echo esc_attr( (string) $subscriber_id ); ?>" />
							</th>
							<td><?php echo esc_html( (string) $row['email'] ); ?></td>
							<td><?php echo esc_html( (string) $row['name'] ); ?></td>
							<td><?php echo esc_html( ucfirst( (string) $row['status'] ) ); ?></td>
							<td><?php echo esc_html( ucfirst( (string) $row['frequency'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
							<td><?php echo esc_html( sprintf( 'sent: %d / failed: %d', (int) $stat['sent_count'], (int) $stat['failed_count'] ) ); ?></td>
							<td>
								<?php
								$last_result = (string) $stat['last_result'];
								$last_sent   = (string) $stat['last_sent_at'];
								echo esc_html( $last_result ? $last_result : '-' );
								if ( $last_sent ) {
									echo '<br /><small>' . esc_html( $last_sent ) . '</small>';
								}
								?>
							</td>
							<td>
								<?php
								$consent = $this->parse_consent_snapshot( isset( $row['consent_text'] ) ? (string) $row['consent_text'] : '' );
								if ( '' !== $consent['consented_at'] ) {
									echo '<strong>' . esc_html( $consent['consented_at'] ) . '</strong>';
								}
								if ( '' !== $consent['ip'] ) {
									echo '<br /><small>' . esc_html( sprintf( 'IP: %s', $consent['ip'] ) ) . '</small>';
								}
								if ( '' !== $consent['text'] ) {
									echo '<br /><small>' . esc_html( wp_html_excerpt( $consent['text'], 120, '...' ) ) . '</small>';
								}
								?>
							</td>
							<td>
								<?php echo $this->render_actions( $subscriber_id, (string) $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
				</div>
				<div class="tablenav bottom">
					<div class="alignleft actions bulkactions">
						<label for="wstp-bulk-action-selector-bottom" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'we-subscribe-to-posts' ); ?></label>
						<select id="wstp-bulk-action-selector-bottom" name="wstp_bulk_action_bottom">
							<option value=""><?php esc_html_e( 'Bulk actions', 'we-subscribe-to-posts' ); ?></option>
							<option value="unsubscribe"><?php esc_html_e( 'Unsubscribe', 'we-subscribe-to-posts' ); ?></option>
							<option value="suppress"><?php esc_html_e( 'Suppress', 'we-subscribe-to-posts' ); ?></option>
							<option value="reactivate"><?php esc_html_e( 'Reactivate', 'we-subscribe-to-posts' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete permanently', 'we-subscribe-to-posts' ); ?></option>
						</select>
						<?php submit_button( __( 'Apply', 'we-subscribe-to-posts' ), 'action', 'wstp_bulk_apply_bottom', false ); ?>
					</div>
				</div>
			</form>
			<script>
				(function() {
					var form = document.querySelector('form[action="<?php echo esc_js( admin_url( 'admin-post.php' ) ); ?>"] input[name="action"][value="wstp_bulk_subscribers_action"]');
					if (!form) {
						return;
					}
					var bulkForm = form.closest('form');
					var topSelect = document.getElementById('wstp-bulk-action-selector-top');
					var bottomSelect = document.getElementById('wstp-bulk-action-selector-bottom');
					var selectAll = document.getElementById('wstp-select-all');
					var rowChecks = bulkForm.querySelectorAll('.wstp-row-select');

					var syncTargetAction = function (sourceValue) {
						if (topSelect) {
							topSelect.value = sourceValue;
						}
					};

					if (bottomSelect) {
						bottomSelect.addEventListener('change', function () {
							syncTargetAction(bottomSelect.value || '');
						});
					}

					if (topSelect) {
						topSelect.addEventListener('change', function () {
							if (bottomSelect) {
								bottomSelect.value = topSelect.value || '';
							}
						});
					}

					if (selectAll) {
						selectAll.addEventListener('change', function () {
							rowChecks.forEach(function (checkbox) {
								checkbox.checked = !!selectAll.checked;
							});
						});
					}

					bulkForm.addEventListener('submit', function (event) {
						var selectedIds = Array.prototype.filter.call(rowChecks, function (checkbox) {
							return checkbox.checked;
						});
						var actionValue = topSelect ? (topSelect.value || '') : '';

						if (!actionValue || selectedIds.length === 0) {
							return;
						}

						if (actionValue === 'delete' && !window.confirm('<?php echo esc_js( __( 'Delete selected subscribers permanently?', 'we-subscribe-to-posts' ) ); ?>')) {
							event.preventDefault();
						}
					});
				})();
			</script>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg(
										array(
											'page'      => self::PAGE_SLUG,
											'status'    => $status,
											'frequency' => $frequency,
											's'         => $search,
											'paged'     => '%#%',
										),
										admin_url( 'admin.php' )
									),
									'format'    => '',
									'current'   => $page,
									'total'     => $total_pages,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Export filtered subscribers as CSV.
	 *
	 * @return void
	 */
	public function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'we-subscribe-to-posts' ) );
		}

		check_admin_referer( 'wstp_export_subscribers' );

		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$frequency = isset( $_GET['frequency'] ) ? sanitize_key( wp_unslash( $_GET['frequency'] ) ) : '';
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$data = $this->subscriber_repository->get_admin_list(
			array(
				'status'    => $status,
				'frequency' => $frequency,
				'search'    => $search,
				'page'      => 1,
				'per_page'  => 100000,
			)
		);

		$rows           = $data['rows'];
		$subscriber_ids = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
		$delivery_stats = $this->event_log_repository->get_stats_for_subscribers( $subscriber_ids );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="wstp-subscribers.csv"' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		fputcsv( $output, array( 'id', 'email', 'name', 'status', 'frequency', 'created_at', 'confirmed_at', 'last_sent_at', 'sent_count', 'failed_count', 'last_result', 'consent_recorded_at', 'consent_ip', 'consent_text' ) );

		foreach ( $rows as $row ) {
			$subscriber_id = (int) $row['id'];
			$stat          = $delivery_stats[ $subscriber_id ] ?? array(
				'sent_count'   => 0,
				'failed_count' => 0,
				'last_result'  => '',
			);
			$consent       = $this->parse_consent_snapshot( isset( $row['consent_text'] ) ? (string) $row['consent_text'] : '' );

			fputcsv(
				$output,
				array(
					$subscriber_id,
					(string) $row['email'],
					(string) $row['name'],
					(string) $row['status'],
					(string) $row['frequency'],
					(string) $row['created_at'],
					(string) $row['confirmed_at'],
					(string) $row['last_sent_at'],
					(int) $stat['sent_count'],
					(int) $stat['failed_count'],
					(string) $stat['last_result'],
					$consent['consented_at'],
					$consent['ip'],
					$consent['text'],
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Render row action links.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $status Current status.
	 * @return string
	 */
	private function render_actions( int $subscriber_id, string $status ): string {
		$links = array();

		if ( in_array( $status, array( 'active', 'pending' ), true ) ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->build_action_url( 'unsubscribe', $subscriber_id ) ),
				esc_html__( 'Unsubscribe', 'we-subscribe-to-posts' )
			);
		}

		if ( 'suppressed' !== $status ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->build_action_url( 'suppress', $subscriber_id ) ),
				esc_html__( 'Suppress', 'we-subscribe-to-posts' )
			);
		}

		if ( 'active' !== $status ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->build_action_url( 'reactivate', $subscriber_id ) ),
				esc_html__( 'Reactivate', 'we-subscribe-to-posts' )
			);
		}

		$links[] = sprintf(
			'<a href="%s" style="color:#b32d2e;" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( $this->build_action_url( 'delete', $subscriber_id ) ),
			esc_js( __( 'Delete subscriber permanently?', 'we-subscribe-to-posts' ) ),
			esc_html__( 'Delete', 'we-subscribe-to-posts' )
		);

		return implode( ' | ', $links );
	}

	/**
	 * Execute a supported subscriber action.
	 *
	 * @param string $action Action key.
	 * @param int    $subscriber_id Subscriber ID.
	 * @return bool
	 */
	private function perform_subscriber_action( string $action, int $subscriber_id ): bool {
		switch ( $action ) {
			case 'unsubscribe':
				return $this->subscriber_repository->mark_unsubscribed( $subscriber_id );
			case 'suppress':
				return $this->subscriber_repository->set_status( $subscriber_id, 'suppressed' );
			case 'reactivate':
				return $this->subscriber_repository->set_status( $subscriber_id, 'active' );
			case 'delete':
				return $this->subscriber_repository->delete_by_id( $subscriber_id );
			default:
				return false;
		}
	}

	/**
	 * Build action URL with nonce.
	 *
	 * @param string $action Action key.
	 * @param int    $subscriber_id Subscriber ID.
	 * @return string
	 */
	private function build_action_url( string $action, int $subscriber_id ): string {
		$url = add_query_arg(
			array(
				'page'                   => self::PAGE_SLUG,
				'wstp_subscriber_action' => $action,
				'subscriber_id'          => $subscriber_id,
			),
			admin_url( 'admin.php' )
		);

		return wp_nonce_url( $url, 'wstp_subscriber_action' );
	}

	/**
	 * Build CSV export URL.
	 *
	 * @param string $status Status filter.
	 * @param string $frequency Frequency filter.
	 * @param string $search Search term.
	 * @return string
	 */
	private function get_export_url( string $status, string $frequency, string $search ): string {
		$url = add_query_arg(
			array(
				'action'    => 'wstp_export_subscribers_csv',
				'status'    => $status,
				'frequency' => $frequency,
				's'         => $search,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'wstp_export_subscribers' );
	}

	/**
	 * Redirect back with admin notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect_with_notice( string $notice ): void {
		$url = add_query_arg(
			array(
				'page'        => self::PAGE_SLUG,
				'wstp_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render page notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function render_notice( string $notice ): void {
		$map = array(
			'subscriber_action_success' => array( 'updated', __( 'Subscriber action completed.', 'we-subscribe-to-posts' ) ),
			'subscriber_action_failed'  => array( 'error', __( 'Subscriber action failed.', 'we-subscribe-to-posts' ) ),
			'bulk_action_success'       => array( 'updated', __( 'Bulk action completed for all selected subscribers.', 'we-subscribe-to-posts' ) ),
			'bulk_action_partial'       => array( 'warning', __( 'Bulk action completed with partial failures.', 'we-subscribe-to-posts' ) ),
			'bulk_action_invalid'       => array( 'error', __( 'Please select a bulk action and at least one subscriber.', 'we-subscribe-to-posts' ) ),
			'suppression_added'         => array( 'updated', __( 'Email added to suppression list.', 'we-subscribe-to-posts' ) ),
			'suppression_invalid'       => array( 'error', __( 'Please provide a valid email to suppress.', 'we-subscribe-to-posts' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		?>
		<div class="<?php echo esc_attr( 'notice notice-' . $map[ $notice ][0] ); ?>">
			<p><?php echo esc_html( $map[ $notice ][1] ); ?></p>
		</div>
		<?php
	}

	/**
	 * Handle manual suppression form submit.
	 *
	 * @return void
	 */
	public function handle_add_suppression(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'we-subscribe-to-posts' ) );
		}

		if ( ! isset( $_POST['wstp_add_suppression_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wstp_add_suppression_nonce'] ) ), 'wstp_add_suppression' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'we-subscribe-to-posts' ) );
		}

		$email = isset( $_POST['wstp_suppress_email'] ) ? sanitize_email( wp_unslash( $_POST['wstp_suppress_email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			$this->redirect_with_notice( 'suppression_invalid' );
		}

		$this->subscriber_repository->upsert_suppressed( $email );
		$this->redirect_with_notice( 'suppression_added' );
	}

	/**
	 * Parse consent snapshot JSON or legacy plain text.
	 *
	 * @param string $raw Consent column value.
	 * @return array<string,string>
	 */
	private function parse_consent_snapshot( string $raw ): array {
		$parsed = json_decode( $raw, true );
		if ( is_array( $parsed ) ) {
			return array(
				'text'        => isset( $parsed['text'] ) && is_string( $parsed['text'] ) ? $parsed['text'] : '',
				'consented_at'=> isset( $parsed['consented_at'] ) && is_string( $parsed['consented_at'] ) ? $parsed['consented_at'] : '',
				'ip'          => isset( $parsed['ip'] ) && is_string( $parsed['ip'] ) ? $parsed['ip'] : '',
			);
		}

		return array(
			'text'        => $raw,
			'consented_at'=> '',
			'ip'          => '',
		);
	}
}
