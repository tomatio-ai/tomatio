/**
 * #751: keys for the list-and-detail pages (Admin\Ui::split). j and k open
 * the next and previous row, / goes to the search box. Rows are links, so
 * everything also works without this script. A link to a closed <details>
 * (Documents' "New document") opens it.
 */
( function () {
	'use strict';

	function openTarget() {
		var el = window.location.hash.length > 1 ? document.getElementById( window.location.hash.slice( 1 ) ) : null;
		if ( el && el.tagName === 'DETAILS' ) {
			el.open = true;
			var field = el.querySelector( 'input:not([type="hidden"]), select, textarea' );
			if ( field ) {
				field.focus();
			}
		}
	}
	window.addEventListener( 'hashchange', openTarget );
	openTarget();

	var split = document.querySelector( '[data-ab-split]' );
	if ( ! split ) {
		return;
	}
	document.documentElement.classList.add( 'ab-ui-on' );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey ) {
			return;
		}
		var t = e.target;
		if ( t && ( t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( t.tagName ) ) ) {
			return;
		}

		if ( e.key === '/' ) {
			var search = document.querySelector( '[data-ab-search]' );
			if ( search ) {
				e.preventDefault();
				search.focus();
				search.select();
			}
			return;
		}

		if ( e.key !== 'j' && e.key !== 'k' ) {
			return;
		}
		var links = Array.prototype.slice.call( split.querySelectorAll( '.ab-ui-row-link' ) );
		var at = links.findIndex( function ( a ) { return a.getAttribute( 'aria-current' ) === 'true'; } );
		var next = links[ at + ( e.key === 'j' ? 1 : -1 ) ];
		if ( next ) {
			e.preventDefault();
			window.location.href = next.href;
		}
	} );
}() );
