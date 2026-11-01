/**
 * TomaTío Overview: live updates.
 *
 * Polls GET agent-brain/v1/admin/overview (see Admin\OverviewRest) every few
 * seconds while the tab is visible, and updates the counts, the head's "N
 * things need you", the Review tile, the activity feed and the latest
 * memories in place. New items get a short highlight. All text goes in
 * through textContent, never innerHTML. Without JavaScript the
 * server-rendered page still works; it just doesn't update.
 */
( function () {
	'use strict';

	var config = window.agentBrainOverview;
	var root = document.getElementById( 'ab-overview' );

	if ( ! config || ! root || ! window.fetch || ! document.getElementById( 'ab-live' ) ) {
		return;
	}

	var seen = { activity: {}, memories: {} };
	// Problems are worked out once, on the server render; the poll only
	// moves the counts.
	var problems = Number( root.getAttribute( 'data-ab-problems' ) ) || 0;
	var live = document.getElementById( 'ab-live' );
	var first = true;
	var timer = null;

	function rememberServerRendered( listId, bucket ) {
		var items = document.querySelectorAll( '#' + listId + ' [data-id]' );
		Array.prototype.forEach.call( items, function ( item ) {
			seen[ bucket ][ item.getAttribute( 'data-id' ) ] = true;
		} );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	function relative( iso ) {
		var then = Date.parse( iso );
		if ( isNaN( then ) ) {
			return '';
		}
		var seconds = Math.max( 0, Math.round( ( Date.now() - then ) / 1000 ) );
		var text;
		if ( seconds < 10 ) {
			return config.i18n.justNow;
		} else if ( seconds < 60 ) {
			text = seconds + ' sec';
		} else if ( seconds < 3600 ) {
			text = Math.round( seconds / 60 ) + ' min';
		} else if ( seconds < 86400 ) {
			text = Math.round( seconds / 3600 ) + ' h';
		} else {
			text = Math.round( seconds / 86400 ) + ' d';
		}
		return config.i18n.ago.replace( '%s', text );
	}

	function timeTag( iso ) {
		var time = el( 'time', 'ab-time', relative( iso ) );
		time.setAttribute( 'datetime', iso );
		return time;
	}

	function avatar( name, id ) {
		var span = el( 'span', 'ab-avatar ab-hue-' + ( Math.abs( id ) % 6 ), ( name || '?' ).charAt( 0 ).toUpperCase() );
		span.setAttribute( 'aria-hidden', 'true' );
		return span;
	}

	function activityItem( row ) {
		var li = el( 'li', 'ab-feed-item' );
		var body = el( 'div', 'ab-feed-body' );
		var text = el( 'span', 'ab-feed-text' );
		text.appendChild( el( 'strong', '', row.agent ) );
		text.appendChild( document.createTextNode( ' ' + row.action ) );
		body.appendChild( text );
		if ( row.status && row.status !== 'ok' ) {
			body.appendChild( document.createTextNode( ' ' ) );
			body.appendChild( el( 'span', 'ab-badge ab-badge-' + row.status, row.status === 'error' ? config.i18n.failed : row.status ) );
		}
		body.appendChild( document.createTextNode( ' ' ) );
		body.appendChild( timeTag( row.ts ) );
		li.appendChild( avatar( row.agent, row.agent_id ) );
		li.appendChild( body );
		return li;
	}

	function memoryItem( row ) {
		var li = el( 'li', 'ab-feed-item' );
		var body = el( 'div', 'ab-feed-body' );
		var meta = el( 'span', 'ab-meta' );
		body.appendChild( el( 'p', 'ab-memory', row.excerpt ) );
		meta.appendChild( el( 'strong', '', row.agent ) );
		if ( row.scope ) {
			meta.appendChild( document.createTextNode( ' ' ) );
			meta.appendChild( el( 'span', 'ab-chip', row.scope ) );
		}
		meta.appendChild( document.createTextNode( ' ' ) );
		meta.appendChild( el( 'span', 'ab-type', row.type ) );
		meta.appendChild( document.createTextNode( ' ' ) );
		meta.appendChild( timeTag( row.ts ) );
		body.appendChild( meta );
		li.appendChild( avatar( row.agent, row.agent_id ) );
		li.appendChild( body );
		return li;
	}

	function renderList( listId, bucket, rows, build, emptyText ) {
		var list = document.getElementById( listId );
		if ( ! list ) {
			return;
		}
		var fresh = document.createDocumentFragment();
		if ( ! rows.length ) {
			fresh.appendChild( el( 'li', 'ab-empty', emptyText ) );
		}
		rows.forEach( function ( row ) {
			var item = build( row );
			var key = String( row.id );
			item.setAttribute( 'data-id', key );
			if ( ! seen[ bucket ][ key ] && ! first ) {
				item.classList.add( 'ab-new' );
			}
			seen[ bucket ][ key ] = true;
			fresh.appendChild( item );
		} );
		list.replaceChildren( fresh );
	}

	function setCount( key, value ) {
		var node = root.querySelector( '[data-ab-count="' + key + '"]' );
		if ( node && node.textContent !== String( value ) ) {
			node.textContent = String( value );
			node.classList.remove( 'ab-bump' );
			void node.offsetWidth;
			node.classList.add( 'ab-bump' );
		}
	}

	function setStatus( needs ) {
		var status = root.querySelector( '.ab-ui-status' );
		if ( ! status ) {
			return;
		}
		var parts = ( needs === 1 ? config.i18n.needsOne : config.i18n.needsMany ).split( '%s' );
		var text = needs ? parts.join( String( needs ) ) : config.i18n.needsNone;
		if ( status.textContent === text ) {
			return;
		}
		if ( ! needs ) {
			status.textContent = text;
			return;
		}
		var fresh = document.createDocumentFragment();
		fresh.appendChild( document.createTextNode( parts[ 0 ] ) );
		fresh.appendChild( el( 'b', '', String( needs ) ) );
		fresh.appendChild( document.createTextNode( parts.slice( 1 ).join( '%s' ) ) );
		status.replaceChildren( fresh );
	}

	function apply( data ) {
		var counts = data.counts || {};
		var stages = data.stages || {};
		var waiting = ( Number( counts.review ) || 0 ) + ( Number( counts.handoffs ) || 0 );
		var documents = 0;
		var tile = root.querySelector( '[data-ab-need="waiting"]' );

		Object.keys( counts ).forEach( function ( key ) {
			setCount( key, counts[ key ] );
		} );
		setCount( 'waiting', waiting );
		if ( tile ) {
			tile.hidden = waiting === 0;
		}
		setStatus( waiting + problems );

		Object.keys( stages ).forEach( function ( stage ) {
			var node = root.querySelector( '[data-ab-stage="' + stage + '"]' );
			documents += Number( stages[ stage ] ) || 0;
			if ( node ) {
				node.textContent = String( stages[ stage ] );
			}
		} );
		setCount( 'documents', documents );
		renderList( 'ab-activity', 'activity', data.activity || [], activityItem, config.i18n.noActivity );
		renderList( 'ab-memories', 'memories', data.memories || [], memoryItem, data.scopeless ? config.i18n.noScopes : config.i18n.noMemories );
		first = false;
	}

	function refreshTimes() {
		Array.prototype.forEach.call( root.querySelectorAll( 'time.ab-time' ), function ( time ) {
			time.textContent = relative( time.getAttribute( 'datetime' ) );
		} );
	}

	function poll() {
		if ( document.hidden ) {
			return;
		}
		window
			.fetch( config.endpoint, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.nonce, Accept: 'application/json' },
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				return response.json();
			} )
			.then( function ( data ) {
				apply( data );
				live.classList.remove( 'ab-live-paused' );
			} )
			.catch( function () {
				live.classList.add( 'ab-live-paused' );
			} );
	}

	rememberServerRendered( 'ab-activity', 'activity' );
	rememberServerRendered( 'ab-memories', 'memories' );
	first = false;
	live.classList.remove( 'ab-live-off' );

	timer = window.setInterval( function () {
		poll();
		refreshTimes();
	}, config.interval || 4000 );

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			poll();
		}
	} );

	window.addEventListener( 'pagehide', function () {
		window.clearInterval( timer );
	} );
} )();
