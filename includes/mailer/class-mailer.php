<?php
/**
 * Mailer service.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Sends plugin emails through wp_mail.
 */
final class Mailer {
	/**
	 * Digest renderer.
	 *
	 * @var Digest_Template_Renderer
	 */
	private Digest_Template_Renderer $digest_renderer;

	/**
	 * Whether current wp_mail call belongs to this plugin.
	 *
	 * @var bool
	 */
	private static bool $is_plugin_mail_context = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->digest_renderer = new Digest_Template_Renderer();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->digest_renderer->register();
		add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ) );
	}

	/**
	 * Send double opt-in confirmation email.
	 *
	 * @param string              $email Recipient.
	 * @param array<string,mixed> $context Template context.
	 * @return bool
	 */
	public function send_double_optin( string $email, array $context ): bool {
		$subject = __( 'Please confirm your post subscription', 'we-subscribe-to-posts' );
		$body    = $this->render_template( 'optin-confirmation.php', $context );

		return $this->send_html_mail( $email, $subject, $body );
	}

	/**
	 * Send digest email.
	 *
	 * @param string              $email Recipient.
	 * @param string              $subject Subject.
	 * @param array<string,mixed> $context Template context.
	 * @return bool
	 */
	public function send_digest( string $email, string $subject, array $context ): bool {
		$body = $this->digest_renderer->render_digest( $context );

		return $this->send_html_mail(
			$email,
			$subject,
			$body
		);
	}

	/**
	 * Send html mail while marking plugin mail context.
	 *
	 * @param string $email Recipient.
	 * @param string $subject Subject.
	 * @param string $body Html body.
	 * @return bool
	 */
	private function send_html_mail( string $email, string $subject, string $body ): bool {
		self::$is_plugin_mail_context = true;

		try {
			return wp_mail(
				$email,
				$subject,
				$body,
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
		} finally {
			self::$is_plugin_mail_context = false;
		}
	}

	/**
	 * Send test email for transport verification.
	 *
	 * @param string $email Recipient.
	 * @return bool
	 */
	public function send_test_email( string $email ): bool {
		$subject = __( '[Test] Post Subscription Mail Transport', 'we-subscribe-to-posts' );
		$body    = '<html><body style="font-family: Arial, sans-serif; line-height: 1.5;"><p>' .
			esc_html__( 'This is a test email from We Subscribe To Posts transport settings.', 'we-subscribe-to-posts' ) .
			'</p></body></html>';

		return $this->send_html_mail( $email, $subject, $body );
	}

	/**
	 * Configure PHPMailer transport from plugin settings.
	 *
	 * @param mixed $phpmailer PHPMailer instance.
	 * @return void
	 */
	public function configure_phpmailer( $phpmailer ): void {
		$mail_settings = $this->get_mail_settings();
		$transport     = (string) $mail_settings['transport'];
		if ( 'wp_default' === $transport ) {
			return;
		}

		$apply_globally = 'yes' === (string) $mail_settings['apply_globally'];
		if ( ! $apply_globally && ! self::$is_plugin_mail_context ) {
			return;
		}

		$host       = (string) $mail_settings['smtp_host'];
		$port       = (int) $mail_settings['smtp_port'];
		$encryption = (string) $mail_settings['smtp_encryption'];
		$auth       = 'yes' === (string) $mail_settings['smtp_auth'];
		$username   = (string) $mail_settings['smtp_username'];
		$password   = (string) $mail_settings['smtp_password'];

		if ( 'gmail' === $transport ) {
			$host       = 'smtp.gmail.com';
			$port       = 587;
			$encryption = 'tls';
			$auth       = true;
		}

		if ( '' === $host || $port < 1 ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host = $host;
		$phpmailer->Port = $port;

		if ( 'none' === $encryption ) {
			$phpmailer->SMTPSecure = '';
		} else {
			$phpmailer->SMTPSecure = $encryption;
		}

		$phpmailer->SMTPAuth = $auth;
		if ( $auth ) {
			$phpmailer->Username = $username;
			$phpmailer->Password = $password;
		}

		$from_email = sanitize_email( (string) $mail_settings['from_email'] );
		$from_name  = sanitize_text_field( (string) $mail_settings['from_name'] );
		if ( $from_email ) {
			$phpmailer->setFrom( $from_email, $from_name ? $from_name : $phpmailer->FromName, false );
		} elseif ( $from_name ) {
			$phpmailer->FromName = $from_name;
		}
	}

	/**
	 * Get normalized mail settings from array option.
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
	 * Render email template and return HTML.
	 *
	 * @param string              $template_name File name.
	 * @param array<string,mixed> $context Data.
	 * @return string
	 */
	private function render_template( string $template_name, array $context ): string {
		$template_file = WSTP_PATH . 'templates/emails/' . $template_name;

		if ( ! file_exists( $template_file ) ) {
			return '';
		}

		extract( $context, EXTR_SKIP );

		ob_start();
		include $template_file;

		return (string) ob_get_clean();
	}
}
