<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * Plain-language wording for audit events, shared by the Overview live feed
 * and the Audit Log viewer (issue #82) so the two never disagree.
 */
final class EventLabels {

	/**
	 * Plain-language labels for agent abilities, shown as "<agent> <label>".
	 */
	private const ABILITY_LABELS = [
		'agent-brain/context-briefing'       => 'loaded a session briefing',
		'agent-brain/memory-search'          => 'searched memory',
		'agent-brain/memory-get'             => 'opened a memory',
		'agent-brain/memory-write'           => 'saved a memory',
		'agent-brain/knowledge-read'         => 'read approved knowledge',
		'agent-brain/handoff-request-review' => 'asked a curator for review',
		'agent-brain/handoff-read'           => 'read a handoff',
		'agent-brain/artifact-create'        => 'started a shared document',
		'agent-brain/artifact-get-outline'   => 'read a document outline',
		'agent-brain/artifact-set-outline'   => 'outlined a document',
		'agent-brain/artifact-reorder'       => 'reordered a document',
		'agent-brain/artifact-status'        => 'checked a document',
		'agent-brain/artifact-list'          => 'looked up documents',
		'agent-brain/artifact-assemble'      => 'assembled a document',
		'agent-brain/artifact-compile'       => 'compiled a document',
		'agent-brain/artifact-publish'       => 'published a document',
		'agent-brain/artifact-unpublish'     => 'unpublished a document',
		'agent-brain/artifact-archive'       => 'archived a document',
		'agent-brain/section-create'         => 'added a section',
		'agent-brain/section-assign'         => 'assigned a section',
		'agent-brain/section-claim'          => 'claimed a section',
		'agent-brain/section-release'        => 'released a section',
		'agent-brain/section-get'            => 'read a section',
		'agent-brain/section-update'         => 'edited a section',
		'agent-brain/section-submit'         => 'submitted a section for review',
		'agent-brain/section-review'         => 'reviewed a section',
		'agent-brain/agent-propose'          => 'proposed a new agent',
		'agent-brain/act-as'                 => 'acted on behalf of a person',
		'agent-brain/agent-register'         => 'registered itself with a registration key',
		'agent-brain/agent-whoami'           => 'checked its own identity',
		'agent-brain/artifact-share-create'  => 'created a share link',
		'agent-brain/artifact-share-revoke'  => 'revoked a share link',
		'agent-brain/artifact-share-list'    => 'listed share links',
		'agent-brain/artifact-share-analytics' => 'read share analytics',
		'agent-brain/artifact-members-address-get' => 'read the members address',
		'agent-brain/artifact-html-put'      => 'uploaded files to an HTML page',
		'agent-brain/artifact-html-get'      => 'read the files of an HTML page',
		'agent-brain/artifact-html-approve'  => 'approved a version of an HTML page',
		'agent-brain/artifact-version-list'      => 'listed an artifact version history',
		'agent-brain/artifact-version-get'       => 'read a version of an artifact',
		'agent-brain/artifact-version-compare'   => 'compared two versions of an artifact',
		'agent-brain/artifact-version-restore'   => 'restored an old version of an artifact as a new version',
		'agent-brain/comment-list'           => 'listed comments',
		'agent-brain/comment-add'            => 'commented on a document',
		'agent-brain/comment-reply'          => 'replied to a comment',
		'agent-brain/comment-resolve'        => 'resolved a comment thread',
		'agent-brain/comment-convert'        => 'turned a comment into a request',
		'agent-brain/artifact-store-get'     => 'read an artifact data store',
		'agent-brain/artifact-store-set'     => 'wrote an artifact data store',
		'agent-brain/artifact-store-delete'  => 'deleted from an artifact data store',
		'agent-brain/artifact-store-config'  => 'changed the page store settings of an artifact',
		'agent-brain/artifact-store-history' => 'read the history of a data store document',
		'agent-brain/artifact-store-restore' => 'restored a data store document version',
		'agent-brain/artifact-store-undo-link' => 'undid the data store writes of a share link',
		'agent-brain/artifact-upload-put'    => 'uploaded a file to an artifact',
		'agent-brain/artifact-upload-list'   => 'listed the files of an artifact',
		'agent-brain/artifact-upload-delete' => 'deleted a file from an artifact',
		'agent-brain/ask'                    => 'asked the brain a question',
		'agent-brain/source-register'        => 'registered an outside source',
		'agent-brain/source-get'             => 'read an outside source record',
		'agent-brain/section-blame'          => 'viewed a section blame',
		'agent-brain/knowledge-blame'        => 'viewed a knowledge blame',
		'agent-brain/brain-app-link-get'     => 'read the app link',
		'agent-brain/brain-app-link-set'     => 'changed the app link',
		'agent-brain/tioday-get'             => 'read a TíoDay',
		'agent-brain/tioday-task'            => 'added or updated a TíoDay task',
		'agent-brain/tioday-done'            => 'recorded work done on a TíoDay',
		'agent-brain/tioday-inflight'        => 'updated work in flight on a TíoDay',
		'agent-brain/tioday-decided'         => 'recorded a decision on a TíoDay',
		'agent-brain/tioday-reply'           => 'replied to a TíoDay comment',
		'agent-brain/review-queue'           => 'read the review queue',
		'agent-brain/review-decide'          => 'decided a suggested fact in chat',
		'agent-brain/handoff-dismiss'        => 'closed a review request in chat',
	];

	/**
	 * Non-ability events worth showing in the live feed; everything else
	 * (backups, compression, recall internals) stays out of the feed but
	 * remains in the audit log.
	 */
	private const EVENT_LABELS = [
		'oauth.grant'                 => 'connected an AI client',
		'knowledge_review'            => 'reviewed a proposed fact',
		'knowledge_lifecycle'         => 'marked a reference page as current',
		'agent.switch'                => 'switched role',
		'agent.create'                => 'added a proposed agent',
		'agent.decline'               => 'declined a proposed agent',
		'artifact_create'             => 'started a shared document',
		'artifact_stage_transition'   => 'moved a document to the next stage',
		'artifact_section_review'     => 'reviewed a section',
		'artifact_lead_warning'       => 'set a lead who is not an agent',
		'artifact_publish'            => 'published a document',
		'artifact_unpublish'          => 'unpublished a document',
		'publish_blocked'             => 'was blocked from publishing',
		'forget.completed'            => 'completed a forget request',
		'erase.complete'              => 'completed a forget request',
		'compression.endpoint_updated' => 'changed the summarising endpoint',
		'compression.endpoint_removed' => 'removed the summarising endpoint',
		'brain.seeded'                => 'set up the brain from the onboarding answers',
		'app_link.updated'            => 'changed the app link',
	];

	/**
	 * Events only the Audit Log shows (internal housekeeping the live feed
	 * deliberately hides).
	 */
	private const AUDIT_ONLY_LABELS = [
		'artifact_html_put'                 => 'uploaded a version of an HTML page',
		'artifact_html_approved'            => 'approved a version of an HTML page',
		'artifact_page_edited'              => 'edited an HTML page in place',
		'artifact_html_restore'             => 'restored an old version of an HTML page as a new version',
		'artifact_html_auto_reload_on'      => 'turned on reloading open pages when a new version of an HTML page is approved',
		'artifact_html_auto_reload_off'     => 'turned off reloading open pages when a new version of an HTML page is approved',
		'artifact_section_restore'          => 'restored an old version of a section as a new version',
		'compression.job_failed'            => 'compression job failed',
		'compression.job_completed'         => 'compressed memories',
		'compression.spend_blocked'         => 'compression paused by the spend cap',
		'compression.disabled'              => 'compression was disabled',
		'compression.endpoint_tested'       => 'tested the summarising endpoint',
		'backup.run'                        => 'ran a backup',
		'export.create'                     => 'made a full export',
		'import.restore'                    => 'migrated a whole brain here from an archive',
		'principal.bind'                    => 'bound a migrated person or agent',
		'import.merge'                      => 'imported pages from an archive',
		'import.reference'                  => 'approved imported pages as reference',
		'import.move'                       => 'moved scopes here from an archive',
		'review.memory_approved'            => 'approved a proposed memory',
		'review.memory_rejected'            => 'rejected a proposed memory',
		'backup.upload'                     => 'uploaded a backup',
		'backup.key_generated'              => 'generated the backup key',
		'backup.key_downloaded'             => 'downloaded the backup key',
		'backup.markdown_export_degraded'   => 'backup skipped the Markdown pages',
		'forget.requested'                  => 'requested a forget',
		'erase.request'                     => 'requested a forget',
		'erase.verify'                      => 'checked whether a subject was forgotten',
		'retention.sweep'                   => 'purged memories deleted over 30 days ago',
		'scope.second_refused'              => 'was refused a second scope on a post',
		'recall_search'                     => 'searched memory',
		'memory.rollover_failed'            => 'memory rollover failed',
		'memory.write_failed'               => 'could not save a memory',
		'artifact_assembly_memory_failed'   => 'could not save an assembled document\'s summary as a memory',
		'compression.write_failed'          => 'compression could not save its result',
		'artifact_archive'                  => 'archived a document',
		'cloud.push_job'                    => 'sent a job to the cloud',
		'agent.register'                    => 'registered an agent',
		'agent.register_failed'             => 'failed to register an agent',
		'agent.register_key_reused'         => 'tried to reuse a spent registration key',
		'agent.registration_create'         => 'created an agent registration',
		'agent.registration_revoke'         => 'revoked an agent registration',
		'agent.revoke_app_passwords'        => 'revoked an agent\'s app passwords',
		'agent.status'                      => 'changed an agent\'s status',
		'app_password_create'               => 'created an app password',
		'app_password_delete'               => 'deleted an app password',
		'agent.issue'                       => 'issued an agent identity',
		'agent.forbidden_caps_removed'      => 'removed capabilities an agent may not hold',
		'agent.propose'                     => 'proposed a new agent',
		'agent.proposal_expired'            => 'removed an expired agent proposal',
		'agent_view.search'                 => 'tested a search in Agent View',
		'artifact_assembled_proposal_failed' => 'could not propose an assembled document for review',
		'backup.forget_propagate'           => 'passed a forget request on to the backups',
		'cloud.callback'                    => 'received a result from the cloud',
		'cloud.connect'                     => 'connected to TomaTío Cloud',
		'cloud.disconnect'                  => 'disconnected from TomaTío Cloud',
		'cloud.rest_request'                => 'received a cloud request',
		'cloud.tick'                        => 'received a cloud wake-up call',
		'compression.retry_requested'       => 'asked to retry a compression job',
		'compression.update_target_invalid' => 'compression skipped a memory that no longer exists',
		'handoff_outcome_memory_write_failed' => 'could not save a handoff outcome as a memory',
		'memory.admin_delete'               => 'deleted a memory',
		'memory.admin_update'               => 'edited a memory',
		'oauth.authorize'                   => 'was asked to authorise an AI client',
		'oauth.cimd_pin_unavailable'        => 'could not verify an AI client address',
		'oauth.client_read'                => 'read an AI client registration',
		'oauth.client_register'            => 'registered an AI client',
		'oauth.client_revoke'              => 'revoked a registered AI client',
		'oauth.revoke'                      => 'revoked AI client access',
		'oauth.token'                       => 'exchanged an AI client token',
		'ops.decay_prune_batch'             => 'pruned old memories',
		'review_outcome_memory_migration'   => 'migrated review outcomes into memories',
		'review_outcome_memory_write_failed' => 'could not save a review outcome as a memory',
		'scope.created'                     => 'created a scope',
		'scope.deleted'                     => 'deleted a scope',
		'scope.renamed'                     => 'renamed a scope',
		'scope_grant'                       => 'granted a scope to an agent',
		'scope_revoke'                      => 'revoked a scope from an agent',
		'grant.removed'                     => 'took the public grant off an item',
		'approval_grant'                    => 'granted approval rights to an agent',
		'approval_revoke'                   => 'revoked approval rights from an agent',
		'embed_code_issued'                 => 'issued a single-use code for an embedded document',
		'embed_exchange_denied'             => 'was blocked: embed code could not be exchanged',
		'embed_exchanged'                   => 'started an embedded document session',
		'interactive_kill_switch_changed'   => 'changed the interactive mode kill switch',
		'interactive_script_hosts_changed'  => 'changed the interactive script hosts',
		'lease_minutes_changed'             => 'changed the section lease length',
		'handoff_expired'                   => 'closed a review request nobody acted on in time',
		'review_settings_changed'           => 'changed the review settings',
		'action_context_changed'            => 'turned action context on or off',
		'action_context_retention_changed'  => 'changed the action context retention period',
		'provenance.retention'              => 'pruned action context and provenance past its retention period',
		'share_link_approved'               => 'approved a share link',
		'share_link_expired_access_attempt' => 'tried to open an expired share link',
		'share_link_ip_mismatch'            => 'was blocked: share link opened from a different network address',
		'share_link_minted'                 => 'created a share link',
		'share_link_revoked'                => 'revoked a share link',
		'share_link_revoked_access_attempt' => 'tried to open a revoked share link',
		'artifact_viewer_identity_disabled' => 'turned off viewer identity for an artifact',
		'artifact_viewer_identity_enabled'  => 'turned on viewer identity for an artifact',
		'share_link_viewed'                 => 'opened a shared document',
		'viewer_code_issued'                => 'issued a single-use viewer code',
		'viewer_token_issued'               => 'opened an artifact as a named viewer',
		'artifact_brain_read'               => 'toggled live brain reads for shared artifact pages',
		'artifact_datastore_enabled'        => 'turned on the shared data store for an artifact',
		'artifact_datastore_disabled'       => 'turned off the shared data store for an artifact',
		'artifact_datastore_collections_set' => 'changed which data store collections share pages may use',
		'datastore_write'                   => 'saved data through an artifact store',
		'datastore_delete'                  => 'deleted data from an artifact store',
		'datastore_link_undone'             => 'undid the data store writes of a share link',
		'artifact_datastore_seats_on'       => 'turned on seats (personal links) for an artifact',
		'artifact_datastore_seats_off'      => 'turned off seats (personal links) for an artifact',
		'datastore_seat_claimed'            => 'claimed a seat on a shared page',
		'datastore_seat_released'           => 'released a seat on a shared page',
		'datastore_seat_imported'           => 'imported an existing personal link as a seat',
		'artifact_datastore_seat_icon_set'  => 'changed how personal links\' Home Screen icons look',
		'artifact_uploads_enabled'          => 'turned on file uploads for an artifact',
		'artifact_uploads_disabled'         => 'turned off file uploads for an artifact',
		'artifact_upload_created'           => 'uploaded a file to an artifact',
		'artifact_upload_deleted'           => 'deleted a file from an artifact',
		'artifact_upload_denied'            => 'was blocked: an upload was refused',
		'artifact_ask_brain'                => 'toggled ask-the-brain for shared artifact pages',
		'artifact_ask_cap'                  => 'set the daily ask budget for an artifact',
		'ask_brain_question'                => 'asked the brain a question from a page',
	];

	/**
	 * Every audit event key that has plain-language wording.
	 *
	 * @return array<int, string>
	 */
	public static function knownEvents(): array {
		return array_keys(self::EVENT_LABELS + self::AUDIT_ONLY_LABELS);
	}

	/**
	 * Every ability name that has plain-language wording.
	 *
	 * @return array<int, string>
	 */
	public static function knownAbilities(): array {
		return array_keys(self::ABILITY_LABELS);
	}

	/**
	 * Live-feed wording; null for events kept out of the feed.
	 */
	public static function feed(string $event, string $objectId): ?string {
		if ($event === 'ability.invoked') {
			return self::ABILITY_LABELS[$objectId] ?? 'used ' . $objectId;
		}

		return self::EVENT_LABELS[$event] ?? null;
	}

	/**
	 * Audit Log wording: the feed label when there is one, then the
	 * audit-only labels, then a humanised version of the raw key. Never
	 * null.
	 */
	public static function friendly(string $event, string $objectId): string {
		if ($event === 'ability.invoked' && $objectId === '') {
			return 'Used an ability';
		}

		if ($event === 'ability.invoked') {
			$label = self::ABILITY_LABELS[$objectId] ?? 'used the ' . trim(str_replace(['agent-brain/', '-', '_', '/'], ['', ' ', ' ', ' '], $objectId)) . ' ability';

			return ucfirst($label);
		}

		$label = self::feed($event, $objectId) ?? self::AUDIT_ONLY_LABELS[$event] ?? null;

		if ($label === null) {
			$label = trim(str_replace(['_', '.', '-'], ' ', $event));
		}

		return $label === '' ? $event : ucfirst($label);
	}
}
