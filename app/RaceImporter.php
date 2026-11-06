<?php

namespace DeliciousBrains\SpinupWPComposerSite;

use WP_CLI;

/**
 * Create draft race posts from a JSON export of Strava activities.
 *
 * The JSON file is an array of activities in the shape the Strava connector
 * returns (id, name, description, start_local, summary.distance in meters,
 * summary.moving_time / elapsed_time in seconds, summary.elevation_gain in
 * meters). Each activity becomes one draft in the Running category, tagged
 * `race`, dated on race day, with a stats block and a Strava link. The
 * activity id is kept in post meta so re-running skips races that already
 * have a post.
 *
 * ## EXAMPLES
 *
 *     wp race import races.json --dry-run
 *     wp race import races.json
 */
class RaceImporter {

	const META_KEY     = 'strava_activity_id';
	const CATEGORY     = 'running';
	const TAG          = [ 'Race', 'race' ];
	const PLACEHOLDER  = 'Write-up to come.';
	const METERS_MILE  = 1609.344;
	const FEET_METER   = 3.28084;

	/**
	 * Import races from a JSON file of Strava activities.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a JSON array of Strava activities.
	 *
	 * [--dry-run]
	 * : Report what would be created without writing anything.
	 *
	 * [--author=<id>]
	 * : Post author user ID. Defaults to 1.
	 *
	 * @when after_wp_load
	 */
	public function import( $args, $assoc ) {
		[ $file ] = $args;
		$dry_run  = ! empty( $assoc['dry-run'] );
		$author   = (int) ( $assoc['author'] ?? 1 );

		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Cannot read $file" );
		}
		$activities = json_decode( file_get_contents( $file ), true );
		if ( ! is_array( $activities ) ) {
			WP_CLI::error( 'File is not a JSON array of activities.' );
		}

		$category = get_category_by_slug( self::CATEGORY );
		if ( ! $category ) {
			WP_CLI::error( sprintf( 'Category "%s" not found.', self::CATEGORY ) );
		}
		$tag_id = $this->ensure_tag( $dry_run );

