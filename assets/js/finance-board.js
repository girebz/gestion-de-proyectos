/* Gestión de Proyectos: tablero de finanzas del sitio ([gdp_finanzas]).
   Recuadros emergentes de los gráficos (repiten lo que ya está escrito en
   leyendas y tablas), cruz de lectura de la curva de caja, impresión del
   informe, descarga de planillas CSV generadas en el navegador, selector de
   tareas del menú de ayuda y cierre de las ayudas desplegables. Los botones
   "Copiar" los atiende finance.js. */
( function () {
	'use strict';

	var money = ( function () {
		var fmt = null;
		try {
			fmt = new Intl.NumberFormat( 'es-CL', { maximumFractionDigits: 0 } );
		} catch ( e ) {
			fmt = null;
		}
		return function ( value ) {
			var n = Math.round( Number( value ) || 0 );
			var text = fmt ? fmt.format( Math.abs( n ) ) : String( Math.abs( n ) ).replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
			return ( n < 0 ? '-' : '' ) + '$\u00A0' + text;
		};
	} )();

	// Recuadro emergente único.
	var tip = null;
	function tooltip() {
		if ( ! tip ) {
			tip = document.createElement( 'div' );
			tip.className = 'gdp-fin-tooltip';
			tip.setAttribute( 'role', 'tooltip' );
			tip.hidden = true;
			document.body.appendChild( tip );
		}
		return tip;
	}
	function showTip( lines, x, y ) {
		var t = tooltip();
		while ( t.firstChild ) {
			t.removeChild( t.firstChild );
		}
		lines.forEach( function ( line, i ) {
			var row = document.createElement( 'div' );
			row.className = i === 0 ? 'gdp-fin-tooltip__head' : 'gdp-fin-tooltip__row';
			if ( line.key ) {
				var key = document.createElement( 'span' );
				key.className = 'gdp-fin-linekey gdp-fin-linekey--' + line.key;
				key.setAttribute( 'aria-hidden', 'true' );
				row.appendChild( key );
			}
			if ( line.value !== undefined ) {
				var strong = document.createElement( 'strong' );
				strong.textContent = line.value;
				row.appendChild( strong );
				row.appendChild( document.createTextNode( ' ' + line.text ) );
			} else {
				row.textContent = line.text;
			}
			t.appendChild( row );
		} );
		t.hidden = false;
		var rect = t.getBoundingClientRect();
		var left = Math.min( Math.max( 8, x - rect.width / 2 ), window.innerWidth - rect.width - 8 );
		var top = y - rect.height - 12;
		if ( top < 8 ) {
			top = y + 18;
		}
		t.style.left = ( left + window.scrollX ) + 'px';
		t.style.top = ( top + window.scrollY ) + 'px';
	}
	function hideTip() {
		if ( tip ) {
			tip.hidden = true;
		}
	}

	// Marcas con texto (barras, segmentos, celdas de la franja).
	function markTip( el, event ) {
		var text = el.getAttribute( 'data-gdp-tip' );
		if ( ! text ) {
			return;
		}
		var r = el.getBoundingClientRect();
		var x = event && event.clientX ? event.clientX : r.left + r.width / 2;
		showTip( [ { text: text } ], x, r.top );
	}
	document.addEventListener( 'pointerover', function ( event ) {
		var el = event.target.closest ? event.target.closest( '.gdp-fin [data-gdp-tip]' ) : null;
		if ( el ) {
			markTip( el, event );
		}
	} );
	document.addEventListener( 'pointerout', function ( event ) {
		var el = event.target.closest ? event.target.closest( '.gdp-fin [data-gdp-tip]' ) : null;
		if ( el && ! el.contains( event.relatedTarget ) ) {
			hideTip();
		}
	} );
	document.addEventListener( 'focusin', function ( event ) {
		var el = event.target.closest ? event.target.closest( '.gdp-fin [data-gdp-tip]' ) : null;
		if ( el ) {
			markTip( el, null );
		}
	} );
	document.addEventListener( 'focusout', function ( event ) {
		if ( event.target.closest && event.target.closest( '.gdp-fin [data-gdp-tip]' ) ) {
			hideTip();
		}
	} );

	// Curva de caja: una cruz vertical que salta al mes más cercano y un recuadro con las tres series.
	function initCurve( wrap ) {
		var conf;
		try {
			conf = JSON.parse( wrap.getAttribute( 'data-gdp-curve' ) || '{}' );
		} catch ( e ) {
			return;
		}
		var svg = wrap.querySelector( 'svg' );
		var cross = svg ? svg.querySelector( '.gdp-fin-line__cross' ) : null;
		if ( ! svg || ! cross || ! conf.n || conf.n < 2 ) {
			return;
		}
		var step = conf.width / ( conf.n - 1 );
		var current = -1;
		function place( i ) {
			current = Math.max( 0, Math.min( conf.n - 1, i ) );
			var x = conf.left + current * step;
			cross.setAttribute( 'x1', x );
			cross.setAttribute( 'x2', x );
			cross.setAttribute( 'visibility', 'visible' );
			var lines = [ { text: conf.months[ current ] } ];
			conf.series.forEach( function ( s ) {
				var v = s.values[ current ];
				var suffix = s.key === 'transfers' && typeof s.from === 'number' && current >= s.from ? ' (proyectado)' : '';
				lines.push( { key: s.key, value: v === null || v === undefined ? '—' : money( v ), text: s.label + suffix } );
			} );
			var box = svg.getBoundingClientRect();
			var scale = box.width / conf.w;
			showTip( lines, box.left + x * scale, box.top + 20 );
		}
		function fromPointer( event ) {
			var box = svg.getBoundingClientRect();
			var scale = conf.w / box.width;
			var x = ( event.clientX - box.left ) * scale;
			place( Math.round( ( x - conf.left ) / step ) );
		}
		function clear() {
			cross.setAttribute( 'visibility', 'hidden' );
			hideTip();
		}
		svg.addEventListener( 'pointermove', fromPointer );
		svg.addEventListener( 'pointerdown', fromPointer );
		svg.addEventListener( 'pointerleave', clear );
		wrap.addEventListener( 'focus', function () {
			place( current < 0 ? conf.n - 1 : current );
		} );
		wrap.addEventListener( 'blur', clear );
		wrap.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'ArrowLeft' || event.key === 'ArrowRight' ) {
				event.preventDefault();
				place( ( current < 0 ? conf.n - 1 : current ) + ( event.key === 'ArrowLeft' ? -1 : 1 ) );
			} else if ( event.key === 'Home' || event.key === 'End' ) {
				event.preventDefault();
				place( event.key === 'Home' ? 0 : conf.n - 1 );
			} else if ( event.key === 'Escape' ) {
				clear();
			}
		} );
	}

	// Planilla CSV con separador punto y coma (la que abre Excel en español) y marca de orden de bytes.
	function downloadCsv( spec ) {
		var csv = spec.rows.map( function ( row ) {
			return row.map( function ( cell ) {
				var text = cell === null || cell === undefined ? '' : String( cell );
				return /[";\n\r]/.test( text ) ? '"' + text.replace( /"/g, '""' ) + '"' : text;
			} ).join( ';' );
		} ).join( '\r\n' );
		var blob = new Blob( [ '﻿' + csv ], { type: 'text/csv;charset=utf-8' } );
		var url = URL.createObjectURL( blob );
		var a = document.createElement( 'a' );
		a.href = url;
		a.download = spec.name || 'datos.csv';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		window.setTimeout( function () {
			URL.revokeObjectURL( url );
		}, 1000 );
	}

	document.addEventListener( 'click', function ( event ) {
		var t = event.target;
		if ( ! t.closest ) {
			return;
		}
		var csv = t.closest( '.gdp-fin [data-gdp-csv]' );
		if ( csv ) {
			try {
				downloadCsv( JSON.parse( csv.getAttribute( 'data-gdp-csv' ) ) );
			} catch ( e ) {
				window.alert( e.message );
			}
			return;
		}
		var print = t.closest( '.gdp-fin [data-gdp-print]' );
		if ( print ) {
			if ( print.getAttribute( 'data-gdp-print' ) === 'report' ) {
				document.documentElement.classList.add( 'gdp-fin-printing-report' );
			}
			window.print();
			return;
		}
		// Un clic fuera de una ayuda abierta la cierra.
		document.querySelectorAll( '.gdp-fin details.gdp-fin-tip[open], .gdp-fin details.gdp-fin-help[open]' ).forEach( function ( d ) {
			if ( ! d.contains( t ) ) {
				d.removeAttribute( 'open' );
			}
		} );
	} );
	window.addEventListener( 'afterprint', function () {
		document.documentElement.classList.remove( 'gdp-fin-printing-report' );
	} );

	// Una sola ayuda breve abierta a la vez; Escape cierra la ayuda y el menú.
	document.addEventListener( 'toggle', function ( event ) {
		var d = event.target;
		if ( ! d.matches || ! d.matches( '.gdp-fin details.gdp-fin-tip' ) || ! d.open ) {
			return;
		}
		document.querySelectorAll( '.gdp-fin details.gdp-fin-tip[open]' ).forEach( function ( other ) {
			if ( other !== d ) {
				other.removeAttribute( 'open' );
			}
		} );
	}, true );
	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Escape' ) {
			return;
		}
		document.querySelectorAll( '.gdp-fin details.gdp-fin-tip[open], .gdp-fin details.gdp-fin-help[open]' ).forEach( function ( d ) {
			d.removeAttribute( 'open' );
			var s = d.querySelector( 'summary' );
			if ( s && d.contains( document.activeElement ) ) {
				s.focus();
			}
		} );
		hideTip();
	} );

	// Selector de tareas: va directo al elegir.
	document.addEventListener( 'change', function ( event ) {
		var select = event.target;
		if ( select.matches && select.matches( '.gdp-fin select[data-gdp-autosubmit]' ) && select.value && select.form ) {
			select.form.submit();
		}
	} );

	function init() {
		document.querySelectorAll( '.gdp-fin [data-gdp-curve]' ).forEach( initCurve );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
