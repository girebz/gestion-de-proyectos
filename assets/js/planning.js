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
		return { day: 28, week: 9, month: 3, quarter: 1.2 }[ this.zoom ] || 9;
	};

	Gantt.prototype.buildToolbar = function () {
		var self = this;
		var zoomBox = document.querySelector( '.gdp-gantt-zoom' );
		if ( zoomBox ) {
			zoomBox.innerHTML = '';
			[ [ 'day', strings.zoomDay ], [ 'week', strings.zoomWeek ], [ 'month', strings.zoomMonth ], [ 'quarter', strings.zoomQuarter ] ].forEach( function ( z ) {
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

		// Filtros: solo críticas, frente y responsable.
		this.filters = { critical: false, front: '', owner: '' };
		var onlyCritical = document.getElementById( 'gdp-gantt-only-critical' );
		if ( onlyCritical ) {
			onlyCritical.addEventListener( 'change', function () {
				self.filters.critical = onlyCritical.checked;
				self.render();
			} );
		}
		var fillSelect = function ( id, key, values, allLabel ) {
			var select = document.getElementById( id );
			if ( ! select ) {
				return;
			}
			select.innerHTML = '';
			select.appendChild( el( 'option', { value: '', text: allLabel } ) );
			( values || [] ).forEach( function ( v ) {
				select.appendChild( el( 'option', { value: v, text: v } ) );
			} );
			select.addEventListener( 'change', function () {
				self.filters[ key ] = select.value;
				self.render();
			} );
		};
		fillSelect( 'gdp-gantt-front', 'front', this.data.fronts, strings.allFronts );
		fillSelect( 'gdp-gantt-owner', 'owner', this.data.owners, strings.allOwners );

		var print = document.getElementById( 'gdp-gantt-print' );
		if ( print ) {
			print.addEventListener( 'click', function () {
				var previous = self.zoom;
				var svg = self.container.querySelector( 'svg' );
				// Un gráfico más ancho que dos páginas se imprime en escala de mes.
				if ( svg && +svg.getAttribute( 'width' ) > 2200 && 'day' !== previous ) {
					self.zoom = 'month';
					self.render();
				} else if ( 'day' === previous ) {
					self.zoom = 'week';
					self.render();
				}
				window.setTimeout( function () {
					window.print();
					self.zoom = previous;
					self.render();
				}, 150 );
			} );
		}
	};

	Gantt.prototype.setStatus = function ( text ) {
		var box = document.querySelector( '.gdp-gantt-status' );
		if ( box ) {
			box.textContent = text || '';
		}
	};

	Gantt.prototype.matchesFilters = function ( a ) {
		var f = this.filters || {};
		if ( f.critical && ! a.critical ) {
			return false;
		}
		if ( f.front && a.front !== f.front ) {
			return false;
		}
		if ( f.owner && a.owner !== f.owner ) {
			return false;
		}
		return true;
	};

	Gantt.prototype.visibleRows = function () {
		var self = this;
		var index = {};
		this.data.activities.forEach( function ( a ) { index[ a.id ] = a; } );

		// Con filtros activos se muestran las hojas que coinciden y sus resúmenes ancestros.
		var filtering = this.filters && ( this.filters.critical || this.filters.front || this.filters.owner );
		var keep = {};
		if ( filtering ) {
			this.data.activities.forEach( function ( a ) {
				if ( 'summary' !== a.kind && self.matchesFilters( a ) ) {
					keep[ a.id ] = true;
					var p = a.parent;
					while ( p && index[ p ] ) {
						keep[ p ] = true;
						p = index[ p ].parent;
					}
				}
			} );
		}

		var hidden = {};
		var rows = [];
		this.data.activities.forEach( function ( a ) {
			if ( filtering && ! keep[ a.id ] ) {
				return;
			}
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
		this.currentRows = rows;

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

		// La altura real de las filas depende de la hoja de estilos del contexto (panel o tema del sitio):
		// se mide una vez dibujada la tabla para que las barras queden alineadas con sus filas.
		this.container.appendChild( left );
		var headRow = thead.firstChild ? thead.firstChild.getBoundingClientRect().height : 0;
		var bodyRow = tbody.firstChild ? tbody.firstChild.getBoundingClientRect().height : 0;
		if ( headRow > 0 && bodyRow > 0 ) {
			this.headerH = Math.round( headRow );
			this.rowH = Math.round( bodyRow );
			height = this.headerH + rows.length * this.rowH;
		}

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
		var isQuarter = 'quarter' === this.zoom;
		while ( cursor <= range.to ) {
			var periodEnd = isQuarter
				? new Date( Date.UTC( cursor.getUTCFullYear(), Math.floor( cursor.getUTCMonth() / 3 ) * 3 + 3, 0 ) )
				: new Date( Date.UTC( cursor.getUTCFullYear(), cursor.getUTCMonth() + 1, 0 ) );
			var last = periodEnd < range.to ? periodEnd : range.to;
			var x0 = x( cursor );
			var x1 = x( last ) + ppd;
			header.appendChild( svgEl( 'line', { x1: x0, x2: x0, y1: 0, y2: isQuarter ? 22 : this.headerH, stroke: '#dcdcde' } ) );
			if ( x1 - x0 > 30 ) {
				var t = svgEl( 'text', { x: x0 + 4, y: 15, 'font-size': 11, fill: '#1d2327', 'font-weight': 600 } );
				t.textContent = isQuarter
					? 'T' + ( Math.floor( cursor.getUTCMonth() / 3 ) + 1 ) + ' ' + cursor.getUTCFullYear()
					: monthNames[ cursor.getUTCMonth() ] + ' ' + cursor.getUTCFullYear();
				header.appendChild( t );
			}
			cursor = addDays( last, 1 );
		}
		if ( isQuarter ) {
			var mc = range.from;
			while ( mc <= range.to ) {
				var mEnd = new Date( Date.UTC( mc.getUTCFullYear(), mc.getUTCMonth() + 1, 0 ) );
				var mLast = mEnd < range.to ? mEnd : range.to;
				var mx0 = x( mc );
				header.appendChild( svgEl( 'line', { x1: mx0, x2: mx0, y1: 22, y2: this.headerH, stroke: '#e5e5e5' } ) );
				if ( x( mLast ) + ppd - mx0 > 18 ) {
					var mt = svgEl( 'text', { x: mx0 + 2, y: 34, 'font-size': 9, fill: '#50575e' } );
					mt.textContent = monthNames[ mc.getUTCMonth() ];
					header.appendChild( mt );
				}
				mc = addDays( mLast, 1 );
			}
		} else if ( 'day' === this.zoom ) {
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
			if ( cfg.canEdit && 'summary' !== a.kind ) {
				// Conector para trazar dependencias fin a inicio hacia otra actividad.
				var cxp = geometry[ a.id ].x2 + 6;
				g.appendChild( svgEl( 'circle', { 'class': 'gdp-gantt-connector', cx: cxp, cy: geometry[ a.id ].y, r: 4, 'data-from': a.id } ) );
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
				var line = svgEl( 'path', { 'class': 'gdp-gantt-link', d: path, fill: 'none', stroke: critical ? self.colors.accent : '#646970', 'stroke-width': 1, 'marker-end': 'url(#gdp-arrow)' } );
				if ( cfg.canEdit ) {
					// Zona de pulsación más ancha, invisible, para quitar la dependencia.
					var hit = svgEl( 'path', { 'class': 'gdp-gantt-link-hit', d: path, fill: 'none', stroke: 'transparent', 'stroke-width': 8 } );
					hit.addEventListener( 'click', function () {
						var label = ( index[ dep.from ] ? index[ dep.from ].code : dep.from ) + ' → ' + ( index[ dep.to ] ? index[ dep.to ].code : dep.to ) + ' (' + dep.type + ( dep.lag ? ( dep.lag > 0 ? '+' : '' ) + dep.lag : '' ) + ')';
						if ( window.confirm( ( strings.unlink || '%s?' ).replace( '%s', label ) ) ) {
							self.setStatus( strings.saving );
							ajax( { op: 'unlink', from_id: dep.from, to_id: dep.to }, function ( data ) {
								self.data = data;
								self.setStatus( '' );
								self.render();
							}, function ( message ) {
								self.setStatus( message );
							} );
						}
					} );
					links.appendChild( hit );
				}
				links.appendChild( line );
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
			if ( event.target.classList.contains( 'gdp-gantt-connector' ) ) {
				self.startLink( a, event, chart );
				event.preventDefault();
				event.stopPropagation();
				return;
			}
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

	/**
	 * Traza una dependencia arrastrando desde el conector de una barra hasta otra barra.
	 */
	Gantt.prototype.startLink = function ( from, event, chart ) {
		var self = this;
		var svg = chart.querySelector( 'svg' );
		var rect = svg.getBoundingClientRect();
		var origin = { x: event.clientX - rect.left, y: event.clientY - rect.top };
		var line = svgEl( 'line', { x1: origin.x, y1: origin.y, x2: origin.x, y2: origin.y, stroke: self.colors.primary, 'stroke-width': 1.5, 'stroke-dasharray': '4 3', 'pointer-events': 'none' } );
		svg.appendChild( line );
		self.setStatus( from.code + ' → ' + strings.linkTo );
		var target = null;

		var move = function ( e ) {
			var px = e.clientX - rect.left;
			var py = e.clientY - rect.top;
			line.setAttribute( 'x2', px );
			line.setAttribute( 'y2', py );
			// El destino es la fila bajo el puntero (basta soltar sobre la fila, no sobre la barra).
			var row = Math.floor( ( py - self.headerH ) / self.rowH );
			var candidate = self.currentRows && row >= 0 ? self.currentRows[ row ] : null;
			target = candidate && 'summary' !== candidate.kind && candidate.id !== from.id ? candidate.id : null;
			chart.querySelectorAll( '.gdp-gantt-bar.is-target' ).forEach( function ( b ) { b.classList.remove( 'is-target' ); } );
			if ( target ) {
				var bar = chart.querySelector( '.gdp-gantt-bar[data-id="' + target + '"]' );
				if ( bar ) {
					bar.classList.add( 'is-target' );
				}
				self.setStatus( from.code + ' → ' + candidate.code );
			} else {
				self.setStatus( from.code + ' → ' + strings.linkTo );
			}
		};
		var finish = function () {
			document.removeEventListener( 'pointermove', move );
			document.removeEventListener( 'pointerup', finish );
			if ( line.parentNode ) {
				line.parentNode.removeChild( line );
			}
			chart.querySelectorAll( '.gdp-gantt-bar.is-target' ).forEach( function ( b ) { b.classList.remove( 'is-target' ); } );
			if ( ! target ) {
				self.setStatus( '' );
				return;
			}
			self.setStatus( strings.saving );
			ajax( { op: 'link', from_id: from.id, to_id: target, type: 'FS', lag: 0 }, function ( data ) {
				self.data = data;
				self.setStatus( '' );
				self.render();
			}, function ( message ) {
				self.setStatus( message );
			} );
		};
		document.addEventListener( 'pointermove', move );
		document.addEventListener( 'pointerup', finish );
	};

	/* ------------------------------------------------------------------ */
	/* Tablero.                                                             */
	/* ------------------------------------------------------------------ */

	function Board( container, data ) {
		this.container = container;
		this.data = data;
		this.group = 'status';
		this.hideDone = false;
		var self = this;
		var select = document.getElementById( 'gdp-board-group' );
		if ( select ) {
			select.addEventListener( 'change', function () {
				self.group = select.value;
				self.render();
			} );
		}
		var hide = document.getElementById( 'gdp-board-hide-done' );
		if ( hide ) {
			hide.addEventListener( 'change', function () {
				self.hideDone = hide.checked;
				self.render();
			} );
		}
		this.render();
	}

	/**
	 * Columnas según la agrupación: [clave, rótulo, campo del ajax, valor a enviar].
	 */
	Board.prototype.columns = function () {
		var cols = [];
		var self = this;
		if ( 'front' === this.group ) {
			cols.push( { key: '', label: strings.noFront, op: 'front', field: 'work_front', value: '' } );
			var known = {};
			Object.keys( this.data.frontOptions || {} ).forEach( function ( slug ) {
				known[ slug ] = true;
				cols.push( { key: slug, label: self.data.frontOptions[ slug ], op: 'front', field: 'work_front', value: slug } );
			} );
			// Frentes usados por actividades pero ausentes del catálogo (por ejemplo, importados).
			this.data.activities.forEach( function ( a ) {
				if ( a.frontSlug && ! known[ a.frontSlug ] ) {
					known[ a.frontSlug ] = true;
					cols.push( { key: a.frontSlug, label: a.frontSlug, op: 'front', field: 'work_front', value: a.frontSlug } );
				}
			} );
		} else if ( 'owner' === this.group ) {
			cols.push( { key: '0', label: strings.noOwner, op: 'owner', field: 'owner_id', value: 0 } );
			Object.keys( this.data.ownerOptions || {} ).forEach( function ( id ) {
				cols.push( { key: String( id ), label: self.data.ownerOptions[ id ], op: 'owner', field: 'owner_id', value: +id } );
			} );
		} else {
			Object.keys( this.data.statuses || {} ).forEach( function ( status ) {
				cols.push( { key: status, label: self.data.statuses[ status ], op: 'status', field: 'status', value: status } );
			} );
		}
		return cols;
	};

	Board.prototype.keyOf = function ( a ) {
		if ( 'front' === this.group ) {
			return a.frontSlug || '';
		}
		if ( 'owner' === this.group ) {
			return String( a.ownerId || 0 );
		}
		return a.status;
	};

	Board.prototype.render = function () {
		var self = this;
		var today = this.data.today;
		var columns = this.columns();
		this.container.innerHTML = '';
		this.container.style.gridTemplateColumns = 'repeat(' + Math.max( 1, columns.length ) + ', minmax(180px, 1fr))';
		columns.forEach( function ( col ) {
			var cards = self.data.activities.filter( function ( a ) {
				if ( 'summary' === a.kind || self.keyOf( a ) !== col.key ) {
					return false;
				}
				return ! ( self.hideDone && ( 'terminada' === a.status || 'cancelada' === a.status ) );
			} );
			var column = el( 'div', { 'class': 'gdp-board__column', 'data-key': col.key } );
			column.appendChild( el( 'h3', { 'class': 'gdp-board__title', html: '<span>' + col.label + '</span><span>' + cards.length + '</span>' } ) );
			cards.forEach( function ( a ) {
				var overdue = a.end && a.end < today && 'terminada' !== a.status && 'cancelada' !== a.status;
				var card = el( 'div', { 'class': 'gdp-board__card' + ( a.critical ? ' gdp-board__card--critical' : '' ) + ( 'milestone' === a.kind ? ' gdp-board__card--milestone' : '' ), draggable: cfg.canEdit ? 'true' : 'false', 'data-id': a.id } );
				card.appendChild( el( 'code', { text: a.code } ) );
				var title = cfg.canEdit && cfg.editUrl ? el( 'a', { href: cfg.editUrl.replace( /([?&])id=0/, '$1id=' + a.id ), text: a.name } ) : el( 'span', { text: a.name } );
				card.appendChild( el( 'strong', {}, [ title ] ) );
				var meta = [];
				if ( a.end ) {
					meta.push( ( 'milestone' === a.kind ? '◆ ' : '' ) + a.end );
				}
				meta.push( a.percent + ' %' );
				if ( 'status' !== self.group ) {
					meta.push( self.data.statuses[ a.status ] || a.status );
				}
				if ( a.owner && 'owner' !== self.group ) {
					meta.push( a.owner );
				}
				if ( a.front && 'front' !== self.group ) {
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
					if ( ! activity || self.keyOf( activity ) === col.key ) {
						return;
					}
					var params = { op: col.op, activity_id: id, version: activity.version };
					params[ col.field ] = col.value;
					ajax( params, function ( data ) {
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
