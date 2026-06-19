<?php
/**
 * Subscription form template.
 *
 * @var array<string,string> $frequency_options Frequency labels.
 * @var string               $privacy_text Privacy notice.
 * @var string               $status Status query value.
 *
 * @package WeSubscribeToPosts
 */

defined( 'ABSPATH' ) || exit;

$messages = array(
	'optin_sent'       => __( 'Please check your inbox and confirm your subscription.', 'we-subscribe-to-posts' ),
	'confirmed'        => __( 'Subscription confirmed. You are now subscribed.', 'we-subscribe-to-posts' ),
	'unsubscribed'     => __( 'You have been unsubscribed successfully.', 'we-subscribe-to-posts' ),
	'invalid_nonce'    => __( 'Security check failed. Please try again.', 'we-subscribe-to-posts' ),
	'invalid_email'    => __( 'Please enter a valid email address.', 'we-subscribe-to-posts' ),
	'invalid_name'     => __( 'Please enter a valid name without links or suspicious patterns.', 'we-subscribe-to-posts' ),
	'invalid_frequency'=> __( 'Please select a valid delivery frequency.', 'we-subscribe-to-posts' ),
	'missing_consent'  => __( 'Please accept the privacy section before subscribing.', 'we-subscribe-to-posts' ),
	'bot_detected'     => __( 'Submission could not be processed. Please try again.', 'we-subscribe-to-posts' ),
	'rate_limited'     => __( 'Too many subscribe attempts in a short time. Please wait a moment and try again.', 'we-subscribe-to-posts' ),
	'send_error'       => __( 'We could not send the confirmation email right now. Please try again later.', 'we-subscribe-to-posts' ),
	'invalid_token'    => __( 'This confirmation or unsubscribe link is invalid or expired.', 'we-subscribe-to-posts' ),
	'already_subscribed' => __( 'This email is already subscribed with the selected frequency.', 'we-subscribe-to-posts' ),
	'optin_already_sent' => __( 'A confirmation email was already sent for this address. Please check your inbox.', 'we-subscribe-to-posts' ),
	'optin_resent' => __( 'We sent a fresh confirmation email. Please check your inbox.', 'we-subscribe-to-posts' ),
	'optin_recently_sent' => __( 'A confirmation email was sent recently. Please wait a few minutes before requesting another one.', 'we-subscribe-to-posts' ),
	'suppressed' => __( 'This email is blocked from subscriptions and cannot be added again.', 'we-subscribe-to-posts' ),
);

$is_compact       = isset( $is_compact ) ? (bool) $is_compact : false;
$default_frequency = isset( $default_frequency ) ? (string) $default_frequency : 'daily';
$button_label      = isset( $button_label ) ? (string) $button_label : __( 'Subscribe', 'we-subscribe-to-posts' );
$button_use_custom_style = isset( $button_use_custom_style ) ? (bool) $button_use_custom_style : false;
$button_bg_color   = isset( $button_bg_color ) ? (string) $button_bg_color : '#1d4ed8';
$button_text_color = isset( $button_text_color ) ? (string) $button_text_color : '#ffffff';
$button_radius     = isset( $button_radius ) ? (int) $button_radius : 6;
$form_rendered_at  = isset( $form_rendered_at ) ? (int) $form_rendered_at : time();
$form_timing_sig   = isset( $form_timing_sig ) ? (string) $form_timing_sig : '';
$status_notice_style    = isset( $status_notice_style ) ? (string) $status_notice_style : 'toast';
$status_notice_position = isset( $status_notice_position ) ? (string) $status_notice_position : 'bottom-right';
$status_notice_seconds  = isset( $status_notice_seconds ) ? (int) $status_notice_seconds : 8;
if ( ! in_array( $status_notice_style, array( 'toast', 'overlay', 'inline' ), true ) ) {
	$status_notice_style = 'toast';
}
if ( ! in_array( $status_notice_position, array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ), true ) ) {
	$status_notice_position = 'bottom-right';
}
if ( $status_notice_seconds < 0 ) {
	$status_notice_seconds = 0;
}

