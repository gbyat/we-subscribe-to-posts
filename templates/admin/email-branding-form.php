<?php
/**
 * Email branding settings form partial.
 *
 * @package WeSubscribeToPosts
 * @var array<string,mixed> $branding
 * @var array<string,string> $resolved_colors
 * @var array<int,array{slug:string,color:string,name:string}> $theme_palette
 */

defined( 'ABSPATH' ) || exit;

$palette_colors = isset( $branding['palette_colors'] ) && is_array( $branding['palette_colors'] )
	? $branding['palette_colors']
	: array();

$slot_labels = array(
	'base'         => __( 'Outer background', 'we-subscribe-to-posts' ),
	'base-two'     => __( 'Content, header, footer, posts', 'we-subscribe-to-posts' ),
	'base-three'   => __( 'Theme surface (optional)', 'we-subscribe-to-posts' ),
	'accent'       => __( 'Body and footer text', 'we-subscribe-to-posts' ),
	'accent-two'   => __( 'Buttons and links', 'we-subscribe-to-posts' ),
	'accent-three' => __( 'Headings (darkest readable theme color)', 'we-subscribe-to-posts' ),
);
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wstp-email-branding-form">
	<input type="hidden" name="action" value="wstp_save_email_branding" />
	<?php wp_nonce_field( 'wstp_save_email_branding', 'wstp_email_branding_nonce' ); ?>

	<p class="description">
		<?php esc_html_e( 'Colors are applied automatically via placeholders such as {{wstp:color_body_bg}}, {{wstp:color_content_bg}}, {{wstp:color_text}} (headings), {{wstp:color_muted}} (body text), {{wstp:color_accent}}, and {{wstp:color_link}}.', 'we-subscribe-to-posts' ); ?>
	</p>

	<h2 class="title" style="margin-top:18px;"><?php esc_html_e( 'Colors', 'we-subscribe-to-posts' ); ?></h2>

	<?php if ( ! empty( $theme_palette ) ) : ?>
		<p class="description"><?php esc_html_e( 'Palette loaded from your theme. Click a swatch to edit.', 'we-subscribe-to-posts' ); ?></p>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'Click a swatch to edit the email palette.', 'we-subscribe-to-posts' ); ?></p>
	<?php endif; ?>

	<div class="wstp-palette-grid">
		<?php
		$slugs = array( 'base', 'base-two', 'accent-three', 'accent', 'accent-two', 'base-three' );
		foreach ( $slugs as $slug ) :
			$field_id = 'wstp_branding_palette_' . str_replace( '-', '_', $slug );
			$value    = (string) ( $palette_colors[ $slug ] ?? '' );
			$label    = $slot_labels[ $slug ] ?? $slug;
			?>
			<div class="wstp-palette-card">
				<div class="wstp-palette-card__swatch">
					<input
						id="<?php echo esc_attr( $field_id ); ?>"
						name="wstp_branding[palette_colors][<?php echo esc_attr( $slug ); ?>]"
						type="text"
						class="wstp-color-picker"
						value="<?php echo esc_attr( $value ); ?>"
						data-default-color="<?php echo esc_attr( $value ); ?>"
					/>
				</div>
				<div class="wstp-palette-card__meta">
					<strong><code><?php echo esc_html( $slug ); ?></code></strong>
					<span class="description"><?php echo esc_html( $label ); ?></span>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<p>
		<button type="submit" class="button" name="wstp_reload_palette_from_theme" value="1">
			<?php esc_html_e( 'Reload colors from theme', 'we-subscribe-to-posts' ); ?>
		</button>
	</p>

	<h2 class="title" style="margin-top:24px;"><?php esc_html_e( 'Header', 'we-subscribe-to-posts' ); ?></h2>
	<p class="description" style="max-width:42em;">
		<?php esc_html_e( 'Optional logo for the email header. Without a logo, name and slogan are used as the default text header (also seeded into new Visual templates).', 'we-subscribe-to-posts' ); ?>
	</p>
	<?php
	$header_title   = trim( (string) ( $branding['header_title'] ?? '' ) );
	$header_tagline = trim( (string) ( $branding['header_tagline'] ?? '' ) );
	if ( '' === $header_title ) {
		$header_title = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}
	if ( '' === $header_tagline ) {
		$header_tagline = wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES );
	}
	$logo_url    = \WSTP\Mailer\Email_Branding::resolve_logo_url_for_preview( $branding );
	$has_logo    = '' !== $logo_url;
	$logo_id     = (int) ( $branding['header_logo_id'] ?? 0 );
	$logo_link   = (string) ( $branding['header_logo_link_url'] ?? home_url( '/' ) );
	$logo_alt    = (string) ( $branding['header_logo_alt'] ?? '' );
	$logo_width  = (int) ( $branding['header_logo_width'] ?? 280 );
	$header_html = \WSTP\Mailer\Email_Branding::resolve_header_text_html(
		array_merge(
			$branding,
			array(
				'header_title'   => $header_title,
				'header_tagline' => $header_tagline,
			)
		)
	);
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Logo', 'we-subscribe-to-posts' ); ?></th>
			<td>
				<input type="hidden" id="wstp_header_logo_id" name="wstp_branding[header_logo_id]" value="<?php echo esc_attr( (string) $logo_id ); ?>" />
				<input type="hidden" id="wstp_header_logo_url" name="wstp_branding[header_logo_url]" value="<?php echo esc_attr( (string) ( $branding['header_logo_url'] ?? '' ) ); ?>" />
				<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
					<img
						id="wstp-header-logo-preview"
						src="<?php echo esc_url( $logo_url ); ?>"
						alt=""
						style="max-height:48px;max-width:220px;<?php echo $has_logo ? '' : 'display:none;'; ?>"
					/>
					<button type="button" class="button" id="wstp-header-logo-select"><?php echo $has_logo ? esc_html__( 'Replace logo', 'we-subscribe-to-posts' ) : esc_html__( 'Select logo', 'we-subscribe-to-posts' ); ?></button>
					<button type="button" class="button-link-delete" id="wstp-header-logo-remove" <?php disabled( ! $has_logo ); ?>><?php esc_html_e( 'Remove logo', 'we-subscribe-to-posts' ); ?></button>
				</div>
				<div class="wstp-header-logo-meta" style="<?php echo $has_logo ? '' : 'display:none;'; ?>">
					<p>
						<label for="wstp_header_logo_link_url"><?php esc_html_e( 'Logo link URL', 'we-subscribe-to-posts' ); ?></label><br />
						<input id="wstp_header_logo_link_url" name="wstp_branding[header_logo_link_url]" type="url" class="regular-text code" value="<?php echo esc_attr( $logo_link ); ?>" />
					</p>
					<p>
						<label for="wstp_header_logo_alt"><?php esc_html_e( 'Logo alt text', 'we-subscribe-to-posts' ); ?></label><br />
						<input id="wstp_header_logo_alt" name="wstp_branding[header_logo_alt]" type="text" class="regular-text" value="<?php echo esc_attr( $logo_alt ); ?>" />
					</p>
					<p>
						<label for="wstp_header_logo_width"><?php esc_html_e( 'Logo max width (px)', 'we-subscribe-to-posts' ); ?></label><br />
						<input id="wstp_header_logo_width" name="wstp_branding[header_logo_width]" type="number" min="80" max="560" value="<?php echo esc_attr( (string) $logo_width ); ?>" />
					</p>
				</div>
			</td>
		</tr>
		<tr class="wstp-header-text-row" style="<?php echo $has_logo ? 'display:none;' : ''; ?>">
			<th scope="row"><label for="wstp_header_title"><?php esc_html_e( 'Name', 'we-subscribe-to-posts' ); ?></label></th>
			<td>
				<input id="wstp_header_title" name="wstp_branding[header_title]" type="text" class="regular-text" value="<?php echo esc_attr( $header_title ); ?>" />
				<p class="description"><?php esc_html_e( 'Used as the default heading when no logo is set.', 'we-subscribe-to-posts' ); ?></p>
			</td>
		</tr>
		<tr class="wstp-header-text-row" style="<?php echo $has_logo ? 'display:none;' : ''; ?>">
			<th scope="row"><label for="wstp_header_tagline"><?php esc_html_e( 'Slogan', 'we-subscribe-to-posts' ); ?></label></th>
			<td>
				<input id="wstp_header_tagline" name="wstp_branding[header_tagline]" type="text" class="regular-text" value="<?php echo esc_attr( $header_tagline ); ?>" />
				<p class="description"><?php esc_html_e( 'Optional line under the name (e.g. “since 2003”).', 'we-subscribe-to-posts' ); ?></p>
			</td>
		</tr>
	</table>
	<textarea name="wstp_branding[header_html]" class="hidden" hidden><?php echo esc_textarea( $header_html ); ?></textarea>

	<h2 class="title" style="margin-top:24px;"><?php esc_html_e( 'Footer', 'we-subscribe-to-posts' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wstp_footer_identity"><?php esc_html_e( 'Name or company', 'we-subscribe-to-posts' ); ?></label></th>
			<td><input id="wstp_footer_identity" name="wstp_branding[footer_identity]" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $branding['footer_identity'] ?? '' ) ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wstp_footer_tagline"><?php esc_html_e( 'Tagline', 'we-subscribe-to-posts' ); ?></label></th>
			<td><input id="wstp_footer_tagline" name="wstp_branding[footer_tagline]" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $branding['footer_tagline'] ?? '' ) ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wstp_footer_address"><?php esc_html_e( 'Address', 'we-subscribe-to-posts' ); ?></label></th>
			<td><textarea id="wstp_footer_address" name="wstp_branding[footer_address]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $branding['footer_address'] ?? '' ) ); ?></textarea></td>
		</tr>
		<tr>
			<th scope="row"><label for="wstp_footer_phone"><?php esc_html_e( 'Phone', 'we-subscribe-to-posts' ); ?></label></th>
			<td><input id="wstp_footer_phone" name="wstp_branding[footer_phone]" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $branding['footer_phone'] ?? '' ) ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wstp_footer_primary_url"><?php esc_html_e( 'Primary URL', 'we-subscribe-to-posts' ); ?></label></th>
			<td><input id="wstp_footer_primary_url" name="wstp_branding[footer_primary_url]" type="url" class="regular-text code" value="<?php echo esc_attr( (string) ( $branding['footer_primary_url'] ?? '' ) ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wstp_footer_primary_label"><?php esc_html_e( 'Primary label', 'we-subscribe-to-posts' ); ?></label></th>
			<td><input id="wstp_footer_primary_label" name="wstp_branding[footer_primary_label]" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $branding['footer_primary_label'] ?? '' ) ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wstp_footer_secondary_url"><?php esc_html_e( 'Secondary URL', 'we-subscribe-to-posts' ); ?></label></th>
			<td><input id="wstp_footer_secondary_url" name="wstp_branding[footer_secondary_url]" type="url" class="regular-text code" value="<?php echo esc_attr( (string) ( $branding['footer_secondary_url'] ?? '' ) ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wstp_footer_secondary_label"><?php esc_html_e( 'Secondary label', 'we-subscribe-to-posts' ); ?></label></th>
			<td><input id="wstp_footer_secondary_label" name="wstp_branding[footer_secondary_label]" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $branding['footer_secondary_label'] ?? '' ) ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wstp_footer_privacy_url"><?php esc_html_e( 'Privacy URL', 'we-subscribe-to-posts' ); ?></label></th>
			<td><input id="wstp_footer_privacy_url" name="wstp_branding[footer_privacy_url]" type="url" class="regular-text code" value="<?php echo esc_attr( (string) ( $branding['footer_privacy_url'] ?? '' ) ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wstp_footer_imprint_url"><?php esc_html_e( 'Imprint URL', 'we-subscribe-to-posts' ); ?></label></th>
			<td><input id="wstp_footer_imprint_url" name="wstp_branding[footer_imprint_url]" type="url" class="regular-text code" value="<?php echo esc_attr( (string) ( $branding['footer_imprint_url'] ?? '' ) ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="wstp_footer_unsubscribe_label"><?php esc_html_e( 'Unsubscribe link label', 'we-subscribe-to-posts' ); ?></label></th>
			<td>
				<input id="wstp_footer_unsubscribe_label" name="wstp_branding[footer_unsubscribe_label]" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $branding['footer_unsubscribe_label'] ?? '' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Text of the unsubscribe link in the footer block.', 'we-subscribe-to-posts' ); ?></p>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save branding', 'we-subscribe-to-posts' ), 'primary', 'submit', false ); ?>
</form>
