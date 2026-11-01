/**
 * TomaTío: share links panel on the artifact edit screen.
 *
 * The panel sits inside the artifact edit form, so it uses plain buttons
 * (never submit buttons) and fetch() calls to admin-post.php. The new link is rendered once (never stored, never
 * re-rendered into the page on reload) with a Copy button. All dynamic text
 * goes in through textContent / value; the refreshed list is server-escaped.
 */
( function () {
	'use strict';

	var config = window.agentBrainShare;
	var panel = document.getElementById( 'ab-share-panel' );

	if ( ! config || ! panel || ! window.fetch ) {
		return;
	}

	var list = document.getElementById( 'ab-share-list' );
	var result = document.getElementById( 'ab-share-result' );

	function post( action, fields ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'artifact_id', String( config.artifactId ) );
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json().then(
				function ( data ) {
					if ( ! response.ok ) {
						throw new Error( ( data && data.message ) || config.i18n.failed );
					}
					return data;
				},
				function () {
					throw new Error( config.i18n.failed );
				}
			);
		} );
	}

	function showError( error ) {
		// Errors interrupt (alert); a successful link is polite (status).
		result.setAttribute( 'role', 'alert' );
		result.textContent = error && error.message ? error.message : config.i18n.failed;
	}

	function copyButton( source ) {
		var copy = document.createElement( 'button' );
		copy.type = 'button';
		copy.className = 'button';
		copy.textContent = config.i18n.copy;
		copy.addEventListener( 'click', function () {
			source.select();
			var done = function () {
				copy.textContent = config.i18n.copied;
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( source.value ).then( done, function () {
					document.execCommand( 'copy' );
					done();
				} );
			} else {
				document.execCommand( 'copy' );
				done();
			}
		} );
		return copy;
	}

	function showLink( data ) {
		result.setAttribute( 'role', 'status' );
		result.textContent = '';

		var note = document.createElement( 'p' );
		note.textContent = config.i18n.shownOnce;
		result.appendChild( note );

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.id = 'ab-share-url';
		input.setAttribute( 'aria-label', config.i18n.linkLabel || 'Share link' );
		input.readOnly = true;
		input.size = 80;
		input.value = data.url;
		input.addEventListener( 'click', function () {
			input.select();
		} );

		var copy = copyButton( input );

		var row = document.createElement( 'p' );
		row.appendChild( input );
		row.appendChild( document.createTextNode( ' ' ) );
		row.appendChild( copy );
		result.appendChild( row );

		// Move keyboard and screen reader focus to the one-time link.
		input.focus();
		input.select();

		var meta = document.createElement( 'p' );
		meta.textContent = config.i18n.expires + ' ' + data.expires_at + '. ' + config.i18n.ipBinding + ': ' + data.ip_mode + '. ' + config.i18n.privacyLabel + ': ' + ( data.privacy === 'public' ? config.i18n.publicLink : config.i18n.privateLink ) + '.';
		result.appendChild( meta );

		if ( data.privacy === 'private' ) {
			var privateNote = document.createElement( 'p' );
			privateNote.textContent = config.i18n.privateHint;
			result.appendChild( privateNote );
		}

		if ( data.embed_html ) {
			var embedNote = document.createElement( 'p' );
			embedNote.textContent = config.i18n.embedHtml;
			result.appendChild( embedNote );

			var snippet = document.createElement( 'textarea' );
			snippet.readOnly = true;
			snippet.rows = 3;
			snippet.className = 'large-text code';
			snippet.value = data.embed_html;
			snippet.addEventListener( 'click', function () {
				snippet.select();
			} );

			var snippetRow = document.createElement( 'p' );
			snippetRow.appendChild( snippet );
			snippetRow.appendChild( document.createElement( 'br' ) );
			snippetRow.appendChild( copyButton( snippet ) );
			result.appendChild( snippetRow );
		}

		if ( data.embed_url ) {
			var codeNote = document.createElement( 'p' );
			codeNote.textContent = config.i18n.embedCode;
			result.appendChild( codeNote );

			var codeInput = document.createElement( 'input' );
			codeInput.type = 'text';
			codeInput.readOnly = true;
			codeInput.size = 80;
			codeInput.setAttribute( 'aria-label', config.i18n.embedCode );
			codeInput.value = data.embed_url;
			codeInput.addEventListener( 'click', function () {
				codeInput.select();
			} );

			var codeRow = document.createElement( 'p' );
			codeRow.appendChild( codeInput );
			codeRow.appendChild( document.createTextNode( ' ' ) );
			codeRow.appendChild( copyButton( codeInput ) );
			result.appendChild( codeRow );
		}

		if ( data.pending_approval ) {
			var pending = document.createElement( 'p' );
			var strong = document.createElement( 'strong' );
			strong.textContent = config.i18n.pending;
			pending.appendChild( strong );
			result.appendChild( pending );
		}
	}

	panel.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}

		if ( target.id === 'ab-share-create' ) {
			event.preventDefault();
			target.disabled = true;
			post( config.actions.create, {
				nonce: config.nonces.create,
				ttl_hours: document.getElementById( 'ab-share-ttl' ).value,
				ip_mode: document.getElementById( 'ab-share-ip' ).value,
				privacy: document.getElementById( 'ab-share-privacy' ) ? document.getElementById( 'ab-share-privacy' ).value : 'private',
				kind: document.getElementById( 'ab-share-kind' ) ? document.getElementById( 'ab-share-kind' ).value : 'link',
				embed_origin: document.getElementById( 'ab-share-origin' ) ? document.getElementById( 'ab-share-origin' ).value : '',
			} )
				.then( function ( data ) {
					showLink( data );
					list.innerHTML = data.html;
				} )
				.catch( showError )
				.then( function () {
					target.disabled = false;
					if ( ! result.querySelector( 'input' ) ) {
						target.focus();
					}
				} );
			return;
		}

		if ( target.classList.contains( 'ab-share-copy' ) ) {
			event.preventDefault();
			var text = target.getAttribute( 'data-copy' ) || '';
			var input = document.getElementById( 'ab-members-address-url' );
			var done = function () {
				var previous = target.textContent;
				target.textContent = config.i18n.copied;
				window.setTimeout( function () {
					target.textContent = previous;
				}, 2000 );
			};
			if ( input ) {
				input.select();
			}
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, function () {
					document.execCommand( 'copy' );
					done();
				} );
			} else {
				document.execCommand( 'copy' );
				done();
			}
			return;
		}

		if ( target.classList.contains( 'ab-share-revoke' ) ) {
			event.preventDefault();
			if ( target.getAttribute( 'data-confirm' ) && ! window.confirm( target.getAttribute( 'data-confirm' ) ) ) {
				return;
			}
			target.disabled = true;
			post( config.actions.revoke, {
				link_id: target.getAttribute( 'data-link-id' ),
				nonce: target.getAttribute( 'data-nonce' ),
			} )
				.then( function ( data ) {
					list.innerHTML = data.html;
					// The revoked row (and its button) is gone: return focus to the
					// status region so the keyboard position is not lost.
					result.setAttribute( 'role', 'status' );
					result.textContent = config.i18n.revoked || '';
					result.focus();
				} )
				.catch( function ( error ) {
					target.disabled = false;
					showError( error );
					target.focus();
				} );
		}
	} );
}() );
