<?php

/**
 * Admin settings page.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Admin;

use WSTP\Mailer\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders plugin settings.
 */
final class Settings_Page {

	/**
	 * General option group.
	 *
	 * @var string
	 */
	private const GENERAL_OPTION_GROUP = 'wstp_settings_group';
	/**
	 * Mail option group.
	 *
	 * @var string
	 */
	private const MAIL_OPTION_GROUP = 'wstp_mail_settings_group';
	/**
	 * Admin menu slug.
	 *
	 * @var string
	 */
	private const MENU_SLUG = 'wstp-settings';
	/**
	 * Mail submenu slug.
	 *
	 * @var string
	 */
	private const MAIL_MENU_SLUG = 'wstp-mail-settings';
	/**
	 * One-time flag for legacy option cleanup.
	 *
	 * @var string
	 */
	private const LEGACY_PURGE_FLAG = 'wstp_legacy_single_options_purged';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'cleanup_legacy_single_options' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_wstp_send_test_mail', array( $this, 'handle_test_mail_send' ) );
	}

	/**
	 * Add admin page.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Post Subscriptions', 'we-subscribe-to-posts' ),
			__( 'Post Subscriptions', 'we-subscribe-to-posts' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-email-alt2',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'we-subscribe-to-posts' ),
			__( 'Settings', 'we-subscribe-to-posts' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Mail Transport', 'we-subscribe-to-posts' ),
			__( 'Mail Transport', 'we-subscribe-to-posts' ),
			'manage_options',
			self::MAIL_MENU_SLUG,
			array( $this, 'render_mail_page' )
		);
	}

	/**
	 * Register options and sanitizers.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GENERAL_OPTION_GROUP,
			'wstp_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_general_settings_array' ),
				'default'           => array(),
			)
		);

		register_setting(
			self::MAIL_OPTION_GROUP,
			'wstp_mail_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_mail_settings_array' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize privacy notice and keep required.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_privacy_notice( $value ): string {
		$sanitized = sanitize_text_field( (string) $value );
		if ( '' === $sanitized ) {
			add_settings_error(
				'wstp_privacy_notice',
				'wstp_privacy_notice_required',
				__( 'Privacy section text is required.', 'we-subscribe-to-posts' ),
				'error'
			);

			$current = $this->get_general_settings();
			return (string) $current['privacy_notice'];
		}

		return $sanitized;
	}

	/**
	 * Sanitize send hour.
	 *
	 * @param int $value Input value.
	 * @return int
	 */
	public function sanitize_send_hour( $value ): int {
		$value = $this->to_int( $value, 9 );

		if ( $value < 0 ) {
			return 0;
		}

		if ( $value > 23 ) {
			return 23;
		}

		return $value;
	}

	/**
	 * Sanitize weekday (1-7).
	 *
	 * @param int $value Input value.
	 * @return int
	 */
	public function sanitize_weekday( $value ): int {
		$value = $this->to_int( $value, 1 );

		if ( $value < 1 ) {
			return 1;
		}

		if ( $value > 7 ) {
			return 7;
		}

		return $value;
	}

	/**
	 * Sanitize monthly day (1-28).
	 *
	 * @param int $value Input value.
	 * @return int
	 */
	public function sanitize_month_day( $value ): int {
		$value = $this->to_int( $value, 1 );

		if ( $value < 1 ) {
			return 1;
		}

		if ( $value > 28 ) {
			return 28;
		}

		return $value;
	}

	/**
	 * Sanitize pending-cleanup retention days.
	 *
	 * @param int $value Input value.
	 * @return int
	 */
	public function sanitize_pending_cleanup_days( $value ): int {
		$value = $this->to_int( $value, 7 );

		if ( $value < 1 ) {
			return 1;
		}

		if ( $value > 365 ) {
			return 365;
		}

		return $value;
	}

	/**
	 * Sanitize mail throttle rate (emails per minute).
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_throttle_per_minute( $value ): int {
		$value = $this->to_int( $value, 0 );
		if ( $value < 0 ) {
			return 0;
		}
		if ( $value > 600 ) {
			return 600;
		}
		return $value;
	}

	/**
	 * Sanitize max posts for digest frequency.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_digest_max_posts( $value ): int {
		$value = $this->to_int( $value, 0 );
		if ( $value < 0 ) {
			return 0;
		}
		if ( $value > 500 ) {
			return 500;
		}
		return $value;
	}

	/**
	 * Sanitize subscribe rate-limit window in seconds.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_subscribe_rate_limit_window_seconds( $value ): int {
		$value = $this->to_int( $value, 600 );
		if ( $value < 0 ) {
			return 0;
		}
		if ( $value > 3600 ) {
			return 3600;
		}
		return $value;
	}

	/**
	 * Sanitize subscribe rate-limit max attempts.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_subscribe_rate_limit_max_attempts( $value ): int {
		$value = $this->to_int( $value, 6 );
		if ( $value < 0 ) {
			return 0;
		}
		if ( $value > 100 ) {
			return 100;
		}
		return $value;
	}

	/**
	 * Sanitize DOI resend cooldown in minutes.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_doi_resend_cooldown_minutes( $value ): int {
		$value = $this->to_int( $value, 10 );
		if ( $value < 0 ) {
			return 0;
		}
		if ( $value > 1440 ) {
			return 1440;
		}
		return $value;
	}

	/**
	 * Sanitize frontend status-notice style.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_status_notice_style( $value ): string {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'toast', 'overlay', 'inline' );
		return in_array( $value, $allowed, true ) ? $value : 'toast';
	}

	/**
	 * Sanitize frontend status-notice position.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_status_notice_position( $value ): string {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );
		return in_array( $value, $allowed, true ) ? $value : 'bottom-right';
	}

	/**
	 * Sanitize frontend status-notice auto-close seconds.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_status_notice_seconds( $value ): int {
		$value = $this->to_int( $value, 8 );
		if ( $value < 0 ) {
			return 0;
		}
		if ( $value > 60 ) {
			return 60;
		}
		return $value;
	}

	/**
	 * Sanitize admin subscriber notification trigger.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_admin_notification_trigger( $value ): string {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'confirmed_only', 'pending_and_confirmed' );
		return in_array( $value, $allowed, true ) ? $value : 'confirmed_only';
	}

	/**
	 * Sanitize admin subscriber notification mode.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_admin_notification_mode( $value ): string {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'instant', 'daily_summary' );
		return in_array( $value, $allowed, true ) ? $value : 'daily_summary';
	}

	/**
	 * Sanitize admin subscriber notification recipient.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_admin_notification_email( $value ): string {
		$email = sanitize_email( (string) $value );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Sanitize digest mail subject template.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private function sanitize_subject_template( $value, string $fallback ): string {
		$sanitized = sanitize_text_field( (string) $value );
		return '' !== $sanitized ? $sanitized : $fallback;
	}

	/**
	 * Sanitize mail transport option.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_mail_transport( $value ): string {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'wp_default', 'smtp', 'gmail' );

		return in_array( $value, $allowed, true ) ? $value : 'wp_default';
	}

	/**
	 * Sanitize yes/no options.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_yes_no( $value ): string {
		return 'yes' === sanitize_key( (string) $value ) ? 'yes' : 'no';
	}

	/**
	 * Sanitize SMTP port.
	 *
	 * @param int $value Port value.
	 * @return int
	 */
	public function sanitize_smtp_port( $value ): int {
		$value = $this->to_int( $value, 587 );

		if ( $value < 1 ) {
			return 1;
		}
		if ( $value > 65535 ) {
			return 65535;
		}
		return $value;
	}

	/**
	 * Sanitize SMTP encryption value.
	 *
	 * @param string $value Encryption value.
	 * @return string
	 */
	public function sanitize_smtp_encryption( $value ): string {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'none', 'tls', 'ssl' );
		return in_array( $value, $allowed, true ) ? $value : 'tls';
	}

	/**
	 * Sanitize SMTP password.
	 *
	 * @param string $value Password.
	 * @return string
	 */
	public function sanitize_smtp_password( $value ): string {
		// Passwords may contain characters that sanitize_text_field() would alter.
		$password = wp_check_invalid_utf8( (string) $value );
		if ( '' === $password ) {
			$current = $this->get_mail_settings();
			return (string) $current['smtp_password'];
		}

		return $password;
	}

	/**
	 * Sanitize and persist general settings option array.
	 *
	 * @param mixed $value Raw settings value.
	 * @return array<string,mixed>
	 */
	public function sanitize_general_settings_array( $value ): array {
		$input    = is_array( $value ) ? $value : array();
		$defaults = $this->get_general_settings();

		$settings = array(
			'privacy_notice'                         => $this->sanitize_privacy_notice( $input['privacy_notice'] ?? $defaults['privacy_notice'] ),
			'preview_email'                          => sanitize_email( (string) ( $input['preview_email'] ?? $defaults['preview_email'] ) ),
			'subject_daily'                          => $this->sanitize_subject_template( $input['subject_daily'] ?? $defaults['subject_daily'], (string) $defaults['subject_daily'] ),
			'subject_weekly'                         => $this->sanitize_subject_template( $input['subject_weekly'] ?? $defaults['subject_weekly'], (string) $defaults['subject_weekly'] ),
			'subject_monthly'                        => $this->sanitize_subject_template( $input['subject_monthly'] ?? $defaults['subject_monthly'], (string) $defaults['subject_monthly'] ),
			'subject_preview'                        => $this->sanitize_subject_template( $input['subject_preview'] ?? $defaults['subject_preview'], (string) $defaults['subject_preview'] ),
			'send_hour'                              => $this->sanitize_send_hour( $input['send_hour'] ?? $defaults['send_hour'] ),
			'weekly_weekday'                         => $this->sanitize_weekday( $input['weekly_weekday'] ?? $defaults['weekly_weekday'] ),
			'monthly_day'                            => $this->sanitize_month_day( $input['monthly_day'] ?? $defaults['monthly_day'] ),
			'pending_cleanup_days'                   => $this->sanitize_pending_cleanup_days( $input['pending_cleanup_days'] ?? $defaults['pending_cleanup_days'] ),
			'throttle_per_minute'                    => $this->sanitize_throttle_per_minute( $input['throttle_per_minute'] ?? $defaults['throttle_per_minute'] ),
			'max_posts_daily'                        => $this->sanitize_digest_max_posts( $input['max_posts_daily'] ?? $defaults['max_posts_daily'] ),
			'max_posts_weekly'                       => $this->sanitize_digest_max_posts( $input['max_posts_weekly'] ?? $defaults['max_posts_weekly'] ),
			'max_posts_monthly'                      => $this->sanitize_digest_max_posts( $input['max_posts_monthly'] ?? $defaults['max_posts_monthly'] ),
			'subscribe_rate_limit_window_seconds'    => $this->sanitize_subscribe_rate_limit_window_seconds( $input['subscribe_rate_limit_window_seconds'] ?? $defaults['subscribe_rate_limit_window_seconds'] ),
			'subscribe_rate_limit_max_attempts'      => $this->sanitize_subscribe_rate_limit_max_attempts( $input['subscribe_rate_limit_max_attempts'] ?? $defaults['subscribe_rate_limit_max_attempts'] ),
			'doi_resend_cooldown_minutes'            => $this->sanitize_doi_resend_cooldown_minutes( $input['doi_resend_cooldown_minutes'] ?? $defaults['doi_resend_cooldown_minutes'] ),
			'github_updates_enabled'                 => $this->sanitize_yes_no( $input['github_updates_enabled'] ?? $defaults['github_updates_enabled'] ),
			'status_notice_style'                    => $this->sanitize_status_notice_style( $input['status_notice_style'] ?? $defaults['status_notice_style'] ),
			'status_notice_position'                 => $this->sanitize_status_notice_position( $input['status_notice_position'] ?? $defaults['status_notice_position'] ),
			'status_notice_seconds'                  => $this->sanitize_status_notice_seconds( $input['status_notice_seconds'] ?? $defaults['status_notice_seconds'] ),
			'admin_subscriber_notifications_enabled' => $this->sanitize_yes_no( $input['admin_subscriber_notifications_enabled'] ?? $defaults['admin_subscriber_notifications_enabled'] ),
			'admin_subscriber_notifications_trigger' => $this->sanitize_admin_notification_trigger( $input['admin_subscriber_notifications_trigger'] ?? $defaults['admin_subscriber_notifications_trigger'] ),
			'admin_subscriber_notifications_mode'    => $this->sanitize_admin_notification_mode( $input['admin_subscriber_notifications_mode'] ?? $defaults['admin_subscriber_notifications_mode'] ),
			'admin_subscriber_notifications_email'   => $this->sanitize_admin_notification_email( $input['admin_subscriber_notifications_email'] ?? $defaults['admin_subscriber_notifications_email'] ),
			'dashboard_widget_enabled'               => $this->sanitize_yes_no( $input['dashboard_widget_enabled'] ?? $defaults['dashboard_widget_enabled'] ),
		);

		return $settings;
	}

	/**
	 * Sanitize and persist mail settings option array.
	 *
	 * @param mixed $value Raw settings value.
	 * @return array<string,mixed>
	 */
	public function sanitize_mail_settings_array( $value ): array {
		$input    = is_array( $value ) ? $value : array();
		$defaults = $this->get_mail_settings();

		$raw_smtp_password = isset( $input['smtp_password'] ) ? (string) $input['smtp_password'] : '';
		$smtp_password     = '' !== $raw_smtp_password ? $this->sanitize_smtp_password( $raw_smtp_password ) : (string) $defaults['smtp_password'];

		$settings = array(
			'transport'       => $this->sanitize_mail_transport( $input['transport'] ?? $defaults['transport'] ),
			'apply_globally'  => $this->sanitize_yes_no( $input['apply_globally'] ?? $defaults['apply_globally'] ),
			'from_email'      => sanitize_email( (string) ( $input['from_email'] ?? $defaults['from_email'] ) ),
			'from_name'       => sanitize_text_field( (string) ( $input['from_name'] ?? $defaults['from_name'] ) ),
			'smtp_host'       => sanitize_text_field( (string) ( $input['smtp_host'] ?? $defaults['smtp_host'] ) ),
			'smtp_port'       => $this->sanitize_smtp_port( $input['smtp_port'] ?? $defaults['smtp_port'] ),
			'smtp_encryption' => $this->sanitize_smtp_encryption( $input['smtp_encryption'] ?? $defaults['smtp_encryption'] ),
			'smtp_auth'       => $this->sanitize_yes_no( $input['smtp_auth'] ?? $defaults['smtp_auth'] ),
			'smtp_username'   => sanitize_text_field( (string) ( $input['smtp_username'] ?? $defaults['smtp_username'] ) ),
			'smtp_password'   => $smtp_password,
		);

		return $settings;
	}

	/**
	 * Convert scalar input to int with fallback.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Fallback when value is invalid.
	 * @return int
	 */
	private function to_int( $value, int $fallback ): int {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		return $fallback;
	}

	/**
	 * Get normalized general settings array with legacy fallback.
	 *
	 * @return array<string,mixed>
	 */
	private function get_general_settings(): array {
		$defaults = array(
			'privacy_notice'                         => __( 'I agree that my email address is used only for post update notifications.', 'we-subscribe-to-posts' ),
			'preview_email'                          => (string) get_option( 'admin_email' ),
			'subject_daily'                          => __( 'Your latest post updates - {site_name}', 'we-subscribe-to-posts' ),
			'subject_weekly'                         => __( 'Your weekly post updates - {site_name}', 'we-subscribe-to-posts' ),
			'subject_monthly'                        => __( 'Your monthly post updates - {site_name}', 'we-subscribe-to-posts' ),
			'subject_preview'                        => __( '[Preview] Latest post updates - {site_name}', 'we-subscribe-to-posts' ),
			'send_hour'                              => 9,
			'weekly_weekday'                         => 1,
			'monthly_day'                            => 1,
			'pending_cleanup_days'                   => 7,
			'throttle_per_minute'                    => 0,
			'max_posts_daily'                        => 0,
			'max_posts_weekly'                       => 0,
			'max_posts_monthly'                      => 0,
			'subscribe_rate_limit_window_seconds'    => 600,
			'subscribe_rate_limit_max_attempts'      => 6,
			'doi_resend_cooldown_minutes'            => 10,
			'github_updates_enabled'                 => 'no',
			'status_notice_style'                    => 'toast',
			'status_notice_position'                 => 'bottom-right',
			'status_notice_seconds'                  => 8,
			'admin_subscriber_notifications_enabled' => 'no',
			'admin_subscriber_notifications_trigger' => 'confirmed_only',
			'admin_subscriber_notifications_mode'    => 'daily_summary',
			'admin_subscriber_notifications_email'   => (string) get_option( 'admin_email' ),
			'dashboard_widget_enabled'               => 'no',
		);

		$stored = get_option( 'wstp_settings', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, $defaults );
	}

	/**
	 * Get normalized mail settings array with legacy fallback.
	 *
	 * @return array<string,mixed>
	 */
	private function get_mail_settings(): array {
		$defaults = array(
			'transport'       => 'wp_default',
			'apply_globally'  => 'no',
			'from_email'      => '',
			'from_name'       => '',
			'smtp_host'       => '',
			'smtp_port'       => 587,
			'smtp_encryption' => 'tls',
			'smtp_auth'       => 'yes',
			'smtp_username'   => '',
			'smtp_password'   => '',
		);

		$stored = get_option( 'wstp_mail_settings', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, $defaults );
	}

	/**
	 * Delete old single options once after array migration.
	 *
	 * @return void
	 */
	public function cleanup_legacy_single_options(): void {
		if ( get_option( self::LEGACY_PURGE_FLAG, 'no' ) === 'yes' ) {
			return;
		}

		$legacy_option_names = array(
			'wstp_privacy_notice',
			'wstp_preview_email',
			'wstp_send_hour',
			'wstp_weekly_weekday',
			'wstp_monthly_day',
			'wstp_pending_cleanup_days',
			'wstp_mail_transport',
			'wstp_mail_apply_globally',
			'wstp_mail_from_email',
			'wstp_mail_from_name',
			'wstp_smtp_host',
			'wstp_smtp_port',
			'wstp_smtp_encryption',
			'wstp_smtp_auth',
			'wstp_smtp_username',
			'wstp_smtp_password',
		);

		foreach ( $legacy_option_names as $option_name ) {
			delete_option( $option_name );
			delete_site_option( $option_name );
		}

		update_option( self::LEGACY_PURGE_FLAG, 'yes' );
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$general_settings                       = $this->get_general_settings();
		$privacy_notice                         = (string) $general_settings['privacy_notice'];
		$preview_email                          = (string) $general_settings['preview_email'];
		$subject_daily                          = (string) $general_settings['subject_daily'];
		$subject_weekly                         = (string) $general_settings['subject_weekly'];
		$subject_monthly                        = (string) $general_settings['subject_monthly'];
		$subject_preview                        = (string) $general_settings['subject_preview'];
		$send_hour                              = (int) $general_settings['send_hour'];
		$weekly_weekday                         = (int) $general_settings['weekly_weekday'];
		$monthly_day                            = (int) $general_settings['monthly_day'];
		$cleanup_days                           = (int) $general_settings['pending_cleanup_days'];
		$throttle_per_minute                    = (int) $general_settings['throttle_per_minute'];
		$max_posts_daily                        = (int) $general_settings['max_posts_daily'];
		$max_posts_weekly                       = (int) $general_settings['max_posts_weekly'];
		$max_posts_monthly                      = (int) $general_settings['max_posts_monthly'];
		$subscribe_rate_limit_window_seconds    = (int) $general_settings['subscribe_rate_limit_window_seconds'];
		$subscribe_rate_limit_max_attempts      = (int) $general_settings['subscribe_rate_limit_max_attempts'];
		$doi_resend_cooldown_minutes            = (int) $general_settings['doi_resend_cooldown_minutes'];
		$github_updates_enabled                 = (string) $general_settings['github_updates_enabled'];
		$status_notice_style                    = (string) $general_settings['status_notice_style'];
		$status_notice_position                 = (string) $general_settings['status_notice_position'];
		$status_notice_seconds                  = (int) $general_settings['status_notice_seconds'];
		$admin_subscriber_notifications_enabled = (string) $general_settings['admin_subscriber_notifications_enabled'];
		$admin_subscriber_notifications_trigger = (string) $general_settings['admin_subscriber_notifications_trigger'];
		$admin_subscriber_notifications_mode    = (string) $general_settings['admin_subscriber_notifications_mode'];
		$admin_subscriber_notifications_email   = (string) $general_settings['admin_subscriber_notifications_email'];
		$dashboard_widget_enabled               = (string) $general_settings['dashboard_widget_enabled'];
		$weekday_labels                         = array(
			1 => __( 'Monday', 'we-subscribe-to-posts' ),
			2 => __( 'Tuesday', 'we-subscribe-to-posts' ),
			3 => __( 'Wednesday', 'we-subscribe-to-posts' ),
			4 => __( 'Thursday', 'we-subscribe-to-posts' ),
			5 => __( 'Friday', 'we-subscribe-to-posts' ),
			6 => __( 'Saturday', 'we-subscribe-to-posts' ),
			7 => __( 'Sunday', 'we-subscribe-to-posts' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only GET notice flag.
		$notice     = isset( $_GET['wstp_admin_notice'] ) ? sanitize_key( wp_unslash( $_GET['wstp_admin_notice'] ) ) : '';
		$notice_map = array(
			'preview_sent'          => array( 'updated', __( 'Preview email sent successfully.', 'we-subscribe-to-posts' ) ),
			'preview_failed'        => array( 'error', __( 'Preview email could not be sent.', 'we-subscribe-to-posts' ) ),
			'preview_invalid_email' => array( 'error', __( 'Preview recipient email is invalid.', 'we-subscribe-to-posts' ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Post Subscription Settings', 'we-subscribe-to-posts' ); ?></h1>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="<?php echo esc_attr( 'notice notice-' . $notice_map[ $notice ][0] ); ?>">
					<p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<?php settings_errors(); ?>

			<form method="post" action="options.php" autocomplete="off" data-lpignore="true" data-1p-ignore="true">
				<?php settings_fields( self::GENERAL_OPTION_GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wstp_privacy_notice"><?php esc_html_e( 'Privacy section text', 'we-subscribe-to-posts' ); ?></label></th>
						<td>
							<input id="wstp_privacy_notice" name="wstp_settings[privacy_notice]" type="text" class="regular-text" required value="<?php echo esc_attr( $privacy_notice ); ?>" />
							<p class="description"><?php esc_html_e( 'Shown next to the required consent checkbox in the signup form.', 'we-subscribe-to-posts' ); ?></p>
						</td>
						</tr>
						<tr>
							<th scope="row"><label for="wstp_preview_email"><?php esc_html_e( 'Preview recipient email', 'we-subscribe-to-posts' ); ?></label></th>
							<td>
								<input id="wstp_preview_email" name="wstp_settings[preview_email]" type="email" class="regular-text" value="<?php echo esc_attr( $preview_email ); ?>" />
								<p class="description"><?php esc_html_e( 'Preview digests are sent to this address (Send preview now on Digest Email Template).', 'we-subscribe-to-posts' ); ?></p>
							</td>
							</tr>
							<tr>
								<th scope="row"><label for="wstp_subject_daily"><?php esc_html_e( 'Daily digest subject', 'we-subscribe-to-posts' ); ?></label></th>
								<td>
									<input id="wstp_subject_daily" name="wstp_settings[subject_daily]" type="text" class="regular-text" value="<?php echo esc_attr( $subject_daily ); ?>" />
									<p class="description"><?php esc_html_e( 'Used for daily digest emails. Placeholder: {site_name}', 'we-subscribe-to-posts' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wstp_subject_weekly"><?php esc_html_e( 'Weekly digest subject', 'we-subscribe-to-posts' ); ?></label></th>
								<td>
									<input id="wstp_subject_weekly" name="wstp_settings[subject_weekly]" type="text" class="regular-text" value="<?php echo esc_attr( $subject_weekly ); ?>" />
									<p class="description"><?php esc_html_e( 'Used for weekly digest emails. Placeholder: {site_name}', 'we-subscribe-to-posts' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wstp_subject_monthly"><?php esc_html_e( 'Monthly digest subject', 'we-subscribe-to-posts' ); ?></label></th>
								<td>
									<input id="wstp_subject_monthly" name="wstp_settings[subject_monthly]" type="text" class="regular-text" value="<?php echo esc_attr( $subject_monthly ); ?>" />
									<p class="description"><?php esc_html_e( 'Used for monthly digest emails. Placeholder: {site_name}', 'we-subscribe-to-posts' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wstp_subject_preview"><?php esc_html_e( 'Preview email subject', 'we-subscribe-to-posts' ); ?></label></th>
								<td>
									<input id="wstp_subject_preview" name="wstp_settings[subject_preview]" type="text" class="regular-text" value="<?php echo esc_attr( $subject_preview ); ?>" />
									<p class="description"><?php esc_html_e( 'Used for admin preview emails. Placeholder: {site_name}', 'we-subscribe-to-posts' ); ?></p>
								</td>
							</tr>
				</table>

				<h2><?php esc_html_e( 'Digest delivery', 'we-subscribe-to-posts' ); ?></h2>
				<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="wstp_send_hour"><?php esc_html_e( 'Daily send hour (site time)', 'we-subscribe-to-posts' ); ?></label></th>
									<td>
										<input id="wstp_send_hour" name="wstp_settings[send_hour]" type="number" min="0" max="23" value="<?php echo esc_attr( (string) $send_hour ); ?>" />
										<p class="description"><?php esc_html_e( 'Applies to daily sends and the dispatch time for weekly/monthly runs.', 'we-subscribe-to-posts' ); ?></p>
									</td>
									</tr>
									<tr>
										<th scope="row"><label for="wstp_weekly_weekday"><?php esc_html_e( 'Weekly send weekday', 'we-subscribe-to-posts' ); ?></label></th>
										<td>
											<select id="wstp_weekly_weekday" name="wstp_settings[weekly_weekday]">
												<?php for ( $weekday = 1; $weekday <= 7; $weekday++ ) : ?>
													<option value="<?php echo esc_attr( (string) $weekday ); ?>" <?php selected( $weekly_weekday, $weekday ); ?>>
														<?php echo esc_html( isset( $weekday_labels[ $weekday ] ) ? (string) $weekday_labels[ $weekday ] : '' ); ?>
													</option>
												<?php endfor; ?>
											</select>
										</td>
										</tr>
										<tr>
											<th scope="row"><label for="wstp_monthly_day"><?php esc_html_e( 'Monthly send day', 'we-subscribe-to-posts' ); ?></label></th>
											<td>
												<input id="wstp_monthly_day" name="wstp_settings[monthly_day]" type="number" min="1" max="28" value="<?php echo esc_attr( (string) $monthly_day ); ?>" />
												<p class="description"><?php esc_html_e( 'Use 1-28 to avoid invalid dates in shorter months.', 'we-subscribe-to-posts' ); ?></p>
											</td>
											</tr>
											<tr>
												<th scope="row"><label for="wstp_pending_cleanup_days"><?php esc_html_e( 'Delete unconfirmed after days', 'we-subscribe-to-posts' ); ?></label></th>
												<td>
													<input id="wstp_pending_cleanup_days" name="wstp_settings[pending_cleanup_days]" type="number" min="1" max="365" value="<?php echo esc_attr( (string) $cleanup_days ); ?>" />
													<p class="description"><?php esc_html_e( 'Pending (double opt-in not confirmed) subscribers are removed by daily cleanup after this many days.', 'we-subscribe-to-posts' ); ?></p>
												</td>
												</tr>
												<tr>
													<th scope="row"><label for="wstp_throttle_per_minute"><?php esc_html_e( 'Max digest emails per minute', 'we-subscribe-to-posts' ); ?></label></th>
													<td>
														<input id="wstp_throttle_per_minute" name="wstp_settings[throttle_per_minute]" type="number" min="0" max="600" value="<?php echo esc_attr( (string) $throttle_per_minute ); ?>" />
														<p class="description"><?php esc_html_e( 'Recommended: keep throttling enabled to reduce hoster spam/rate-limit risk on larger sends. Use 0 only if you explicitly want no throttling.', 'we-subscribe-to-posts' ); ?></p>
													</td>
													</tr>
													<tr>
														<th scope="row"><label for="wstp_max_posts_daily"><?php esc_html_e( 'Max posts per daily digest', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<input id="wstp_max_posts_daily" name="wstp_settings[max_posts_daily]" type="number" min="0" max="500" value="<?php echo esc_attr( (string) $max_posts_daily ); ?>" />
															<p class="description"><?php esc_html_e( 'Set to 0 to include all matching posts.', 'we-subscribe-to-posts' ); ?></p>
														</td>
													</tr>
													<tr>
														<th scope="row"><label for="wstp_max_posts_weekly"><?php esc_html_e( 'Max posts per weekly digest', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<input id="wstp_max_posts_weekly" name="wstp_settings[max_posts_weekly]" type="number" min="0" max="500" value="<?php echo esc_attr( (string) $max_posts_weekly ); ?>" />
															<p class="description"><?php esc_html_e( 'Set to 0 to include all matching posts.', 'we-subscribe-to-posts' ); ?></p>
														</td>
													</tr>
													<tr>
														<th scope="row"><label for="wstp_max_posts_monthly"><?php esc_html_e( 'Max posts per monthly digest', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<input id="wstp_max_posts_monthly" name="wstp_settings[max_posts_monthly]" type="number" min="0" max="500" value="<?php echo esc_attr( (string) $max_posts_monthly ); ?>" />
															<p class="description"><?php esc_html_e( 'Set to 0 to include all matching posts.', 'we-subscribe-to-posts' ); ?></p>
														</td>
													</tr>
													<tr>
														<th scope="row"><label for="wstp_subscribe_rate_limit_window_seconds"><?php esc_html_e( 'Subscribe anti-spam window (seconds)', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<input id="wstp_subscribe_rate_limit_window_seconds" name="wstp_settings[subscribe_rate_limit_window_seconds]" type="number" min="0" max="3600" value="<?php echo esc_attr( (string) $subscribe_rate_limit_window_seconds ); ?>" />
															<p class="description"><?php esc_html_e( 'How long the anti-spam window lasts for signup attempts. Set to 0 to disable subscribe rate limiting.', 'we-subscribe-to-posts' ); ?></p>
														</td>
													</tr>
													<tr class="wstp-subscribe-rate-limit-dependent"<?php echo $subscribe_rate_limit_window_seconds <= 0 ? ' style="display:none;"' : ''; ?>>
														<th scope="row"><label for="wstp_subscribe_rate_limit_max_attempts"><?php esc_html_e( 'Subscribe attempts allowed per window', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<input id="wstp_subscribe_rate_limit_max_attempts" name="wstp_settings[subscribe_rate_limit_max_attempts]" type="number" min="0" max="100" value="<?php echo esc_attr( (string) $subscribe_rate_limit_max_attempts ); ?>" />
															<p class="description"><?php esc_html_e( 'Maximum subscribe attempts per email/IP during one anti-spam window.', 'we-subscribe-to-posts' ); ?></p>
														</td>
													</tr>
													<tr>
														<th scope="row"><label for="wstp_doi_resend_cooldown_minutes"><?php esc_html_e( 'Double opt-in resend cooldown (minutes)', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<input id="wstp_doi_resend_cooldown_minutes" name="wstp_settings[doi_resend_cooldown_minutes]" type="number" min="0" max="1440" value="<?php echo esc_attr( (string) $doi_resend_cooldown_minutes ); ?>" />
															<p class="description"><?php esc_html_e( 'Prevents repeated confirmation mails for pending subscribers. Set to 0 to allow immediate resend.', 'we-subscribe-to-posts' ); ?></p>
														</td>
													</tr>
													<tr>
														<th scope="row"><label for="wstp_admin_subscriber_notifications_enabled"><?php esc_html_e( 'Admin notifications for new subscribers', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<select id="wstp_admin_subscriber_notifications_enabled" name="wstp_settings[admin_subscriber_notifications_enabled]">
																<option value="no" <?php selected( $admin_subscriber_notifications_enabled, 'no' ); ?>><?php esc_html_e( 'Disabled', 'we-subscribe-to-posts' ); ?></option>
																<option value="yes" <?php selected( $admin_subscriber_notifications_enabled, 'yes' ); ?>><?php esc_html_e( 'Enabled', 'we-subscribe-to-posts' ); ?></option>
															</select>
															<p class="description"><?php esc_html_e( 'Optional notifications when new subscribers register or confirm.', 'we-subscribe-to-posts' ); ?></p>
														</td>
													</tr>
													<tr class="wstp-admin-notify-dependent" <?php echo 'yes' !== $admin_subscriber_notifications_enabled ? ' style="display:none;"' : ''; ?>>
														<th scope="row"><label for="wstp_admin_subscriber_notifications_trigger"><?php esc_html_e( 'Notification trigger', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<select id="wstp_admin_subscriber_notifications_trigger" name="wstp_settings[admin_subscriber_notifications_trigger]">
																<option value="confirmed_only" <?php selected( $admin_subscriber_notifications_trigger, 'confirmed_only' ); ?>><?php esc_html_e( 'Only after double opt-in confirmation', 'we-subscribe-to-posts' ); ?></option>
																<option value="pending_and_confirmed" <?php selected( $admin_subscriber_notifications_trigger, 'pending_and_confirmed' ); ?>><?php esc_html_e( 'Both: pending signup and confirmed subscriber', 'we-subscribe-to-posts' ); ?></option>
															</select>
														</td>
													</tr>
													<tr class="wstp-admin-notify-dependent" <?php echo 'yes' !== $admin_subscriber_notifications_enabled ? ' style="display:none;"' : ''; ?>>
														<th scope="row"><label for="wstp_admin_subscriber_notifications_mode"><?php esc_html_e( 'Notification mode', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<select id="wstp_admin_subscriber_notifications_mode" name="wstp_settings[admin_subscriber_notifications_mode]">
																<option value="instant" <?php selected( $admin_subscriber_notifications_mode, 'instant' ); ?>><?php esc_html_e( 'Instant (one email per event)', 'we-subscribe-to-posts' ); ?></option>
																<option value="daily_summary" <?php selected( $admin_subscriber_notifications_mode, 'daily_summary' ); ?>><?php esc_html_e( 'Daily summary', 'we-subscribe-to-posts' ); ?></option>
															</select>
														</td>
													</tr>
													<tr class="wstp-admin-notify-dependent" <?php echo 'yes' !== $admin_subscriber_notifications_enabled ? ' style="display:none;"' : ''; ?>>
														<th scope="row"><label for="wstp_admin_subscriber_notifications_email"><?php esc_html_e( 'Notification recipient email', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<input id="wstp_admin_subscriber_notifications_email" name="wstp_settings[admin_subscriber_notifications_email]" type="email" class="regular-text" value="<?php echo esc_attr( $admin_subscriber_notifications_email ); ?>" />
															<p class="description"><?php esc_html_e( 'Default is the WordPress admin email.', 'we-subscribe-to-posts' ); ?></p>
														</td>
													</tr>
													<tr>
														<th scope="row"><label for="wstp_dashboard_widget_enabled"><?php esc_html_e( 'Dashboard widget', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<select id="wstp_dashboard_widget_enabled" name="wstp_settings[dashboard_widget_enabled]">
																<option value="no" <?php selected( $dashboard_widget_enabled, 'no' ); ?>><?php esc_html_e( 'Disabled', 'we-subscribe-to-posts' ); ?></option>
																<option value="yes" <?php selected( $dashboard_widget_enabled, 'yes' ); ?>><?php esc_html_e( 'Enabled', 'we-subscribe-to-posts' ); ?></option>
															</select>
															<p class="description"><?php esc_html_e( 'Shows an overview widget on the WordPress Dashboard with subscriber and mail stats.', 'we-subscribe-to-posts' ); ?></p>
														</td>
													</tr>
													<tr>
														<th scope="row"><label for="wstp_github_updates_enabled"><?php esc_html_e( 'GitHub auto updates', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<select id="wstp_github_updates_enabled" name="wstp_settings[github_updates_enabled]">
																<option value="no" <?php selected( $github_updates_enabled, 'no' ); ?>><?php esc_html_e( 'Disabled', 'we-subscribe-to-posts' ); ?></option>
																<option value="yes" <?php selected( $github_updates_enabled, 'yes' ); ?>><?php esc_html_e( 'Enabled', 'we-subscribe-to-posts' ); ?></option>
															</select>
															<p class="description"><?php esc_html_e( 'When enabled, WordPress checks GitHub releases for plugin updates.', 'we-subscribe-to-posts' ); ?></p>
														</td>
													</tr>
													<tr>
															<th scope="row"><label for="wstp_status_notice_style"><?php esc_html_e( 'Success message display', 'we-subscribe-to-posts' ); ?></label></th>
														<td>
															<select id="wstp_status_notice_style" name="wstp_settings[status_notice_style]">
																<option value="toast" <?php selected( $status_notice_style, 'toast' ); ?>><?php esc_html_e( 'Slide-in notification', 'we-subscribe-to-posts' ); ?></option>
																<option value="overlay" <?php selected( $status_notice_style, 'overlay' ); ?>><?php esc_html_e( 'Centered overlay notification', 'we-subscribe-to-posts' ); ?></option>
																<option value="inline" <?php selected( $status_notice_style, 'inline' ); ?>><?php esc_html_e( 'Inline message above the form', 'we-subscribe-to-posts' ); ?></option>
															</select>
															<p class="description"><?php esc_html_e( 'Choose how users see confirmation after signup.', 'we-subscribe-to-posts' ); ?></p>
														</td>
														</tr>
														<tr class="wstp-status-toast-only" <?php echo 'toast' !== $status_notice_style ? ' style="display:none;"' : ''; ?>>
															<th scope="row"><label for="wstp_status_notice_position"><?php esc_html_e( 'Slide-in position', 'we-subscribe-to-posts' ); ?></label></th>
															<td>
																<select id="wstp_status_notice_position" name="wstp_settings[status_notice_position]">
																	<option value="bottom-right" <?php selected( $status_notice_position, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'we-subscribe-to-posts' ); ?></option>
																	<option value="bottom-left" <?php selected( $status_notice_position, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'we-subscribe-to-posts' ); ?></option>
																	<option value="top-right" <?php selected( $status_notice_position, 'top-right' ); ?>><?php esc_html_e( 'Top right', 'we-subscribe-to-posts' ); ?></option>
																	<option value="top-left" <?php selected( $status_notice_position, 'top-left' ); ?>><?php esc_html_e( 'Top left', 'we-subscribe-to-posts' ); ?></option>
																</select>
																<p class="description"><?php esc_html_e( 'Used for slide-in notification style.', 'we-subscribe-to-posts' ); ?></p>
															</td>
														</tr>
														<tr class="wstp-status-toast-only" <?php echo 'toast' !== $status_notice_style ? ' style="display:none;"' : ''; ?>>
															<th scope="row"><label for="wstp_status_notice_seconds"><?php esc_html_e( 'Auto-close after seconds', 'we-subscribe-to-posts' ); ?></label></th>
															<td>
																<input id="wstp_status_notice_seconds" name="wstp_settings[status_notice_seconds]" type="number" min="0" max="60" value="<?php echo esc_attr( (string) $status_notice_seconds ); ?>" />
																<p class="description"><?php esc_html_e( 'Set to 0 to disable auto-close.', 'we-subscribe-to-posts' ); ?></p>
															</td>
														</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'we-subscribe-to-posts' ) ); ?>
			</form>
			<script>
				(function() {
					var styleSelect = document.getElementById('wstp_status_notice_style');
					if (!styleSelect) {
						return;
					}

					var toastRows = document.querySelectorAll('.wstp-status-toast-only');
					var rateLimitWindowInput = document.getElementById('wstp_subscribe_rate_limit_window_seconds');
					var rateLimitDependentRows = document.querySelectorAll('.wstp-subscribe-rate-limit-dependent');
					var adminNotifyEnabledSelect = document.getElementById('wstp_admin_subscriber_notifications_enabled');
					var adminNotifyRows = document.querySelectorAll('.wstp-admin-notify-dependent');
					var updateStatusUi = function() {
						var showToastRows = (styleSelect.value || 'toast') === 'toast';
						toastRows.forEach(function(row) {
							row.style.display = showToastRows ? '' : 'none';
						});
					};
					var updateRateLimitUi = function() {
						if (!rateLimitWindowInput) {
							return;
						}
						var windowSeconds = parseInt(rateLimitWindowInput.value || '0', 10);
						var showDependents = windowSeconds > 0;
						rateLimitDependentRows.forEach(function(row) {
							row.style.display = showDependents ? '' : 'none';
						});
					};
					var updateAdminNotifyUi = function() {
						if (!adminNotifyEnabledSelect) {
							return;
						}
						var showRows = (adminNotifyEnabledSelect.value || 'no') === 'yes';
						adminNotifyRows.forEach(function(row) {
							row.style.display = showRows ? '' : 'none';
						});
					};

					styleSelect.addEventListener('change', updateStatusUi);
					if (rateLimitWindowInput) {
						rateLimitWindowInput.addEventListener('change', updateRateLimitUi);
						rateLimitWindowInput.addEventListener('input', updateRateLimitUi);
					}
					if (adminNotifyEnabledSelect) {
						adminNotifyEnabledSelect.addEventListener('change', updateAdminNotifyUi);
					}
					updateStatusUi();
					updateRateLimitUi();
					updateAdminNotifyUi();
				})();
			</script>

			<p>
				<?php
				$template_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wstp-email-template' ) ) . '">' . esc_html__( 'Post Subscriptions > Digest Email Template', 'we-subscribe-to-posts' ) . '</a>';
				echo wp_kses_post(
					sprintf(
						/* translators: %s: admin URL. */
						esc_html__( 'Edit your MJML digest email template under %s.', 'we-subscribe-to-posts' ),
						$template_link
					)
				);
				?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wstp-email-template' ) ); ?>">
					<?php esc_html_e( 'Edit email template', 'we-subscribe-to-posts' ); ?>
				</a>
			</p>

					<?php
					$cron_url = add_query_arg( 'doing_wp_cron', '1', home_url( '/wp-cron.php' ) );
					?>
					<hr />
					<h2><?php esc_html_e( 'Cron reliability (recommended)', 'we-subscribe-to-posts' ); ?></h2>
					<p><?php esc_html_e( 'For reliable digest delivery, set up a real server cron job that triggers WordPress cron regularly.', 'we-subscribe-to-posts' ); ?></p>
					<p><strong><?php esc_html_e( 'HTTP endpoint:', 'we-subscribe-to-posts' ); ?></strong> <code><?php echo esc_html( $cron_url ); ?></code></p>
					<p><strong><?php esc_html_e( 'Example interval:', 'we-subscribe-to-posts' ); ?></strong> <?php esc_html_e( 'every 5 minutes', 'we-subscribe-to-posts' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render dedicated mail transport page.
	 *
	 * @return void
	 */
	public function render_mail_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$general_settings = $this->get_general_settings();
		$mail_settings    = $this->get_mail_settings();
		$preview_email    = (string) $general_settings['preview_email'];
		$mail_transport   = (string) $mail_settings['transport'];
		$mail_global      = (string) $mail_settings['apply_globally'];
		$mail_from_email  = (string) $mail_settings['from_email'];
		$mail_from_name   = (string) $mail_settings['from_name'];
		$smtp_host        = (string) $mail_settings['smtp_host'];
		$smtp_port        = (int) $mail_settings['smtp_port'];
		$smtp_encryption  = (string) $mail_settings['smtp_encryption'];
		$smtp_auth        = (string) $mail_settings['smtp_auth'];
		$smtp_username    = (string) $mail_settings['smtp_username'];
		$smtp_password    = (string) $mail_settings['smtp_password'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only GET notice flag.
		$notice     = isset( $_GET['wstp_admin_notice'] ) ? sanitize_key( wp_unslash( $_GET['wstp_admin_notice'] ) ) : '';
		$notice_map = array(
			'mail_test_sent'    => array( 'updated', __( 'Test transport email sent successfully.', 'we-subscribe-to-posts' ) ),
			'mail_test_failed'  => array( 'error', __( 'Test transport email could not be sent.', 'we-subscribe-to-posts' ) ),
			'mail_test_invalid' => array( 'error', __( 'Please provide a valid test recipient email.', 'we-subscribe-to-posts' ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mail Transport Settings', 'we-subscribe-to-posts' ); ?></h1>

			<?php if ( isset( $notice_map[ $notice ] ) ) : ?>
				<div class="<?php echo esc_attr( 'notice notice-' . $notice_map[ $notice ][0] ); ?>">
					<p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
				</div>
			<?php endif; ?>

			<?php settings_errors(); ?>

			<form method="post" action="options.php" autocomplete="off" data-lpignore="true" data-1p-ignore="true">
				<?php settings_fields( self::MAIL_OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wstp_mail_transport"><?php esc_html_e( 'Mail transport', 'we-subscribe-to-posts' ); ?></label></th>
						<td>
							<select id="wstp_mail_transport" name="wstp_mail_settings[transport]">
								<option value="wp_default" <?php selected( $mail_transport, 'wp_default' ); ?>><?php esc_html_e( 'WordPress default (wp_mail / host)', 'we-subscribe-to-posts' ); ?></option>
								<option value="smtp" <?php selected( $mail_transport, 'smtp' ); ?>><?php esc_html_e( 'Custom SMTP', 'we-subscribe-to-posts' ); ?></option>
								<option value="gmail" <?php selected( $mail_transport, 'gmail' ); ?>><?php esc_html_e( 'Gmail (SMTP preset)', 'we-subscribe-to-posts' ); ?></option>
							</select>
						</td>
					</tr>
					<tr class="wstp-mail-non-default">
						<th scope="row"><label for="wstp_mail_apply_globally"><?php esc_html_e( 'Use for all WordPress emails', 'we-subscribe-to-posts' ); ?></label></th>
						<td>
							<input type="hidden" name="wstp_mail_settings[apply_globally]" value="no" />
							<label>
								<input id="wstp_mail_apply_globally" type="checkbox" name="wstp_mail_settings[apply_globally]" value="yes" <?php checked( $mail_global, 'yes' ); ?> />
								<?php esc_html_e( 'Apply this transport globally (not only this plugin).', 'we-subscribe-to-posts' ); ?>
							</label>
						</td>
					</tr>
					<tr class="wstp-mail-non-default">
						<th scope="row"><label for="wstp_mail_from_email"><?php esc_html_e( 'From email (optional)', 'we-subscribe-to-posts' ); ?></label></th>
						<td>
							<input id="wstp_mail_from_email" name="wstp_mail_settings[from_email]" type="email" class="regular-text" value="<?php echo esc_attr( $mail_from_email ); ?>" />
						</td>
					</tr>
					<tr class="wstp-mail-non-default">
						<th scope="row"><label for="wstp_mail_from_name"><?php esc_html_e( 'From name (optional)', 'we-subscribe-to-posts' ); ?></label></th>
						<td>
							<input id="wstp_mail_from_name" name="wstp_mail_settings[from_name]" type="text" class="regular-text" value="<?php echo esc_attr( $mail_from_name ); ?>" />
						</td>
					</tr>
					<tr class="wstp-mail-smtp-only">
						<th scope="row"><label for="wstp_smtp_host"><?php esc_html_e( 'SMTP host', 'we-subscribe-to-posts' ); ?></label></th>
						<td>
							<input id="wstp_smtp_host" name="wstp_mail_settings[smtp_host]" type="text" class="regular-text" value="<?php echo esc_attr( $smtp_host ); ?>" />
						</td>
					</tr>
					<tr class="wstp-mail-smtp-only">
						<th scope="row"><label for="wstp_smtp_port"><?php esc_html_e( 'SMTP port', 'we-subscribe-to-posts' ); ?></label></th>
						<td>
							<input id="wstp_smtp_port" name="wstp_mail_settings[smtp_port]" type="number" min="1" max="65535" value="<?php echo esc_attr( (string) $smtp_port ); ?>" />
						</td>
					</tr>
					<tr class="wstp-mail-smtp-only">
						<th scope="row"><label for="wstp_smtp_encryption"><?php esc_html_e( 'SMTP encryption', 'we-subscribe-to-posts' ); ?></label></th>
						<td>
							<select id="wstp_smtp_encryption" name="wstp_mail_settings[smtp_encryption]">
								<option value="none" <?php selected( $smtp_encryption, 'none' ); ?>><?php esc_html_e( 'None', 'we-subscribe-to-posts' ); ?></option>
								<option value="tls" <?php selected( $smtp_encryption, 'tls' ); ?>>TLS</option>
								<option value="ssl" <?php selected( $smtp_encryption, 'ssl' ); ?>>SSL</option>
							</select>
						</td>
					</tr>
					<tr class="wstp-mail-smtp-only">
						<th scope="row"><label for="wstp_smtp_auth"><?php esc_html_e( 'SMTP authentication', 'we-subscribe-to-posts' ); ?></label></th>
						<td>
							<select id="wstp_smtp_auth" name="wstp_mail_settings[smtp_auth]">
								<option value="yes" <?php selected( $smtp_auth, 'yes' ); ?>><?php esc_html_e( 'Yes', 'we-subscribe-to-posts' ); ?></option>
								<option value="no" <?php selected( $smtp_auth, 'no' ); ?>><?php esc_html_e( 'No', 'we-subscribe-to-posts' ); ?></option>
							</select>
						</td>
					</tr>
					<tr class="wstp-mail-smtp-only">
						<th scope="row"><label for="wstp_smtp_username"><?php esc_html_e( 'SMTP username', 'we-subscribe-to-posts' ); ?></label></th>
						<td>
							<input id="wstp_smtp_username" name="wstp_mail_settings[smtp_username]" type="text" class="regular-text" value="<?php echo esc_attr( $smtp_username ); ?>" autocomplete="off" data-lpignore="true" data-1p-ignore="true" spellcheck="false" />
						</td>
					</tr>
					<tr class="wstp-mail-smtp-only">
						<th scope="row"><label for="wstp_smtp_password"><?php esc_html_e( 'SMTP password/app password', 'we-subscribe-to-posts' ); ?></label></th>
						<td>
							<input id="wstp_smtp_password" name="wstp_mail_settings[smtp_password]" type="password" class="regular-text" value="<?php echo esc_attr( $smtp_password ); ?>" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" />
							<p class="description"><?php esc_html_e( 'For Gmail use an app password and keep TLS/587.', 'we-subscribe-to-posts' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save mail transport settings', 'we-subscribe-to-posts' ) ); ?>
			</form>

			<script>
				(function() {
					var transport = document.getElementById('wstp_mail_transport');
					if (!transport) {
						return;
					}

					var nonDefaultRows = document.querySelectorAll('.wstp-mail-non-default');
					var smtpOnlyRows = document.querySelectorAll('.wstp-mail-smtp-only');
					var host = document.getElementById('wstp_smtp_host');
					var port = document.getElementById('wstp_smtp_port');
					var encryption = document.getElementById('wstp_smtp_encryption');
					var auth = document.getElementById('wstp_smtp_auth');
					var customSnapshot = null;
					var previousTransport = transport.value || 'wp_default';

					var snapshotCustomValues = function() {
						customSnapshot = {
							host: host ? host.value : '',
							port: port ? port.value : '',
							encryption: encryption ? encryption.value : '',
							auth: auth ? auth.value : ''
						};
					};

					var restoreCustomValues = function() {
						if (!customSnapshot) {
							if (host && host.value === 'smtp.gmail.com') {
								host.value = '';
							}
							return;
						}

						if (host) {
							host.value = customSnapshot.host;
						}
						if (port) {
							port.value = customSnapshot.port;
						}
						if (encryption) {
							encryption.value = customSnapshot.encryption;
						}
						if (auth) {
							auth.value = customSnapshot.auth;
						}
					};

					var updateVisibility = function() {
						var value = transport.value || 'wp_default';
						var showNonDefault = value !== 'wp_default';
						var showSmtp = value === 'smtp' || value === 'gmail';

						nonDefaultRows.forEach(function(row) {
							row.style.display = showNonDefault ? '' : 'none';
						});
						smtpOnlyRows.forEach(function(row) {
							row.style.display = showSmtp ? '' : 'none';
						});

						if (value === 'gmail' && previousTransport !== 'gmail') {
							snapshotCustomValues();
						}

						if (value === 'gmail') {
							if (host) {
								host.value = 'smtp.gmail.com';
							}
							if (port) {
								port.value = '587';
							}
							if (encryption) {
								encryption.value = 'tls';
							}
							if (auth) {
								auth.value = 'yes';
							}
						}

						if (value === 'smtp' && previousTransport === 'gmail') {
							restoreCustomValues();
						}

						previousTransport = value;
					};

					transport.addEventListener('change', updateVisibility);
					updateVisibility();
				})();
			</script>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 14px 0 6px 0;" autocomplete="off" data-lpignore="true" data-1p-ignore="true">
				<input type="hidden" name="action" value="wstp_send_test_mail" />
				<input type="hidden" name="wstp_redirect_page" value="<?php echo esc_attr( self::MAIL_MENU_SLUG ); ?>" />
				<?php wp_nonce_field( 'wstp_send_test_mail', 'wstp_send_test_mail_nonce' ); ?>
				<label for="wstp_test_mail_recipient"><?php esc_html_e( 'Test transport recipient', 'we-subscribe-to-posts' ); ?></label>
				<input id="wstp_test_mail_recipient" name="wstp_test_mail_recipient" type="email" class="regular-text" value="<?php echo esc_attr( $preview_email ); ?>" />
				<?php submit_button( __( 'Send test email', 'we-subscribe-to-posts' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php
			$effective_host       = $smtp_host;
			$effective_port       = $smtp_port;
			$effective_encryption = $smtp_encryption;
			$effective_auth       = $smtp_auth;

			if ( 'gmail' === $mail_transport ) {
				$effective_host       = 'smtp.gmail.com';
				$effective_port       = 587;
				$effective_encryption = 'tls';
				$effective_auth       = 'yes';
			}
			?>
			<div style="margin: 8px 0 14px 0; padding: 10px 12px; border: 1px solid #ccd0d4; background: #f8f9fa;">
				<p style="margin: 0 0 6px 0;"><strong><?php esc_html_e( 'Active transport summary', 'we-subscribe-to-posts' ); ?></strong></p>
				<?php if ( 'wp_default' === $mail_transport ) : ?>
					<p style="margin: 0;"><?php esc_html_e( 'Transport: WordPress default wp_mail() / host environment', 'we-subscribe-to-posts' ); ?></p>
				<?php else : ?>
					<p style="margin: 0 0 4px 0;"><?php echo esc_html( sprintf( 'Transport: %s', 'gmail' === $mail_transport ? 'Gmail SMTP preset' : 'Custom SMTP' ) ); ?></p>
					<p style="margin: 0 0 4px 0;"><?php echo esc_html( sprintf( 'Host: %s | Port: %d | Encryption: %s', $effective_host ? $effective_host : '-', (int) $effective_port, $effective_encryption ) ); ?></p>
					<p style="margin: 0;"><?php echo esc_html( sprintf( 'Auth: %s | Scope: %s', 'yes' === $effective_auth ? 'enabled' : 'disabled', 'yes' === $mail_global ? 'global (all WP emails)' : 'plugin-only' ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle transport test email action.
	 *
	 * @return void
	 */
	public function handle_test_mail_send(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'we-subscribe-to-posts' ) );
		}

		if ( ! isset( $_POST['wstp_send_test_mail_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wstp_send_test_mail_nonce'] ) ), 'wstp_send_test_mail' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'we-subscribe-to-posts' ) );
		}

		$recipient     = isset( $_POST['wstp_test_mail_recipient'] ) ? sanitize_email( wp_unslash( $_POST['wstp_test_mail_recipient'] ) ) : '';
		$redirect_page = isset( $_POST['wstp_redirect_page'] ) ? sanitize_key( wp_unslash( $_POST['wstp_redirect_page'] ) ) : self::MAIL_MENU_SLUG;
		if ( ! in_array( $redirect_page, array( self::MENU_SLUG, self::MAIL_MENU_SLUG ), true ) ) {
			$redirect_page = self::MAIL_MENU_SLUG;
		}

		if ( ! is_email( $recipient ) ) {
			$this->redirect_with_admin_notice( 'mail_test_invalid', $redirect_page );
		}

		$mailer = new Mailer();
		$sent   = $mailer->send_test_email( $recipient );

		$this->redirect_with_admin_notice( $sent ? 'mail_test_sent' : 'mail_test_failed', $redirect_page );
	}

	/**
	 * Redirect back to settings with a notice code.
	 *
	 * @param string $notice Notice key.
	 * @param string $page Target page.
	 * @return void
	 */
	private function redirect_with_admin_notice( string $notice, string $page = self::MENU_SLUG ): void {
		$url = add_query_arg(
			array(
				'page'              => $page,
				'wstp_admin_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
