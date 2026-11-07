/**
 * TomaTío page store client (#375), served at /share/{id}/{token}/_tomatio.js.
 *
 * A shared page runs sandboxed (opaque origin, no browser storage), so it
 * keeps state in its artifact's data store instead. Load it with
 * <script src="_tomatio.js"></script> and use window.tomatio.store:
 *
 *   tomatio.store.list('scores')                         -> [doc, ...]
 *   tomatio.store.get('scores', 'ana')                   -> doc or null
 *   tomatio.store.increment('scores', 'ANA', 'wins')     -> doc (counter)
 *   tomatio.store.create('rsvps', {name: 'Ana'})         -> doc (generated key)
 *   tomatio.store.append('board', 'ideas', 'items', 'x') -> doc
 *   tomatio.store.set('votes', 'mine', {choice: 'B'})    -> doc (create or replace)
 *   tomatio.store.set('votes', 'mine', v, {expectedVersion: doc.version})
 *   tomatio.store.update('votes', 'mine', {choice: 'C'}) -> doc (merged)
 *   tomatio.store.delete('rsvps', doc.key)
 *   tomatio.store.query('scores', {team: 'red'}, {limit: 10})
 *   tomatio.store.rules('scores')                        -> what this page may do there
 *   tomatio.store.collections()                          -> every collection it may use
 *
 * Each collection has rules (a preset such as counter, ballot, entries,
 * personal, inbox or shared) that the server enforces; check rules() to
 * adapt the page. Every call returns a Promise. A doc is {key, value,
 * version, created_at, updated_at, mine}. Failures reject with an Error
 * carrying status and code (op_not_allowed, not_owner, schema, rate_limited,
 * version_conflict, ...), plus retryAfter (seconds) when rate limited,
 * detail for a schema refusal and doc (the current one) for a conflict.
 *
 * Values come from other viewers: render them as text (textContent), never
 * as HTML.
 *
 * Seats (#469, when the artifact has seats on): one public link, a personal
 * link per name. The personal link carries the seat's secret in the
 * fragment (#seat=..., or #p=... for links a page made before seats), which
 * never reaches the server in a URL; the store
 * calls send it in a header, so rules with owner "seat" let only that seat
 * write the document named after it.
 *
 *   tomatio.seat.claim('Ana')          -> {seat, secret, link}: create-only; link is the personal URL
 *   tomatio.seat.link(secret, 'Ana')   -> the personal URL for a secret
 *   tomatio.seat.current()             -> the seat name this page proves, or null
 *   tomatio.seat.list()                -> every seat's name, in claim order (#501)
 *   tomatio.seat.name                  -> the name in ?seat= (a hint, not proof), or null
 *
 * Updates (#469): tomatio.version is the version this page was served as.
 * When a new version is approved and the artifact reloads open pages (the
 * default), the page reloads at its next visibility change, never while in
 * use, keeping the address and its fragment. tomatio.onUpdate(fn) takes that
 * over: fn(newVersion) returning true reloads now, false asks again at the
 * next check (about once a minute, and when the page comes back on screen).
 *
 * The server also adds this script to every shared HTML page, so pages that
 * load it themselves get it once.
 *
 * Members address (#610): a page with the store on, opened by a signed-in
 * member at /a/{slug}, gets this script too, with the member's viewer token
 * in <meta name="tomatio-viewer-token">, and the store below that address
 * (/a/{slug}/store). The token lasts 20 minutes; reloading the page in its
 * frame gets a fresh one. Seats and the update watcher are share-link only.
 */
