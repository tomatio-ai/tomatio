<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * The brain's entry under WordPress's own Tools > Import, where people look
 * when they move a site or bring content in from another platform. It
 * explains the three flows and links to them on the Export and import page,
 * which does the work behind its own checks; nothing is uploaded here.
 */
final class WpImporter {

	public const ID = 'tomatio-pcp';

	public static function register(): void {
		if (! function_exists('register_importer') || ! Exports::canExport(get_current_user_id())) {
			return;
		}

		register_importer(
			self::ID,
			__('TomaTío brain (PCP archive)', 'agent-brain'),
			__('Migrate a whole brain here from its full export, move chosen scopes of another brain here, or import the pages of a PCP archive from another brain or tool (Notion, through the converter) as proposals.', 'agent-brain'),
			[self::class, 'render']
		);
	}

	public static function render(): void {
		if (! Exports::canExport(get_current_user_id())) {
			wp_die(esc_html__('You do not have permission to import into this brain.', 'agent-brain'), 403);
		}

		$page = admin_url('admin.php?page=' . ExportPage::SLUG);

		echo '<div class="wrap ab-admin"><h1>' . esc_html__('Bring a brain or an archive into TomaTío', 'agent-brain') . '</h1>';
		echo '<ul class="ul-disc">';
		echo '<li><a href="' . esc_url($page . '#migrate') . '"><strong>' . esc_html__('Migrate a brain here', 'agent-brain') . '</strong></a>: ' . esc_html__('a whole brain, from its full export, into this empty brain.', 'agent-brain') . '</li>';
		echo '<li><a href="' . esc_url($page . '#move') . '"><strong>' . esc_html__('Move scopes here', 'agent-brain') . '</strong></a>: ' . esc_html__('chosen scopes of another brain, with their documents, history and people, into this brain.', 'agent-brain') . '</li>';
		echo '<li><a href="' . esc_url($page . '#import') . '"><strong>' . esc_html__('Import an archive', 'agent-brain') . '</strong></a>: ' . esc_html__('pages from another brain or PCP tool, as proposals for a person to review.', 'agent-brain') . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__('Backups of this brain are not archives to migrate, and restoring one is not available yet.', 'agent-brain') . '</p>';
		echo '</div>';
	}
}
