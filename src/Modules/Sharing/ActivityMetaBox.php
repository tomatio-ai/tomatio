<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Cpts;

/**
 * #221: "Activity" box on the artifact screen — anonymous per-day and
 * per-link share-page view totals (the wp-admin face of ViewAnalytics).
 * Shown only to those who may manage the artifact's share links; the rows
 * carry counts, never viewer material.
 */
final class ActivityMetaBox {

	public const DAYS = 14;

	public static function register(): void {
		add_action( 'add_meta_boxes_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'addMetaBox' ] );
	}

	public static function addMetaBox( \WP_Post $post ): void {
		if ( ! ShareLinks::canManage( get_current_user_id(), (int) $post->ID ) ) {
			return;
		}

		add_meta_box( 'ab_artifact_activity', __( 'Activity', 'agent-brain' ), [ self::class, 'render' ], Cpts::ARTIFACT_POST_TYPE, 'side', 'default' );
	}

	public static function render( \WP_Post $post ): void {
		echo self::html( (int) $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* in html().
	}

	public static function html( int $artifactId ): string {
		$days   = self::DAYS;
		$totals = ViewAnalytics::totals( $artifactId, $days );
		$byDay  = ViewAnalytics::perDayTotals( $artifactId, $days );
		$byLink = ViewAnalytics::perLinkTotals( $artifactId, $days );

		$out = '<p>' . esc_html( sprintf(
			/* translators: 1: number of views, 2: number of unique viewers, 3: number of days. */
			_n( '%1$d view (%2$d unique viewer) in the last %3$d days.', '%1$d views (%2$d unique viewers) in the last %3$d days.', $totals['views'], 'agent-brain' ),
			$totals['views'],
			$totals['viewers'],
			$days
		) ) . '</p>';

		if ( $byDay !== [] ) {
			$out .= '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Day', 'agent-brain' ) . '</th><th>' . esc_html__( 'Views', 'agent-brain' ) . '</th><th>' . esc_html__( 'Viewers', 'agent-brain' ) . '</th></tr></thead><tbody>';
			foreach ( $byDay as $row ) {
				$out .= '<tr><td>' . esc_html( $row['day'] ) . '</td><td>' . esc_html( (string) $row['views'] ) . '</td><td>' . esc_html( (string) $row['viewers'] ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}

		if ( $byLink !== [] ) {
			$out .= '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Link', 'agent-brain' ) . '</th><th>' . esc_html__( 'Views', 'agent-brain' ) . '</th><th>' . esc_html__( 'Viewers', 'agent-brain' ) . '</th></tr></thead><tbody>';
			foreach ( $byLink as $row ) {
				$out .= '<tr><td>#' . esc_html( (string) $row['link_id'] ) . '</td><td>' . esc_html( (string) $row['views'] ) . '</td><td>' . esc_html( (string) $row['viewers'] ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}

		return $out . '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d: number of days. */
				__( 'Anonymous counts only (daily-rotated IP hashes); daily detail is kept for %d days.', 'agent-brain' ),
				ViewAnalytics::KEEP_DAYS
			)
		) . '</p>';
	}
}
