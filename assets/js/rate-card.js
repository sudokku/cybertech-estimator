/**
 * Rate card editor: live "effect on the sample project", team-band sum
 * hints, history diffs and confirm dialogs.
 *
 * Effects are computed by the sandbox REST endpoint with the *unsaved*
 * card serialised from the form, so the numbers the admin sees before
 * saving are exactly the engine's. One request per factor row (the
 * endpoint prices one answer set); requests run through a small pool and
 * a stale run is aborted as soon as the next edit lands.
 *
 * ES2020, no build step, no jQuery. Config comes from window.ctEstRateCard.
 */
( () => {
	'use strict';

	const cfg = window.ctEstRateCard;
	if ( ! cfg ) {
		return;
	}
	const form = document.getElementById( cfg.formId );
	if ( ! form ) {
		return;
	}

	const DEBOUNCE_MS = 400;
	const POOL_SIZE = 6;

	/* ---------- formatting ---------- */

	const numberFormat = new Intl.NumberFormat( cfg.locale, { maximumFractionDigits: 1 } );

	const money = ( amount, currency ) => {
		try {
			return new Intl.NumberFormat( cfg.locale, {
				style: 'currency',
				currency,
				maximumFractionDigits: 0,
			} ).format( amount );
		} catch ( e ) {
			// Unknown / non-ISO code typed into the currency field: fall back to "1,234 XYZ".
			return `${ numberFormat.format( amount ) } ${ currency }`;
		}
	};
	const hours = ( h ) => `${ numberFormat.format( h ) } ${ cfg.i18n.hours }`;
	const weeks = ( w ) => `${ w } ${ cfg.i18n.weeks }`;
	const range = ( r ) => `${ money( r.price_low, r.currency ) } – ${ money( r.price_high, r.currency ) }`;

	/* ---------- form → card ---------- */

	const PATH_RE = /\[([^\]]*)\]/g;

	/**
	 * Build the nested card from the form's `rate_card[...]` fields. Numeric
	 * inputs become numbers (empty → null), checkboxes become booleans or
	 * list members; objects whose keys are 0..n-1 become arrays so PHP sees
	 * the same shapes the repository stores.
	 */
	const serialise = () => {
		const card = {};
		for ( const el of form.elements ) {
			if ( ! el.name || ! el.name.startsWith( 'rate_card[' ) ) {
				continue;
			}
			const path = [ ...el.name.slice( 'rate_card'.length ).matchAll( PATH_RE ) ].map( ( m ) => m[ 1 ] );
			const isList = path[ path.length - 1 ] === '';
			if ( isList ) {
				path.pop();
			}
			let value;
			if ( el.type === 'checkbox' ) {
				if ( isList ) {
					if ( ! el.checked ) {
						continue;
					}
					value = el.value;
				} else {
					value = el.checked;
				}
			} else if ( el.type === 'number' ) {
				value = el.value.trim() === '' ? null : Number( el.value );
			} else {
				value = el.value;
			}
			let node = card;
			for ( let i = 0; i < path.length - 1; i++ ) {
				node = node[ path[ i ] ] ??= {};
			}
			const leaf = path[ path.length - 1 ];
			if ( isList ) {
				( node[ leaf ] ??= [] ).push( value );
			} else {
				node[ leaf ] = value;
			}
		}
		return listify( card );
	};

	const listify = ( node ) => {
		if ( Array.isArray( node ) ) {
			return node.map( listify );
		}
		if ( node === null || typeof node !== 'object' ) {
			return node;
		}
		const keys = Object.keys( node );
		const sequential = keys.length > 0 && keys.every( ( k, i ) => String( i ) === k );
		if ( sequential ) {
			return keys.map( ( k ) => listify( node[ k ] ) );
		}
		const out = {};
		for ( const k of keys ) {
			out[ k ] = listify( node[ k ] );
		}
		return out;
	};

	/* ---------- REST ---------- */

	const estimate = async ( answers, card, signal ) => {
		const res = await fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: JSON.stringify( { answers, rate_card: card } ),
			signal,
		} );
		let body = null;
		try {
			body = await res.json();
		} catch ( e ) {
			body = null;
		}
		if ( ! res.ok ) {
			const err = new Error( body && body.message ? body.message : res.statusText );
			err.isValidation = res.status === 400;
			throw err;
		}
		return body.result;
	};

	const pool = async ( jobs, size, worker ) => {
		let next = 0;
		const run = async () => {
			while ( next < jobs.length ) {
				const job = jobs[ next++ ];
				await worker( job );
			}
		};
		await Promise.all( Array.from( { length: Math.min( size, jobs.length ) }, run ) );
	};

	/* ---------- rendering ---------- */

	const statusEl = document.querySelector( '[data-live-status]' );
	const setStatus = ( state, text ) => {
		if ( ! statusEl ) {
			return;
		}
		statusEl.textContent = text;
		statusEl.className = `ct-rc-live is-${ state }`;
	};

	const setStale = ( stale ) => {
		document.querySelectorAll( '.ct-rc-effect, .ct-rc-stat' ).forEach( ( el ) => el.classList.toggle( 'is-stale', stale ) );
	};

	const renderStat = ( line, r ) => {
		const tile = document.querySelector( `[data-stat="${ line }"]` );
		if ( ! tile ) {
			return;
		}
		tile.querySelector( '[data-stat-range]' ).textContent = range( r );
		tile.querySelector( '[data-stat-hours]' ).textContent = hours( r.hours );
		tile.querySelector( '[data-stat-weeks]' ).textContent = weeks( r.weeks );
		tile.classList.remove( 'is-stale' );
	};

	const renderEffect = ( id, r, baseline ) => {
		const cell = document.querySelector( `[data-effect="${ CSS.escape( id ) }"]` );
		if ( ! cell ) {
			return;
		}
		const delta = baseline ? r.hours - baseline.hours : 0;
		let deltaHtml;
		if ( Math.abs( delta ) < 0.005 ) {
			deltaHtml = `<em class="ct-rc-delta ct-rc-delta--zero">${ escapeHtml( cfg.i18n.sample ) }</em>`;
		} else {
			const cls = delta > 0 ? 'ct-rc-delta--up' : 'ct-rc-delta--down';
			deltaHtml = `<em class="ct-rc-delta ${ cls }">${ delta > 0 ? '+' : '−' }${ escapeHtml( hours( Math.abs( delta ) ) ) }</em>`;
		}
		cell.innerHTML = `<span class="ct-rc-effect__range">${ escapeHtml( range( r ) ) }</span>` +
			`<span class="ct-rc-effect__meta">${ escapeHtml( `${ hours( r.hours ) } · ${ weeks( r.weeks ) }` ) } ${ deltaHtml }</span>`;
		cell.classList.remove( 'is-stale' );
	};

	const escapeHtml = ( s ) => String( s ).replace( /[&<>"']/g, ( c ) => ( {
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#39;',
	} )[ c ] );

	/* ---------- recalculation ---------- */

	let controller = null;
	let timer = null;

	const recalc = async () => {
		if ( controller ) {
			controller.abort();
		}
		controller = new AbortController();
		const { signal } = controller;
		const card = serialise();
		setStatus( 'busy', cfg.i18n.busy );

		// Baselines first: they surface a validation error once, before fanning out ~40 row requests.
		const baselines = {};
		try {
			await Promise.all( Object.entries( cfg.samples ).map( async ( [ line, answers ] ) => {
				const r = await estimate( answers, card, signal );
				baselines[ line ] = r;
				renderStat( line, r );
			} ) );
		} catch ( e ) {
			if ( e.name === 'AbortError' ) {
				return;
			}
			setStale( true );
			setStatus( 'error', e.isValidation ? `${ cfg.i18n.error } ${ e.message }` : cfg.i18n.network );
			return;
		}

		const jobs = Object.entries( cfg.rows ).map( ( [ id, row ] ) => ( { id, ...row } ) );
		try {
			await pool( jobs, POOL_SIZE, async ( job ) => {
				const r = await estimate( job.answers, card, signal );
				renderEffect( job.id, r, baselines[ job.line ] );
			} );
		} catch ( e ) {
			if ( e.name === 'AbortError' ) {
				return;
			}
			setStatus( 'error', e.isValidation ? `${ cfg.i18n.error } ${ e.message }` : cfg.i18n.network );
			return;
		}
		if ( ! signal.aborted ) {
			setStatus( 'ok', cfg.i18n.ok );
		}
	};

	const schedule = () => {
		setStale( true );
		clearTimeout( timer );
		timer = setTimeout( recalc, DEBOUNCE_MS );
	};

	form.addEventListener( 'input', ( e ) => {
		updateSums( e.target );
		schedule();
	} );
	form.addEventListener( 'change', schedule );

	/* ---------- team band sums ---------- */

	const updateSums = ( target ) => {
		const bands = target && target.dataset.band ? [ target.dataset.band ] : [ ...document.querySelectorAll( '[data-sum-for]' ) ].map( ( el ) => el.dataset.sumFor );
		for ( const band of bands ) {
			const cell = document.querySelector( `[data-sum-for="${ CSS.escape( band ) }"]` );
			if ( ! cell ) {
				continue;
			}
			let sum = 0;
			form.querySelectorAll( `[data-band="${ CSS.escape( band ) }"]` ).forEach( ( input ) => {
				sum += Number( input.value ) || 0;
			} );
			cell.textContent = numberFormat.format( sum );
			const ok = Math.abs( sum - 100 ) < 0.01;
			cell.classList.toggle( 'is-ok', ok );
			cell.classList.toggle( 'is-bad', ! ok );
		}
	};

	/* ---------- history diff ---------- */

	const isScalarList = ( v ) => Array.isArray( v ) && v.every( ( x ) => x === null || typeof x !== 'object' );

	const flatten = ( node, prefix, out ) => {
		if ( node !== null && typeof node === 'object' && ! isScalarList( node ) ) {
			const keys = Array.isArray( node ) ? node.map( ( _, i ) => String( i ) ) : Object.keys( node );
			for ( const k of keys ) {
				flatten( node[ k ], prefix ? `${ prefix }.${ k }` : k, out );
			}
			return out;
		}
		out[ prefix ] = JSON.stringify( node );
		return out;
	};

	const diff = ( from, to ) => {
		const a = flatten( from, '', {} );
		const b = flatten( to, '', {} );
		const paths = [ ...new Set( [ ...Object.keys( a ), ...Object.keys( b ) ] ) ]
			.filter( ( p ) => p !== 'version' && p !== 'format' )
			.sort();
		return paths
			.filter( ( p ) => a[ p ] !== b[ p ] )
			.map( ( p ) => ( { path: p, from: a[ p ], to: b[ p ] } ) );
	};

	const renderDiff = ( container, version ) => {
		const entry = cfg.history.find( ( h ) => h.version === version );
		if ( ! entry ) {
			return;
		}
		const changes = diff( entry.card, cfg.current );
		if ( ! changes.length ) {
			container.innerHTML = `<p class="description">${ escapeHtml( cfg.i18n.identical ) }</p>`;
			return;
		}
		const items = changes.map( ( c ) => {
			const id = `rc-${ c.path.replace( /\./g, '-' ) }`;
			const label = document.getElementById( id ) ? `<a href="#${ id }"><code>${ escapeHtml( c.path ) }</code></a>` : `<code>${ escapeHtml( c.path ) }</code>`;
			const from = c.from === undefined ? '<i>∅</i>' : `<del>${ escapeHtml( c.from ) }</del>`;
			const to = c.to === undefined ? '<i>∅</i>' : `<ins>${ escapeHtml( c.to ) }</ins>`;
			return `<li>${ label }: ${ from } → ${ to }</li>`;
		} );
		container.innerHTML = `<p class="description">${ escapeHtml( cfg.i18n.changed.replace( '%d', changes.length ) ) }</p><ul>${ items.join( '' ) }</ul>`;
	};

	document.querySelectorAll( '[data-diff]' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const container = document.getElementById( button.getAttribute( 'aria-controls' ) );
			if ( ! container ) {
				return;
			}
			const open = container.hidden;
			if ( open && ! container.dataset.rendered ) {
				renderDiff( container, Number( button.dataset.diff ) );
				container.dataset.rendered = '1';
			}
			container.hidden = ! open;
			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
	} );

	/* ---------- confirms + deep links ---------- */

	document.querySelectorAll( 'form[data-confirm]' ).forEach( ( f ) => {
		f.addEventListener( 'submit', ( e ) => {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( f.dataset.confirm ) ) {
				e.preventDefault();
			}
		} );
	} );

	// The sandbox links here with #rc-<path>; focus the field so the :target highlight and the caret agree.
	const focusTarget = () => {
		const hash = window.location.hash.slice( 1 );
		if ( ! hash.startsWith( 'rc-' ) ) {
			return;
		}
		const el = document.getElementById( hash );
		if ( el && typeof el.focus === 'function' ) {
			el.focus( { preventScroll: true } );
		}
	};
	window.addEventListener( 'hashchange', focusTarget );
	focusTarget();
	updateSums( null );
} )();
