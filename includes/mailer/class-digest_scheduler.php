<?php
/**
 * Digest scheduler.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Mailer;

use WSTP\DB\Event_Log_Repository;
use WSTP\DB\Subscriber_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Handles cron schedule and delivery.
 */
final class Digest_Scheduler {
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
	 * Builder.
	 *
	 * @var Digest_Builder
	 */
	private Digest_Builder $digest_builder;

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Constructor.
	 *
	 * @param Subscriber_Repository $subscriber_repository Subscriber repo.
	 * @param Event_Log_Repository  $event_repository Event repo.
	 * @param Digest_Builder        $digest_builder Digest builder.
	 * @param Mailer                $mailer Mailer service.
	 */
	public function __construct( Subscriber_Repository $subscriber_repository, Event_Log_Repository $event_repository, Digest_Builder $digest_builder, Mailer $mailer ) {
		$this->subscriber_repository = $subscriber_repository;
		$this->event_repository      = $event_repository;
		$this->digest_builder        = $digest_builder;
		$this->mailer                = $mailer;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wstp_daily_digest_event', array( $this, 'send_daily_digests' ) );
		add_action( 'wstp_weekly_digest_event', array( $this, 'send_weekly_digests' ) );
		add_action( 'wstp_monthly_digest_event', array( $this, 'send_monthly_digests' ) );
		add_action( 'init', array( $this, 'ensure_events_scheduled' ), 20 );
	}

	/**
	 * Ensure digest events are scheduled hourly.
	 *
	 * Hourly scheduling is required because the actual send hour is checked
	 * at runtime. A daily cron schedule can permanently miss the configured
	 * hour depending on activation time.
	 *
	 * @return void
	 */
	public function ensure_events_scheduled(): void {
		$this->ensure_event_is_hourly( 'wstp_daily_digest_event' );
		$this->ensure_event_is_hourly( 'wstp_weekly_digest_event' );
		$this->ensure_event_is_hourly( 'wstp_monthly_digest_event' );
	}

	/**
	 * Ensure a single event hook runs hourly.
	 *
	 * @param string $hook Event hook name.
	 * @return void
	 */
	private function ensure_event_is_hourly( string $hook ): void {
		$scheduled = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( $hook ) : null;

		if ( is_object( $scheduled ) && isset( $scheduled->schedule ) && 'hourly' === $scheduled->schedule ) {
			return;
		}

		$next_run = is_object( $scheduled ) && isset( $scheduled->timestamp ) ? (int) $scheduled->timestamp : 0;
		if ( $next_run <= time() ) {
			$next_run = time() + 300;
		}

		wp_clear_scheduled_hook( $hook );
		wp_schedule_event( $next_run, 'hourly', $hook );
	}

	/**
	 * Daily sender.
	 *
	 * @return void
	 */
	public function send_daily_digests(): void {
		if ( ! $this->is_dispatch_due( 'daily' ) ) {
			return;
		}

		$this->send_for_frequency( 'daily' );
		$this->mark_dispatch_done( 'daily' );
	}

	/**
	 * Weekly sender.
	 *
	 * @return void
	 */
	public function send_weekly_digests(): void {
		if ( ! $this->is_weekly_send_day() || ! $this->is_dispatch_due( 'weekly' ) ) {
			return;
		}

		$this->send_for_frequency( 'weekly' );
		$this->mark_dispatch_done( 'weekly' );
	}

	/**
	 * Monthly sender.
	 *
	 * @return void
	 */
	public function send_monthly_digests(): void {
		if ( ! $this->is_monthly_send_day() || ! $this->is_dispatch_due( 'monthly' ) ) {
			return;
		}

		$this->send_for_frequency( 'monthly' );
		$this->mark_dispatch_done( 'monthly' );
	}

	/**
	 * Send digest for one frequency.
	 *
	 * @param string $frequency Frequency key.
	 * @return void
	 */
	private function send_for_frequency( string $frequency ): void {
		$subscribers = $this->subscriber_repository->get_active_by_frequency( $frequency );
		$settings    = $this->get_general_settings();
		$rate_limit  = isset( $settings['throttle_per_minute'] ) ? (int) $settings['throttle_per_minute'] : 0;
		$delay_us    = $rate_limit > 0 ? (int) floor( 60000000 / $rate_limit ) : 0;
		$attempted   = 0;

		foreach ( $subscribers as $subscriber ) {
			$payload = $this->digest_builder->build_payload( $subscriber, $frequency, false );

			if ( empty( $payload['posts'] ) || ! is_array( $payload['posts'] ) ) {
				continue;
			}

			$unsubscribe_token = wp_generate_password( 32, false, false );
			$this->subscriber_repository->update_unsubscribe_token_hash(
				(int) $subscriber['id'],
				$this->hash_token( $unsubscribe_token )
			);

			$unsubscribe_url = add_query_arg(
				array(
					'wstp_action' => 'unsubscribe',
					'wstp_token'  => rawurlencode( $unsubscribe_token ),
				),
				home_url( '/' )
			);

			$context = array(
				'greeting_name'   => $this->resolve_greeting_name( $subscriber ),
				'posts'           => $payload['posts'],
				'unsubscribe_url' => $unsubscribe_url,
			);

			if ( $delay_us > 0 && $attempted > 0 ) {
				usleep( $delay_us );
			}

			$subject = (string) $payload['subject'];
			$sent    = $this->mailer->send_digest( (string) $subscriber['email'], $subject, $context );
			++$attempted;
			$post_ids = array_map(
				static fn( array $post ): int => isset( $post['id'] ) ? (int) $post['id'] : 0,
				$payload['posts']
			);

			if ( $sent ) {
				$sent_at = current_time( 'mysql' );
				$this->subscriber_repository->touch_last_sent_at( (int) $subscriber['id'], $sent_at );
				$this->event_repository->log_send( (int) $subscriber['id'], $frequency, $post_ids, $subject, 'sent', null );
			} else {
				$this->event_repository->log_send( (int) $subscriber['id'], $frequency, $post_ids, $subject, 'failed', __( 'wp_mail returned false.', 'we-subscribe-to-posts' ) );
			}
		}
	}

