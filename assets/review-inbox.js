/**
 * TomaTío Review inbox (#701).
 *
 * On top of the server-rendered page (Review\InboxView), which works
 * without JavaScript: selecting an item without a reload, keys (j/k, a, e,
 * x, u, space, ?), editing the text in place with a live word diff, an
 * optional reason for Not needed, and deciding several checked items at
 * once. A decision leaves the list at once and waits config.delay ms with
 * an Undo button before its form is posted (with ab_js=1, so the handler
 * answers in JSON); leaving the page sends what is still waiting. All text
 * goes in through textContent, never innerHTML.
 */
( function () {
	'use strict';

	var config = window.agentBrainReview;
	var root = document.getElementById( 'ab-review' );

	if ( ! config || ! root || ! window.fetch || ! window.FormData ) {
		return;
	}

	var text = config.text || {};
	var list = root.querySelector( '.ab-ri-list' );
	var toast = root.querySelector( '.ab-ri-toast' );
	var help = root.querySelector( '.ab-ri-help' );
	var progress = root.querySelector( '.ab-ri-count' );
	var bar = root.querySelector( '.ab-ri-bar i' );
	var bulkCount = root.querySelector( '.ab-ri-bulk-count' );
	var total = parseInt( root.getAttribute( 'data-total' ), 10 ) || 0;
	var decided = 0;
	var current = null;
	var batches = [];
	var toastTimer = null;
	var helpOpener = null;
	var narrow = window.matchMedia ? window.matchMedia( '(max-width: 960px)' ) : { matches: false };

	root.classList.add( 'ab-ri-on' );
	Array.prototype.forEach.call( root.querySelectorAll( '.ab-ri-js' ), function ( node ) {
		node.hidden = false;
	} );

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( template ).replace( /%(\d\$)?[sd]/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : i++;
			return args[ index ] !== undefined ? args[ index ] : match;
		} );
	}

	function rows() {
		return list ? Array.prototype.filter.call( list.querySelectorAll( '.ab-ri-row' ), function ( row ) {
			return ! row.hidden;
		} ) : [];
	}

	function rowFor( key ) {
		return list ? list.querySelector( '.ab-ri-row[data-key="' + key + '"]' ) : null;
	}

	function detailFor( key ) {
		return document.getElementById( 'ab-ri-d-' + key );
	}

	function updateProgress() {
		if ( ! progress ) {
			return;
		}
		var left = Math.max( 0, total - decided );
		progress.textContent = format( text.left, left, total );
		if ( bar ) {
			bar.style.width = ( total ? ( ( total - left ) / total ) * 100 : 0 ) + '%';
		}
	}

	function updateBulk() {
		var count = root.querySelectorAll( '.ab-ri-row:not([hidden]) .ab-ri-check:checked' ).length;
		root.classList.toggle( 'ab-ri-selecting', count > 0 );
		if ( bulkCount ) {
			bulkCount.textContent = count ? format( text.selected, count ) : '';
		}
	}

	function select( key, focusHeading ) {
		var row = rowFor( key );
		var detail = detailFor( key );
		if ( ! row || ! detail ) {
			return;
		}

		if ( current && current !== key ) {
			stopEditing( detailFor( current ), true );
		}

		Array.prototype.forEach.call( list.querySelectorAll( '.ab-ri-link[aria-current]' ), function ( link ) {
			link.removeAttribute( 'aria-current' );
		} );
		row.querySelector( '.ab-ri-link' ).setAttribute( 'aria-current', 'true' );
		Array.prototype.forEach.call( root.querySelectorAll( '.ab-ri-detail' ), function ( node ) {
			node.hidden = node !== detail;
		} );
		current = key;
		row.scrollIntoView( { block: 'nearest' } );

		if ( window.history && window.history.replaceState ) {
			var url = new URL( window.location.href );
			url.searchParams.set( 'item', key );
			window.history.replaceState( null, '', url.toString() );
		}

		if ( narrow.matches ) {
			root.classList.add( 'ab-ri-show-detail' );
		}
		if ( focusHeading || narrow.matches ) {
			var heading = detail.querySelector( 'h2' );
			if ( heading ) {
				heading.focus();
			}
		}
	}

	function move( step ) {
		var visible = rows();
		if ( ! visible.length ) {
			return;
		}
		var index = visible.indexOf( rowFor( current ) );
		var next = visible[ Math.max( 0, Math.min( visible.length - 1, index + step ) ) ];
		select( next.getAttribute( 'data-key' ), false );
	}

	/* ---------------------------------------------------------- word diff */

	function words( value ) {
		return String( value ).trim().split( /\s+/ ).filter( Boolean );
	}

	function diffWords( before, after ) {
		var a = words( before );
		var b = words( after );
		var n = a.length;
		var m = b.length;
		if ( n * m > 250000 ) {
			return [ [ 'del', a.join( ' ' ) ], [ 'ins', b.join( ' ' ) ] ];
		}
		var table = [];
		var i;
		var j;
		for ( i = 0; i <= n; i++ ) {
			table.push( new Uint16Array( m + 1 ) );
		}
		for ( i = n - 1; i >= 0; i-- ) {
			for ( j = m - 1; j >= 0; j-- ) {
				table[ i ][ j ] = a[ i ] === b[ j ] ? table[ i + 1 ][ j + 1 ] + 1 : Math.max( table[ i + 1 ][ j ], table[ i ][ j + 1 ] );
			}
		}
		var out = [];
		function push( kind, word ) {
			var last = out[ out.length - 1 ];
			if ( last && last[ 0 ] === kind ) {
				last[ 1 ] += ' ' + word;
			} else {
				out.push( [ kind, word ] );
			}
		}
		i = 0;
		j = 0;
		while ( i < n && j < m ) {
			if ( a[ i ] === b[ j ] ) {
				push( 'same', a[ i ] );
				i++;
				j++;
			} else if ( table[ i + 1 ][ j ] >= table[ i ][ j + 1 ] ) {
				push( 'del', a[ i++ ] );
			} else {
				push( 'ins', b[ j++ ] );
			}
		}
		while ( i < n ) {
			push( 'del', a[ i++ ] );
		}
		while ( j < m ) {
			push( 'ins', b[ j++ ] );
		}
		return out;
	}

	function showLiveDiff( detail ) {
		var area = detail.querySelector( '.ab-ri-textarea' );
		var live = detail.querySelector( '.ab-ri-live' );
		if ( ! area || ! live ) {
			return;
		}
		live.textContent = '';
		if ( words( area.value ).join( ' ' ) === words( area.defaultValue ).join( ' ' ) ) {
			live.textContent = text.noChange;
			return;
		}
		live.appendChild( document.createTextNode( text.changes + ' ' ) );
		diffWords( area.defaultValue, area.value ).forEach( function ( part ) {
			var node = part[ 0 ] === 'same' ? document.createElement( 'span' ) : document.createElement( part[ 0 ] );
			node.textContent = part[ 1 ];
			live.appendChild( node );
			live.appendChild( document.createTextNode( ' ' ) );
		} );
	}

	function autosize( area ) {
		area.style.height = 'auto';
		area.style.height = area.scrollHeight + 2 + 'px';
	}

	/* ------------------------------------------------- edit and Not needed */

	function startEditing( detail ) {
		var edit = detail && detail.querySelector( '.ab-ri-edit' );
		if ( ! edit ) {
			return;
		}
		stopReason( detail );
		edit.open = true;
		detail.classList.add( 'is-editing' );
		var area = edit.querySelector( 'textarea' );
		autosize( area );
		area.focus();
		area.setSelectionRange( area.value.length, area.value.length );
		showLiveDiff( detail );
	}

	function stopEditing( detail, keepText ) {
		var edit = detail && detail.querySelector( '.ab-ri-edit' );
		if ( ! edit || ! edit.open ) {
			return;
		}
		edit.open = false;
		detail.classList.remove( 'is-editing' );
		if ( ! keepText ) {
			var area = edit.querySelector( 'textarea' );
			area.value = area.defaultValue;
		}
	}

	function startReason( detail ) {
		var reason = detail.querySelector( '.ab-ri-reason' );
		if ( ! reason ) {
			return false;
		}
		stopEditing( detail, true );
		reason.open = true;
		detail.classList.add( 'is-rejecting' );
		var first = reason.querySelector( 'input' );
		if ( first ) {
			first.focus();
		}
		return true;
	}

	function stopReason( detail ) {
		var reason = detail && detail.querySelector( '.ab-ri-reason' );
		if ( reason && reason.open ) {
			reason.open = false;
			detail.classList.remove( 'is-rejecting' );
		}
	}

	/* --------------------------------------------------------- decisions */

	function formData( form, button ) {
		var data = new FormData( form );
		if ( button && button.name ) {
			data.append( button.name, button.value );
		}
		data.append( 'ab_js', '1' );
		return data;
	}

	function titleOf( detail ) {
		var heading = detail.querySelector( 'h2' );
		return heading ? heading.textContent : '';
	}

	function outcomeWord( button ) {
		return ( button && button.getAttribute( 'data-done' ) ) || '';
	}

	/**
	 * Queue one or more decisions as one batch: the rows leave now, the
	 * forms are posted when the Undo window ends.
	 */
	function decide( pairs ) {
		if ( ! pairs.length ) {
			return;
		}

		flush();

		var visible = rows();
		var last = visible.indexOf( rowFor( pairs[ pairs.length - 1 ].key ) );
		var batch = { items: [], timer: null };

		pairs.forEach( function ( pair ) {
			var row = rowFor( pair.key );
			var detail = detailFor( pair.key );
			if ( ! row || ! detail ) {
				return;
			}
			stopEditing( detail, true );
			stopReason( detail );
			batch.items.push( {
				key: pair.key,
				row: row,
				detail: detail,
				data: formData( detail.querySelector( 'form' ), pair.button ),
			} );
			row.hidden = true;
			detail.hidden = true;
			var check = row.querySelector( '.ab-ri-check' );
			if ( check ) {
				check.checked = false;
			}
		} );

		if ( ! batch.items.length ) {
			return;
		}

		decided += batch.items.length;
		updateProgress();
		updateBulk();

		var remaining = rows();
		if ( remaining.length ) {
			var next = remaining[ Math.min( Math.max( last - batch.items.length + 1, 0 ), remaining.length - 1 ) ];
			select( next.getAttribute( 'data-key' ), false );
		} else {
			current = null;
			caughtUp( true );
		}

		var message = batch.items.length === 1
			? format( text.decided, titleOf( batch.items[ 0 ].detail ), outcomeWord( pairs[ 0 ].button ) )
			: format( text.decidedN, batch.items.length, outcomeWord( pairs[ 0 ].button ) );

		batch.timer = window.setTimeout( function () {
			send( batch );
		}, config.delay || 6000 );
		batches.push( batch );
		showToast( message, true );
	}

	function send( batch ) {
		var index = batches.indexOf( batch );
		if ( index === -1 ) {
			return;
		}
		batches.splice( index, 1 );
		window.clearTimeout( batch.timer );
		if ( ! batches.length ) {
			hideToast();
		}

		batch.items.forEach( function ( item ) {
			window.fetch( config.endpoint, { method: 'POST', body: item.data, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json().catch( function () {
						return { success: false };
					} );
				} )
				.then( function ( answer ) {
					if ( answer && answer.success ) {
						item.row.parentNode.removeChild( item.row );
						item.detail.parentNode.removeChild( item.detail );
						return;
					}
					restore( item );
					var why = answer && answer.data && answer.data.message ? ' ' + answer.data.message : '';
					showToast( text.failed + why, false );
				} )
				.catch( function () {
					restore( item );
					showToast( text.failed, false );
				} );
		} );
	}

	function restore( item ) {
		item.row.hidden = false;
		decided = Math.max( 0, decided - 1 );
		caughtUp( false );
		updateProgress();
		select( item.key, false );
	}

	function undo() {
		var batch = batches.pop();
		if ( ! batch ) {
			return;
		}
		window.clearTimeout( batch.timer );
		batch.items.forEach( function ( item ) {
			item.row.hidden = false;
			decided = Math.max( 0, decided - 1 );
		} );
		caughtUp( false );
		updateProgress();
		select( batch.items[ 0 ].key, true );
		showToast( text.undone, false );
	}

	/** Sends every waiting decision now: a new decision, or leaving the page. */
	function flush( beacon ) {
		batches.slice().forEach( function ( batch ) {
			if ( beacon && navigator.sendBeacon ) {
				window.clearTimeout( batch.timer );
				batch.items.forEach( function ( item ) {
					navigator.sendBeacon( config.endpoint, item.data );
				} );
				batches.splice( batches.indexOf( batch ), 1 );
			} else {
				send( batch );
			}
		} );
	}

	function caughtUp( on ) {
		var note = root.querySelector( '.ab-ri-caught' );
		if ( on && ! note ) {
			note = document.createElement( 'p' );
			note.className = 'ab-ri-caught';
			note.setAttribute( 'tabindex', '-1' );
			note.textContent = text.caught;
			root.querySelector( '.ab-ri-details' ).appendChild( note );
			note.focus();
		} else if ( ! on && note ) {
			note.parentNode.removeChild( note );
		}
	}

	function showToast( message, withUndo ) {
		if ( ! toast ) {
			return;
		}
		window.clearTimeout( toastTimer );
		toast.textContent = '';
		var span = document.createElement( 'span' );
		span.textContent = message;
		toast.appendChild( span );
		if ( withUndo ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'button';
			button.textContent = text.undo + ' ';
			var key = document.createElement( 'kbd' );
			key.textContent = 'u';
			button.appendChild( key );
			button.addEventListener( 'click', undo );
			toast.appendChild( button );
			var drain = document.createElement( 'span' );
			drain.className = 'ab-ri-drain';
			drain.style.animationDuration = ( config.delay || 6000 ) + 'ms';
			toast.appendChild( drain );
		} else {
			toastTimer = window.setTimeout( hideToast, 4000 );
		}
		toast.hidden = false;
	}

	function hideToast() {
		if ( toast ) {
			toast.hidden = true;
		}
	}

	/* ------------------------------------------------------------ events */

	if ( list ) {
		list.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '.ab-ri-link' );
			if ( ! link || event.metaKey || event.ctrlKey || event.shiftKey ) {
				return;
			}
			event.preventDefault();
			select( link.parentNode.getAttribute( 'data-key' ), false );
		} );
		list.addEventListener( 'change', function ( event ) {
			if ( event.target.classList.contains( 'ab-ri-check' ) ) {
				updateBulk();
			}
		} );
	}

	// The bulk buttons decide each checked item through its own form.
	var listForm = root.querySelector( '.ab-ri-listform' );
	if ( listForm ) {
		listForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var outcome = event.submitter ? event.submitter.value : 'approved';
			var pairs = [];
			Array.prototype.forEach.call( root.querySelectorAll( '.ab-ri-row:not([hidden]) .ab-ri-check:checked' ), function ( check ) {
				var key = check.closest( '.ab-ri-row' ).getAttribute( 'data-key' );
				var detail = detailFor( key );
				var button = detail && detail.querySelector( '.ab-ri-actions [data-act="' + ( outcome === 'rejected' ? 'reject' : 'approve' ) + '"]' );
				if ( button ) {
					pairs.push( { key: key, button: button } );
				}
			} );
			decide( pairs );
		} );
	}

	root.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.classList.contains( 'ab-ri-form' ) ) {
			return;
		}
		event.preventDefault();
		var button = event.submitter || form.querySelector( '[data-act="approve"]' );
		var detail = form.closest( '.ab-ri-detail' );
		// Not needed on a fact asks for the optional reason first.
		if ( button && button.getAttribute( 'data-act' ) === 'reject' && detail.querySelector( '.ab-ri-reason' ) && ! detail.classList.contains( 'is-rejecting' ) ) {
			startReason( detail );
			return;
		}
		decide( [ { key: detail.getAttribute( 'data-key' ), button: button } ] );
	} );

	root.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( '[data-act], [data-pane], .ab-ri-help-open, .ab-ri-help-close, .ab-ri-back' );
		if ( ! target ) {
			return;
		}
		var detail = target.closest( '.ab-ri-detail' );
		if ( target.classList.contains( 'ab-ri-help-open' ) ) {
			openHelp( target );
		} else if ( target.classList.contains( 'ab-ri-help-close' ) ) {
			closeHelp();
		} else if ( target.classList.contains( 'ab-ri-back' ) ) {
			event.preventDefault();
			root.classList.remove( 'ab-ri-show-detail' );
			var link = rowFor( current ) && rowFor( current ).querySelector( '.ab-ri-link' );
			if ( link ) {
				link.focus();
			}
		} else if ( target.hasAttribute( 'data-pane' ) && target.tagName === 'BUTTON' ) {
			var pane = target.getAttribute( 'data-pane' );
			Array.prototype.forEach.call( detail.querySelectorAll( '.ab-ri-seg button' ), function ( button ) {
				button.setAttribute( 'aria-pressed', button === target ? 'true' : 'false' );
			} );
			Array.prototype.forEach.call( detail.querySelectorAll( 'div[data-pane]' ), function ( node ) {
				node.hidden = node.getAttribute( 'data-pane' ) !== pane;
			} );
			var legend = detail.querySelector( '.ab-ri-diff-key' );
			if ( legend ) {
				legend.hidden = pane !== 'changes';
			}
		} else {
			var act = target.getAttribute( 'data-act' );
			if ( act === 'edit' ) {
				startEditing( detail );
			} else if ( act === 'cancel' ) {
				stopEditing( detail, false );
				stopReason( detail );
			} else if ( act === 'next' ) {
				move( 1 );
			}
		}
	} );

	root.addEventListener( 'input', function ( event ) {
		if ( event.target.classList.contains( 'ab-ri-textarea' ) ) {
			autosize( event.target );
			showLiveDiff( event.target.closest( '.ab-ri-detail' ) );
		}
	} );

	Array.prototype.forEach.call( root.querySelectorAll( '.ab-ri-selects select' ), function ( select ) {
		select.addEventListener( 'change', function () {
			select.form.submit();
		} );
	} );

	function openHelp( opener ) {
		if ( ! help ) {
			return;
		}
		helpOpener = opener || document.activeElement;
		help.hidden = false;
		help.querySelector( '.ab-ri-help-close' ).focus();
	}

	function closeHelp() {
		if ( help && ! help.hidden ) {
			help.hidden = true;
			if ( helpOpener && helpOpener.focus ) {
				helpOpener.focus();
			}
		}
	}

	function clickAct( act ) {
		var detail = current && detailFor( current );
		var button = detail && detail.querySelector( '.ab-ri-actions [data-act="' + act + '"]' );
		if ( button ) {
			button.click();
		}
	}

	document.addEventListener( 'keydown', function ( event ) {
		var detail = current && detailFor( current );
		// A radio or checkbox takes no text, so the letter keys still work
		// there (x on a reason choice confirms Not needed); arrows and space
		// keep their own meaning on it.
		var choice = event.target.matches && event.target.matches( 'input[type="radio"], input[type="checkbox"]' );
		var typing = ! choice && event.target.closest && event.target.closest( 'input, textarea, select, [contenteditable="true"]' );

		if ( event.key === 'Escape' ) {
			if ( help && ! help.hidden ) {
				closeHelp();
			} else if ( detail && ( detail.classList.contains( 'is-editing' ) || detail.classList.contains( 'is-rejecting' ) ) ) {
				stopEditing( detail, false );
				stopReason( detail );
				var primary = detail.querySelector( '.ab-ri-actions [data-act="approve"]' );
				if ( primary ) {
					primary.focus();
				}
			}
			return;
		}

		if ( event.key === 'Enter' && ( event.metaKey || event.ctrlKey ) && detail && detail.classList.contains( 'is-editing' ) ) {
			event.preventDefault();
			detail.querySelector( '[data-act="save"]' ).click();
			return;
		}

		if ( typing || event.metaKey || event.ctrlKey || event.altKey || ( help && ! help.hidden ) ) {
			return;
		}

		switch ( event.key ) {
			case 'ArrowDown':
			case 'ArrowUp':
				if ( choice ) {
					break;
				}
				event.preventDefault();
				move( event.key === 'ArrowDown' ? 1 : -1 );
				break;
			case 'j':
			case 'k':
				event.preventDefault();
				move( event.key === 'j' ? 1 : -1 );
				break;
			case 'a':
				clickAct( 'approve' );
				break;
			case 'x':
				clickAct( 'reject' );
				break;
			case 'e':
				if ( detail ) {
					event.preventDefault();
					startEditing( detail );
				}
				break;
			case 'u':
				undo();
				break;
			case '?':
				openHelp();
				break;
			case ' ':
				if ( current && ! choice && ! event.target.closest( 'button, a' ) ) {
					event.preventDefault();
					var check = rowFor( current ).querySelector( '.ab-ri-check' );
					check.checked = ! check.checked;
					updateBulk();
				}
				break;
		}
	} );

	window.addEventListener( 'pagehide', function () {
		flush( true );
	} );

	var first = list && list.querySelector( '.ab-ri-link[aria-current]' );
	current = first ? first.parentNode.getAttribute( 'data-key' ) : null;
	updateProgress();
	updateBulk();
}() );
