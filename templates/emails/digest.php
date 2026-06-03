<?php
/**
 * Digest email template.
 *
 * @var string                   $greeting_name Greeting value.
 * @var array<int,array<string>> $posts Post cards.
 * @var string                   $unsubscribe_url Unsubscribe URL.
 *
 * @package WeSubscribeToPosts
 */

defined( 'ABSPATH' ) || exit;
?>
<html>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #111;">
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: subscriber name. */
				__( 'Hi %s,', 'we-subscribe-to-posts' ),
				$greeting_name
			)
		);
		?>
	</p>
	<p><?php esc_html_e( 'Here are the latest published posts:', 'we-subscribe-to-posts' ); ?></p>

	<?php foreach ( $posts as $post_item ) : ?>
		<div style="margin-bottom: 28px; padding-bottom: 20px; border-bottom: 1px solid #e5e5e5;">
			<?php if ( ! empty( $post_item['featured_image_url'] ) ) : ?>
				<p>
					<img src="<?php echo esc_url( $post_item['featured_image_url'] ); ?>" alt="" style="max-width: 100%; height: auto;" />
				</p>
			<?php endif; ?>

			<h3 style="margin: 0 0 8px 0;">
				<a href="<?php echo esc_url( $post_item['permalink'] ); ?>" style="color: #111; text-decoration: none;">
					<?php echo esc_html( $post_item['title'] ); ?>
				</a>
			</h3>

			<p style="margin: 0 0 10px 0;"><?php echo esc_html( $post_item['excerpt'] ); ?></p>
			<p style="margin: 0;">
				<a href="<?php echo esc_url( $post_item['permalink'] ); ?>"><?php esc_html_e( 'Read more', 'we-subscribe-to-posts' ); ?></a>
			</p>
		</div>
	<?php endforeach; ?>

	<p>
		<a href="<?php echo esc_url( $unsubscribe_url ); ?>"><?php esc_html_e( 'Unsubscribe instantly', 'we-subscribe-to-posts' ); ?></a>
	</p>
</body>
</html>
