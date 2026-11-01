<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Patterns;

use AgentBrain\Core\ModuleInterface;

/**
 * Registers the "TomaTío" block pattern category plus the landing page
 * and footer patterns that ship with the plugin, so site owners can insert
 * them from the pattern inserter without hand-assembling blocks.
 *
 * Owns no tables (migrations() is empty). Pattern markup is static PHP under
 * plugin/agent-brain/patterns/*.php, included via output buffering so
 * translatable strings inside them run through esc_html_e()/esc_html__().
 */
class Module implements ModuleInterface {

	public const CATEGORY = 'agent-brain';

	private const PATTERNS_DIR = __DIR__ . '/../../../patterns';

	public function id(): string {
		return 'patterns';
	}

	public function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('init', [self::class, 'registerPatterns']);
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [];
	}

	public function activate(): void {
		// Nothing to activate; patterns register on every 'init' via register().
	}

	public function deactivate(): void {
		// Nothing to deactivate; patterns are not persisted state.
	}

	public static function registerPatterns(): void {
		if (function_exists('register_block_pattern_category')) {
			register_block_pattern_category(
				self::CATEGORY,
				[ 'label' => __( 'TomaTío', 'agent-brain' ) ]
			);
		}

		if (! function_exists('register_block_pattern')) {
			return;
		}

		$landingContent = self::patternContent('landing-page.php');
		if ($landingContent !== null) {
			register_block_pattern(
				'agent-brain/landing-page',
				[
					'title'         => __( 'TomaTío landing page', 'agent-brain' ),
					'description'   => __( 'A full landing page introducing TomaTío: hero, feature columns, how-it-works steps, the multi-agent authoring pipeline, and a closing call to action.', 'agent-brain' ),
					'categories'    => [ self::CATEGORY, 'featured' ],
					'postTypes'     => [ 'page' ],
					'blockTypes'    => [ 'core/post-content' ],
					'viewportWidth' => 1440,
					'content'       => $landingContent,
				]
			);
		}

		$footerContent = self::patternContent('footer.php');
		if ($footerContent !== null) {
			register_block_pattern(
				'agent-brain/footer',
				[
					'title'       => __( 'TomaTío footer', 'agent-brain' ),
					'description' => __( 'A site footer describing TomaTío, linking to the project, and listing what the plugin includes.', 'agent-brain' ),
					'categories'  => [ self::CATEGORY, 'footer' ],
					'blockTypes'  => [ 'core/template-part/footer' ],
					'content'     => $footerContent,
				]
			);
		}
	}

	/**
	 * Load a pattern markup file's rendered output via output buffering.
	 * Fails loudly (error_log) and returns null rather than fatal if the
	 * file is missing, so one missing pattern never blocks the others or
	 * the request.
	 */
	private static function patternContent(string $filename): ?string {
		$path = self::PATTERNS_DIR . '/' . $filename;

		if (! is_file($path)) {
			error_log(sprintf('[agent-brain] Patterns module: pattern file missing: %s', $path));
			return null;
		}

		ob_start();
		include $path;
		$content = ob_get_clean();

		if ($content === false) {
			error_log(sprintf('[agent-brain] Patterns module: failed to capture output for %s', $path));
			return null;
		}

		return $content;
	}
}
