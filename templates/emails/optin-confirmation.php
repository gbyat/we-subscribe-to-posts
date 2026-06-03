<?php
/**
 * Double opt-in email template.
 *
 * @var string $name Recipient name (optional).
 * @var string $confirm_url Confirmation URL.
 *
 * @package WeSubscribeToPosts
 */

defined( 'ABSPATH' ) || exit;

$display_name = ! empty( $name ) ? $name : __( 'there', 'we-subscribe-to-posts' );
?>
<html>
<body style="font-family: Arial, sans-serif; line-height: 1.5;">
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: subscriber display name. */
				__( 'Hi %s,', 'we-subscribe-to-posts' ),
				$display_name
			)
		);
		?>
	</p>
	<p><?php esc_html_e( 'Please confirm your subscription to post update notifications by clicking the link below:', 'we-subscribe-to-posts' ); ?></p>
	<p><a href="<?php echo esc_url( $confirm_url ); ?>"><?php esc_html_e( 'Confirm subscription', 'we-subscribe-to-posts' ); ?></a></p>
</body>
</html>
