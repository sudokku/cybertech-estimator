/**
 * Settings page: the "Refresh model list" button on the AI tab.
 *
 * Calls the admin models endpoint (Phase 4) and fills the <datalist> the
 * model input is bound to. Until that endpoint exists the request 404s and
 * we say so instead of failing silently. Vanilla ES2020, no jQuery; config
 * arrives as `window.ctEstSettings` (JSON injected server-side). Every DOM
 * write uses createElement/textContent — nothing from the API is parsed
 * as HTML.
 *
 * @package Cybertech\Estimator
 */
( () => {
	'use strict';

	const cfg = window.ctEstSettings;
	if ( ! cfg ) {
		return;
	}

	const button = document.getElementById( 'ct-est-models-refresh' );
	const list = document.getElementById( 'ct-est-models' );
	const input = document.getElementById( 'ct-st-ai-model' );
	const status = document.getElementById( 'ct-est-models-status' );
	if ( ! button || ! list || ! input || ! status ) {
		return;
	}

	const sprintf = ( tpl, ...args ) => {
		let i = 0;
		return tpl.replace( /%%|%(\d+\$)?[sd]/g, ( match, pos ) => {
			if ( match === '%%' ) {
				return '%';
			}
			const idx = pos ? parseInt( pos, 10 ) - 1 : i++;
			return String( args[ idx ] ?? '' );
		} );
	};

	const say = ( text, tone = '' ) => {
		status.textContent = text;
		status.classList.toggle( 'is-error', tone === 'error' );
		status.classList.toggle( 'is-ok', tone === 'ok' );
	};

	/**
	 * Price per 1M tokens as a short label; "free" when both sides are zero.
	 *
	 * @param {Object} model
	 * @return {string}
	 */
	const priceLabel = ( model ) => {
		const p = Number( model.prompt_price ?? 0 );
		const c = Number( model.completion_price ?? 0 );
		if ( ! p && ! c ) {
			return cfg.i18n.free;
		}
		const fmt = ( n ) => ( n >= 1 ? n.toFixed( 2 ) : n.toFixed( 3 ) ).replace( /\.?0+$/, '' );
		return sprintf( cfg.i18n.price, fmt( p ), fmt( c ) );
	};

	/**
	 * Replace the datalist options. Chrome shows `label`, Firefox shows
	 * `value` + text, so both carry the price.
	 *
	 * @param {Array<Object>} models
	 */
	const fill = ( models ) => {
		list.replaceChildren();
		for ( const model of models ) {
			if ( ! model || typeof model.id !== 'string' ) {
				continue;
			}
			const option = document.createElement( 'option' );
			const label = `${ model.label || model.id } — ${ priceLabel( model ) }`;
			option.value = model.id;
			option.label = label;
			option.textContent = label;
			list.append( option );
		}
	};

	const money = ( cents ) => ( Number( cents || 0 ) / 100 ).toFixed( 2 );

	/**
	 * The same response carries breaker + spend, so the status strip can be
	 * brought up to date without a reload. Ids are the Phase 4 contract.
	 *
	 * @param {Object} data
	 */
	const updateStrip = ( data ) => {
		const breaker = document.getElementById( 'ct-est-ai-breaker' );
		if ( breaker && data && data.breaker ) {
			const open = Number( data.breaker.open_until || 0 ) > Date.now() / 1000;
			const failures = Number( data.breaker.failures || 0 );
			breaker.dataset.state = open ? 'open' : 'closed';
			breaker.classList.toggle( 'ct-st-stat--bad', open );
			breaker.classList.toggle( 'ct-st-stat--ok', ! open );
			const value = document.getElementById( 'ct-est-ai-breaker-value' );
			if ( value ) {
				value.textContent = open ? cfg.i18n.breakerOpen : cfg.i18n.breakerClosed;
			}
			const meta = document.getElementById( 'ct-est-ai-breaker-meta' );
			if ( meta && ! open && failures > 0 ) {
				meta.textContent = sprintf( cfg.i18n.breakerFails, failures );
			}
		}
		const spend = document.getElementById( 'ct-est-ai-spend' );
		if ( spend && data && data.spend ) {
			const cents = Number( data.spend.cents || 0 );
			const budget = Number( data.spend.budget || 0 );
			const pct = budget > 0 ? Math.min( 100, Math.round( ( cents / budget ) * 100 ) ) : 0;
			spend.dataset.cents = String( cents );
			spend.dataset.budget = String( budget );
			spend.dataset.state = String( data.spend.state || ( budget > 0 && cents >= budget ? 'exhausted' : 'ok' ) );
			spend.classList.toggle( 'ct-st-stat--bad', spend.dataset.state === 'exhausted' );
			const value = document.getElementById( 'ct-est-ai-spend-value' );
			if ( value ) {
				value.textContent = budget > 0 ? sprintf( cfg.i18n.spendOf, money( cents ), money( budget ), pct ) : sprintf( cfg.i18n.spendNoBudget, money( cents ) );
			}
			const bar = document.getElementById( 'ct-est-ai-spend-bar' );
			if ( bar ) {
				bar.value = pct;
			}
		}
	};

	const refresh = async () => {
		button.disabled = true;
		say( cfg.i18n.loading );
		try {
			// refresh=1 bypasses the 24h price-list transient: the button is the admin saying "go ask the provider".
			const url = new URL( cfg.modelsEndpoint, window.location.origin );
			url.searchParams.set( 'refresh', '1' );
			const response = await fetch( url.toString(), {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
			} );
			if ( response.status === 404 ) {
				say( cfg.i18n.notYet );
				return;
			}
			if ( ! response.ok ) {
				let detail = `HTTP ${ response.status }`;
				try {
					const err = await response.json();
					if ( err && err.message ) {
						detail = err.message;
					}
				} catch ( e ) {
					// Non-JSON error body: keep the status line.
				}
				say( sprintf( cfg.i18n.failed, detail ), 'error' );
				return;
			}
			const data = await response.json();
			const models = Array.isArray( data ) ? data : Array.isArray( data?.models ) ? data.models : [];
			updateStrip( data );
			if ( ! models.length ) {
				say( cfg.i18n.empty, 'error' );
				return;
			}
			fill( models );

			// D8: no slug is hardcoded; if the field is empty suggest the first free model.
			const free = models.find( ( m ) => m && ( m.free === true || ( typeof m.id === 'string' && m.id.endsWith( ':free' ) ) ) );
			if ( ! input.value.trim() && free ) {
				input.value = free.id;
				say( sprintf( cfg.i18n.suggested, free.id ), 'ok' );
				return;
			}
			say( sprintf( cfg.i18n.loaded, models.length ), 'ok' );
		} catch ( e ) {
			say( sprintf( cfg.i18n.failed, e && e.message ? e.message : 'network' ), 'error' );
		} finally {
			button.disabled = false;
		}
	};

	button.addEventListener( 'click', refresh );
} )();
