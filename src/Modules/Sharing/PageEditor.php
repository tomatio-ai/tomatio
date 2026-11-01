<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\RestNamespaces;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\DataStore\StoreClient;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * #632: edit a page in place. An HTML artifact whose index.html has no
 * scripts of its own works as a document, so the people who may edit it
 * (curators, managers, the lead agent's person) get an Edit button on its
 * members address (/a/{slug}?edit=1). Pages with scripts are apps and get
 * no editor.
 *
 * In edit mode the page stays in its sandboxed frame, with the editor
 * (assets/page-editor.js) put in. The frame cannot reach the site, so the
 * members page around it saves: on Save it asks the frame for the edited
 * body and posts it here,
 *
 *   POST /wp-json/agent-brain/v1/artifacts/{id}/page-edit  {body, base}
 *
 * which puts it into the page's own index.html (head untouched), writes a
 * new HTML version under the person's name (HtmlFiles::put, so it is in the
 * version history) and makes it live at once. Share links never change for
 * a signed-in visitor (their render never reads cookies), so editing lives
 * on the members address only.
 */
final class PageEditor {

	public const ASSET  = '/assets/page-editor.js';
	public const ROUTE  = '/artifacts/(?P<id>\d+)/page-edit';
	public const PARAM  = 'edit';
	public const SAVED  = 'saved';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
	}

	public static function registerRoutes(): void {
		RestNamespaces::register(
			self::ROUTE,
			[
				'methods'             => 'POST',
				'permission_callback' => static fn (): bool => is_user_logged_in(),
				'callback'            => [ self::class, 'rest' ],
				'args'                => [
					'id'   => [ 'type' => 'integer', 'required' => true ],
					'body' => [ 'type' => 'string', 'required' => true ],
					'base' => [ 'type' => 'integer', 'required' => true ],
				],
			]
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest( \WP_REST_Request $request ) {
		$result = self::save( get_current_user_id(), (int) $request['id'], (string) $request['body'], (int) $request['base'] );

		return $result instanceof \WP_Error ? $result : new \WP_REST_Response( $result, 200 );
	}

	/**
	 * May this person edit the artifact in place: a person (never an agent
	 * on its own authority) who may edit its files.
	 */
	public static function canEdit( int $actorId, int $artifactId ): bool {
		return $actorId > 0 && ! Capabilities::isPureAgent( $actorId ) && HtmlFiles::canEdit( $actorId, $artifactId );
	}

	/**
	 * #643: the version a signed-in person may edit in place, or null: the
	 * members address check (MembersAddress::canSeeAddress), the editor
	 * check (canEdit) and a page that works as a document. The members
	 * address and share links ask exactly this.
	 *
	 * @return array{version:int,files:array<string, mixed>}|null
	 */
	public static function editableFor( int $actorId, int $artifactId ): ?array {
		if ( ! MembersAddress::canSeeAddress( $actorId, $artifactId ) || ! self::canEdit( $actorId, $artifactId ) ) {
			return null;
		}

		return self::editableVersion( $artifactId );
	}

	/**
	 * Whether a page works as a document: a body to edit and no scripts of
	 * its own. A plain reference to the store client (_tomatio.js) does not
	 * count; any other script element, inline event handler, javascript:
	 * address or srcdoc frame does.
	 */
	public static function isDocumentLike( string $html ): bool {
		return preg_match( '#<body\b#i', $html ) === 1 && ! self::hasOwnScripts( $html );
	}

	public static function hasOwnScripts( string $html ): bool {
		$withoutClient = (string) preg_replace( '#<script\b[^>]*\bsrc\s*=\s*(["\']?)(?:\./)?' . preg_quote( StoreClient::PATH, '#' ) . '\1[^>]*>\s*</script\s*>#i', '', $html );

		return preg_match( '#<script\b|<[a-z][^>]*\son[a-z]+\s*=|<[a-z][^>]*\s(?:href|src|action|formaction|data|xlink:href)\s*=\s*(["\']?)\s*javascript:|<iframe\b[^>]*\ssrcdoc\s*=#i', $withoutClient ) === 1;
	}

	/**
	 * The served version when the page can be edited in place, else null.
	 *
	 * @return array{version:int,files:array<string, mixed>}|null
	 */
	public static function editableVersion( int $artifactId ): ?array {
		if ( ! HtmlFiles::isHtml( $artifactId ) || Interactive::killSwitchOn() ) {
			return null;
		}

		$version = HtmlFiles::servedVersion( $artifactId );
		if ( $version === null || ! isset( $version['files'][ HtmlFiles::ENTRY ] ) ) {
			return null;
		}

		return self::isDocumentLike( HtmlFiles::bytes( $version['files'][ HtmlFiles::ENTRY ] ) ) ? $version : null;
	}

	/**
	 * Save an edited body: a new version of the page, live at once.
	 *
	 * @param int $base The version the editor opened; anything newer since is a conflict.
	 * @return array{version:int,live:bool,url:string}|\WP_Error
	 */
	public static function save( int $actorId, int $artifactId, string $body, int $base ) {
		if ( ! self::canEdit( $actorId, $artifactId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		$version = self::editableVersion( $artifactId );
		if ( $version === null ) {
			return new \WP_Error( 'ab_page_not_editable', __( 'This page cannot be edited in place: it has scripts of its own, or nothing is published yet.', 'agent-brain' ), [ 'status' => 409 ] );
		}

		if ( $base !== (int) $version['version'] || HtmlFiles::latestNumber( $artifactId ) !== (int) $version['version'] ) {
			return new \WP_Error( 'ab_page_changed', __( 'The page changed while you were editing it. Copy your changes, reload and edit the new version.', 'agent-brain' ), [ 'status' => 409 ] );
		}

		$body = self::clean( $body );
		if ( self::hasOwnScripts( $body ) ) {
			return new \WP_Error( 'ab_page_scripts', __( 'An edited page cannot add scripts.', 'agent-brain' ), [ 'status' => 400 ] );
		}

		$index = self::withBody( HtmlFiles::bytes( $version['files'][ HtmlFiles::ENTRY ] ), $body );
		$user  = get_userdata( $actorId );
		/* translators: %s: the name of the person who edited the page. */
		$note = sprintf( __( 'Edited in the page by %s', 'agent-brain' ), $user instanceof \WP_User ? $user->display_name : '#' . $actorId );

		$put = HtmlFiles::put( $artifactId, $actorId, [ [ 'path' => HtmlFiles::ENTRY, 'content' => $index ] ], [], $note );
		if ( $put instanceof \WP_Error ) {
			return $put;
		}

		// Live at once: approved, unless the page already serves its latest version.
		if ( HtmlFiles::mode( $artifactId ) !== HtmlFiles::MODE_LATEST ) {
			$approved = HtmlFiles::approve( $artifactId, $actorId, (int) $put['version'] );
			if ( $approved instanceof \WP_Error ) {
				return $approved;
			}
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'artifact_page_edited',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'version' => (int) $put['version'], 'from' => $base ],
			]
		);

		return [
			'version' => (int) $put['version'],
			'live'    => HtmlFiles::servedNumber( $artifactId ) === (int) $put['version'],
			'url'     => add_query_arg( self::SAVED, (string) $put['version'], MembersAddress::url( $artifactId ) ),
		];
	}

	/**
	 * #632: the page in edit mode: a bar with Save and Cancel above the
	 * editing frame. Save asks the frame for the edited body and posts it
	 * (the REST route above, with the person's REST nonce). #643: the
	 * members address and share links (ShareEditor) both serve it, each
	 * with its own addresses and headers.
	 *
	 * @param array{version:int,files:array<string, mixed>} $version
	 * @param array<string, string>                          $headers The address's own headers; the policy is replaced.
	 * @param array{back:string,frame:string,icon:string,nonce:string,done:string,ancestors:string} $at
	 *        back: where Cancel goes; frame: the editing frame; icon: the tab icon;
	 *        nonce: the person's wp_rest nonce; done: after a save, this address
	 *        plus the new version number ('' = the address the save answers);
	 *        ancestors: the frame-ancestors sources of the edit page.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function editPage( int $artifactId, array $version, array $headers, array $at ): array {
		$title = (string) get_post( $artifactId )->post_title;
		$nonce = bin2hex( random_bytes( 16 ) );

		$headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; frame-src 'self'; script-src 'nonce-" . $nonce . "'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors " . $at['ancestors'];

		$body = '<div class="ab-edit-bar" data-endpoint="' . esc_url( rest_url( 'agent-brain/v1/artifacts/' . $artifactId . '/page-edit' ) ) . '"'
			. ' data-nonce="' . esc_attr( $at['nonce'] ) . '" data-base="' . (int) $version['version'] . '"'
			. ( $at['done'] !== '' ? ' data-done="' . esc_url( $at['done'] ) . '"' : '' ) . '>'
			. '<span class="ab-edit-title">' . esc_html__( 'Editing', 'agent-brain' ) . ' <b>' . esc_html( $title ) . '</b></span>'
			. '<span class="ab-edit-status" id="ab-edit-status" aria-live="polite">' . esc_html__( 'Loading…', 'agent-brain' ) . '</span>'
			. '<a class="ab-edit-cancel" href="' . esc_url( $at['back'] ) . '">' . esc_html__( 'Cancel', 'agent-brain' ) . '</a>'
			. '<button type="button" class="ab-edit-save" id="ab-edit-save" disabled>' . esc_html__( 'Save and publish', 'agent-brain' ) . '</button>'
			. '</div>'
			. '<iframe sandbox="allow-scripts" referrerpolicy="no-referrer" title="' . esc_attr( $title ) . '" src="' . esc_url( $at['frame'] ) . '"></iframe>'
			. '<script nonce="' . esc_attr( $nonce ) . '">' . self::editScript() . '</script>';

		$style = 'html,body{margin:0;height:100%;background:#f6f7f9;color:#16181d;font:15px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif}'
			. 'body{display:flex;flex-direction:column}'
			. '.ab-edit-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:8px max(16px,env(safe-area-inset-left,0px));background:#16181d;color:#fff}'
			. '.ab-edit-title{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
			. '.ab-edit-status{color:#c7ccd6;font-size:14px}.ab-edit-status.is-bad{color:#ffb4b4}'
			. '.ab-edit-cancel{color:#fff;padding:8px 12px}'
			. '.ab-edit-save{font:inherit;font-weight:600;padding:9px 16px;border:0;border-radius:999px;background:#16a34a;color:#fff;cursor:pointer}'
			. '.ab-edit-save:disabled{opacity:.55;cursor:default}'
			. 'iframe{flex:1 1 auto;display:block;width:100%;border:0;min-height:0}'
			. '@media (prefers-color-scheme:dark){html,body{background:#111318;color:#e8eaf0}}';

		return [
			'status'  => 200,
			'headers' => $headers,
			'body'    => '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">'
				. '<meta name="viewport" content="width=device-width, initial-scale=1">'
				/* translators: %s: page title. */
				. '<title>' . esc_html( sprintf( __( 'Editing %s', 'agent-brain' ), $title ) ) . '</title>'
				. ( $at['icon'] !== '' ? '<link rel="icon" type="image/png" href="' . esc_url( $at['icon'] ) . '">' : '' )
				. '<style>' . $style . '</style></head><body>' . $body . '</body></html>',
		];
	}

	/**
	 * The edit page's own script: Save asks the frame for the body and posts
	 * it, then goes to the address the save answers, or (#643) to data-done
	 * plus the new version number.
	 */
	private static function editScript(): string {
		$saving = esc_js( __( 'Saving…', 'agent-brain' ) );
		$ready  = esc_js( __( 'Click any text to change it.', 'agent-brain' ) );
		$dirty  = esc_js( __( 'Unsaved changes', 'agent-brain' ) );
		$failed = esc_js( __( 'Saving failed. Your changes are still here.', 'agent-brain' ) );

		return "(function(){var bar=document.querySelector('.ab-edit-bar'),frame=document.querySelector('iframe'),save=document.getElementById('ab-edit-save'),status=document.getElementById('ab-edit-status');"
			. "var ready=false,dirty=false,busy=false,ask=0;"
			. "function say(t,bad){status.textContent=t;status.className='ab-edit-status'+(bad?' is-bad':'');}"
			. "window.addEventListener('message',function(e){if(e.source!==frame.contentWindow||!e.data)return;var d=e.data;"
			. "if(d.type==='ab-edit:ready'){ready=true;save.disabled=false;say('" . $ready . "');}"
			. "else if(d.type==='ab-edit:dirty'){dirty=true;say('" . $dirty . "');}"
			. "else if(d.type==='ab-edit:body'&&busy&&d.id===ask){post(String(d.html||''));}});"
			. "save.addEventListener('click',function(){if(!ready||busy)return;busy=true;save.disabled=true;say('" . $saving . "');ask++;frame.contentWindow.postMessage({type:'ab-edit:collect',id:ask},'*');});"
			. "function post(html){fetch(bar.getAttribute('data-endpoint'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':bar.getAttribute('data-nonce')},body:JSON.stringify({body:html,base:Number(bar.getAttribute('data-base'))})})"
			. ".then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});})"
			. ".then(function(res){if(res.ok&&res.j&&res.j.url){dirty=false;var done=bar.getAttribute('data-done');location.href=done?done+encodeURIComponent(String(res.j.version)):res.j.url;return;}throw new Error(res.j&&res.j.message?res.j.message:'');})"
			. ".catch(function(err){busy=false;save.disabled=false;say(err&&err.message?err.message:'" . $failed . "',true);});}"
			. "window.addEventListener('beforeunload',function(e){if(dirty&&!busy){e.preventDefault();e.returnValue='';}});})();";
	}

	/**
	 * The page with its body's content replaced; everything outside the
	 * body (doctype, head, the body tag's own attributes) as it was.
	 */
	public static function withBody( string $html, string $body ): string {
		if ( preg_match( '#<body\b[^>]*>#i', $html, $open, PREG_OFFSET_CAPTURE ) !== 1 ) {
			return $html;
		}

		$start = (int) $open[0][1] + strlen( $open[0][0] );
		$close = strripos( $html, '</body' );
		$end   = $close !== false && $close >= $start ? $close : strlen( $html );

		return substr( $html, 0, $start ) . "\n" . trim( $body ) . "\n" . substr( $html, $end );
	}

	/** The editor's own markers out, in case the frame left any. */
	private static function clean( string $body ): string {
		return (string) preg_replace( '#\s(?:contenteditable|data-ab-edit|data-ab-new|data-ab-src)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*))?#i', '', $body );
	}

	/**
	 * The editor for the frame document: its style in the head, its script
	 * at the end of the body. $parent is the members page's origin, the only
	 * one the frame talks to.
	 */
	public static function injectEditor( string $document, string $parent ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled plugin file, read-only.
		$script = file_get_contents( \AGENT_BRAIN_PLUGIN_DIR . self::ASSET );
		if ( ! is_string( $script ) ) {
			return $document;
		}

		$style = '<style id="ab-editor-style">'
			. '[data-ab-edit]{outline:1px dashed color-mix(in srgb,var(--ab-muted,#5d6370) 40%,transparent);outline-offset:3px;border-radius:2px;cursor:text}'
			. '[data-ab-edit]:hover{outline-color:var(--ab-accent,#16a34a)}'
			. '[data-ab-edit]:focus{outline:2px solid var(--ab-accent,#16a34a)}'
			. '[data-ab-new]:empty::before{content:"Type here";color:var(--ab-muted,#5d6370)}'
			. '.ab-editor-ui{position:sticky;top:0;z-index:2147483647;display:flex;flex-wrap:wrap;gap:4px;align-items:center;padding:6px 8px;margin:0 0 8px;'
			. 'background:var(--ab-surface,#fff);color:var(--ab-fg,#16181d);border-bottom:1px solid var(--ab-line,#dde1e8);box-shadow:0 2px 8px rgba(16,24,40,.08);font:500 14px/1.2 system-ui,-apple-system,"Segoe UI",sans-serif}'
			. '.ab-editor-ui button{font:inherit;color:inherit;background:transparent;border:1px solid var(--ab-line,#dde1e8);border-radius:6px;padding:6px 10px;min-height:34px;cursor:pointer}'
			. '.ab-editor-ui button:hover{background:var(--ab-accent-soft,#dcfce7)}'
			. '.ab-editor-ui button[title="Bold"]{font-weight:700}.ab-editor-ui button[title="Italic"]{font-style:italic}'
			. '.ab-editor-link{display:inline-flex;gap:4px}.ab-editor-link[hidden]{display:none}'
			. '.ab-editor-link input{font:inherit;min-width:12em;padding:6px 8px;border:1px solid var(--ab-line,#dde1e8);border-radius:6px;background:var(--ab-bg,#fff);color:inherit}'
			. '</style>';
		$tag = '<script data-parent="' . esc_attr( $parent ) . '">' . StoreClient::inlineScript( $script ) . '</script>';

		// In the head, else at the start of the body; never ahead of the doctype.
		foreach ( [ [ '#</head\s*>#i', true ], [ '#<body\b[^>]*>#i', false ] ] as [ $anchor, $before ] ) {
			$count     = 0;
			$escaped   = str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $style );
			$withStyle = preg_replace( $anchor, $before ? $escaped . '$0' : '$0' . $escaped, $document, 1, $count );
			if ( $count === 1 && is_string( $withStyle ) ) {
				$document = $withStyle;
				break;
			}
		}

		$close = strripos( $document, '</body' );

		return $close === false ? $document . $tag : substr( $document, 0, $close ) . $tag . substr( $document, $close );
	}
}
