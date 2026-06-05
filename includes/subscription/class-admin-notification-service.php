<?php
/**
 * Admin subscriber notification service.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Subscription;

use WSTP\Mailer\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Sends admin notifications for new subscribers.
 */
final class Admin_Notification_Service {
	/**
	 * Queue option name.
	 */
	private const QUEUE_OPTION = 'wstp_admin_subscriber_notification_queue';

	/**
	 * Daily summary hook.
	 */
	private const SUMMARY_HOOK = 'wstp_admin_subscriber_summary_event';

	/**
	 * Mailer service.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Constructor.
	 *
	 * @param Mailer $mailer Mailer service.
	 */
	public function __construct( Mailer $mailer ) {
		$this->mailer = $mailer;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'ensure_summary_event_scheduled' ), 20 );
		add_action( self::SUMMARY_HOOK, array( $this, 'send_daily_summary' ) );
	}

	/**
	 * Ensure daily summary event is scheduled.
	 *
	 * @return void
	 */
	public function ensure_summary_event_scheduled(): void {
		$scheduled = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::SUMMARY_HOOK ) : null;
		if ( is_object( $scheduled ) && isset( $scheduled->schedule ) && 'daily' === $scheduled->schedule ) {
			return;
		}

		wp_clear_scheduled_hook( self::SUMMARY_HOOK );
		wp_schedule_event( time() + 3600, 'daily', self::SUMMARY_HOOK );
	}

	/**
	 * Notify about pending DOI subscriber.
	 *
	 * @param array<string,mixed> $subscriber Subscriber data.
	 * @return void
	 */
	public function notify_pending( array $subscriber ): void {
		$this->handle_event( 'pending', $subscriber );
	}

	/**
	 * Notify about confirmed subscriber.
	 *
	 * @param array<string,mixed> $subscriber Subscriber data.
	 * @return void
	 */
	public function notify_confirmed( array $subscriber ): void {
		$this->handle_event( 'confirmed', $subscriber );
	}

	/**
	 * Send daily summary mail from queue.
	 *
	 * @return void
	 */
	public function send_daily_summary(): void {
		$settings = $this->get_settings();
		if ( ! $this->is_enabled( $settings ) || 'daily_summary' !== $settings['mode'] ) {
			return;
		}

		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		$total     = count( $queue );
		$confirmed = 0;
		$pending   = 0;
		foreach ( $queue as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['event'] ) ) {
				continue;
			}
			if ( 'confirmed' === $entry['event'] ) {
				++$confirmed;
			} elseif ( 'pending' === $entry['event'] ) {
				++$pending;
			}
		}

		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf(
			/* translators: 1: site name, 2: number of events. */
			__( '[%1$s] New subscriber notifications (%2$d)', 'we-subscribe-to-posts' ),
			$site_name,
			$total
		);

		$rows = '';
		foreach ( $queue as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$event_label = isset( $entry['event'] ) && 'confirmed' === $entry['event']
				? __( 'Confirmed', 'we-subscribe-to-posts' )
				: __( 'Pending DOI', 'we-subscribe-to-posts' );
			$email       = isset( $entry['email'] ) ? (string) $entry['email'] : '';
			$name        = isset( $entry['name'] ) ? (string) $entry['name'] : '';
			$frequency   = isset( $entry['frequency'] ) ? (string) $entry['frequency'] : '';
			$timestamp   = isset( $entry['created_at'] ) ? (string) $entry['created_at'] : '';

			$rows .= '<tr>';
			$rows .= '<td style="padding:6px 8px;border:1px solid #ddd;">' . esc_html( $event_label ) . '</td>';
			$rows .= '<td style="padding:6px 8px;border:1px solid #ddd;">' . esc_html( $email ) . '</td>';
			$rows .= '<td style="padding:6px 8px;border:1px solid #ddd;">' . esc_html( $name ) . '</td>';
			$rows .= '<td style="padding:6px 8px;border:1px solid #ddd;">' . esc_html( $frequency ) . '</td>';
			$rows .= '<td style="padding:6px 8px;border:1px solid #ddd;">' . esc_html( $timestamp ) . '</td>';
			$rows .= '</tr>';
		}

		$admin_link = admin_url( 'admin.php?page=wstp-subscribers' );
		$body       = '<html><body style="font-family:Arial,sans-serif;line-height:1.5;">';
		$body      .= '<p>' . esc_html(
			sprintf(
				/* translators: 1: total events, 2: confirmed events, 3: pending events. */
				__( 'Total: %1$d, confirmed: %2$d, pending: %3$d.', 'we-subscribe-to-posts' ),
				$total,
				$confirmed,
				$pending
			)
		) . '</p>';
		$body      .= '<table cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">';
		$body      .= '<thead><tr>';
		$body      .= '<th style="padding:6px 8px;border:1px solid #ddd;text-align:left;">' . esc_html__( 'Status', 'we-subscribe-to-posts' ) . '</th>';
		$body      .= '<th style="padding:6px 8px;border:1px solid #ddd;text-align:left;">' . esc_html__( 'Email', 'we-subscribe-to-posts' ) . '</th>';
		$body      .= '<th style="padding:6px 8px;border:1px solid #ddd;text-align:left;">' . esc_html__( 'Name', 'we-subscribe-to-posts' ) . '</th>';
		$body      .= '<th style="padding:6px 8px;border:1px solid #ddd;text-align:left;">' . esc_html__( 'Frequency', 'we-subscribe-to-posts' ) . '</th>';
		$body      .= '<th style="padding:6px 8px;border:1px solid #ddd;text-align:left;">' . esc_html__( 'Time', 'we-subscribe-to-posts' ) . '</th>';
		$body      .= '</tr></thead><tbody>' . $rows . '</tbody></table>';
		$body      .= '<p><a href="' . esc_url( $admin_link ) . '">' . esc_html__( 'Open Subscribers screen', 'we-subscribe-to-posts' ) . '</a></p>';
		$body      .= '</body></html>';

		$sent = $this->mailer->send_admin_notification( $settings['recipient'], $subject, $body );
		if ( $sent ) {
			delete_option( self::QUEUE_OPTION );
		}
	}

	/**
	 * Process one event according to settings.
	 *
	 * @param string              $event pending|confirmed.
	 * @param array<string,mixed> $subscriber Subscriber data.
	 * @return void
	 */
	private function handle_event( string $event, array $subscriber ): void {
		$settings = $this->get_settings();
		if ( ! $this->is_enabled( $settings ) ) {
			return;
		}

		if ( 'confirmed_only' === $settings['trigger'] && 'pending' === $event ) {
			return;
		}

		$record = array(
			'event'      => $event,
			'email'      => isset( $subscriber['email'] ) ? sanitize_email( (string) $subscriber['email'] ) : '',
			'name'       => isset( $subscriber['name'] ) ? sanitize_text_field( (string) $subscriber['name'] ) : '',
			'frequency'  => isset( $subscriber['frequency'] ) ? sanitize_key( (string) $subscriber['frequency'] ) : '',
			'created_at' => current_time( 'mysql' ),
		);

		if ( 'instant' === $settings['mode'] ) {
			$this->send_instant( $record, $settings['recipient'] );
			return;
		}

		$this->queue_event( $record );
	}

	/**
	 * Send one instant admin notification.
	 *
	 * @param array<string,string> $record Event data.
	 * @param string               $recipient Recipient email.
	 * @return void
	 */
	private function send_instant( array $record, string $recipient ): void {
		$event_label = 'confirmed' === $record['event']
			? __( 'subscriber confirmed', 'we-subscribe-to-posts' )
			: __( 'subscriber pending DOI', 'we-subscribe-to-posts' );
		$site_name   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$subject     = sprintf(
			/* translators: 1: site name, 2: event label. */
			__( '[%1$s] New %2$s', 'we-subscribe-to-posts' ),
			$site_name,
			$event_label
		);

		$admin_link = admin_url( 'admin.php?page=wstp-subscribers' );
		$body       = '<html><body style="font-family:Arial,sans-serif;line-height:1.5;">';
		$body      .= '<p><strong>' . esc_html__( 'Email:', 'we-subscribe-to-posts' ) . '</strong> ' . esc_html( $record['email'] ) . '</p>';
		$body      .= '<p><strong>' . esc_html__( 'Name:', 'we-subscribe-to-posts' ) . '</strong> ' . esc_html( $record['name'] ) . '</p>';
		$body      .= '<p><strong>' . esc_html__( 'Frequency:', 'we-subscribe-to-posts' ) . '</strong> ' . esc_html( $record['frequency'] ) . '</p>';
		$body      .= '<p><strong>' . esc_html__( 'Status:', 'we-subscribe-to-posts' ) . '</strong> ' . esc_html( $event_label ) . '</p>';
		$body      .= '<p><strong>' . esc_html__( 'Time:', 'we-subscribe-to-posts' ) . '</strong> ' . esc_html( $record['created_at'] ) . '</p>';
		$body      .= '<p><a href="' . esc_url( $admin_link ) . '">' . esc_html__( 'Open Subscribers screen', 'we-subscribe-to-posts' ) . '</a></p>';
		$body      .= '</body></html>';

		$this->mailer->send_admin_notification( $recipient, $subject, $body );
	}

	/**
	 * Add event to summary queue.
	 *
	 * @param array<string,string> $record Event data.
	 * @return void
	 */
	private function queue_event( array $record ): void {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$queue[] = $record;
		if ( count( $queue ) > 500 ) {
			$queue = array_slice( $queue, -500 );
		}

		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * Resolve notification settings.
	 *
	 * @return array<string,string>
	 */
	private function get_settings(): array {
		$settings = get_option( 'wstp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$enabled = isset( $settings['admin_subscriber_notifications_enabled'] ) ? sanitize_key( (string) $settings['admin_subscriber_notifications_enabled'] ) : 'no';
		$trigger = isset( $settings['admin_subscriber_notifications_trigger'] ) ? sanitize_key( (string) $settings['admin_subscriber_notifications_trigger'] ) : 'confirmed_only';
		$mode    = isset( $settings['admin_subscriber_notifications_mode'] ) ? sanitize_key( (string) $settings['admin_subscriber_notifications_mode'] ) : 'daily_summary';
		$email   = isset( $settings['admin_subscriber_notifications_email'] ) ? sanitize_email( (string) $settings['admin_subscriber_notifications_email'] ) : '';

		if ( '' === $email ) {
			$email = (string) get_option( 'admin_email' );
		}

		if ( ! in_array( $trigger, array( 'confirmed_only', 'pending_and_confirmed' ), true ) ) {
			$trigger = 'confirmed_only';
		}

		if ( ! in_array( $mode, array( 'instant', 'daily_summary' ), true ) ) {
			$mode = 'daily_summary';
		}

		return array(
			'enabled'   => 'yes' === $enabled ? 'yes' : 'no',
			'trigger'   => $trigger,
			'mode'      => $mode,
			'recipient' => $email,
		);
	}

	/**
	 * Check if notifications are enabled.
	 *
	 * @param array<string,string> $settings Notification settings.
	 * @return bool
	 */
	private function is_enabled( array $settings ): bool {
		return isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
	}
}

