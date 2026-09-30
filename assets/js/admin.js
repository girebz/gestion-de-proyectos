/* global gdpAdmin */
( function () {
	'use strict';

	var strings = window.gdpAdmin || { copied: 'Copiado', copy: 'Copiar', confirmDel: '¿Confirma?' };

	// Botones "Copiar" (URL del servidor, token).
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.gdp-copy-button' );
		if ( ! button ) {
			return;
		}

		var target = document.querySelector( button.getAttribute( 'data-copy' ) );
		if ( ! target ) {
			return;
		}

		var text = target.textContent.trim();

		var done = function () {
			var original = button.textContent;
			button.textContent = strings.copied;
			window.setTimeout( function () {
				button.textContent = original;
			}, 1500 );
		};

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
			return;
		}

		// Alternativa para contextos sin API de portapapeles.
		var range = document.createRange();
		range.selectNodeContents( target );
		var selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange( range );
		try {
			document.execCommand( 'copy' );
			done();
		} catch ( e ) {
			// El texto queda seleccionado para copiarlo manualmente.
		}
	} );

	// Confirmación en formularios destructivos.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( form && form.matches( 'form[data-confirm]' ) && ! window.confirm( strings.confirmDel ) ) {
			event.preventDefault();
		}
	} );
}() );
