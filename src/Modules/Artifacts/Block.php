<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

/**
 * ART-1: registers the `brain/section` block server-side. If the
 * ArtifactsEditor unit has built plugin/agent-brain/build/section/block.json
 * we register from that (so editor.js/style assets load); otherwise we
 * register by name with the contract's own attributes so the block still
 * parses/renders correctly with no editor UI. Either way the dynamic
 * render_callback is ours: it re-checks read access to the linked section
 * on every render (defense in depth alongside the map_meta_cap gate) and
 * always kses's the output before it reaches a curator's screen.
 */
class Block {

	public const NAME = 'brain/section';

	public static function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) ) {
			return;
		}

		$buildDir = dirname( __DIR__, 3 ) . '/build/section';

		if ( is_file( $buildDir . '/block.json' ) ) {
			register_block_type( $buildDir, [ 'render_callback' => [ self::class, 'render' ] ] );

			return;
		}

		register_block_type( self::NAME, [
			'attributes'      => [
				'sectionId' => [ 'type' => 'number' ],
			],
			'render_callback' => [ self::class, 'render' ],
		] );
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render( array $attributes ): string {
		$sectionId = isset( $attributes['sectionId'] ) ? (int) $attributes['sectionId'] : 0;
		if ( $sectionId <= 0 ) {
			return '';
		}

		$post = get_post( $sectionId );
		if ( ! $post || $post->post_type !== Cpts::SECTION_POST_TYPE ) {
			return '';
		}

		if ( ! current_user_can( 'read_post', $sectionId ) ) {
			return '';
		}

		return wp_kses_post( do_blocks( (string) $post->post_content ) );
	}
}
