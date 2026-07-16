<?php
/**
 * Dashboard widget.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Admin;

use WSTP\DB\Event_Log_Repository;
use WSTP\DB\Subscriber_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Adds optional admin dashboard widget with subscription stats.
 */
final class Dashboard_Widget {
	/**
	 * Subscriber repository.
	 *
	 * @var Subscriber_Repository
	 */
	private Subscriber_Repository $subscriber_repository;

	/**
	 * Event repository.
	 *
	 * @var Event_Log_Repository
	 */
	private Event_Log_Repository $event_repository;

	/**
	 * Constructor.
	 *
	 * @param Subscriber_Repository $subscriber_repository Subscriber repository.
	 * @param Event_Log_Repository  $event_repository Event repository.
	 */
	public function __construct( Subscriber_Repository $subscriber_repository, Event_Log_Repository $event_repository ) {
		$this->subscriber_repository = $subscriber_repository;
		$this->event_repository      = $event_repository;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	/**
	 * Register widget when enabled.
	 *
	 * @return void
	 */
	public function register_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		wp_add_dashboard_widget(
			'wstp_dashboard_widget',
			__( 'Post Subscriptions Overview', 'we-subscribe-to-posts' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Render widget body.
	 *
	 * @return void
	 */
	public function render_widget(): void {
		$subscriber_stats = $this->subscriber_repository->get_dashboard_counts();
		$signup_trend     = $this->subscriber_repository->get_signup_trend();
		$mail_stats       = $this->event_repository->get_dashboard_summary();
		$mail_settings    = $this->get_mail_settings();
		$transport_label  = $this->resolve_transport_label( (string) $mail_settings['transport'] );
		$apply_globally   = 'yes' === (string) $mail_settings['apply_globally']
			? __( 'Yes', 'we-subscribe-to-posts' )
			: __( 'No', 'we-subscribe-to-posts' );
		$last_sent_at     = isset( $mail_stats['last_sent_at'] ) ? (string) $mail_stats['last_sent_at'] : '';
		$last_sent_label  = '' !== $last_sent_at ? $last_sent_at : __( 'No sends yet', 'we-subscribe-to-posts' );

		$by_status    = isset( $subscriber_stats['by_status'] ) && is_array( $subscriber_stats['by_status'] ) ? $subscriber_stats['by_status'] : array();
		$by_frequency = isset( $subscriber_stats['by_frequency'] ) && is_array( $subscriber_stats['by_frequency'] ) ? $subscriber_stats['by_frequency'] : array();
		$signup_trend_label = $this->format_signup_trend_label( $signup_trend );
		?>
		<div class="wstp-dashboard-widget">
			<p><strong><?php esc_html_e( 'Subscribers', 'we-subscribe-to-posts' ); ?></strong></p>
			<ul style="margin-top:0;">
				<?php
				/* translators: %d: total subscriber count. */
				echo '<li>' . esc_html( sprintf( __( 'Total: %d', 'we-subscribe-to-posts' ), (int) ( $subscriber_stats['total'] ?? 0 ) ) ) . '</li>';
				/* translators: %d: active subscriber count. */
				echo '<li>' . esc_html( sprintf( __( 'Active: %d', 'we-subscribe-to-posts' ), (int) ( $by_status['active'] ?? 0 ) ) ) . '</li>';
				/* translators: %d: pending subscriber count. */
				echo '<li>' . esc_html( sprintf( __( 'Pending: %d', 'we-subscribe-to-posts' ), (int) ( $by_status['pending'] ?? 0 ) ) ) . '</li>';
				/* translators: %d: unsubscribed subscriber count. */
				echo '<li>' . esc_html( sprintf( __( 'Unsubscribed: %d', 'we-subscribe-to-posts' ), (int) ( $by_status['unsubscribed'] ?? 0 ) ) ) . '</li>';
				/* translators: %d: suppressed subscriber count. */
				echo '<li>' . esc_html( sprintf( __( 'Suppressed: %d', 'we-subscribe-to-posts' ), (int) ( $by_status['suppressed'] ?? 0 ) ) ) . '</li>';
				/* translators: %d: signup count in the last 7 days. */
				echo '<li>' . esc_html( sprintf( __( 'Signups (last 7 days): %d', 'we-subscribe-to-posts' ), (int) ( $signup_trend['recent_count'] ?? 0 ) ) ) . '</li>';
				?>
				<li><?php echo esc_html( $signup_trend_label ); ?></li>
			</ul>

			<p><strong><?php esc_html_e( 'Active by frequency', 'we-subscribe-to-posts' ); ?></strong></p>
			<ul style="margin-top:0;">
				<?php
				/* translators: %d: active daily subscriber count. */
				echo '<li>' . esc_html( sprintf( __( 'Daily: %d', 'we-subscribe-to-posts' ), (int) ( $by_frequency['daily'] ?? 0 ) ) ) . '</li>';
				/* translators: %d: active weekly subscriber count. */
				echo '<li>' . esc_html( sprintf( __( 'Weekly: %d', 'we-subscribe-to-posts' ), (int) ( $by_frequency['weekly'] ?? 0 ) ) ) . '</li>';
				/* translators: %d: active monthly subscriber count. */
				echo '<li>' . esc_html( sprintf( __( 'Monthly: %d', 'we-subscribe-to-posts' ), (int) ( $by_frequency['monthly'] ?? 0 ) ) ) . '</li>';
				?>
			</ul>

			<p><strong><?php esc_html_e( 'Mail usage', 'we-subscribe-to-posts' ); ?></strong></p>
			<ul style="margin-top:0;">
				<?php
				/* translators: %d: total digest mails sent. */
				echo '<li>' . esc_html( sprintf( __( 'Digest mails sent (all time): %d', 'we-subscribe-to-posts' ), (int) ( $mail_stats['sent_total'] ?? 0 ) ) ) . '</li>';
				/* translators: %d: total digest mails that failed. */
				echo '<li>' . esc_html( sprintf( __( 'Digest mails failed (all time): %d', 'we-subscribe-to-posts' ), (int) ( $mail_stats['failed_total'] ?? 0 ) ) ) . '</li>';
				/* translators: %d: digest mails sent in the last 7 days. */
				echo '<li>' . esc_html( sprintf( __( 'Digest mails sent (last 7 days): %d', 'we-subscribe-to-posts' ), (int) ( $mail_stats['sent_last_7_days'] ?? 0 ) ) ) . '</li>';
				/* translators: %d: number of unique subscribers who received digests. */
				echo '<li>' . esc_html( sprintf( __( 'Unique subscribers reached: %d', 'we-subscribe-to-posts' ), (int) ( $mail_stats['distinct_sent_subscribers'] ?? 0 ) ) ) . '</li>';
				/* translators: %s: last digest send datetime or status label. */
				echo '<li>' . esc_html( sprintf( __( 'Last digest send: %s', 'we-subscribe-to-posts' ), $last_sent_label ) ) . '</li>';
				?>
			</ul>

			<p><strong><?php esc_html_e( 'Mail transport', 'we-subscribe-to-posts' ); ?></strong></p>
			<ul style="margin-top:0;">
				<?php
				/* translators: %s: mail transport name. */
				echo '<li>' . esc_html( sprintf( __( 'Transport: %s', 'we-subscribe-to-posts' ), $transport_label ) ) . '</li>';
				/* translators: %s: yes/no whether transport applies globally. */
				echo '<li>' . esc_html( sprintf( __( 'Apply globally: %s', 'we-subscribe-to-posts' ), $apply_globally ) ) . '</li>';
				?>
			</ul>

			<p style="margin-bottom:0;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wstp-subscribers' ) ); ?>"><?php esc_html_e( 'Open Subscribers', 'we-subscribe-to-posts' ); ?></a>
				|
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wstp-settings' ) ); ?>"><?php esc_html_e( 'Open Settings', 'we-subscribe-to-posts' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Check if dashboard widget is enabled in settings.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		$settings = get_option( 'wstp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return isset( $settings['dashboard_widget_enabled'] ) && 'yes' === sanitize_key( (string) $settings['dashboard_widget_enabled'] );
	}

	/**
	 * Get normalized mail settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_mail_settings(): array {
		$defaults = array(
			'transport'      => 'wp_default',
			'apply_globally' => 'no',
		);
		$stored   = get_option( 'wstp_mail_settings', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, $defaults );
	}

	/**
	 * Map transport key to human label.
	 *
	 * @param string $transport Transport key.
	 * @return string
	 */
	private function resolve_transport_label( string $transport ): string {
		return match ( $transport ) {
			'smtp'   => __( 'Custom SMTP', 'we-subscribe-to-posts' ),
			'gmail'  => __( 'Gmail preset', 'we-subscribe-to-posts' ),
			default  => __( 'WordPress default', 'we-subscribe-to-posts' ),
		};
	}

	/**
	 * Build human-readable trend text for signups.
	 *
	 * @param array<string,int|float|null> $trend Signup trend data.
	 * @return string
	 */
	private function format_signup_trend_label( array $trend ): string {
		$delta          = isset( $trend['delta'] ) ? (int) $trend['delta'] : 0;
		$previous_count = isset( $trend['previous_count'] ) ? (int) $trend['previous_count'] : 0;
		$percent_change = isset( $trend['percent_change'] ) && is_float( $trend['percent_change'] ) ? (float) $trend['percent_change'] : null;

		if ( 0 === $delta ) {
			return __( 'Trend vs previous 7 days: stable', 'we-subscribe-to-posts' );
		}

		$direction = $delta > 0 ? __( 'up', 'we-subscribe-to-posts' ) : __( 'down', 'we-subscribe-to-posts' );
		$delta_abs = abs( $delta );

		if ( null === $percent_change || $previous_count <= 0 ) {
			return sprintf(
				/* translators: 1: direction label, 2: absolute change count. */
				__( 'Trend vs previous 7 days: %1$s by %2$d', 'we-subscribe-to-posts' ),
				$direction,
				$delta_abs
			);
		}

		return sprintf(
			/* translators: 1: direction label, 2: absolute change count, 3: percentage change. */
			__( 'Trend vs previous 7 days: %1$s by %2$d (%3$s%%)', 'we-subscribe-to-posts' ),
			$direction,
			$delta_abs,
			number_format_i18n( abs( $percent_change ), 1 )
		);
	}
}