	/**
	 * Resolve greeting name fallback.
	 *
	 * @param array<string,mixed> $subscriber Subscriber row.
	 * @return string
	 */
	private function resolve_greeting_name( array $subscriber ): string {
		if ( ! empty( $subscriber['name'] ) ) {
			return (string) $subscriber['name'];
		}

		$email = (string) $subscriber['email'];
		if ( str_contains( $email, '@' ) ) {
			$parts = explode( '@', $email );
			return (string) $parts[0];
		}

		return __( 'there', 'we-subscribe-to-posts' );
	}

	/**
	 * Hash token for DB lookup.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Checks configured send hour against current local hour.
	 *
	 * @return bool
	 */
	private function is_configured_send_hour_or_later(): bool {
		$settings        = $this->get_general_settings();
		$configured_hour = (int) $settings['send_hour'];
		$current_hour    = (int) current_time( 'G' );

		return $current_hour >= $configured_hour;
	}

	/**
	 * Check if weekly digest should run today.
	 *
	 * @return bool
	 */
	private function is_weekly_send_day(): bool {
		$settings           = $this->get_general_settings();
		$configured_weekday = (int) $settings['weekly_weekday'];
		$current_weekday    = (int) current_time( 'N' );

		return $configured_weekday === $current_weekday;
	}

	/**
	 * Check if monthly digest should run today.
	 *
	 * @return bool
	 */
	private function is_monthly_send_day(): bool {
		$settings       = $this->get_general_settings();
		$configured_day = (int) $settings['monthly_day'];
		$current_day    = (int) current_time( 'j' );

		return $configured_day === $current_day;
	}

	/**
	 * Read general plugin settings array.
	 *
	 * @return array<string,mixed>
	 */
	private function get_general_settings(): array {
		$stored = get_option( 'wstp_settings', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args(
			$stored,
			array(
				'send_hour'           => 9,
				'weekly_weekday'      => 1,
				'monthly_day'         => 1,
				'throttle_per_minute' => 0,
			)
		);
	}

	/**
	 * Check whether dispatch is due for a frequency in current period.
	 *
	 * @param string $frequency Frequency key.
	 * @return bool
	 */
	private function is_dispatch_due( string $frequency ): bool {
		if ( ! $this->is_configured_send_hour_or_later() ) {
			return false;
		}

		$state      = $this->get_dispatch_state();
		$current_key = $this->get_dispatch_period_key( $frequency );
		if ( '' === $current_key ) {
			return false;
		}

		$last_key = isset( $state[ $frequency ] ) ? (string) $state[ $frequency ] : '';
		return $last_key !== $current_key;
	}

	/**
	 * Mark frequency dispatch done for current period.
	 *
	 * @param string $frequency Frequency key.
	 * @return void
	 */
	private function mark_dispatch_done( string $frequency ): void {
		$period_key = $this->get_dispatch_period_key( $frequency );
		if ( '' === $period_key ) {
			return;
		}

		$state               = $this->get_dispatch_state();
		$state[ $frequency ] = $period_key;
		update_option( 'wstp_scheduler_state', $state, false );
	}

	/**
	 * Read scheduler dispatch state.
	 *
	 * @return array<string,string>
	 */
	private function get_dispatch_state(): array {
		$state = get_option( 'wstp_scheduler_state', array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Build period key for frequency.
	 *
	 * @param string $frequency Frequency key.
	 * @return string
	 */
	private function get_dispatch_period_key( string $frequency ): string {
		$now = current_time( 'timestamp' );
		return match ( $frequency ) {
			'daily'   => wp_date( 'Y-m-d', $now ),
			'weekly'  => wp_date( 'o-W', $now ),
			'monthly' => wp_date( 'Y-m', $now ),
			default   => '',
		};
	}
}
