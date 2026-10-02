/* global gdpFinance */
( function () {
	'use strict';

	var strings = window.gdpFinance || { copied: 'Copiado', copy: 'Copiar' };

	// Botones "Copiar" de las hojas de ejecución: copian el valor del atributo data-copy.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.gdp-copy' );
		if ( ! button ) {
			return;
		}
		var text = button.getAttribute( 'data-copy' ) || '';
		var done = function () {
			var original = button.textContent;
			button.textContent = strings.copied;
			button.classList.add( 'gdp-copy--done' );
			window.setTimeout( function () {
				button.textContent = original;
				button.classList.remove( 'gdp-copy--done' );
			}, 1500 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
			return;
		}
		var area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'absolute';
		area.style.left = '-9999px';
		document.body.appendChild( area );
		area.select();
		try {
			document.execCommand( 'copy' );
			done();
		} catch ( e ) {
			window.prompt( strings.copy, text );
		}
		document.body.removeChild( area );
	} );
} )();
