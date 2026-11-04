<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Artifacts\ReviewLock;
use AgentBrain\Modules\AskBrain\MetaBox as AskBrainMetaBox;
use AgentBrain\Modules\AskBrain\Mode as AskBrainMode;
use AgentBrain\Modules\AskBrain\Spend;
use AgentBrain\Modules\BrainRead\MetaBox as BrainReadMetaBox;
use AgentBrain\Modules\BrainRead\Mode as BrainReadMode;
use AgentBrain\Modules\DataStore\StoreMetaBox;
use AgentBrain\Modules\DataStore\StoreMode;
use AgentBrain\Modules\DataStore\StoreRules;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\OAuth\RoleSwitching;
use AgentBrain\Modules\Uploads\UploadMode;
use AgentBrain\Modules\Uploads\UploadsMetaBox;
use WP_Error;

/**
 * #796: every per-artifact setting, in one registry that the artifact
 * screen's boxes take their labels from and artifact-settings-get / -set
 * expose over MCP, so a new checkbox reaches MCP by being added here
 * (ArtifactSettingsRegistryTest fails when a box field is missing).
 *
 * Each entry reads and writes through the setter its box saves with
 * (StoreMode::set, UploadMode::set, ReviewLock::set, HtmlFiles::approve and
 * so on), so a change over MCP has the same checks, audit event and effect
 * as a click. None of them needs a purge: share routes read these flags on
 * every request.
 *
 * Who decides: the connection's own user, or, on a person's role-switching
 * connection with an agent chosen (act-as), the person behind it, the rule
 * of the review-in-chat tools (Review\ReviewChat::reviewer()). Each setting
 * then keeps its box's own rule, so an agent's own connection gets only
 * what an agent may do (lock an artifact it leads for review).
 */
final class ArtifactSettings {

	public const PAGE_STORE             = 'page_store';
	public const PAGE_STORE_COLLECTIONS = 'page_store_collections';
	public const SEATS                  = 'seats';
	public const SEAT_ICON              = 'seat_icon';
	public const UPLOADS                = 'uploads';
	public const INTERACTIVE            = 'interactive';
	public const VIEWER_IDENTITY        = 'viewer_identity';
	public const BRAIN_READS            = 'brain_reads';
	public const ASK_BRAIN              = 'ask_brain';
	public const ASK_DAILY_BUDGET_USD   = 'ask_daily_budget_usd';
	public const HTML_SERVE_LATEST      = 'html_serve_latest';
	public const HTML_AUTO_RELOAD       = 'html_auto_reload';
	public const REVIEW_REQUIRED        = 'review_required';

	/**
	 * Setting key => the form field its box on the artifact screen posts
	 * (null: no box, set over MCP only). Plain constants, so the registry
	 * test runs without WordPress. Also the order changes are applied in:
	 * collections before the store is turned on, review_required last
	 * because an agent may lock but never unlock, so it is never rolled back.
	 */
	public const UI_FIELDS = [
		self::PAGE_STORE_COLLECTIONS => StoreMetaBox::FIELD_COLLECTIONS,
		self::PAGE_STORE             => StoreMetaBox::FIELD,
		self::SEATS                  => null,
		self::SEAT_ICON              => null,
		self::UPLOADS                => UploadsMetaBox::FIELD,
		self::INTERACTIVE            => InteractiveMetaBox::FIELD,
		self::VIEWER_IDENTITY        => ViewerMetaBox::FIELD,
		self::BRAIN_READS            => BrainReadMetaBox::FIELD,
		self::ASK_BRAIN              => AskBrainMetaBox::FIELD,
		self::ASK_DAILY_BUDGET_USD   => AskBrainMetaBox::FIELD_CAP,
		self::HTML_SERVE_LATEST      => HtmlMetaBox::FIELD_LATEST,
		self::HTML_AUTO_RELOAD       => HtmlMetaBox::FIELD_RELOAD,
		self::REVIEW_REQUIRED        => ReviewLock::FIELD,
	];

	/** How long a confirm token for a change that widens exposure stays valid. */
	public const CONFIRM_TTL = 600;

	private const CONFIRM_PREFIX = 'ab_settings_confirm_';

	/** Who may change it, as the tools say it. */
	private const WHO_CURATOR = 'curator or manager';
	private const WHO_LEAD    = 'the artifact\'s lead, its person, a curator or a manager; turning it off only a person, never an agent';
	private const WHO_PERSON  = 'the artifact\'s lead person, a curator or a manager; never an agent on its own';

