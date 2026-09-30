/* global gdpPlanning */
/**
 * Planificación y tiempo: editor de predecesoras, carta Gantt (SVG propio) y tablero.
 */
( function () {
	'use strict';

	var cfg = window.gdpPlanning || {};
	var strings = cfg.strings || {};
	var DAY = 86400000;
	var SVG_NS = 'http://www.w3.org/2000/svg';

	/* ------------------------------------------------------------------ */
	/* Utilidades de fechas (todo en UTC para evitar saltos de horario).   */
	/* ------------------------------------------------------------------ */

	function parseDate( s ) {
		if ( ! s ) {
			return null;
		}
		var p = s.split( '-' );
		return new Date( Date.UTC( +p[0], +p[1] - 1, +p[2] ) );
	}

	function formatDate( d ) {
		var m = d.getUTCMonth() + 1;
		var day = d.getUTCDate();
		return d.getUTCFullYear() + '-' + ( m < 10 ? '0' : '' ) + m + '-' + ( day < 10 ? '0' : '' ) + day;
	}

	function addDays( d, n ) {
		return new Date( d.getTime() + n * DAY );
	}

	function diffDays( a, b ) {
		return Math.round( ( b.getTime() - a.getTime() ) / DAY );
	}

	function shortDate( s ) {
		if ( ! s ) {
			return '';
		}
		var p = s.split( '-' );
		return p[2] + '-' + p[1];
	}

	function makeCalendar( data ) {
		var weekdays = {};
		( data.weekdays || [ 1, 2, 3, 4, 5 ] ).forEach( function ( d ) {
			weekdays[ d ] = true;
		} );
		var exceptions = data.exceptions || {};
		return {
			isWorking: function ( d ) {
				var key = formatDate( d );
				if ( Object.prototype.hasOwnProperty.call( exceptions, key ) ) {
					return !! exceptions[ key ];
				}
				var n = d.getUTCDay();
				n = 0 === n ? 7 : n;
				return !! weekdays[ n ];
			},
			countWorking: function ( from, to ) {
				var count = 0;
				for ( var d = from; d.getTime() <= to.getTime(); d = addDays( d, 1 ) ) {
					if ( this.isWorking( d ) ) {
						count++;
					}
				}
				return count;
			},
			nextWorking: function ( d ) {
				var c = d;
				for ( var i = 0; i < 400; i++ ) {
					if ( this.isWorking( c ) ) {
						return c;
					}
					c = addDays( c, 1 );
				}
				return d;
			}
		};
	}

	function ajax( params, done, fail ) {
		var body = new window.FormData();
		body.append( 'action', 'gdp_planning' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'project_id', cfg.projectId );
		Object.keys( params ).forEach( function ( k ) {
			if ( null !== params[ k ] && undefined !== params[ k ] ) {
				body.append( k, params[ k ] );
			}
		} );
		window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json && json.success ) {
					done( json.data );
				} else {
					fail( json && json.data && json.data.message ? json.data.message : strings.error );
				}
			} )
			.catch( function () { fail( strings.error ); } );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( 'text' === k ) {
				node.textContent = attrs[ k ];
			} else if ( 'html' === k ) {
				node.innerHTML = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			node.appendChild( c );
		} );
		return node;
	}

	function svgEl( tag, attrs ) {
		var node = document.createElementNS( SVG_NS, tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		return node;
	}

	function cssVar( name, fallback ) {
		var wrap = document.querySelector( '.gdp-wrap' ) || document.body;
		var value = window.getComputedStyle( wrap ).getPropertyValue( name ).trim();
		return value || fallback;
	}

	/* ------------------------------------------------------------------ */
	/* Editor de predecesoras y campos según tipo (formulario).            */
	/* ------------------------------------------------------------------ */

	function initForm() {
		var table = document.getElementById( 'gdp-deps' );
		var add = document.getElementById( 'gdp-deps-add' );
		if ( table && add ) {
			add.addEventListener( 'click', function () {
				var template = table.querySelector( '.gdp-deps__row--template' );
				var row = template.cloneNode( true );
				row.classList.remove( 'gdp-deps__row--template' );
				template.parentNode.insertBefore( row, template );
			} );
			table.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '.gdp-deps__remove' );
				if ( button ) {
					var row = button.closest( 'tr' );
					if ( ! row.classList.contains( 'gdp-deps__row--template' ) ) {
						row.parentNode.removeChild( row );
					}
				}
			} );
		}

		var kind = document.getElementById( 'gdp-act-kind' );
		if ( kind ) {
			var sync = function () {
				var isSummary = 'summary' === kind.value;
				var isMilestone = 'milestone' === kind.value;
				document.querySelectorAll( '.gdp-act-leaf' ).forEach( function ( row ) {
					row.style.display = isSummary ? 'none' : '';
				} );
				var duration = document.getElementById( 'gdp-act-duration' );
				if ( duration ) {
					duration.disabled = isMilestone;
					if ( isMilestone ) {
						duration.value = 0;
					} else if ( '0' === duration.value ) {
						duration.value = 1;
					}
				}
			};
			kind.addEventListener( 'change', sync );
			sync();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Carta Gantt.                                                         */
	/* ------------------------------------------------------------------ */

	function Gantt( container, data ) {
		this.container = container;
		this.data = data;
		this.zoom = window.localStorage ? ( window.localStorage.getItem( 'gdpGanttZoom' ) || 'week' ) : 'week';
		this.collapsed = {};
		this.rowH = 28;
		this.headerH = 44;
		this.colors = {
			primary: cssVar( '--gdp-primary', '#2271b1' ),
			secondary: cssVar( '--gdp-secondary', '#1d2327' ),
			accent: cssVar( '--gdp-accent', '#d63638' )
		};
		this.options = {
			baseline: true,
			critical: true,
			links: true
		};
		this.buildToolbar();
		this.render();
	}

	Gantt.prototype.pxPerDay = function () {
		return { day: 28, week: 9, month: 3 }[ this.zoom ] || 9;
	};

	Gantt.prototype.buildToolbar = function () {
		var self = this;
		var zoomBox = document.querySelector( '.gdp-gantt-zoom' );
		if ( zoomBox ) {
			zoomBox.innerHTML = '';
			[ [ 'day', strings.zoomDay ], [ 'week', strings.zoomWeek ], [ 'month', strings.zoomMonth ] ].forEach( function ( z ) {
				var b = el( 'button', { type: 'button', 'class': 'button button-small' + ( z[0] === self.zoom ? ' is-active' : '' ), text: z[1] } );
				b.addEventListener( 'click', function () {
					self.zoom = z[0];
					if ( window.localStorage ) {
						window.localStorage.setItem( 'gdpGanttZoom', z[0] );
					}
					zoomBox.querySelectorAll( '.button' ).forEach( function ( x ) { x.classList.remove( 'is-active' ); } );
					b.classList.add( 'is-active' );
					self.render();
				} );
				zoomBox.appendChild( b );
			} );
		}
		[ 'baseline', 'critical', 'links' ].forEach( function ( key ) {
			var box = document.getElementById( 'gdp-gantt-' + key );
			if ( box ) {
				self.options[ key ] = box.checked;
				box.addEventListener( 'change', function () {
					self.options[ key ] = box.checked;
					self.render();
				} );
			}
		} );
	};

	Gantt.prototype.setStatus = function ( text ) {
		var box = document.querySelector( '.gdp-gantt-status' );
		if ( box ) {
			box.textContent = text || '';
		}
	};

	Gantt.prototype.visibleRows = function () {
		var self = this;
		var hidden = {};
		var rows = [];
		this.data.activities.forEach( function ( a ) {
			if ( hidden[ a.parent ] ) {
				hidden[ a.id ] = true;
				return;
			}
			if ( self.collapsed[ a.id ] ) {
				hidden[ a.id ] = true; // Los hijos se ocultan; el propio resumen se muestra.
				rows.push( a );
				return;
			}
			rows.push( a );
		} );
		return rows;
	};

	Gantt.prototype.range = function () {
		var min = null;
		var max = null;
		var consider = function ( s ) {
			var d = parseDate( s );
			if ( ! d ) {
				return;
			}
			if ( ! min || d < min ) {
				min = d;
			}
			if ( ! max || d > max ) {
				max = d;
			}
		};
		this.data.activities.forEach( function ( a ) {
			consider( a.start );
			consider( a.end );
			consider( a.baseStart );
			consider( a.baseEnd );
		} );
		consider( this.data.today );
		if ( this.data.project ) {
			consider( this.data.project.deadline_date );
		}
		if ( ! min ) {
			min = parseDate( this.data.today );
			max = addDays( min, 30 );
		}
		min = addDays( min, -7 );
		max = addDays( max, 14 );
		// Alinear al lunes.
		var n = min.getUTCDay();
		min = addDays( min, -( ( n + 6 ) % 7 ) );
		return { from: min, to: max, days: diffDays( min, max ) + 1 };
	};

	Gantt.prototype.render = function () {
		var self = this;
		var rows = this.visibleRows();
		var range = this.range();
		var ppd = this.pxPerDay();
		var width = range.days * ppd;
		var height = this.headerH + rows.length * this.rowH;
		var calendar = makeCalendar( this.data.calendar || {} );
		var index = {};
		this.data.activities.forEach( function ( a ) { index[ a.id ] = a; } );
		var rowIndex = {};
		rows.forEach( function ( a, i ) { rowIndex[ a.id ] = i; } );

		this.container.innerHTML = '';

		/* Tabla izquierda. */
		var table = el( 'table' );
		var thead = el( 'thead' );
		thead.appendChild( el( 'tr', {}, [
			el( 'th', { 'class': 'gdp-gantt__col-code', text: '#' } ),
			el( 'th', { text: strings.activity || 'Actividad' } ),
			el( 'th', { 'class': 'gdp-gantt__col-date', text: strings.start || 'Inicio' } ),
			el( 'th', { 'class': 'gdp-gantt__col-date', text: strings.end || 'Término' } ),
			el( 'th', { 'class': 'gdp-gantt__col-pct', text: '%' } )
		] ) );
		table.appendChild( thead );
		var tbody = el( 'tbody' );
		rows.forEach( function ( a ) {
			var tr = el( 'tr', { 'class': 'gdp-gantt__row gdp-gantt__row--' + a.kind } );
			tr.appendChild( el( 'td', { 'class': 'gdp-gantt__col-code', html: '<code>' + a.code + '</code>' } ) );
			var name = el( 'td', { 'class': 'gdp-gantt__name' } );
			name.appendChild( el( 'span', { 'class': 'gdp-wbs__indent', style: '--gdp-level: ' + a.level } ) );
			if ( 'summary' === a.kind ) {
				var toggle = el( 'span', { 'class': 'gdp-gantt__toggle', text: self.collapsed[ a.id ] ? '▸' : '▾', title: self.collapsed[ a.id ] ? strings.expand : strings.collapse } );
				toggle.addEventListener( 'click', function () {
					self.collapsed[ a.id ] = ! self.collapsed[ a.id ];
					self.render();
				} );
				name.appendChild( toggle );
			} else {
				name.appendChild( el( 'span', { 'class': 'gdp-gantt__toggle' } ) );
			}
			if ( cfg.canEdit && cfg.editUrl ) {
				name.appendChild( el( 'a', { href: cfg.editUrl.replace( /([?&])id=0/, '$1id=' + a.id ), text: a.name, title: a.name } ) );
			} else {
				name.appendChild( el( 'span', { text: a.name, title: a.name } ) );
			}
			tr.appendChild( name );
			tr.appendChild( el( 'td', { 'class': 'gdp-gantt__col-date', text: 'milestone' === a.kind ? '' : ( a.start || '' ) } ) );
			tr.appendChild( el( 'td', { 'class': 'gdp-gantt__col-date', text: a.end || '' } ) );
			tr.appendChild( el( 'td', { 'class': 'gdp-gantt__col-pct', text: a.percent + ' %' } ) );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		var left = el( 'div', { 'class': 'gdp-gantt__table' }, [ table ] );

		/* Gráfico. */
		var chart = el( 'div', { 'class': 'gdp-gantt__chart' } );
		var svg = svgEl( 'svg', { width: width, height: height, viewBox: '0 0 ' + width + ' ' + height } );
		var defs = svgEl( 'defs' );
		var marker = svgEl( 'marker', { id: 'gdp-arrow', viewBox: '0 0 10 10', refX: '9', refY: '5', markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse' } );
		marker.appendChild( svgEl( 'path', { d: 'M 0 0 L 10 5 L 0 10 z', fill: '#646970' } ) );
		defs.appendChild( marker );
		svg.appendChild( defs );

		var x = function ( d ) {
			return diffDays( range.from, d ) * ppd;
		};

		/* Fondo: días no laborables y cuadrícula. */
		var bg = svgEl( 'g' );
		for ( var i = 0; i < range.days; i++ ) {
			var d = addDays( range.from, i );
			if ( ! calendar.isWorking( d ) ) {
				bg.appendChild( svgEl( 'rect', { x: i * ppd, y: this.headerH, width: ppd, height: height - this.headerH, fill: '#f3f4f5' } ) );
			}
			if ( 1 === d.getUTCDay() && ppd >= 3 ) {
				bg.appendChild( svgEl( 'line', { x1: i * ppd, x2: i * ppd, y1: this.headerH, y2: height, stroke: '#e5e5e5', 'stroke-width': 1 } ) );
			}
		}
		rows.forEach( function ( a, r ) {
			var y = self.headerH + ( r + 1 ) * self.rowH - 0.5;
			bg.appendChild( svgEl( 'line', { x1: 0, x2: width, y1: y, y2: y, stroke: '#f0f0f1' } ) );
		} );
		svg.appendChild( bg );

		/* Cabecera: meses y días o semanas. */
		var header = svgEl( 'g' );
		header.appendChild( svgEl( 'rect', { x: 0, y: 0, width: width, height: this.headerH, fill: '#f6f7f7' } ) );
		header.appendChild( svgEl( 'line', { x1: 0, x2: width, y1: this.headerH - 0.5, y2: this.headerH - 0.5, stroke: '#dcdcde' } ) );
		var monthNames = [ 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' ];
		var cursor = range.from;
		while ( cursor <= range.to ) {
			var monthEnd = new Date( Date.UTC( cursor.getUTCFullYear(), cursor.getUTCMonth() + 1, 0 ) );
			var last = monthEnd < range.to ? monthEnd : range.to;
			var x0 = x( cursor );
			var x1 = x( last ) + ppd;
			header.appendChild( svgEl( 'line', { x1: x0, x2: x0, y1: 0, y2: this.headerH, stroke: '#dcdcde' } ) );
			if ( x1 - x0 > 30 ) {
				var t = svgEl( 'text', { x: x0 + 4, y: 15, 'font-size': 11, fill: '#1d2327', 'font-weight': 600 } );
				t.textContent = monthNames[ cursor.getUTCMonth() ] + ' ' + cursor.getUTCFullYear();
				header.appendChild( t );
			}
			cursor = addDays( last, 1 );
		}
		if ( 'day' === this.zoom ) {
			for ( var k = 0; k < range.days; k++ ) {
				var dd = addDays( range.from, k );
				var tx = svgEl( 'text', { x: k * ppd + ppd / 2, y: 34, 'font-size': 10, fill: '#50575e', 'text-anchor': 'middle' } );
				tx.textContent = dd.getUTCDate();
				header.appendChild( tx );
			}
		} else {
			for ( var w = 0; w < range.days; w += 7 ) {
				var wd = addDays( range.from, w );
				var wt = svgEl( 'text', { x: w * ppd + 3, y: 34, 'font-size': 10, fill: '#50575e' } );
				wt.textContent = 'week' === this.zoom ? shortDate( formatDate( wd ) ) : String( wd.getUTCDate() );
				header.appendChild( wt );
			}
		}
		svg.appendChild( header );

		/* Barras. */
		var bars = svgEl( 'g' );
		var geometry = {};
		rows.forEach( function ( a, r ) {
			var y = self.headerH + r * self.rowH;
			var start = parseDate( a.start );
			var end = parseDate( a.end );
			if ( ! start || ! end ) {
				return;
			}
			var bx = x( start );
			var bw = Math.max( ppd, x( end ) + ppd - bx );
			var critical = self.options.critical && a.critical && 'summary' !== a.kind;
			var color = critical ? self.colors.accent : self.colors.primary;
			var g = svgEl( 'g', { 'class': 'gdp-gantt-bar' + ( a.fixed ? ' gdp-gantt-bar--fixed' : '' ), 'data-id': a.id } );

			if ( self.options.baseline && a.baseStart && a.baseEnd ) {
				var bs = parseDate( a.baseStart );
				var be = parseDate( a.baseEnd );
				bars.appendChild( svgEl( 'rect', { x: x( bs ), y: y + self.rowH - 6, width: Math.max( 2, x( be ) + ppd - x( bs ) ), height: 3, fill: '#a7aaad' } ) );
			}

			if ( 'summary' === a.kind ) {
				g.appendChild( svgEl( 'rect', { x: bx, y: y + 7, width: bw, height: 6, fill: self.colors.secondary } ) );
				g.appendChild( svgEl( 'path', { d: 'M ' + bx + ' ' + ( y + 7 ) + ' l 0 10 l 5 -5 z', fill: self.colors.secondary } ) );
				g.appendChild( svgEl( 'path', { d: 'M ' + ( bx + bw ) + ' ' + ( y + 7 ) + ' l 0 10 l -5 -5 z', fill: self.colors.secondary } ) );
				geometry[ a.id ] = { x1: bx, x2: bx + bw, y: y + 10 };
			} else if ( 'milestone' === a.kind ) {
				var mx = x( end ) + ppd / 2;
				var my = y + self.rowH / 2;
				g.appendChild( svgEl( 'path', { d: 'M ' + mx + ' ' + ( my - 7 ) + ' l 7 7 l -7 7 l -7 -7 z', fill: critical ? self.colors.accent : self.colors.secondary } ) );
				if ( a.percent >= 100 ) {
					g.appendChild( svgEl( 'path', { d: 'M ' + ( mx - 3 ) + ' ' + my + ' l 2 2 l 4 -4', stroke: '#fff', 'stroke-width': 1.5, fill: 'none' } ) );
				}
				geometry[ a.id ] = { x1: mx - 7, x2: mx + 7, y: my };
			} else {
				g.appendChild( svgEl( 'rect', { x: bx, y: y + 6, width: bw, height: 16, fill: color, 'fill-opacity': 0.35, rx: 0 } ) );
				g.appendChild( svgEl( 'rect', { x: bx, y: y + 6, width: bw * Math.min( 100, a.percent ) / 100, height: 16, fill: color } ) );
				if ( bw > 40 ) {
					var label = svgEl( 'text', { x: bx + 4, y: y + 18, 'font-size': 10, fill: a.percent > 30 ? '#fff' : '#1d2327' } );
					label.textContent = a.name;
					g.appendChild( label );
				}
				if ( cfg.canEdit && ! a.fixed ) {
					g.appendChild( svgEl( 'rect', { 'class': 'gdp-gantt-handle', x: bx + bw - 6, y: y + 6, width: 6, height: 16 } ) );
				}
				geometry[ a.id ] = { x1: bx, x2: bx + bw, y: y + 14 };
			}
			if ( 'asap' !== a.constraint && 'summary' !== a.kind ) {
				g.appendChild( svgEl( 'circle', { cx: bx - 5, cy: y + self.rowH / 2, r: 2.5, fill: '#646970' } ) );
			}
			g.addEventListener( 'mouseenter', function ( event ) { self.tooltip( chart, a, event ); } );
			g.addEventListener( 'mousemove', function ( event ) { self.tooltip( chart, a, event ); } );
			g.addEventListener( 'mouseleave', function () { self.tooltip( chart, null ); } );
			if ( cfg.canEdit && 'summary' !== a.kind ) {
				self.enableDrag( g, a, ppd, calendar, chart );
			}
			bars.appendChild( g );
		} );

		/* Dependencias. */
		if ( this.options.links ) {
			var links = svgEl( 'g' );
			this.data.dependencies.forEach( function ( dep ) {
				var from = geometry[ dep.from ];
				var to = geometry[ dep.to ];
				if ( ! from || ! to ) {
					return;
				}
				var sx = 'SS' === dep.type || 'SF' === dep.type ? from.x1 : from.x2;
				var ex = 'FF' === dep.type || 'SF' === dep.type ? to.x2 : to.x1;
				var sy = from.y;
				var ey = to.y;
				var dirIn = ( 'FF' === dep.type || 'SF' === dep.type ) ? 1 : -1; // Lado por el que entra la flecha.
				var mid = ex + dirIn * 8;
				var path;
				if ( -1 === dirIn && sx + 8 <= ex - 8 ) {
					path = 'M ' + sx + ' ' + sy + ' H ' + ( sx + 8 ) + ' V ' + ey + ' H ' + ex;
				} else {
					var rowGap = ey > sy ? sy + self.rowH / 2 : sy - self.rowH / 2;
					path = 'M ' + sx + ' ' + sy + ' H ' + ( sx + 8 ) + ' V ' + rowGap + ' H ' + mid + ' V ' + ey + ' H ' + ex;
				}
				var critical = self.options.critical && index[ dep.from ] && index[ dep.to ] && index[ dep.from ].critical && index[ dep.to ].critical;
				links.appendChild( svgEl( 'path', { d: path, fill: 'none', stroke: critical ? self.colors.accent : '#646970', 'stroke-width': 1, 'marker-end': 'url(#gdp-arrow)' } ) );
			} );
			svg.appendChild( links );
		}
		svg.appendChild( bars );

		/* Hoy y término contractual. */
		var today = parseDate( this.data.today );
		if ( today ) {
			var tx0 = x( today ) + ppd / 2;
			svg.appendChild( svgEl( 'line', { x1: tx0, x2: tx0, y1: this.headerH, y2: height, stroke: this.colors.accent, 'stroke-width': 1.5, 'stroke-dasharray': '4 3' } ) );
			var tl = svgEl( 'text', { x: tx0 + 3, y: height - 4, 'font-size': 10, fill: this.colors.accent } );
			tl.textContent = strings.today;
			svg.appendChild( tl );
		}
		if ( this.data.project && this.data.project.deadline_date ) {
			var dl = x( parseDate( this.data.project.deadline_date ) ) + ppd;
			svg.appendChild( svgEl( 'line', { x1: dl, x2: dl, y1: this.headerH, y2: height, stroke: this.colors.secondary, 'stroke-width': 1.5 } ) );
		}

		chart.appendChild( svg );
		this.container.appendChild( left );
		this.container.appendChild( chart );

		// Desplazamiento vertical sincronizado y posición inicial cerca de hoy.
		chart.addEventListener( 'scroll', function () {
			left.scrollTop = chart.scrollTop;
		} );
		if ( today && ! this.scrolled ) {
			this.scrolled = true;
			chart.scrollLeft = Math.max( 0, x( today ) - 200 );
		}
	};

	Gantt.prototype.tooltip = function ( chart, a, event ) {
		var box = chart.querySelector( '.gdp-gantt-tooltip' );
		if ( ! a ) {
			if ( box ) {
				box.parentNode.removeChild( box );
			}
			return;
		}
		if ( ! box ) {
			box = el( 'div', { 'class': 'gdp-gantt-tooltip' } );
			chart.appendChild( box );
		}
		var lines = [ a.code + ' ' + a.name ];
		if ( 'milestone' === a.kind ) {
			lines.push( a.end || strings.noDates );
		} else {
			lines.push( ( a.start || '' ) + ' → ' + ( a.end || '' ) + ' (' + a.duration + ' ' + strings.days + ')' );
		}
		lines.push( a.percent + ' %' + ( a.owner ? ' · ' + a.owner : '' ) + ( a.front ? ' · ' + a.front : '' ) );
		if ( 'summary' !== a.kind && null !== a.float && undefined !== a.float ) {
			lines.push( strings.float + ': ' + a.float + ' ' + strings.days + ( a.critical ? ' · ' + strings.critical : '' ) );
		}
		if ( a.baseStart && a.baseEnd ) {
			lines.push( strings.baseline + ': ' + a.baseStart + ' → ' + a.baseEnd );
		}
		if ( a.conflicts && a.conflicts.length ) {
			lines.push( a.conflicts.join( ' ' ) );
		}
		if ( a.fixed ) {
			lines.push( strings.fixed );
		}
		box.textContent = lines.join( '\n' );
		var rect = chart.getBoundingClientRect();
		box.style.left = ( event.clientX - rect.left + chart.scrollLeft + 14 ) + 'px';
		box.style.top = ( event.clientY - rect.top + chart.scrollTop + 14 ) + 'px';
	};

	Gantt.prototype.enableDrag = function ( g, a, ppd, calendar, chart ) {
		var self = this;
		var startX = null;
		var mode = null;
		var dx = 0;
		g.addEventListener( 'pointerdown', function ( event ) {
			if ( a.fixed ) {
				self.setStatus( strings.fixed );
				return;
			}
			mode = event.target.classList.contains( 'gdp-gantt-handle' ) ? 'resize' : 'move';
			if ( 'resize' === mode && 'activity' !== a.kind ) {
				return;
			}
			startX = event.clientX;
			dx = 0;
			g.classList.add( 'is-dragging' );
			g.setPointerCapture( event.pointerId );
			event.preventDefault();
		} );
		g.addEventListener( 'pointermove', function ( event ) {
			if ( null === startX ) {
				return;
			}
			dx = event.clientX - startX;
			var days = Math.round( dx / ppd );
			if ( 'move' === mode ) {
				g.setAttribute( 'transform', 'translate(' + days * ppd + ',0)' );
				self.setStatus( a.code + ' → ' + formatDate( addDays( parseDate( 'milestone' === a.kind ? a.end : a.start ), days ) ) );
			} else {
				var rects = g.querySelectorAll( 'rect' );
				var newEnd = addDays( parseDate( a.end ), days );
				var duration = Math.max( 1, calendar.countWorking( parseDate( a.start ), newEnd ) );
				self.setStatus( a.code + ': ' + duration + ' ' + strings.days );
				var w = Math.max( ppd, ( diffDays( parseDate( a.start ), newEnd ) + 1 ) * ppd );
				rects[0].setAttribute( 'width', w );
				rects[1].setAttribute( 'width', w * Math.min( 100, a.percent ) / 100 );
			}
		} );
		var finish = function ( event ) {
			if ( null === startX ) {
				return;
			}
			g.classList.remove( 'is-dragging' );
			try {
				g.releasePointerCapture( event.pointerId );
			} catch ( e ) {
				// Sin captura activa.
			}
			var days = Math.round( dx / ppd );
			startX = null;
			if ( 0 === days ) {
				self.render();
				return;
			}
			self.setStatus( strings.saving );
			var params = { activity_id: a.id, version: a.version };
			if ( 'move' === mode ) {
				params.op = 'move';
				params.start = formatDate( addDays( parseDate( 'milestone' === a.kind ? a.end : a.start ), days ) );
			} else {
				params.op = 'resize';
				params.duration = Math.max( 1, calendar.countWorking( parseDate( a.start ), addDays( parseDate( a.end ), days ) ) );
			}
			ajax( params, function ( data ) {
				self.data = data;
				self.setStatus( '' );
				self.render();
			}, function ( message ) {
				self.setStatus( message );
				self.render();
			} );
		};
		g.addEventListener( 'pointerup', finish );
		g.addEventListener( 'pointercancel', finish );
		g.addEventListener( 'dblclick', function () {
			if ( 'asap' !== a.constraint && window.confirm( ( strings.clear || '%s?' ).replace( '%s', a.code ) ) ) {
				ajax( { op: 'clear_constraint', activity_id: a.id, version: a.version }, function ( data ) {
					self.data = data;
					self.render();
				}, function ( message ) {
					self.setStatus( message );
				} );
			}
		} );
	};

	/* ------------------------------------------------------------------ */
	/* Tablero.                                                             */
	/* ------------------------------------------------------------------ */

	function Board( container, data ) {
		this.container = container;
		this.data = data;
		this.render();
	}

	Board.prototype.render = function () {
		var self = this;
		var statuses = this.data.statuses || {};
		var today = this.data.today;
		this.container.innerHTML = '';
		Object.keys( statuses ).forEach( function ( status ) {
			var cards = self.data.activities.filter( function ( a ) { return 'summary' !== a.kind && a.status === status; } );
			var column = el( 'div', { 'class': 'gdp-board__column', 'data-status': status } );
			column.appendChild( el( 'h3', { 'class': 'gdp-board__title', html: '<span>' + statuses[ status ] + '</span><span>' + cards.length + '</span>' } ) );
			cards.forEach( function ( a ) {
				var overdue = a.end && a.end < today && 'terminada' !== status && 'cancelada' !== status;
				var card = el( 'div', { 'class': 'gdp-board__card' + ( a.critical ? ' gdp-board__card--critical' : '' ) + ( 'milestone' === a.kind ? ' gdp-board__card--milestone' : '' ), draggable: cfg.canEdit ? 'true' : 'false', 'data-id': a.id } );
				card.appendChild( el( 'code', { text: a.code } ) );
				var title = cfg.canEdit && cfg.editUrl ? el( 'a', { href: cfg.editUrl.replace( /([?&])id=0/, '$1id=' + a.id ), text: a.name } ) : el( 'span', { text: a.name } );
				card.appendChild( el( 'strong', {}, [ title ] ) );
				var meta = [];
				if ( a.end ) {
					meta.push( ( 'milestone' === a.kind ? '◆ ' : '' ) + a.end );
				}
				meta.push( a.percent + ' %' );
				if ( a.owner ) {
					meta.push( a.owner );
				}
				if ( a.front ) {
					meta.push( a.front );
				}
				card.appendChild( el( 'div', { 'class': 'gdp-board__meta' + ( overdue ? ' gdp-board__overdue' : '' ), text: meta.join( ' · ' ) } ) );
				if ( cfg.canEdit ) {
					card.addEventListener( 'dragstart', function ( event ) {
						event.dataTransfer.setData( 'text/plain', String( a.id ) );
						event.dataTransfer.effectAllowed = 'move';
						card.classList.add( 'is-dragging' );
					} );
					card.addEventListener( 'dragend', function () {
						card.classList.remove( 'is-dragging' );
					} );
				}
				column.appendChild( card );
			} );
			if ( cfg.canEdit ) {
				column.addEventListener( 'dragover', function ( event ) {
					event.preventDefault();
					event.dataTransfer.dropEffect = 'move';
					column.classList.add( 'is-over' );
				} );
				column.addEventListener( 'dragleave', function () {
					column.classList.remove( 'is-over' );
				} );
				column.addEventListener( 'drop', function ( event ) {
					event.preventDefault();
					column.classList.remove( 'is-over' );
					var id = parseInt( event.dataTransfer.getData( 'text/plain' ), 10 );
					var activity = self.data.activities.filter( function ( a ) { return a.id === id; } )[0];
					if ( ! activity || activity.status === status ) {
						return;
					}
					ajax( { op: 'status', activity_id: id, status: status, version: activity.version }, function ( data ) {
						self.data = data;
						self.render();
					}, function ( message ) {
						window.alert( message );
					} );
				} );
			}
			self.container.appendChild( column );
		} );
	};

	/* ------------------------------------------------------------------ */
	/* Arranque.                                                            */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		initForm();
		var gantt = document.getElementById( 'gdp-gantt' );
		if ( gantt && cfg.data ) {
			new Gantt( gantt, cfg.data );
		}
		var board = document.getElementById( 'gdp-board' );
		if ( board && cfg.data ) {
			new Board( board, cfg.data );
		}
	} );
}() );
