( function ( $ ) {
	'use strict';

	var colorPickersInitialized = false;

	function getTabFromUrl() {
		var params = new URLSearchParams( window.location.search );
		var tab = params.get( 'tab' );
		if ( tab === 'branding' || tab === 'visual' || tab === 'template' ) {
			return tab;
		}
		return 'visual';
	}

	function initColorPickers() {
		if ( colorPickersInitialized || ! $.fn.wpColorPicker ) {
			return;
		}

		$( '.wstp-color-picker' ).each( function () {
			var $input = $( this );
			if ( $input.closest( '.wp-picker-container' ).length ) {
				return;
			}

			$input.wpColorPicker( {
				change: function ( event, ui ) {
					$( event.target ).val( ui.color.toString() ).trigger( 'change' );
				},
				clear: function () {
					$( this ).val( '' ).trigger( 'change' );
				},
			} );
		} );

		colorPickersInitialized = true;
	}

	function activateTab( tab ) {
		$( '.wstp-template-tab-panel' ).hide();
		$( '.wstp-template-tab-panel[data-tab="' + tab + '"]' ).show();
		$( '.nav-tab' ).removeClass( 'nav-tab-active' );
		$( '.nav-tab[data-tab="' + tab + '"]' ).addClass( 'nav-tab-active' );

		if ( tab === 'branding' ) {
			initColorPickers();
		}

		if ( tab === 'visual' && typeof window.wstpMountEmailVisualEditor === 'function' ) {
			window.wstpMountEmailVisualEditor();
		}

		if ( tab === 'template' && typeof window.wstpEnsureMjmlCodeEditor === 'function' ) {
			// CodeMirror must init/refresh while the panel is visible.
			window.setTimeout( function () {
				window.wstpEnsureMjmlCodeEditor();
			}, 30 );
		}
	}

	$( '.nav-tab-wrapper a.nav-tab' ).on( 'click', function ( event ) {
		event.preventDefault();
		var tab = $( this ).data( 'tab' ) || 'visual';
		var url = new URL( window.location.href );
		url.searchParams.set( 'tab', tab );
		window.history.replaceState( {}, '', url.toString() );
		activateTab( tab );
	} );

	activateTab( getTabFromUrl() );
}( jQuery ) );