$button_style  = '';
if ( $button_use_custom_style ) {
	$button_declarations = array(
		'display:inline-block',
		'cursor:pointer',
		'text-decoration:none',
		'-webkit-appearance:none',
		'appearance:none',
	);
	if ( '' !== $button_bg_color ) {
		$button_declarations[] = 'background:' . $button_bg_color . ' !important';
	}
	if ( '' !== $button_text_color ) {
		$button_declarations[] = 'color:' . $button_text_color . ' !important';
	}
	if ( $button_radius > 0 ) {
		$button_declarations[] = 'border-radius:' . $button_radius . 'px !important';
	}

	$button_style = implode( '; ', $button_declarations ) . ';';
}
$rest_subscribe_url = rest_url( 'wstp/v1/subscribe' );
$rest_nonce_url = rest_url( 'wstp/v1/subscribe-nonce' );
$overlay_statuses = array( 'optin_sent', 'optin_resent', 'confirmed', 'unsubscribed' );
$can_float_notice = isset( $messages[ $status ] ) && in_array( $status, $overlay_statuses, true ) && 'inline' !== $status_notice_style;
$toast_position_css = 'right:16px; bottom:16px;';
if ( 'bottom-left' === $status_notice_position ) {
	$toast_position_css = 'left:16px; bottom:16px;';
} elseif ( 'top-right' === $status_notice_position ) {
	$toast_position_css = 'right:16px; top:16px;';
} elseif ( 'top-left' === $status_notice_position ) {
	$toast_position_css = 'left:16px; top:16px;';
}
?>
<div class="wstp-form-wrap<?php echo $is_compact ? ' wstp-form-wrap--compact' : ''; ?>">
	<?php if ( $can_float_notice && 'toast' === $status_notice_style ) : ?>
		<div id="wstp-status-overlay" role="status" aria-live="polite" style="position:fixed; <?php echo esc_attr( $toast_position_css ); ?> z-index:99999; max-width:420px; width:calc(100% - 32px); background:#ffffff; color:#111111; border-radius:10px; box-shadow:0 12px 32px rgba(0,0,0,0.22); border:1px solid #d9dbe1; padding:16px 16px 14px 16px; transform:translateY(130%); opacity:0; transition:transform .26s ease, opacity .26s ease;">
			<p style="margin:0 0 10px 0; font-size:15px; line-height:1.45;"><?php echo esc_html( $messages[ $status ] ); ?></p>
			<button id="wstp-overlay-dismiss" type="button" style="display:inline-block; border:0; padding:8px 12px; cursor:pointer; background:#1d4ed8; color:#ffffff; border-radius:6px;">
				<?php esc_html_e( 'OK', 'we-subscribe-to-posts' ); ?>
			</button>
		</div>
		<script>
			(function () {
				var overlay = document.getElementById('wstp-status-overlay');
				if (!overlay) {
					return;
				}
				if (document.body && overlay.parentNode !== document.body) {
					document.body.appendChild(overlay);
				}
				window.requestAnimationFrame(function () {
					overlay.style.transform = 'translateY(0)';
					overlay.style.opacity = '1';
				});

				var dismiss = document.getElementById('wstp-overlay-dismiss');
				var closeOverlay = function () {
					overlay.style.transform = 'translateY(130%)';
					overlay.style.opacity = '0';
					window.setTimeout(function () {
						overlay.style.display = 'none';
					}, 260);
				};

				if (dismiss) {
					dismiss.addEventListener('click', closeOverlay);
				}
				<?php if ( $status_notice_seconds > 0 ) : ?>
				window.setTimeout(closeOverlay, <?php echo esc_js( (string) ( $status_notice_seconds * 1000 ) ); ?>);
				<?php endif; ?>
			})();
		</script>
	<?php endif; ?>

	<?php if ( $can_float_notice && 'overlay' === $status_notice_style ) : ?>
		<div id="wstp-status-overlay" style="position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.55); display:flex; align-items:center; justify-content:center; padding:24px;">
			<div role="dialog" aria-modal="true" style="max-width:520px; width:100%; background:#ffffff; color:#111111; border-radius:10px; padding:22px;">
				<p style="margin:0 0 16px 0; font-size:18px; line-height:1.4;"><?php echo esc_html( $messages[ $status ] ); ?></p>
				<button id="wstp-overlay-dismiss" type="button" style="display:inline-block; border:0; padding:10px 14px; cursor:pointer; background:#1d4ed8; color:#ffffff; border-radius:6px;">
					<?php esc_html_e( 'Continue to website', 'we-subscribe-to-posts' ); ?>
				</button>
			</div>
		</div>
		<script>
			(function () {
				var overlay = document.getElementById('wstp-status-overlay');
				if (!overlay) {
					return;
				}
				if (document.body && overlay.parentNode !== document.body) {
					document.body.appendChild(overlay);
				}
				document.body.style.overflow = 'hidden';

				var dismiss = document.getElementById('wstp-overlay-dismiss');
				var closeOverlay = function () {
					overlay.style.display = 'none';
					document.body.style.overflow = '';
				};

				if (dismiss) {
					dismiss.addEventListener('click', closeOverlay);
				}
				overlay.addEventListener('click', function (event) {
					if (event.target === overlay) {
						closeOverlay();
					}
				});
			})();
		</script>
	<?php endif; ?>

	<?php if ( isset( $messages[ $status ] ) && ! $can_float_notice ) : ?>
		<p class="wstp-message"><?php echo esc_html( $messages[ $status ] ); ?></p>
	<?php endif; ?>

	<form class="wstp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wstp_subscribe" />
		<?php wp_nonce_field( 'wstp_subscribe', 'wstp_nonce' ); ?>
		<input type="hidden" name="wstp_rendered_at" value="<?php echo esc_attr( (string) $form_rendered_at ); ?>" />
		<input type="hidden" name="wstp_timing_sig" value="<?php echo esc_attr( $form_timing_sig ); ?>" />
		<p style="position:absolute; left:-10000px; top:auto; width:1px; height:1px; overflow:hidden;" aria-hidden="true">
			<label for="wstp-website"><?php esc_html_e( 'Website', 'we-subscribe-to-posts' ); ?></label>
			<input id="wstp-website" type="text" name="wstp_website" value="" tabindex="-1" autocomplete="off" />
		</p>

		<p class="wstp-form-field">
			<label for="wstp-email"><?php esc_html_e( 'Email address', 'we-subscribe-to-posts' ); ?></label><br />
			<input class="wstp-form-control" id="wstp-email" type="email" name="wstp_email" required />
		</p>

		<p class="wstp-form-field">
			<label for="wstp-name"><?php esc_html_e( 'Name (optional)', 'we-subscribe-to-posts' ); ?></label><br />
			<input class="wstp-form-control" id="wstp-name" type="text" name="wstp_name" />
		</p>

		<p class="wstp-form-field">
			<label for="wstp-frequency"><?php esc_html_e( 'Send frequency', 'we-subscribe-to-posts' ); ?></label><br />
			<select class="wstp-form-control" id="wstp-frequency" name="wstp_frequency" required>
				<?php foreach ( $frequency_options as $frequency_key => $frequency_label ) : ?>
					<option value="<?php echo esc_attr( $frequency_key ); ?>" <?php selected( $default_frequency, $frequency_key ); ?>><?php echo esc_html( $frequency_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="wstp-form-field">
			<label>
				<input type="checkbox" name="wstp_consent" value="yes" required />
				<?php echo esc_html( $privacy_text ); ?>
			</label>
		</p>

		<p class="wstp-form-actions">
			<button class="wstp-submit-button" type="submit"<?php echo '' !== $button_style ? ' style="' . esc_attr( $button_style ) . '"' : ''; ?>><?php echo esc_html( $button_label ); ?></button>
		</p>
	</form>
	<script>
		(function () {
			var messages = <?php echo wp_json_encode( $messages ); ?>;
			var style = <?php echo wp_json_encode( $status_notice_style ); ?>;
			var position = <?php echo wp_json_encode( $status_notice_position ); ?>;
			var autoCloseSeconds = <?php echo (int) $status_notice_seconds; ?>;
			var restUrl = <?php echo wp_json_encode( $rest_subscribe_url ); ?>;
			var restNonceUrl = <?php echo wp_json_encode( $rest_nonce_url ); ?>;
			var wrap = (document.currentScript && document.currentScript.closest('.wstp-form-wrap')) || document.querySelector('.wstp-form-wrap');
			var form = wrap ? wrap.querySelector('form') : null;
			var clearStatusFromUrl = function () {
				if (!window.history || !window.history.replaceState) {
					return;
				}
				var url = new URL(window.location.href);
				if (!url.searchParams.has('wstp_status')) {
					return;
				}
				url.searchParams.delete('wstp_status');
				window.history.replaceState({}, document.title, url.toString());
			};
			var ensureFreshNonce = function () {
				var nonceInput = form ? form.querySelector('input[name="wstp_nonce"]') : null;
				var existingNonce = nonceInput ? (nonceInput.value || '') : '';
				if (!restNonceUrl) {
					return Promise.resolve(existingNonce);
				}
				var nonceUrl = new URL(restNonceUrl, window.location.origin);
				nonceUrl.searchParams.set('_wstp_nonce_refresh', String(Date.now()));

				return fetch(nonceUrl.toString(), {
					method: 'GET',
					credentials: 'same-origin',
					cache: 'no-store',
					headers: {
						'Accept': 'application/json'
					}
				})
					.then(function (response) {
						return response.json().catch(function () {
							return {};
						});
					})
					.then(function (json) {
						var freshNonce = json && json.nonce ? String(json.nonce) : '';
						if (freshNonce && nonceInput) {
							nonceInput.value = freshNonce;
						}
						return freshNonce || existingNonce;
					})
					.catch(function () {
						return existingNonce;
					});
			};

			var showStatus = function (status) {
				if (!messages[status]) {
					return;
				}
				clearStatusFromUrl();

				var text = messages[status];
				window.__wstpStatusShown = false;

				var existingInline = wrap ? wrap.querySelector('.wstp-message') : null;
				if (existingInline) {
					existingInline.remove();
				}
				var existingOverlay = document.getElementById('wstp-status-overlay');
				if (existingOverlay) {
					existingOverlay.remove();
					document.body.style.overflow = '';
				}

				if (style === 'inline') {
					if (wrap) {
						var msg = document.createElement('p');
						msg.className = 'wstp-message';
						msg.textContent = text;
						wrap.insertBefore(msg, wrap.firstChild);
					}
					window.__wstpStatusShown = true;
					return;
				}

				var overlay = document.createElement('div');
				overlay.id = 'wstp-status-overlay';

				var closeOverlay = function () {
					if (style === 'overlay') {
						overlay.style.display = 'none';
						document.body.style.overflow = '';
					} else {
						overlay.style.transform = 'translateY(130%)';
						overlay.style.opacity = '0';
						window.setTimeout(function () {
							overlay.style.display = 'none';
						}, 260);
					}
				};

				if (style === 'overlay') {
					overlay.setAttribute('style', 'position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.55); display:flex; align-items:center; justify-content:center; padding:24px;');
					overlay.innerHTML = '<div role="dialog" aria-modal="true" style="max-width:520px; width:100%; background:#ffffff; color:#111111; border-radius:10px; padding:22px;"><p style="margin:0 0 16px 0; font-size:18px; line-height:1.4;"></p><button type="button" style="display:inline-block; border:0; padding:10px 14px; cursor:pointer; background:#1d4ed8; color:#ffffff; border-radius:6px;"><?php echo esc_js( __( 'Continue to website', 'we-subscribe-to-posts' ) ); ?></button></div>';
					overlay.querySelector('p').textContent = text;
					overlay.querySelector('button').addEventListener('click', closeOverlay);
					overlay.addEventListener('click', function (event) {
						if (event.target === overlay) {
							closeOverlay();
						}
					});
					document.body.appendChild(overlay);
					document.body.style.overflow = 'hidden';
				} else {
					var positionCss = 'right:16px; bottom:16px;';
					if (position === 'bottom-left') {
						positionCss = 'left:16px; bottom:16px;';
					} else if (position === 'top-right') {
						positionCss = 'right:16px; top:16px;';
					} else if (position === 'top-left') {
						positionCss = 'left:16px; top:16px;';
					}
					overlay.setAttribute('role', 'status');
					overlay.setAttribute('aria-live', 'polite');
					overlay.setAttribute('style', 'position:fixed; ' + positionCss + ' z-index:99999; max-width:420px; width:calc(100% - 32px); background:#ffffff; color:#111111; border-radius:10px; box-shadow:0 12px 32px rgba(0,0,0,0.22); border:1px solid #d9dbe1; padding:16px 16px 14px 16px; transform:translateY(130%); opacity:0; transition:transform .26s ease, opacity .26s ease;');
					overlay.innerHTML = '<p style="margin:0 0 10px 0; font-size:15px; line-height:1.45;"></p><button type="button" style="display:inline-block; border:0; padding:8px 12px; cursor:pointer; background:#1d4ed8; color:#ffffff; border-radius:6px;"><?php echo esc_js( __( 'OK', 'we-subscribe-to-posts' ) ); ?></button>';
					overlay.querySelector('p').textContent = text;
					overlay.querySelector('button').addEventListener('click', closeOverlay);
					document.body.appendChild(overlay);
					window.requestAnimationFrame(function () {
						overlay.style.transform = 'translateY(0)';
						overlay.style.opacity = '1';
					});
					if (autoCloseSeconds > 0) {
						window.setTimeout(closeOverlay, autoCloseSeconds * 1000);
					}
				}

				window.__wstpStatusShown = true;
			};

			if (form && restUrl) {
				form.addEventListener('submit', function (event) {
					event.preventDefault();

					if (form.dataset.wstpSubmitting === '1') {
						return;
					}
					form.dataset.wstpSubmitting = '1';

					var submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
					if (submitButton) {
						submitButton.disabled = true;
					}

					var unlockForm = function () {
						form.dataset.wstpSubmitting = '0';
						if (submitButton) {
							submitButton.disabled = false;
						}
					};

					ensureFreshNonce()
						.then(function (freshNonce) {
							var data = new FormData(form);
							var payload = {
								wstp_nonce: freshNonce || data.get('wstp_nonce') || '',
								wstp_email: data.get('wstp_email') || '',
								wstp_name: data.get('wstp_name') || '',
								wstp_frequency: data.get('wstp_frequency') || '',
								wstp_consent: data.get('wstp_consent') || '',
								wstp_website: data.get('wstp_website') || '',
								wstp_rendered_at: data.get('wstp_rendered_at') || '',
								wstp_timing_sig: data.get('wstp_timing_sig') || ''
							};

							return fetch(restUrl, {
								method: 'POST',
								credentials: 'same-origin',
								cache: 'no-store',
								headers: {
									'Content-Type': 'application/json'
								},
								body: JSON.stringify(payload)
							});
						})
						.then(function (response) {
							return response.json().catch(function () {
								return {};
							}).then(function (json) {
								return { ok: response.ok, json: json };
							});
						})
						.then(function (result) {
							var responseStatus = result.json && result.json.status ? result.json.status : '';
							if (responseStatus === 'invalid_nonce') {
								return ensureFreshNonce().then(function (retryNonce) {
									var dataRetry = new FormData(form);
									var payloadRetry = {
										wstp_nonce: retryNonce || dataRetry.get('wstp_nonce') || '',
										wstp_email: dataRetry.get('wstp_email') || '',
										wstp_name: dataRetry.get('wstp_name') || '',
										wstp_frequency: dataRetry.get('wstp_frequency') || '',
										wstp_consent: dataRetry.get('wstp_consent') || '',
										wstp_website: dataRetry.get('wstp_website') || '',
										wstp_rendered_at: dataRetry.get('wstp_rendered_at') || '',
										wstp_timing_sig: dataRetry.get('wstp_timing_sig') || ''
									};
									return fetch(restUrl, {
										method: 'POST',
										credentials: 'same-origin',
										cache: 'no-store',
										headers: {
											'Content-Type': 'application/json'
										},
										body: JSON.stringify(payloadRetry)
									}).then(function (retryResponse) {
										return retryResponse.json().catch(function () {
											return {};
										}).then(function (retryJson) {
											return retryJson && retryJson.status ? retryJson.status : 'invalid_nonce';
										});
									});
								}).then(function (retryStatus) {
									if (messages[retryStatus]) {
										showStatus(retryStatus);
										if (retryStatus === 'optin_sent' || retryStatus === 'optin_resent') {
											form.reset();
										}
										return;
									}
									form.submit();
								});
							}

							if (!responseStatus || !messages[responseStatus]) {
								form.submit();
								return;
							}

							showStatus(responseStatus);

							if (responseStatus === 'optin_sent' || responseStatus === 'optin_resent') {
								form.reset();
							}
						})
						.catch(function () {
							form.submit();
						})
						.finally(function () {
							unlockForm();
						});
				});
			}

			if (!window.__wstpStatusShown) {
				var params = new URLSearchParams(window.location.search || '');
				var statusFromUrl = params.get('wstp_status');
				if (statusFromUrl) {
					showStatus(statusFromUrl);
				}
			}
		})();
	</script>
</div>
