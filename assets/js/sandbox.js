/**
 * Sandbox page: live re-pricing of the questionnaire through the admin-only
 * sandbox REST endpoint, plus tabs, presets and show_if visibility.
 *
 * Vanilla ES2020, no jQuery. Config arrives as `window.ctEstSandbox`
 * (injected server-side as JSON). Every DOM write goes through
 * createElement/textContent — nothing from the API is ever parsed as HTML.
 *
 * @package Cybertech\Estimator
 */
( () => {
	'use strict';

	const cfg = window.ctEstSandbox;
	if ( ! cfg ) {
		return;
	}

	const $ = ( sel, root = document ) => root.querySelector( sel );
	const $$ = ( sel, root = document ) => Array.from( root.querySelectorAll( sel ) );

	const form = $( '#ct-sb-form' );
	const status = $( '#ct-sb-status' );
	if ( ! form ) {
		return;
	}

	/* ---------- tiny DOM helper ---------- */

	/**
	 * Build an element. `attrs` are set as attributes (class/hidden handled),
	 * children may be strings (→ text nodes) or nodes.
	 *
	 * @param {string} tag
	 * @param {Object} attrs
	 * @param {...(string|Node)} children
	 * @return {HTMLElement}
	 */
	const h = ( tag, attrs = {}, ...children ) => {
		const el = document.createElement( tag );
		for ( const [ k, v ] of Object.entries( attrs ) ) {
			if ( v === null || v === undefined || v === false ) {
				continue;
			}
			if ( k === 'hidden' ) {
				el.hidden = Boolean( v );
			} else {
				el.setAttribute( k, String( v ) );
			}
		}
		for ( const c of children ) {
			if ( c === null || c === undefined ) {
				continue;
			}
			el.append( typeof c === 'string' ? document.createTextNode( c ) : c );
		}
		return el;
	};

	const sprintf = ( tpl, ...args ) => {
		let i = 0;
		return tpl.replace( /%[sd]/g, () => String( args[ i++ ] ?? '' ) );
	};

	/* ---------- formatting (mirrors Support\Money) ---------- */

	const numFmt = new Intl.NumberFormat( cfg.locale || 'en', { maximumFractionDigits: 2 } );
	const intFmt = new Intl.NumberFormat( cfg.locale || 'en', { maximumFractionDigits: 0 } );

	const fmtNum = ( n ) => numFmt.format( Number( n ) || 0 );

	const money = ( n ) => {
		const s = intFmt.format( Math.round( Number( n ) || 0 ) );
		return cfg.currencySymbol ? cfg.currencySymbol + s : `${ s } ${ cfg.currency }`;
	};

	/**
	 * Value with its breakdown unit: 'h' | currency | currency + '/h' | 'weeks' | 'pts' | ''.
	 *
	 * @param {number} v
	 * @param {string} unit
	 * @return {string}
	 */
	const withUnit = ( v, unit ) => {
		switch ( unit ) {
			case 'h':
				return sprintf( cfg.i18n.hours, fmtNum( v ) );
			case cfg.currency:
				return money( v );
			case 'weeks':
				return sprintf( cfg.i18n.weeks, fmtNum( v ) );
			case 'pts':
				return sprintf( cfg.i18n.points, fmtNum( v ) );
			case '':
				return fmtNum( v );
			default:
				return `${ fmtNum( v ) } ${ unit }`;
		}
	};

	const scoreClass = ( score ) => {
		if ( score >= cfg.thresholds.green ) {
			return 'is-green';
		}
		return score >= cfg.thresholds.amber ? 'is-amber' : 'is-red';
	};

	/* ---------- form state ---------- */

	const wrapperOf = ( id ) => form.querySelector( `[data-question="${ id }"]` );

	/** Raw value of a single-choice question (used for show_if, ignores visibility). */
	const singleValue = ( id ) => {
		const el = form.querySelector( `input[name="${ id }"]:checked` );
		return el ? el.value : null;
	};

	/**
	 * Apply every `show_if` rule. Rules only reference single-choice
	 * questions today (service_line); evaluated in schema order so a
	 * chained dependency would still resolve top-down.
	 */
	const applyVisibility = () => {
		for ( const [ id, q ] of Object.entries( cfg.questions ) ) {
			const rules = Object.entries( q.show_if || {} );
			if ( ! rules.length ) {
				continue;
			}
			const wrap = wrapperOf( id );
			if ( ! wrap ) {
				continue;
			}
			const visible = rules.every( ( [ dep, allowed ] ) => allowed.includes( singleValue( dep ) ) );
			wrap.hidden = ! visible;
		}
	};

	/**
	 * Collect answers the engine should see: visible, non-contact questions
	 * only. Hidden branches are skipped so the JSON panel never shows
	 * answers from a service line the visitor moved away from.
	 *
	 * @return {Object}
	 */
	const readAnswers = () => {
		const answers = {};
		for ( const [ id, q ] of Object.entries( cfg.questions ) ) {
			if ( q.contact ) {
				continue;
			}
			const wrap = wrapperOf( id );
			if ( ! wrap || wrap.hidden ) {
				continue;
			}
			switch ( q.type ) {
				case 'single': {
					const v = singleValue( id );
					if ( v !== null ) {
						answers[ id ] = v;
					}
					break;
				}
				case 'multi': {
					const vals = $$( `input[name="${ id }[]"]:checked`, form ).map( ( e ) => e.value );
					if ( vals.length ) {
						answers[ id ] = vals;
					}
					break;
				}
				case 'number': {
					const el = form.elements[ id ];
					if ( el && el.value !== '' ) {
						answers[ id ] = Number( el.value );
					}
					break;
				}
				case 'checkbox': {
					const el = form.elements[ id ];
					answers[ id ] = Boolean( el && el.checked );
					break;
				}
				default: {
					const el = form.elements[ id ];
					if ( el && el.value.trim() !== '' ) {
						answers[ id ] = el.value;
					}
				}
			}
		}
		return answers;
	};

	/**
	 * Fill the form from an answers map. Questions absent from the map fall
	 * back to their schema defaults via form.reset() (which restores the
	 * server-rendered checked/value state).
	 *
	 * @param {Object} answers
	 */
	const applyAnswers = ( answers ) => {
		form.reset();
		for ( const [ id, value ] of Object.entries( answers ) ) {
			const q = cfg.questions[ id ];
			if ( ! q ) {
				continue;
			}
			switch ( q.type ) {
				case 'single': {
					const el = form.querySelector( `input[name="${ id }"][value="${ String( value ) }"]` );
					if ( el ) {
						el.checked = true;
					}
					break;
				}
				case 'multi': {
					const set = new Set( ( value || [] ).map( String ) );
					$$( `input[name="${ id }[]"]`, form ).forEach( ( e ) => {
						e.checked = set.has( e.value );
					} );
					break;
				}
				default: {
					const el = form.elements[ id ];
					if ( el ) {
						el.value = String( value );
					}
				}
			}
		}
		updateNotesCount();
		applyVisibility();
	};

	const notes = form.elements.notes;
	const notesCount = $( '#ct-sb-notes-count' );
	const updateNotesCount = () => {
		if ( notes && notesCount ) {
			notesCount.textContent = String( notes.value.length );
		}
	};

	/* ---------- status line ---------- */

	const setStatus = ( text, kind = '' ) => {
		if ( ! status ) {
			return;
		}
		status.textContent = text;
		status.className = 'ct-sb__status' + ( kind ? ` is-${ kind }` : '' );
	};

	/* ---------- fetch (debounced, latest-wins) ---------- */

	let timer = null;
	let controller = null;
	let seq = 0;
	let lastResult = null;

	const schedule = () => {
		clearTimeout( timer );
		timer = setTimeout( run, 300 );
	};

	const run = async () => {
		const answers = readAnswers();
		if ( controller ) {
			controller.abort();
		}
		controller = new AbortController();
		const mine = ++seq;
		setStatus( cfg.i18n.estimating, 'busy' );
		try {
			const res = await fetch( cfg.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce,
				},
				body: JSON.stringify( { answers } ),
				signal: controller.signal,
			} );
			const data = await res.json();
			if ( mine !== seq ) {
				return; // A newer request superseded this one.
			}
			if ( ! res.ok ) {
				throw new Error( data && data.message ? data.message : res.statusText );
			}
			render( data );
			setStatus( '' );
		} catch ( err ) {
			if ( err.name === 'AbortError' ) {
				return;
			}
			setStatus( sprintf( cfg.i18n.error, err.message ), 'error' );
		}
	};

	/* ---------- rendering ---------- */

	const render = ( data ) => {
		const result = data.result;
		lastResult = result;
		renderStats( result );
		renderVisitor( result, data.labels || {} );
		renderBreakdown( result );
		renderJson( result );
	};

	const renderStats = ( r ) => {
		$( '#ct-sb-stat-hours' ).textContent = sprintf( cfg.i18n.hours, fmtNum( r.hours ) );
		$( '#ct-sb-stat-range' ).textContent = `${ money( r.price_low ) } – ${ money( r.price_high ) }`;
		$( '#ct-sb-stat-weeks' ).textContent = sprintf( cfg.i18n.weeks, r.weeks );
		$( '#ct-sb-stat-band' ).textContent = r.band_label || r.band;
		const score = $( '#ct-sb-stat-score' );
		score.textContent = sprintf( cfg.i18n.score, r.qualification );
		score.className = `ct-sb-stat__value ${ scoreClass( r.qualification ) }`;

		const badge = $( '#ct-sb-rate-card-version' );
		if ( badge ) {
			badge.textContent = sprintf( cfg.i18n.rateCard, r.rate_card_version );
		}
	};

	const renderVisitor = ( r, labels ) => {
		$( '#ct-sb-v-open-range' ).textContent = `${ money( r.price_low ) } – ${ money( r.price_high ) }`;
		$( '#ct-sb-v-open-weeks' ).textContent = sprintf( cfg.i18n.weeks, r.weeks );

		const team = $( '#ct-sb-v-open-team' );
		team.replaceChildren();
		for ( const [ role, info ] of Object.entries( r.team.roles || {} ) ) {
			team.append(
				h( 'li', {},
					h( 'span', { class: 'ct-sb-team__role' }, cfg.roleLabels[ role ] || role ),
					h( 'span', { class: 'ct-sb-team__hours' }, sprintf( cfg.i18n.hours, fmtNum( info.hours ) ) )
				)
			);
		}

		$( '#ct-sb-v-band-label' ).textContent = r.band_label || r.band;
		$( '#ct-sb-v-band-weeks' ).textContent = sprintf( cfg.i18n.weeks, r.weeks );

		const dl = $( '#ct-sb-v-answers' );
		dl.replaceChildren();
		for ( const [ id, row ] of Object.entries( labels ) ) {
			if ( cfg.questions[ id ] && cfg.questions[ id ].contact ) {
				continue;
			}
			dl.append( h( 'dt', {}, row.label ), h( 'dd', {}, row.value ) );
		}
	};

	/** Rate-card deep link: `factors.web_templates.value` → `#rc-factors-web_templates-value`. */
	const sourceLink = ( source ) =>
		`${ cfg.rateCardUrl }#rc-${ source.replace( /\./g, '-' ) }`;

	const renderBreakdown = ( r ) => {
		const body = $( '#ct-sb-breakdown-body' );
		body.replaceChildren();
		let lastStep = null;
		for ( const row of r.breakdown ) {
			if ( row.step !== lastStep ) {
				body.append(
					h( 'tr', { class: 'ct-sb-group', 'data-step': row.step },
						h( 'th', { scope: 'rowgroup', colspan: 6 }, cfg.stepLabels[ row.step ] || row.step )
					)
				);
				lastStep = row.step;
			}
			const isQual = row.step === 'qualification';
			const isScore = isQual && row.source === 'qualification';
			const tr = h( 'tr', {
				class: [ isQual ? 'is-qual' : '', isScore ? `is-score ${ scoreClass( row.after ) }` : '' ].join( ' ' ).trim() || null,
				'data-step': row.step,
			} );
			tr.append( h( 'th', { scope: 'row' }, row.label ) );
			tr.append( h( 'td', { class: 'is-input' }, row.input ) );
			tr.append( h( 'td', { class: 'is-op' }, row.operation ) );
			tr.append( h( 'td', { class: 'is-num' }, withUnit( row.before, row.unit ) ) );
			tr.append( h( 'td', { class: 'is-num is-after' }, withUnit( row.after, row.unit ) ) );
			const src = h( 'td', { class: 'is-source' } );
			if ( row.source ) {
				src.append( h( 'a', { href: sourceLink( row.source ), title: cfg.i18n.openInCard }, row.source ) );
			} else {
				src.append( h( 'span', { class: 'ct-sb-muted', title: cfg.i18n.notFromCard }, '—' ) );
			}
			tr.append( src );
			body.append( tr );
		}
	};

	const renderJson = ( r ) => {
		$( '#ct-sb-json' ).textContent = JSON.stringify( r, null, 2 );
	};

	/* ---------- tabs (WAI-ARIA tabs pattern, arrow keys) ---------- */

	const tablist = $( '.ct-sb-tabs' );
	const tabs = $$( '[role="tab"]', tablist );
	const STORAGE_KEY = 'ctEstSandboxTab';

	const activateTab = ( tab, focus = true ) => {
		for ( const t of tabs ) {
			const on = t === tab;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.tabIndex = on ? 0 : -1;
			const panel = document.getElementById( t.getAttribute( 'aria-controls' ) );
			if ( panel ) {
				panel.hidden = ! on;
			}
		}
		if ( focus ) {
			tab.focus();
		}
		try {
			window.localStorage.setItem( STORAGE_KEY, tab.id );
		} catch ( e ) {
			// Storage may be unavailable; remembering the tab is a nicety only.
		}
	};

	tabs.forEach( ( tab, i ) => {
		tab.addEventListener( 'click', () => activateTab( tab ) );
		tab.addEventListener( 'keydown', ( e ) => {
			let next = null;
			if ( e.key === 'ArrowRight' ) {
				next = tabs[ ( i + 1 ) % tabs.length ];
			} else if ( e.key === 'ArrowLeft' ) {
				next = tabs[ ( i - 1 + tabs.length ) % tabs.length ];
			} else if ( e.key === 'Home' ) {
				next = tabs[ 0 ];
			} else if ( e.key === 'End' ) {
				next = tabs[ tabs.length - 1 ];
			}
			if ( next ) {
				e.preventDefault();
				activateTab( next );
			}
		} );
	} );

	try {
		const saved = window.localStorage.getItem( STORAGE_KEY );
		const tab = saved && tabs.find( ( t ) => t.id === saved );
		if ( tab ) {
			activateTab( tab, false );
		}
	} catch ( e ) {
		// Ignore storage errors.
	}

	/* ---------- wiring ---------- */

	form.addEventListener( 'input', ( e ) => {
		if ( e.target === notes ) {
			updateNotesCount();
		}
		applyVisibility();
		schedule();
	} );
	form.addEventListener( 'change', () => {
		applyVisibility();
		schedule();
	} );
	form.addEventListener( 'submit', ( e ) => e.preventDefault() );

	const preset = $( '#ct-sb-preset' );
	if ( preset ) {
		preset.addEventListener( 'change', () => {
			const p = cfg.presets.find( ( x ) => x.id === preset.value );
			if ( p ) {
				applyAnswers( p.answers );
				schedule();
			}
		} );
	}

	const reset = $( '#ct-sb-reset' );
	if ( reset ) {
		reset.addEventListener( 'click', () => {
			applyAnswers( {} );
			if ( preset ) {
				preset.value = '';
			}
			schedule();
		} );
	}

	const copy = $( '#ct-sb-copy-json' );
	if ( copy ) {
		copy.addEventListener( 'click', async () => {
			if ( ! lastResult ) {
				return;
			}
			try {
				await navigator.clipboard.writeText( JSON.stringify( lastResult, null, 2 ) );
				setStatus( cfg.i18n.copied, 'ok' );
			} catch ( e ) {
				setStatus( cfg.i18n.copyFailed, 'error' );
			}
		} );
	}

	applyVisibility();
	updateNotesCount();
	run();
} )();
