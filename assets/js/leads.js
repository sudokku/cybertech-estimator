/**
 * Leads admin: inline status save (list table), copy-link buttons (both
 * screens) and the read-only title on the edit screen. Vanilla, no jQuery.
 * Config arrives as `window.ctEstLeads` (see LeadColumns::enqueue()).
 */
( () => {
	'use strict';

	const cfg = window.ctEstLeads || {};
	const i18n = cfg.i18n || {};

	/* ---- Inline status change --------------------------------------- */

	const setState = ( el, cls, text ) => {
		el.className = 'ct-lead-status__state' + ( cls ? ' is-' + cls : '' );
		el.textContent = text;
	};

	const saveStatus = async ( wrap, select ) => {
		const state = wrap.querySelector( '.ct-lead-status__state' );
		const previous = select.dataset.saved || select.defaultValue;
		setState( state, 'busy', '…' );
		select.disabled = true;

		const body = new FormData();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'id', wrap.dataset.id );
		body.append( 'status', select.value );

		try {
			const res = await fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
			const json = await res.json();
			if ( ! res.ok || ! json.success ) {
				throw new Error( ( json.data && json.data.message ) || res.statusText );
			}
			select.dataset.saved = json.data.status;
			setState( state, 'ok', '✓' );
			state.setAttribute( 'title', i18n.saved || 'Saved' );
			// Keep the tick briefly so a rep scanning the list sees what changed.
			window.setTimeout( () => setState( state, '', '' ), 2500 );
		} catch ( err ) {
			select.value = previous;
			setState( state, 'error', '✕' );
			state.setAttribute( 'title', ( i18n.error || 'Could not save' ) + ': ' + err.message );
		} finally {
			select.disabled = false;
		}
	};

	document.querySelectorAll( '.ct-lead-status' ).forEach( ( wrap ) => {
		const select = wrap.querySelector( '.ct-lead-status__select' );
		if ( ! select ) {
			return;
		}
		select.dataset.saved = select.value;
		select.addEventListener( 'change', () => saveStatus( wrap, select ) );
	} );

	/* ---- Copy-to-clipboard buttons ---------------------------------- */

	const copyText = async ( text ) => {
		if ( navigator.clipboard && window.isSecureContext ) {
			await navigator.clipboard.writeText( text );
			return;
		}
		// http:// local sites have no async clipboard; fall back to a hidden textarea.
		const ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		const ok = document.execCommand( 'copy' );
		ta.remove();
		if ( ! ok ) {
			throw new Error( 'execCommand failed' );
		}
	};

	document.querySelectorAll( '.ct-copy[data-ct-copy]' ).forEach( ( btn ) => {
		const original = btn.textContent;
		btn.addEventListener( 'click', async () => {
			try {
				await copyText( btn.dataset.ctCopy );
				btn.textContent = i18n.copied || 'Copied';
				btn.classList.add( 'is-copied' );
			} catch ( e ) {
				btn.textContent = i18n.copyFailed || 'Copy failed';
			}
			window.setTimeout( () => {
				btn.textContent = original;
				btn.classList.remove( 'is-copied' );
			}, 1800 );
		} );
	} );

	/* ---- Edit screen: the title is derived from the snapshot --------- */

	const title = document.getElementById( 'title' );
	if ( title && document.body.classList.contains( 'post-type-ct_estimate_lead' ) ) {
		title.setAttribute( 'readonly', 'readonly' );
		title.setAttribute( 'aria-readonly', 'true' );
	}
} )();
