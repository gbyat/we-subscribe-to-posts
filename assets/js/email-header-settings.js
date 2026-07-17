( function ( $ ) {
	'use strict';

	var frame = null;
	var strings = window.wstpEmailHeaderSettings || {};
	var updatingLogo = false;

	function updatePreview( url ) {
		var preview = $( '#wstp-header-logo-preview' );
		if ( ! preview.length ) {
			return;
		}

		if ( url ) {
			// Bust browser cache when replacing/reselecting the same path.
			var bust = url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'wstp_ver=' + Date.now();
			preview.attr( 'src', bust ).show();
			return;
		}

		preview.attr( 'src', '' ).hide();
	}

	function updateLogoDependentUi() {
		var id = $.trim( $( '#wstp_header_logo_id' ).val() || '' );
		var url = $.trim( $( '#wstp_header_logo_url' ).val() || '' );
		var hasLogo = url !== '' || ( id !== '' && id !== '0' );
		$( '.wstp-header-text-row' ).toggle( ! hasLogo );
		$( '.wstp-header-logo-meta' ).toggle( hasLogo );
		$( document ).trigger( 'wstp-branding-logo-changed' );
	}

	function setLogo( attachment ) {
		var id = attachment && attachment.id ? String( attachment.id ) : '';
		var url = '';
		if ( attachment ) {
			if ( attachment.sizes && attachment.sizes.full && attachment.sizes.full.url ) {
				url = String( attachment.sizes.full.url );
			} else if ( attachment.url ) {
				url = String( attachment.url );
			}
		}

		updatingLogo = true;
		$( '#wstp_header_logo_id' ).val( id );
		$( '#wstp_header_logo_url' ).val( url );
		updatingLogo = false;
		updatePreview( url );
		updateLogoDependentUi();
	}

	$( document ).on( 'click', '#wstp-header-logo-select', function ( event ) {
		event.preventDefault();

		if ( typeof wp === 'undefined' || ! wp.media ) {
			window.alert( strings.mediaUnavailable || 'Media library is not available.' );
			return;
		}

		if ( frame ) {
			frame.open();
			return;
		}

		frame = wp.media( {
			title: strings.selectTitle || 'Select logo',
			button: {
				text: strings.selectButton || 'Use logo',
			},
			multiple: false,
			library: {
				type: 'image',
			},
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			setLogo( attachment );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '#wstp-header-logo-remove', function ( event ) {
		event.preventDefault();
		setLogo( { id: '', url: '' } );
		$( '#wstp_header_logo_url' ).trigger( 'blur' );
	} );

	// Manual URL edits must clear the attachment ID, otherwise save could
	// re-resolve a previous media item. Skip when setLogo updates the field.
	$( document ).on( 'input change', '#wstp_header_logo_url', function () {
		if ( updatingLogo ) {
			return;
		}
		var url = $.trim( $( this ).val() || '' );
		$( '#wstp_header_logo_id' ).val( '' );
		updatePreview( url );
		updateLogoDependentUi();
	} );

	updateLogoDependentUi();
}( jQuery ) );
