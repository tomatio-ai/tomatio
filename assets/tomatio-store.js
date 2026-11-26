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
 *   tomatio.store.page('archive', {prefix: 'done.', order: 'desc', before: next, limit: 50})
 *                                                        -> {docs: [doc, ...], next: key or null}
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
 * Long lists (#1058): page() reads one page at a time, in key order, past
 * the first 500 documents a list() returns. Options: limit (1-100, default
 * 100), order ('asc' default, or 'desc'), before / after (a key; the page
 * starts past it), prefix (only keys that start with it) and where (as in
 * query()). It resolves {docs, next}: pass next as `after` ('asc') or
 * `before` ('desc') for the following page; null means there is no more.
 * query() takes order, before, after and prefix too, and then resolves the
 * docs of that page. A page may read the `archive` of a collection it can
 * read in full, a page at a time with the prefix '<collection>.', or one
 * archived item with get('archive', '<collection>.<key>'); archived keys keep
 * that prefix.
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
 *
 * Who is viewing (#808):
 *
 *   tomatio.viewer()                   -> {id, name} for the signed-in member, or null
 *
 * On a private share link it is the member the link's viewer pass names; at
 * the members address, the member signed in there (the frame's viewer token
 * was issued after the same sign-in that opened the page). A public link, a
 * seat or a page without the store resolves null. The name is for display:
 * the server records who really wrote each document.
 *
 * A link per view (#998): keep the page's view (tab, filters, the open
 * item) in its fragment and tell the address bar about it:
 *
 *   window.parent.postMessage({type: 'tomatio:hash', hash: '#view=roadmap'}, '*')
 *
 * At the members address the page runs in a frame: the outer page opens it
 * with the link's fragment (read location.hash as usual), takes the message
 * only from its own frame, and only a string that starts with '#', is at
 * most 1,000 characters and has no control characters, and puts it in the
 * address bar with history.replaceState (no navigation, no history entry).
 * Back, forward or a fragment typed into the address bar reach the page as
 * a hashchange. On a share link the page is the top document and its
 * parent is itself; this script takes the same message there. Either way a
 * share link's own parts (ab_viewer_token=, seat=, p=) are never handed to
 * the page and are kept when the page sets its fragment, so the copied
 * address still opens. Post '#' to clear the page's part.
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

	// #1058: order, before, after and prefix make the query a page ({docs, next}).
	function queryPage( collection, where, options ) {
		var body = { where: where || {} };
		options = options || {};
		[ 'limit', 'offset' ].forEach( function ( name ) {
			if ( typeof options[ name ] === 'number' ) {
				body[ name ] = options[ name ];
			}
		} );
		[ 'order', 'before', 'after', 'prefix' ].forEach( function ( name ) {
			if ( typeof options[ name ] === 'string' ) {
				body[ name ] = options[ name ];
			}
		} );

		return call( 'POST', '/' + segment( collection, 'collection' ) + '/_query', body );
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
			return queryPage( collection, where, options ).then( function ( data ) {
				return data.docs;
			} );
		},
		page: function ( collection, options ) {
			var paged = { order: 'asc' };
			Object.keys( options || {} ).forEach( function ( name ) {
				paged[ name ] = options[ name ];
			} );

			return queryPage( collection, paged.where, paged ).then( function ( data ) {
				return { docs: data.docs, next: typeof data.next === 'string' ? data.next : null };
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

	// #998: on a share link the page is the top document; keep the link's
	// own fragment parts when it sets its view (FrameHash does this at the
	// members address).
	if ( window.parent === window && window.history && window.history.replaceState ) {
		window.addEventListener( 'message', function ( event ) {
			var data = event.data;
			if ( event.source !== window || ! data || data.type !== 'tomatio:hash' || typeof data.hash !== 'string'
				|| data.hash.charAt( 0 ) !== '#' || data.hash.length > 1000 || /[\u0000-\u001f\u007f]/.test( data.hash ) ) {
				return;
			}
			var keep = /^(ab_viewer_token|seat|p)=/;
			var own = window.location.hash.slice( 1 ).split( '&' ).filter( function ( part ) {
				return keep.test( part );
			} );
			var page = data.hash.slice( 1 ).split( '&' ).filter( function ( part ) {
				return part !== '' && ! keep.test( part );
			} );
			var parts = own.concat( page );
			var hash = parts.length ? '#' + parts.join( '&' ) : '';
			if ( hash !== window.location.hash ) {
				window.history.replaceState( window.history.state, '', window.location.pathname + window.location.search + hash );
			}
		} );
	}

	// #808: who is viewing, asked of the server once per page load. Display
	// only: the server attributes every write from the viewer token itself.
	var viewing = null;
	function viewer() {
		if ( ! viewing ) {
			viewing = request( 'GET', base + '/_viewer' ).then(
				function ( data ) {
					var v = data && data.viewer;

					return v && typeof v.id === 'number' && v.id > 0
						? { id: v.id, name: typeof v.name === 'string' ? v.name : '' }
						: null;
				},
				function () {
					// No store, a revoked link or a network failure: nobody known.
					viewing = null;

					return null;
				}
			);
		}

		return viewing;
	}

	window.tomatio = window.tomatio || {};
	window.tomatio.viewer = viewer;
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