	/**
	 * The registry: key => label (the box's wording), description, type,
	 * default, allowed values, who, and the callables that read, check,
	 * normalize and write it.
	 *
	 * normalize returns the value as stored, or a WP_Error saying what is
	 * expected. box, risk and widens_when are for the curator reference
	 * (reference()); widens says whether a change opens the artifact's
	 * share pages to more, which then needs a confirm token.
	 *
	 * @return array<string, array{label: string, description: string, type: string, default: mixed, allowed: mixed, who: string, box: string|null, risk: string, widens_when: string|null, widens: (callable(mixed, mixed, int, array<string, array{old: mixed, new: mixed}>): bool)|null, html_only: bool, kill_switch: (callable(): bool)|null, get: callable(int): mixed, normalize: callable(mixed): mixed, can: callable(int, int, mixed): bool, set: callable(int, mixed, int): (true|WP_Error)}>
	 */
	public static function all(): array {
		$curator = static fn ( int $actorId ): bool => StoreMode::canToggle( $actorId );
		$bool    = [ self::class, 'normalizeBool' ];

		return [
			self::PAGE_STORE_COLLECTIONS => [
				'label'       => __( 'Collections pages may use, one per line with its preset (scores: counter)', 'tomatio' ),
				'description' => 'The collections share pages may use, each with a preset or {"preset": name, ...tightening} (see artifact-store-config for the rules). {} allows none. Replaces the whole list.',
				'type'        => 'object',
				'default'     => (object) [],
				'allowed'     => array_keys( StoreRules::presets() ),
				'who'         => self::WHO_CURATOR,
				'box'         => 'Shared data store',
				'risk'        => 'A collection whose preset lets anyone holding a share link write (counter, ballot, entries, personal, inbox) takes input from anyone with the link; pages must show stored values as text, never as HTML.',
				'widens_when' => 'a collection becomes writable without a viewer token (writers anonymous or link)',
				'widens'      => static fn ( $old, $new ): bool => self::anonymousWritable( (array) $new, (array) $old ) !== [],
				'html_only'   => false,
				'kill_switch' => [ StoreMode::class, 'killSwitchOn' ],
				'get'         => static fn ( int $id ) => (object) StoreMode::collections( $id ),
				'normalize'   => static function ( $value ) {
					$value = self::decoded( $value );
					if ( $value instanceof \stdClass ) {
						$value = (array) $value;
					}

					$normal = is_array( $value ) ? StoreMode::normalizeCollections( $value ) : 'an object of collection name to preset';

					return is_string( $normal ) ? self::bad( $normal ) : $normal;
				},
				'can'         => static fn ( int $actorId ): bool => $curator( $actorId ),
				'set'         => static fn ( int $id, $value, int $actorId ) => StoreMode::setCollections( $id, (array) $value, $actorId ),
			],
			self::PAGE_STORE             => [
				'label'       => __( 'Allow share pages to use the shared data store', 'tomatio' ),
				'description' => 'Share pages get the page store API and _tomatio.js. Turning it on needs a collection list (page_store_collections).',
				'type'        => 'boolean',
				'default'     => false,
				'allowed'     => [ true, false ],
				'who'         => self::WHO_CURATOR,
				'box'         => 'Shared data store',
				'risk'        => 'Pages can store and read data, limited to the listed collections and their presets.',
				'widens_when' => 'it is turned on while a collection is writable without a viewer token',
				'widens'      => static fn ( $old, $new, int $id, array $changes ): bool => ! $old && $new && self::anonymousWritable( isset( $changes[ self::PAGE_STORE_COLLECTIONS ] ) ? (array) $changes[ self::PAGE_STORE_COLLECTIONS ]['new'] : StoreMode::collections( $id ) ) !== [],
				'html_only'   => false,
				'kill_switch' => [ StoreMode::class, 'killSwitchOn' ],
				'get'         => [ StoreMode::class, 'isFlagged' ],
				'normalize'   => $bool,
				'can'         => static fn ( int $actorId ): bool => $curator( $actorId ),
				'set'         => static fn ( int $id, $value, int $actorId ) => StoreMode::set( $id, (bool) $value, $actorId ),
			],
			self::SEATS                  => [
				'label'       => __( 'Seats: personal links on one public link', 'tomatio' ),
				'description' => 'On one public link each viewer claims a name and gets a personal link (tomatio.seat). Off keeps the claimed seats. Release or import seats with artifact-store-config.',
				'type'        => 'boolean',
				'default'     => false,
				'allowed'     => [ true, false ],
				'who'         => self::WHO_CURATOR,
				'box'         => null,
				'risk'        => 'Anyone on the public link can claim a free name and gets a personal link for it.',
				'widens_when' => null,
				'widens'      => null,
				'html_only'   => false,
				'kill_switch' => null,
				'get'         => [ StoreMode::class, 'seatsOn' ],
				'normalize'   => $bool,
				'can'         => static fn ( int $actorId ): bool => $curator( $actorId ),
				'set'         => static fn ( int $id, $value, int $actorId ) => StoreMode::setSeats( $id, (bool) $value, $actorId ),
			],
			self::SEAT_ICON              => [
				'label'       => __( 'How a seat\'s Home Screen icon differs from the link\'s', 'tomatio' ),
				'description' => 'recolor: the background and letter badge in the seat\'s colour; badge: a letter badge only; off: the link\'s own icon.',
				'type'        => 'string',
				'default'     => AppIcon::STYLE_RECOLOR,
				'allowed'     => AppIcon::STYLES,
				'who'         => self::WHO_CURATOR,
				'box'         => null,
				'risk'        => 'None: it only changes how a seat\'s Home Screen icon looks.',
				'widens_when' => null,
				'widens'      => null,
				'html_only'   => false,
				'kill_switch' => null,
				'get'         => [ StoreMode::class, 'seatIconStyle' ],
				'normalize'   => static fn ( $value ) => is_string( $value ) && in_array( $value, AppIcon::STYLES, true ) ? $value : self::bad( 'one of ' . implode( ', ', AppIcon::STYLES ) ),
				'can'         => static fn ( int $actorId ): bool => $curator( $actorId ),
				'set'         => static fn ( int $id, $value, int $actorId ) => StoreMode::setSeatIconStyle( $id, (string) $value, $actorId ),
			],
			self::UPLOADS                => [
				'label'       => __( 'Let members upload files to this artifact\'s share pages (file uploads)', 'tomatio' ),
				'description' => 'Named members upload from the shared page; the lead and curators upload with artifact-upload-put. Anyone holding a share link can list and download.',
				'type'        => 'boolean',
				'default'     => false,
				'allowed'     => [ true, false ],
				'who'         => self::WHO_CURATOR,
				'box'         => 'File uploads',
				'risk'        => 'Named members can add files, and anyone holding a share link can list and download them.',
				'widens_when' => 'it is turned on',
				'widens'      => static fn ( $old, $new ): bool => ! $old && (bool) $new,
				'html_only'   => false,
				'kill_switch' => [ UploadMode::class, 'killSwitchOn' ],
				'get'         => [ UploadMode::class, 'isFlagged' ],
				'normalize'   => $bool,
				'can'         => static fn ( int $actorId ): bool => UploadMode::canToggle( $actorId ),
				'set'         => static fn ( int $id, $value, int $actorId ) => UploadMode::set( $id, (bool) $value, $actorId ),
			],
			self::INTERACTIVE            => [
				'label'       => __( 'Allow scripts in share links (sandboxed)', 'tomatio' ),
				'description' => 'Share links of a section artifact run its inline scripts in an isolated sandbox: no network, no forms, no cookies.',
				'type'        => 'boolean',
				'default'     => false,
				'allowed'     => [ true, false ],
				'who'         => self::WHO_CURATOR,
				'box'         => 'Interactive mode',
				'risk'        => 'Scripts run in a sandbox with no network, forms or cookies, but they decide what the viewer sees.',
				'widens_when' => 'it is turned on',
				'widens'      => static fn ( $old, $new ): bool => ! $old && (bool) $new,
				'html_only'   => false,
				'kill_switch' => [ Interactive::class, 'killSwitchOn' ],
				'get'         => [ Interactive::class, 'isFlagged' ],
				'normalize'   => $bool,
				'can'         => static fn ( int $actorId ): bool => Interactive::canToggle( $actorId ),
				'set'         => static fn ( int $id, $value, int $actorId ) => Interactive::set( $id, (bool) $value, $actorId ),
			],
			self::VIEWER_IDENTITY        => [
				'label'       => __( 'Let share links of this artifact know who is viewing (viewer identity)', 'tomatio' ),
				'description' => 'Members links name their viewer with a short-lived signed token; everyone else stays anonymous. Private links need it.',
				'type'        => 'boolean',
				'default'     => false,
				'allowed'     => [ true, false ],
				'who'         => self::WHO_CURATOR,
				'box'         => 'Viewer identity',
				'risk'        => 'Low: viewers are named only on members links, with a short-lived token. Private links, brain reads and ask the brain need it.',
				'widens_when' => null,
				'widens'      => null,
				'html_only'   => false,
				'kill_switch' => [ ViewerMode::class, 'killSwitchOn' ],
				'get'         => [ ViewerMode::class, 'isFlagged' ],
				'normalize'   => $bool,
				'can'         => static fn ( int $actorId ): bool => ViewerMode::canToggle( $actorId ),
				'set'         => static fn ( int $id, $value, int $actorId ) => ViewerMode::set( $id, (bool) $value, $actorId ),
			],
			self::BRAIN_READS            => [
				'label'       => __( 'Let share pages of this artifact read live brain data in its scope', 'tomatio' ),
				'description' => 'Pages search and read memories and approved knowledge of the artifact\'s scope. Works only with viewer_identity on.',
				'type'        => 'boolean',
				'default'     => false,
				'allowed'     => [ true, false ],
				'who'         => self::WHO_CURATOR,
				'box'         => 'Live brain reads',
				'risk'        => 'Share pages read the scope\'s memories and approved knowledge: named members as their grants allow, everyone else holding a link the scope read-only.',
				'widens_when' => 'it is turned on',
				'widens'      => static fn ( $old, $new ): bool => ! $old && (bool) $new,
				'html_only'   => false,
				'kill_switch' => [ BrainReadMode::class, 'killSwitchOn' ],
				'get'         => [ BrainReadMode::class, 'isFlagged' ],
				'normalize'   => $bool,
				'can'         => static fn ( int $actorId ): bool => BrainReadMode::canToggle( $actorId ),
				'set'         => static fn ( int $id, $value, int $actorId ) => BrainReadMode::set( $id, (bool) $value, $actorId ),
			],
			self::ASK_BRAIN              => [
				'label'       => __( 'Let share pages of this artifact ask questions answered from its scope', 'tomatio' ),
				'description' => 'Pages ask questions answered with citations from the artifact\'s scope by the site\'s AI provider, rate limited and cost-capped. Works only with viewer_identity on.',
				'type'        => 'boolean',
				'default'     => false,
				'allowed'     => [ true, false ],
				'who'         => self::WHO_CURATOR,
				'box'         => 'Ask the brain',
				'risk'        => 'Pages ask the site\'s AI provider about the scope: answers can quote its memories and knowledge, and every answer costs money.',
				'widens_when' => 'it is turned on',
				'widens'      => static fn ( $old, $new ): bool => ! $old && (bool) $new,
				'html_only'   => false,
				'kill_switch' => [ AskBrainMode::class, 'killSwitchOn' ],
				'get'         => [ AskBrainMode::class, 'isFlagged' ],
				'normalize'   => $bool,
				'can'         => static fn ( int $actorId ): bool => AskBrainMode::canToggle( $actorId ),
				'set'         => static fn ( int $id, $value, int $actorId ) => AskBrainMode::set( $id, (bool) $value, $actorId ),
			],
			self::ASK_DAILY_BUDGET_USD   => [
				'label'       => __( 'Daily ask budget (USD, empty = default, 0 = unlimited)', 'tomatio' ),
				'description' => sprintf( 'What ask-the-brain may spend per day for this artifact, in US dollars. null: the default (%s); 0: unlimited.', number_format( Spend::DEFAULT_ARTIFACT_CAP_USD, 2 ) ),
				'type'        => 'number|null',
				'default'     => null,
				'allowed'     => 'a number of 0 or more, or null',
				'who'         => self::WHO_CURATOR,
				'box'         => 'Ask the brain',
				'risk'        => 'A higher budget, or 0 (unlimited), lets the artifact\'s pages spend more on the AI provider each day.',
				'widens_when' => 'the budget goes up, or becomes 0 (unlimited)',
				'widens'      => static fn ( $old, $new ): bool => self::budget( $new ) > self::budget( $old ),
				'html_only'   => false,
				'kill_switch' => null,
				'get'         => [ AskBrainMode::class, 'cap' ],
				'normalize'   => static function ( $value ) {
					if ( $value === null ) {
						return null;
					}

					return ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) && (float) $value >= 0 ? (float) $value : self::bad( 'a number of 0 or more, or null' );
				},
				'can'         => static fn ( int $actorId ): bool => AskBrainMode::canToggle( $actorId ),
				'set'         => static fn ( int $id, $value, int $actorId ) => AskBrainMode::setCap( $id, $value === null ? null : (float) $value, $actorId ),
			],
			self::HTML_SERVE_LATEST      => [
				'label'       => __( 'Share links serve the latest version without approval', 'tomatio' ),
				'description' => 'HTML pages only. On: share links serve every new version at once. Off: only the version approved with artifact-html-approve.',
				'type'        => 'boolean',
				'default'     => false,
				'allowed'     => [ true, false ],
				'who'         => self::WHO_CURATOR,
				'box'         => 'HTML page',
				'risk'        => 'Every new version is live on share links at once, with no person approving it.',
				'widens_when' => 'it is turned on',
				'widens'      => static fn ( $old, $new ): bool => ! $old && (bool) $new,
				'html_only'   => true,
				'kill_switch' => null,
				'get'         => static fn ( int $id ): bool => HtmlFiles::mode( $id ) === HtmlFiles::MODE_LATEST,
				'normalize'   => $bool,
				'can'         => static fn ( int $actorId, int $id ): bool => HtmlFiles::canSetMode( $actorId ) && HtmlFiles::canEdit( $actorId, $id ) && ! Capabilities::isPureAgent( $actorId ),
				'set'         => static function ( int $id, $value, int $actorId ) {
					$done = HtmlFiles::approve( $id, $actorId, 0, $value ? HtmlFiles::MODE_LATEST : HtmlFiles::MODE_APPROVED );

					return $done instanceof WP_Error ? $done : true;
				},
			],
			self::HTML_AUTO_RELOAD       => [
				'label'       => __( 'Reload open pages when a new version is approved', 'tomatio' ),
				'description' => 'HTML pages only. Open share pages reload by themselves when the version they serve changes.',
				'type'        => 'boolean',
				'default'     => true,
				'allowed'     => [ true, false ],
				'who'         => self::WHO_PERSON,
				'box'         => 'HTML page',
				'risk'        => 'Low: open pages reload when the version they serve changes.',
				'widens_when' => null,
				'widens'      => null,
				'html_only'   => true,
				'kill_switch' => null,
				'get'         => [ HtmlFiles::class, 'autoReload' ],
				'normalize'   => $bool,
				'can'         => static fn ( int $actorId, int $id ): bool => HtmlFiles::canEdit( $actorId, $id ) && ! Capabilities::isPureAgent( $actorId ),
				'set'         => static fn ( int $id, $value, int $actorId ) => HtmlFiles::setAutoReload( $id, $actorId, (bool) $value ),
			],
			self::REVIEW_REQUIRED        => [
				'label'       => __( 'Review required, even when a person asks their AI', 'tomatio' ),
				'description' => 'With it on, changes made through an AI connection land as drafts for normal review, even with delegated authority.',
				'type'        => 'boolean',
				'default'     => false,
				'allowed'     => [ true, false ],
				'who'         => self::WHO_LEAD,
				'box'         => 'Review required',
				'risk'        => 'Off, AI connections with delegated authority can publish and approve this artifact in one step when their person asks.',
				'widens_when' => null,
				'widens'      => null,
				'html_only'   => false,
				'kill_switch' => null,
				'get'         => [ ReviewLock::class, 'isLocked' ],
				'normalize'   => $bool,
				'can'         => static fn ( int $actorId, int $id, $value ): bool => $value ? ReviewLock::canLock( $actorId, $id ) : ReviewLock::canUnlock( $actorId, $id ),
				'set'         => static fn ( int $id, $value, int $actorId ) => ReviewLock::set( $id, (bool) $value, $actorId ),
			],
		];
	}

	/** A box's label, from the registry. */
	public static function label( string $key ): string {
		return self::all()[ $key ]['label'] ?? $key;
	}

	/**
	 * Who decides on this connection (see the class comment): the person
	 * behind a role-switching connection while an agent acts for them,
	 * else the current user.
	 */
	public static function decider(): int {
		$userId = (int) get_current_user_id();
		$person = RoleSwitching::person();
		if ( $person > 0 && $person !== $userId && self::isAgent( $userId ) ) {
			return $person;
		}

		return $userId;
	}

	/**
	 * Every setting of an artifact, with what $actorId may change.
	 *
	 * @return array<string, array<string, mixed>>|WP_Error
	 */
	public static function describe( int $artifactId, int $actorId ) {
		if ( ! self::isArtifact( $artifactId ) ) {
			return self::notFound();
		}

		$html = HtmlFiles::isHtml( $artifactId );
		$out  = [];
		foreach ( self::all() as $key => $entry ) {
			$applies = $html || ! $entry['html_only'];
			$value   = ( $entry['get'] )( $artifactId );
			$row     = [
				'value'       => $value,
				'default'     => $entry['default'],
				'type'        => $entry['type'],
				'allowed'     => $entry['allowed'],
				'label'       => $entry['label'],
				'description' => $entry['description'],
				'who'         => $entry['who'],
				'applies'     => $applies,
				// review_required: whether this caller may change it from where it is now.
				'can_change'  => $applies && (bool) ( $entry['can'] )( $actorId, $artifactId, is_bool( $value ) ? ! $value : $value ),
			];
			if ( $entry['kill_switch'] !== null ) {
				$row['off_site_wide'] = (bool) ( $entry['kill_switch'] )();
			}
			$out[ $key ] = $row;
		}

		return $out;
	}

	/**
	 * Change several settings at once, all or nothing: every key, value and
	 * permission is checked first, then the changes are applied in
	 * UI_FIELDS order through the boxes' own setters, and if one still
	 * fails the ones already applied are set back. With $dryRun nothing is
	 * written and the result lists what would change.
	 *
	 * A change that widens exposure (see widens in all()) is saved only
	 * with a confirm token: a call without one saves nothing and returns
	 * the diff and a single-use token bound to this caller, this artifact
	 * and exactly these changes; the same call again with the token applies
	 * them. On an artifact locked as "Review required" such a change is
	 * refused over MCP: a person makes it on the artifact screen.
	 *
	 * @param array<mixed> $settings
	 * @return array{changes: array<string, array{old: mixed, new: mixed}>, settings: array<string, array<string, mixed>>, dry_run: bool, warnings: array<int, string>, widening: array<int, string>, needs_confirmation: bool, confirm_token?: string, expires_in?: int}|WP_Error
	 */
	public static function apply( int $artifactId, array $settings, int $actorId, bool $dryRun = false, int $via = 0, string $confirmToken = '' ) {
		if ( ! self::isArtifact( $artifactId ) ) {
			return self::notFound();
		}

		$registry = self::all();
		$unknown  = array_values( array_diff( array_map( 'strval', array_keys( $settings ) ), array_keys( $registry ) ) );
		if ( $unknown !== [] ) {
			return new WP_Error(
				'ab_settings_unknown',
				/* translators: 1: the unknown keys, 2: the known keys */
				sprintf( __( 'Unknown setting: %1$s. Known settings: %2$s. Nothing was changed.', 'tomatio' ), implode( ', ', $unknown ), implode( ', ', array_keys( $registry ) ) ),
				[ 'status' => 400 ]
			);
		}
		if ( $settings === [] ) {
			return new WP_Error( 'ab_settings_empty', __( 'Give at least one setting to change (artifact-settings-get lists them).', 'tomatio' ), [ 'status' => 400 ] );
		}

		$html    = HtmlFiles::isHtml( $artifactId );
		$changes = [];
		foreach ( $registry as $key => $entry ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}

			if ( $entry['html_only'] && ! $html ) {
				/* translators: %s: setting key */
				return self::invalid( $key, sprintf( __( '%s applies to HTML pages only, and this artifact is not one. Nothing was changed.', 'tomatio' ), $key ) );
			}

			$new = ( $entry['normalize'] )( $settings[ $key ] );
			if ( $new instanceof WP_Error ) {
				/* translators: 1: setting key, 2: what is wrong or expected */
				return self::invalid( $key, sprintf( __( 'Invalid value for %1$s: %2$s. Nothing was changed.', 'tomatio' ), $key, $new->get_error_message() ) );
			}

			$old = ( $entry['get'] )( $artifactId );
			if ( self::same( $old, $new ) ) {
				continue;
			}

			if ( ! ( $entry['can'] )( $actorId, $artifactId, $new ) ) {
				return new WP_Error(
					'ab_forbidden',
					/* translators: 1: setting key, 2: who may change it */
					sprintf( __( 'You may not change %1$s: only %2$s can. Ask one of them, or act as them. Nothing was changed.', 'tomatio' ), $key, $entry['who'] ),
					[ 'status' => 403, 'setting' => $key ]
				);
			}

			$changes[ $key ] = [ 'old' => $old, 'new' => $new ];
		}

		// As artifact-store-config: turning the page store on needs collections to use.
		$collections = isset( $changes[ self::PAGE_STORE_COLLECTIONS ] ) ? (array) $changes[ self::PAGE_STORE_COLLECTIONS ]['new'] : StoreMode::collections( $artifactId );
		if ( array_key_exists( self::PAGE_STORE, $settings ) && self::normalizeBool( $settings[ self::PAGE_STORE ] ) === true && $collections === [] ) {
			return self::invalid( self::PAGE_STORE, __( 'Turning page_store on needs page_store_collections, each with a preset (for example {"scores": "counter"}). Nothing was changed.', 'tomatio' ) );
		}

		$warnings = self::warnings( $artifactId, $changes );
		$widening = self::widening( $artifactId, $changes );
		$blocked  = self::reviewBlock( $artifactId, $widening, isset( $changes[ self::REVIEW_REQUIRED ] ) && $changes[ self::REVIEW_REQUIRED ]['new'] === true );
		if ( $blocked !== null ) {
			return $blocked;
		}

		$out = [
			'changes'            => self::forOutput( $changes ),
			'settings'           => [],
			'dry_run'            => $dryRun,
			'warnings'           => $warnings,
			'widening'           => $widening,
			'needs_confirmation' => $widening !== [],
		];

		if ( $dryRun || $changes === [] ) {
			return [ 'settings' => self::describeOrEmpty( $artifactId, $actorId ) ] + $out;
		}

		if ( $widening !== [] ) {
			$confirm = self::confirmStep( $artifactId, $actorId, $changes, $confirmToken );
			if ( $confirm !== null ) {
				return $confirm instanceof WP_Error ? $confirm : [ 'settings' => self::describeOrEmpty( $artifactId, $actorId ) ] + $confirm + $out;
			}
		}

		$applied = [];
		foreach ( $changes as $key => $change ) {
			// A last say before one setting is written: a filter returning a
			// WP_Error refuses it (a host that never allows scripts, say), and
			// the settings already written in this call are set back.
			$veto = apply_filters( 'agent_brain/artifact_setting_pre_set', null, $key, $artifactId, $change['new'], $actorId );
			$done = $veto instanceof WP_Error ? $veto : ( $registry[ $key ]['set'] )( $artifactId, $change['new'], $actorId );
			if ( $done instanceof WP_Error ) {
				foreach ( array_reverse( $applied ) as $undo ) {
					( $registry[ $undo ]['set'] )( $artifactId, $changes[ $undo ]['old'], $actorId );
				}

				return new WP_Error(
					$done->get_error_code(),
					/* translators: 1: setting key, 2: the reason */
					sprintf( __( 'Could not change %1$s: %2$s Nothing was changed.', 'tomatio' ), $key, $done->get_error_message() ),
					array_merge( (array) $done->get_error_data(), [ 'setting' => $key ] )
				);
			}
			$applied[] = $key;
		}

		foreach ( $changes as $key => $change ) {
			do_action(
				'agent_brain/audit',
				[
					'actor_id'    => $actorId,
					'event'       => 'artifact_setting_changed',
					'status'      => 'ok',
					'object_type' => 'artifact',
					'object_id'   => (string) $artifactId,
					'meta'        => [
						'setting' => $key,
						'old'     => self::forOutputValue( $change['old'] ),
						'new'     => self::forOutputValue( $change['new'] ),
					] + ( in_array( $key, $widening, true ) ? [ 'confirmed' => true ] : [] ) + ( $via > 0 && $via !== $actorId ? [ 'via' => $via ] : [] ),
				]
			);
		}

		return [ 'settings' => self::describeOrEmpty( $artifactId, $actorId ), 'needs_confirmation' => false ] + $out;
	}

	/**
	 * The keys of $changes that open the artifact's share pages to more
	 * (widens in all()). artifact-store-config and artifact-html-approve
	 * describe their changes with the same keys.
	 *
	 * @param array<string, array{old: mixed, new: mixed}> $changes
	 * @return array<int, string>
	 */
	public static function widening( int $artifactId, array $changes ): array {
		$registry = self::all();
		$widening = [];
		foreach ( $changes as $key => $change ) {
			$widens = $registry[ $key ]['widens'] ?? null;
			if ( $widens !== null && $widens( $change['old'], $change['new'], $artifactId, $changes ) ) {
				$widening[] = $key;
			}
		}

		return $widening;
	}

	/**
	 * On an artifact locked as "Review required" (or being locked in the
	 * same call), a change that widens exposure is refused over MCP: a
	 * person makes it on the artifact screen.
	 *
	 * @param array<int, string> $widening
	 */
	public static function reviewBlock( int $artifactId, array $widening, bool $locking = false ): ?WP_Error {
		if ( $widening === [] || ( ! $locking && ! ReviewLock::isLocked( $artifactId ) ) ) {
			return null;
		}

		return new WP_Error(
			'ab_review_required',
			/* translators: %s: setting keys */
			sprintf( __( 'This artifact is locked as "Review required": a change that opens its share pages to more (%s) is made by a person on the artifact screen, not over an AI connection. Nothing was changed.', 'tomatio' ), implode( ', ', $widening ) ),
			[ 'status' => 403, 'setting' => $widening[0] ]
		);
	}

	/**
	 * The confirm step for a change that widens exposure: without a token,
	 * a new single-use one bound to this caller, this artifact and exactly
	 * these changes (to hand back with the diff); with a matching token,
	 * null, so the caller goes ahead; otherwise a WP_Error.
	 *
	 * @param array<string, array{old: mixed, new: mixed}> $changes
	 * @return array{needs_confirmation: true, changes: array<string, mixed>, widening: array<int, string>, confirm_token: string, expires_in: int}|WP_Error|null
	 */
	public static function confirmStep( int $artifactId, int $actorId, array $changes, string $confirmToken ) {
		$digest = self::digest( $artifactId, $actorId, $changes );
		if ( $confirmToken === '' ) {
			$token = wp_generate_password( 32, false );
			set_transient( self::CONFIRM_PREFIX . hash( 'sha256', $token ), $digest, self::CONFIRM_TTL );

			return [
				'needs_confirmation' => true,
				'changes'            => self::forOutput( $changes ),
				'widening'           => self::widening( $artifactId, $changes ),
				'confirm_token'      => $token,
				'expires_in'         => self::CONFIRM_TTL,
			];
		}

		$slot  = self::CONFIRM_PREFIX . hash( 'sha256', $confirmToken );
		$saved = get_transient( $slot );
		// Single use: gone whether it matches or not.
		delete_transient( $slot );

		return is_string( $saved ) && hash_equals( $saved, $digest ) ? null : new WP_Error( 'ab_settings_confirm_invalid', __( 'That confirm token does not match these changes, or it expired or was used. Make the same call again without confirm_token to get the changes and a new token. Nothing was changed.', 'tomatio' ), [ 'status' => 400 ] );
	}

	/**
	 * The curator reference (docs/manual/artifact-settings.md), written from
	 * this registry so the page cannot drift from what the tools do;
	 * ArtifactSettingsReferenceTest fails when the committed page is stale.
	 */
	public static function reference(): string {
		$fmt = static function ( $value ): string {
			if ( is_bool( $value ) ) {
				return $value ? 'on' : 'off';
			}
			if ( $value === null ) {
				return 'empty (the site default)';
			}
			if ( $value instanceof \stdClass || is_array( $value ) ) {
				return (array) $value === [] ? 'none' : (string) json_encode( $value, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- also runs without WordPress (ArtifactSettingsReferenceTest).
			}

			return (string) $value;
		};

		$out  = "# Artifact settings\n\n";
		$out .= "Every page and document (artifact) has its own settings: what its share pages may do, and who reviews changes. Curators and managers change them on the artifact's screen in wp-admin, or from their AI with `tomatio-artifact-settings-get` and `tomatio-artifact-settings-set`, using the key of each setting below.\n\n";
		$out .= "Over MCP, a change that opens the artifact's share pages to more (marked **Needs confirmation**) is not saved at once: `artifact-settings-set` returns the changes and a confirm token, and the same call again with `confirm_token` saves them. `artifact-store-config` (a collection anyone with the link may write) and `artifact-html-approve` with mode=latest take the same step. On an artifact locked as **Review required**, such a change is made by a person on the artifact screen. A site administrator can also turn several features off for the whole site; the setting then stays saved but does nothing.\n\n";
		$out .= "| Key | Setting | Default | Needs confirmation |\n|---|---|---|---|\n";
		foreach ( self::all() as $key => $entry ) {
			$out .= '| `' . $key . '` | ' . $entry['label'] . ' | ' . $fmt( $entry['default'] ) . ' | ' . ( $entry['widens_when'] !== null ? 'when ' . $entry['widens_when'] : 'no' ) . " |\n";
		}

		foreach ( self::all() as $key => $entry ) {
			$out .= "\n## " . $entry['label'] . "\n\n";
			$out .= $entry['description'] . "\n\n";
			$out .= '- **Key:** `' . $key . '` (' . $entry['type'] . ( is_array( $entry['allowed'] ) && $entry['type'] === 'string' ? ': ' . implode( ', ', $entry['allowed'] ) : '' ) . ")\n";
			$out .= '- **Default:** ' . $fmt( $entry['default'] ) . "\n";
			$out .= '- **Where:** ' . ( $entry['box'] !== null ? 'the "' . $entry['box'] . '" box on the artifact screen, or ' : 'only over MCP: ' ) . '`artifact-settings-set`' . ( $entry['html_only'] ? ' (HTML pages only)' : '' ) . "\n";
			$out .= '- **Who may change it:** ' . $entry['who'] . "\n";
			$out .= '- **Risk:** ' . $entry['risk'] . "\n";
			$out .= '- **Needs confirmation over MCP:** ' . ( $entry['widens_when'] !== null ? 'when ' . $entry['widens_when'] : 'no' ) . "\n";
			if ( $entry['kill_switch'] !== null ) {
				$out .= "- **Site-wide off switch:** yes\n";
			}
		}

		// The copy on tomatio.ai (src/docs/manual) is checked against this
		// hash, so an edit there by hand fails its tests (docs/releasing.md).
		return "<!-- Generated from AgentBrain\\Modules\\Sharing\\ArtifactSettings::reference(). Do not edit by hand: change the registry, then run the unit test ArtifactSettingsReferenceTest with UPDATE_REFERENCE=1. -->\n"
			. '<!-- body-sha256: ' . hash( 'sha256', $out ) . " (of everything after this line and the blank line below it) -->\n\n" . $out;
	}

	/**
	 * Collections of $collections a page may write to without a viewer
	 * token (writers anonymous or link, with any op besides read) that were
	 * not already so in $before.
	 *
	 * @param array<mixed> $collections
	 * @param array<mixed> $before
	 * @return array<int, string>
	 */
	public static function anonymousWritable( array $collections, array $before = [] ): array {
		$open = static fn ( $rules ): bool => is_array( $rules )
			&& in_array( $rules['writers'] ?? '', [ 'anonymous', 'link' ], true )
			&& array_diff( (array) ( $rules['ops'] ?? [] ), [ 'read' ] ) !== [];

		$out = [];
		foreach ( $collections as $name => $rules ) {
			if ( $open( $rules ) && ! $open( $before[ $name ] ?? null ) ) {
				$out[] = (string) $name;
			}
		}

		return $out;
	}

	/**
	 * An ask budget as the most a day may cost: null is the default, 0 is
	 * unlimited.
	 *
	 * @param mixed $cap
	 */
	private static function budget( $cap ): float {
		if ( $cap === null ) {
			return (float) Spend::DEFAULT_ARTIFACT_CAP_USD;
		}

		return (float) $cap <= 0 ? INF : (float) $cap;
	}

	/**
	 * What a confirm token is bound to: the caller, the artifact and the
	 * exact changes (old and new), so a token never applies anything else.
	 *
	 * @param array<string, array{old: mixed, new: mixed}> $changes
	 */
	private static function digest( int $artifactId, int $actorId, array $changes ): string {
		return hash( 'sha256', $artifactId . '|' . $actorId . '|' . (string) wp_json_encode( self::forOutput( $changes ) ) );
	}

	/**
	 * A boolean setting's value: true or false, or the JSON-ish strings some
	 * AI clients send for one ("true", "false"); anything else is refused.
	 *
	 * @param mixed $value
	 * @return bool|WP_Error
	 */
	public static function normalizeBool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( $value === 'true' || $value === 'false' ) {
			return $value === 'true';
		}

		return self::bad( 'true or false' );
	}

	/**
	 * What a change leaves inactive, as the boxes warn: brain reads and ask
	 * the brain do nothing without viewer identity.
	 *
	 * @param array<string, array{old: mixed, new: mixed}> $changes
	 * @return array<int, string>
	 */
	private static function warnings( int $artifactId, array $changes ): array {
		$viewer = isset( $changes[ self::VIEWER_IDENTITY ] ) ? (bool) $changes[ self::VIEWER_IDENTITY ]['new'] : ViewerMode::isFlagged( $artifactId );
		$out    = [];
		foreach ( [ self::BRAIN_READS, self::ASK_BRAIN ] as $key ) {
			$on = isset( $changes[ $key ] ) ? (bool) $changes[ $key ]['new'] : (bool) ( self::all()[ $key ]['get'] )( $artifactId );
			if ( $on && ! $viewer && ( isset( $changes[ $key ] ) || isset( $changes[ self::VIEWER_IDENTITY ] ) ) ) {
				/* translators: %s: setting key */
				$out[] = sprintf( __( '%s works only with viewer_identity on.', 'tomatio' ), $key );
			}
		}

		return $out;
	}

	/**
	 * @param array<string, array{old: mixed, new: mixed}> $changes
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	private static function forOutput( array $changes ): array {
		$out = [];
		foreach ( $changes as $key => $change ) {
			$out[ $key ] = [ 'old' => self::forOutputValue( $change['old'] ), 'new' => self::forOutputValue( $change['new'] ) ];
		}

		return $out;
	}

	/**
	 * Collections as a JSON object even when empty.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function forOutputValue( $value ) {
		return is_array( $value ) ? (object) $value : $value;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function describeOrEmpty( int $artifactId, int $actorId ): array {
		$out = self::describe( $artifactId, $actorId );

		return is_array( $out ) ? $out : [];
	}

	/**
	 * @param mixed $old
	 * @param mixed $new
	 */
	private static function same( $old, $new ): bool {
		if ( $old instanceof \stdClass ) {
			$old = (array) $old;
		}
		if ( is_float( $old ) || is_float( $new ) ) {
			return $old !== null && $new !== null && (float) $old === (float) $new;
		}

		return $old === $new;
	}

	/**
	 * The #634 rule of artifact-store-config: an object some AI clients send
	 * as its JSON text is decoded.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function decoded( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$decoded = json_decode( trim( $value ), true );

		return is_array( $decoded ) ? $decoded : $value;
	}

	private static function isArtifact( int $artifactId ): bool {
		$post = $artifactId > 0 ? get_post( $artifactId ) : null;

		return $post instanceof \WP_Post && $post->post_type === Cpts::ARTIFACT_POST_TYPE;
	}

	private static function isAgent( int $userId ): bool {
		$user = $userId > 0 ? get_userdata( $userId ) : false;

		return $user instanceof \WP_User && in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true );
	}

	private static function notFound(): WP_Error {
		return new WP_Error( 'ab_not_found', __( 'Artifact not found.', 'tomatio' ), [ 'status' => 404 ] );
	}

	private static function bad( string $expected ): WP_Error {
		return new WP_Error( 'ab_settings_invalid', $expected );
	}

	private static function invalid( string $key, string $message ): WP_Error {
		return new WP_Error( 'ab_settings_invalid', $message, [ 'status' => 400, 'setting' => $key ] );
	}
}
