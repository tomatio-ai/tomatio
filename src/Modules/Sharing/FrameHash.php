<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * #998: a link per view. A page at the members address (/a/{slug}) runs in
 * a sandboxed frame, so it can neither read the link's fragment nor change
 * the address bar. The outer page's one script (SCRIPT, allowed by its
 * hash in the outer page's CSP) passes the fragment both ways:
 *
 * - On load, the frame opens with the outer location.hash added, so
 *   /a/planning-board#view=roadmap opens the frame at ?frame=1#view=roadmap.
 *   The fragment never reaches the server: access checks are unchanged.
 * - The page posts {type: 'tomatio:hash', hash: '#…'} to its parent. The
 *   outer page takes it only from its own frame (event.source), only a
 *   string that starts with '#', is at most MAX characters and has no
 *   control characters, and calls history.replaceState: the address bar
 *   becomes the shareable link, with no navigation and no history entry.
 * - Back, forward or a fragment typed into the address bar (hashchange)
 *   go to the frame the same way, as a fragment navigation (the page gets
 *   its own hashchange).
 * - A share link's own fragment parts, the viewer token (ab_viewer_token=)
 *   and the seat secret (seat=, p=), are never passed to the frame and are
 *   kept when the page sets its fragment. On a share link (/share/…) the
 *   page is the top document; tomatio-store.js keeps those parts there.
 * - Dennis, 15 Nov: the page may post {type: 'tomatio:open', url} to go to
 *   another page at the members address (the TíoDay and Tomagic header
 *   links). The outer page takes it only from its own frame and only for
 *   an address on this site whose path ends in /a/{slug}, or is a folder
 *   (the brain's home, /), with no query, and goes there
 *   (location.assign). Anything else is ignored.
 */
final class FrameHash {

	/** The longest fragment a page may set. */
	public const MAX = 1000;

	/**
	 * Runs right after the outer page's <iframe>. Static, so its hash in
	 * the CSP never changes per request. Keep it in step with the copy in
	 * tests/js/frame-hash.test.js (the test reads it from here).
	 */
	public const SCRIPT = <<<'JS'
(function(){var f=document.querySelector('iframe[data-tomatio-frame]');if(!f){return;}var base=f.getAttribute('src').split('#')[0];var KEEP=/^(ab_viewer_token|seat|p)=/;function split(h){var own=[],page=[];(h.charAt(0)==='#'?h.slice(1):h).split('&').forEach(function(p){if(p!==''){(KEEP.test(p)?own:page).push(p);}});return{own:own,page:page};}function ok(h){return typeof h==='string'&&h.charAt(0)==='#'&&h.length<=1000&&!/[\u0000-\u001f\u007f]/.test(h);}var last=split(location.hash).page.join('&');function go(){var page=split(location.hash).page.join('&');if(page===last){return;}last=page;f.src=base+(page?'#'+page:'');}if(last){f.src=base+'#'+last;}window.addEventListener('message',function(e){var d=e.data;if(e.source!==f.contentWindow||!d){return;}if(d.type==='tomatio:open'){var u;try{u=new URL(String(d.url),location.href);}catch(x){return;}if(u.origin===location.origin&&(/\/a\/[a-z][a-z0-9-]*$/.test(u.pathname)||/^(\/[A-Za-z0-9._~-]+)*\/$/.test(u.pathname))&&u.search===''&&u.username===''){location.assign(u.href);}return;}if(d.type!=='tomatio:hash'||!ok(d.hash)){return;}var parts=split(location.hash).own.concat(split(d.hash).page);last=split(d.hash).page.join('&');var h=parts.length?'#'+parts.join('&'):'';if(h!==location.hash){history.replaceState(history.state,'',location.pathname+location.search+h);}});window.addEventListener('hashchange',go);})();
JS;

	/** The outer page's script tag. */
	public static function tag(): string {
		return '<script>' . self::SCRIPT . '</script>';
	}

	/** The CSP source that allows SCRIPT, and only it. */
	public static function cspSource(): string {
		return "'sha256-" . base64_encode( hash( 'sha256', self::SCRIPT, true ) ) . "'";
	}
}
