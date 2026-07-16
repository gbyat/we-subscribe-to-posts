( function ( $ ) {
	'use strict';

	var frame = null;
	var strings = window.wstpEmailHeaderSettings || {};

	function updatePreview( url ) {
		var preview = $( '#wstp-header-logo-preview' );
		if ( ! preview.length ) {
			return;
		}

		if ( url ) {
			preview.attr( 'src', url ).show();
			return;
		}

		preview.attr( 'src', '' ).hide();
	}

	$( '#wstp-header-logo-select' ).on( 'click', function ( event ) {
		event.preventDefault();

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
			$( '#wstp_header_logo_url' ).val( attachment.url || '' );
			updatePreview( attachment.url || '' );
		} );

		frame.open();
	} );

	$( '#wstp-header-logo-remove' ).on( 'click', function ( event ) {
		event.preventDefault();
		$( '#wstp_header_logo_url' ).val( '' );
		updatePreview( '' );
	} );
}( jQuery ) );