( function () {
	'use strict';

	var match = /^\/share\/\d+\/[^/]+/.exec( window.location.pathname );

	// #610: the members address, only when the server handed over a token.
	var tokenMeta = match ? null : document.querySelector( 'meta[name="tomatio-viewer-token"]' );
	var membersToken = tokenMeta ? tokenMeta.getAttribute( 'content' ) : null;
	if ( membersToken ) {
		match = /^\/a\/[a-z0-9][a-z0-9-]*(?=\/?$)/.exec( window.location.pathname );
	}

	if ( ! match || ( window.tomatio && window.tomatio.store ) ) {
		return;
	}

	var root = match[ 0 ];
	var base = root + '/store';

	// A private link hands its viewer token over in the fragment (#219);
	// read it once, before the page's own code can rewrite the hash.
	var tokenMatch = membersToken ? null : /(?:^#|&)ab_viewer_token=([^&]+)/.exec( window.location.hash );
	var viewerToken = membersToken || ( tokenMatch ? decodeURIComponent( tokenMatch[ 1 ] ) : null );

	// #469: the seat secret (fragment) and the name hint (query).
	var seatMatch = /(?:^#|&)(?:seat|p)=([A-Za-z0-9_-]{20,64})(?:&|$)/.exec( window.location.hash );
	var seatSecret = seatMatch ? seatMatch[ 1 ] : null;
	var nameMatch = /(?:^\?|&)seat=([^&]*)/.exec( window.location.search );
	var seatName = nameMatch ? decodeURIComponent( nameMatch[ 1 ].replace( /\+/g, ' ' ) ) : null;

	function withToken( url ) {
		// In the query, not the body: a preflight (PUT, DELETE) carries
		// the URL but no body.
		return viewerToken ? url + ( url.indexOf( '?' ) < 0 ? '?' : '&' ) + 'viewer_token=' + encodeURIComponent( viewerToken ) : url;
	}

	function segment( value, what ) {
		if ( typeof value !== 'string' || value === '' ) {
			throw new TypeError( 'tomatio.store: ' + what + ' must be a non-empty string' );
		}

		return encodeURIComponent( value );
	}

	function failure( status, data ) {
		var code = data && typeof data.error === 'string' ? data.error : null;
		var error = new Error( 'tomatio.store: ' + ( code || 'HTTP ' + status ) );
		error.status = status;
		error.code = code;
		if ( data && 'doc' in data ) {
			error.doc = data.doc;
		}
		if ( data && typeof data.retry_after === 'number' ) {
			error.retryAfter = data.retry_after;
		}
		if ( data && typeof data.detail === 'string' ) {
			error.detail = data.detail;
		}

		return error;
	}

	function call( method, path, body ) {
		return request( method, base + path, body );
	}

	function request( method, path, body ) {
		// No Content-Type header: a string body goes as text/plain, so a
		// POST needs no preflight (unless a seat header rides along). The
		// server reads the body as JSON.
		var init = { method: method, credentials: 'omit', cache: 'no-store' };
		if ( body !== undefined ) {
			init.body = JSON.stringify( body );
		}
		if ( seatSecret ) {
			init.headers = { 'X-Tomatio-Seat': seatSecret };
		}

		return window.fetch( withToken( path ), init ).then( function ( response ) {
			return response.text().then( function ( text ) {
				var data = null;
				try {
					data = text ? JSON.parse( text ) : null;
				} catch ( e ) {
					data = null;
				}

				if ( ! response.ok ) {
					throw failure( response.status, data );
				}

				return data;
			} );
		} );
	}

	function withVersion( body, options ) {
		if ( options && typeof options.expectedVersion === 'number' ) {
			body.expected_version = options.expectedVersion;
		}

		return body;
	}

	function docOf( data ) {
		return data.doc;
	}

	var store = {
		collections: function () {
			return call( 'GET', '' ).then( function ( data ) {
				return data.collections;
			} );
		},
		rules: function ( collection ) {
			return call( 'GET', '/' + segment( collection, 'collection' ) + '/_rules' ).then( function ( data ) {
				return data.rules;
			} );
		},
		create: function ( collection, value ) {
			// For collections whose rules generate the keys (entries, inbox).
			return call( 'POST', '/' + segment( collection, 'collection' ), { value: value } ).then( docOf );
		},
		increment: function ( collection, key, field, by ) {
			var body = { field: field };
			if ( typeof by === 'number' ) {
				body.by = by;
			}

			return call( 'POST', '/' + segment( collection, 'collection' ) + '/' + segment( key, 'key' ) + '/_increment', body ).then( docOf );
		},
		append: function ( collection, key, field, item ) {
			return call( 'POST', '/' + segment( collection, 'collection' ) + '/' + segment( key, 'key' ) + '/_append', { field: field, item: item } ).then( docOf );
		},
		list: function ( collection ) {
			return call( 'GET', '/' + segment( collection, 'collection' ) ).then( function ( data ) {
				return data.docs;
			} );
		},
		get: function ( collection, key ) {
			var path = '/' + segment( collection, 'collection' ) + '/' + segment( key, 'key' );

			return call( 'GET', path ).then(
				function ( data ) {
					return data.doc;
				},
				function ( error ) {
					// A missing document is null; anything else (store off,
					// link revoked) still rejects.
					if ( error.code === 'not_found' ) {
						return null;
					}
					throw error;
				}
			);
		},
		set: function ( collection, key, value, options ) {
			segment( key, 'key' );

			return call( 'POST', '/' + segment( collection, 'collection' ), withVersion( { key: key, value: value }, options ) ).then( function ( data ) {
				return data.doc;
			} );
		},
		update: function ( collection, key, patch, options ) {
			var path = '/' + segment( collection, 'collection' ) + '/' + segment( key, 'key' );

			return call( 'PUT', path, withVersion( { patch: patch }, options ) ).then( function ( data ) {
				return data.doc;
			} );
		},
		delete: function ( collection, key, options ) {
			var path = '/' + segment( collection, 'collection' ) + '/' + segment( key, 'key' );

			return call( 'DELETE', path, withVersion( {}, options ) ).then( function () {
				return true;
			} );
		},
		query: function ( collection, where, options ) {
			var body = { where: where || {} };
			if ( options && typeof options.limit === 'number' ) {
				body.limit = options.limit;
			}
			if ( options && typeof options.offset === 'number' ) {
				body.offset = options.offset;
			}

			return call( 'POST', '/' + segment( collection, 'collection' ) + '/_query', body ).then( function ( data ) {
				return data.docs;
			} );
		},
	};

	function personalLink( secret, name ) {
		var url = window.location.protocol + '//' + window.location.host + root + '/';
		if ( typeof name === 'string' && name !== '' ) {
			url += '?seat=' + encodeURIComponent( name );
		}

		return url + '#seat=' + secret;
	}

	var opened = null;
	var seat = {
		name: seatName,
		claim: function ( name ) {
			return request( 'POST', root + '/_seat', { name: name } ).then( function ( data ) {
				seatSecret = data.secret;
				seat.name = data.seat;

				return { seat: data.seat, secret: data.secret, link: personalLink( data.secret, data.seat ) };
			} );
		},
		link: function ( secret, name ) {
			if ( typeof secret !== 'string' || ! /^[A-Za-z0-9_-]{20,64}$/.test( secret ) ) {
				throw new TypeError( 'tomatio.seat: not a seat secret' );
			}

			return personalLink( secret, name === undefined && secret === seatSecret ? seat.name : name );
		},
		list: function () {
			return request( 'GET', root + '/_seats' ).then( function ( data ) {
				return data.seats;
			} );
		},
		current: function () {
			if ( ! seatSecret ) {
				return Promise.resolve( null );
			}
			// The first call of a page load counts an open of the personal link.
			var path = root + '/_seat' + ( opened ? '' : '?open=1' );
			opened = true;

			return request( 'GET', path ).then( function ( data ) {
				return data.seat;
			} );
		},
	};

	// #469: the update watcher.
	var meta = document.querySelector( 'meta[name="tomatio-version"]' );
	var version = meta ? parseInt( meta.getAttribute( 'content' ), 10 ) : NaN;
	var handlers = [];
	var pending = false;
	var checking = false;

	function check() {
		if ( isNaN( version ) || checking || pending ) {
			return;
		}
		checking = true;
		window.fetch( withToken( root + '/_version' ), { credentials: 'omit', cache: 'no-store' } )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				checking = false;
				if ( ! data || typeof data.version !== 'number' || data.version < 1 || data.version === version ) {
					return;
				}
				if ( handlers.length ) {
					var now = false;
					handlers.forEach( function ( fn ) {
						try {
							now = fn( data.version ) === true || now;
						} catch ( e ) {
							// A failing handler holds the reload.
						}
					} );
					if ( now ) {
						window.location.reload();
					}

					return;
				}
				// Never while in use: at the next visibility change.
				pending = data.reload === true;
			} )
			.catch( function () {
				checking = false;
			} );
	}

	if ( ! isNaN( version ) ) {
		document.addEventListener( 'visibilitychange', function () {
			if ( pending ) {
				pending = false;
				window.location.reload();

				return;
			}
			if ( document.visibilityState === 'visible' ) {
				check();
			}
		} );
		window.setInterval( function () {
			if ( document.visibilityState === 'visible' ) {
				check();
			}
		}, 60000 );
	}

	// A personal link without the seat's name in its address (one a page
	// made before seats, #p=...): the server dresses a page (title, icon,
	// manifest) only from ?seat=, and Safari reads the icon only from the
	// page as served, so once the seat is known, open the dressed address
	// instead, once, keeping the fragment.
	function dress( name ) {
		if ( ! name || ( seatName !== null && seatName.toUpperCase() === name.toUpperCase() ) ) {
			return;
		}
		var search = window.location.search.replace( /(?:^\?|&)seat=[^&]*/, '' ).replace( /^&/, '?' );
		search += ( search ? '&' : '?' ) + 'seat=' + encodeURIComponent( name );
		window.location.replace( window.location.pathname + search + window.location.hash );
	}

	if ( seatSecret ) {
		seat.current().then( dress, function () {} );
	}

	window.tomatio = window.tomatio || {};
	window.tomatio.store = Object.freeze( store );
	window.tomatio.seat = seat;
	window.tomatio.version = isNaN( version ) ? null : version;
	window.tomatio.onUpdate = function ( fn ) {
		if ( typeof fn !== 'function' ) {
			throw new TypeError( 'tomatio.onUpdate: a function' );
		}
		handlers.push( fn );
	};
	window.tomatio.checkForUpdate = check;
} )();