		$created = 0;
		$skipped = 0;
		foreach ( $activities as $activity ) {
			$race = $this->normalize( $activity );
			if ( ! $race ) {
				WP_CLI::warning( 'Skipping activity without id, name, start_local or summary.' );
				$skipped++;
				continue;
			}

			$existing = $this->find_existing( $race['id'] );
			if ( $existing ) {
				WP_CLI::log( sprintf( 'skip   %s (post %d exists)', $race['name'], $existing ) );
				$skipped++;
				continue;
			}

			WP_CLI::log( sprintf( 'create %s — %s — %s', $race['date'], $race['name'], $race['stats']['distance'] ) );
			if ( $dry_run ) {
				$created++;
				continue;
			}

			$post_id = wp_insert_post(
				[
					'post_type'     => 'post',
					'post_status'   => 'draft',
					'post_title'    => $this->title( $race['name'] ),
					'post_content'  => $this->content( $race ),
					'post_date'     => $race['date'],
					// Pin the date: a draft with no GMT date floats to the time it is
					// saved or published, which would lose the race day.
					'post_date_gmt' => get_gmt_from_date( $race['date'] ),
					'edit_date'     => true,
					'post_author'   => $author,
					'post_category' => [ $category->term_id ],
					'tags_input'    => [ $tag_id ],
					'meta_input'    => [ self::META_KEY => $race['id'] ],
				],
				true
			);
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( $post_id->get_error_message() );
				$skipped++;
				continue;
			}
			$created++;
		}

		WP_CLI::success( sprintf( '%d created, %d skipped%s.', $created, $skipped, $dry_run ? ' (dry run)' : '' ) );
	}

	private function ensure_tag( bool $dry_run ): int {
		[ $name, $slug ] = self::TAG;
		$term            = get_term_by( 'slug', $slug, 'post_tag' );
		if ( $term ) {
			return (int) $term->term_id;
		}
		if ( $dry_run ) {
			WP_CLI::log( "would create tag $slug" );
			return 0;
		}
		$result = wp_insert_term( $name, 'post_tag', [ 'slug' => $slug ] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		return (int) $result['term_id'];
	}

	private function find_existing( string $activity_id ): ?int {
		$ids = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_KEY,
				'meta_value'     => $activity_id,
			]
		);
		return $ids ? (int) $ids[0] : null;
	}

	private function normalize( array $activity ): ?array {
		$id      = (string) ( $activity['id'] ?? '' );
		$name    = trim( (string) ( $activity['name'] ?? '' ) );
		$start   = (string) ( $activity['start_local'] ?? '' );
		$summary = $activity['summary'] ?? null;
		if ( ! $id || ! $name || ! $start || ! is_array( $summary ) ) {
			return null;
		}
		$ts = strtotime( $start );
		if ( ! $ts ) {
			return null;
		}

		$meters  = (float) ( $summary['distance'] ?? 0 );
		$miles   = $meters / self::METERS_MILE;
		$elapsed = (int) ( $summary['elapsed_time'] ?? 0 );
		$moving  = (int) ( $summary['moving_time'] ?? 0 );
		$feet    = (float) ( $summary['elevation_gain'] ?? 0 ) * self::FEET_METER;

		return [
			'id'    => $id,
			'name'  => $name,
			'date'  => gmdate( 'Y-m-d H:i:s', $ts ),
			'url'   => 'https://www.strava.com/activities/' . $id,
			'stats' => [
				'distance'  => sprintf( '%.2f mi (%.2f km)', $miles, $meters / 1000 ),
				'elapsed'   => $this->duration( $elapsed ),
				'moving'    => $this->duration( $moving ),
				// Strava reports race pace over elapsed time.
				'pace'      => $miles > 0 && $elapsed > 0 ? $this->duration( (int) round( $elapsed / $miles ) ) . ' /mi' : '',
				'elevation' => sprintf( '%s ft', number_format( round( $feet ) ) ),
			],
		];
	}

	/**
	 * Strava names often carry emoji. This site's tables are still latin1, so
	 * 4-byte characters would be stored as "?"; encode them as HTML entities
	 * (as core does on non-utf8mb4 sites) unless the column can hold them.
	 */
	private function title( string $name ): string {
		global $wpdb;
		if ( 'utf8mb4' === $wpdb->get_col_charset( $wpdb->posts, 'post_title' ) ) {
			return $name;
		}
		return wp_encode_emoji( $name );
	}

	private function duration( int $seconds ): string {
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;
		return $h ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $m, $s );
	}

	private function content( array $race ): string {
		$rows = [
			'Distance'       => $race['stats']['distance'],
			'Elapsed time'   => $race['stats']['elapsed'],
			'Moving time'    => $race['stats']['moving'],
			'Average pace'   => $race['stats']['pace'],
			'Elevation gain' => $race['stats']['elevation'],
		];
		$items = '';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$items .= sprintf(
				"<!-- wp:list-item -->\n<li><strong>%s:</strong> %s</li>\n<!-- /wp:list-item -->\n\n",
				esc_html( $label ),
				esc_html( $value )
			);
		}
		$items .= sprintf(
			"<!-- wp:list-item -->\n<li><a href=\"%s\">View on Strava</a></li>\n<!-- /wp:list-item -->\n",
			esc_url( $race['url'] )
		);

		return "<!-- wp:group {\"className\":\"race-stats\",\"layout\":{\"type\":\"constrained\"}} -->\n"
			. "<div class=\"wp-block-group race-stats\"><!-- wp:list -->\n"
			. "<ul class=\"wp-block-list\">$items</ul>\n"
			. "<!-- /wp:list --></div>\n"
			. "<!-- /wp:group -->\n\n"
			. "<!-- wp:paragraph -->\n<p>" . esc_html( self::PLACEHOLDER ) . "</p>\n<!-- /wp:paragraph -->";
	}
}
