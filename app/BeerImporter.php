<?php

namespace DeliciousBrains\SpinupWPComposerSite;

use SimpleXMLElement;
use WP_CLI;

use function Env\env;

/**
 * Draft brewing posts from Brewfather: a BeerXML export, or a batch pulled
 * from the Brewfather API with its brew day data.
 *
 * A post starts with the generated recipe (intro line, `recipe-stats` vitals,
 * fermentables, hops, other additions, yeast, mash, fermentation) and, for an
 * API batch, a `brew-day` group with the measured numbers, batch notes and
 * readings. Anything written after that generated span is left alone when a
 * batch is re-imported with --update. US units with metric alongside.
 *
 * The API needs BREWFATHER_USER_ID and BREWFATHER_API_KEY in .env, from a key
 * with the Read Recipes and Read Batches scopes.
 *
 * ## EXAMPLES
 *
 *     wp beer import ~/Brewfather_BeerXML_Oktoberfest.xml --dry-run
 *     wp beer batches
 *     wp beer batch 14
 *     wp beer batch "Oktoberfest" --update
 */
class BeerImporter {

	const META_SOURCE = 'beerxml_source';
	const META_BATCH  = 'brewfather_batch_id';
	const CATEGORY    = 'brewing';
	const TAG         = [ 'Recipe', 'recipe' ];
	const PLACEHOLDER = 'Brew day notes to come.';
	const API         = 'https://api.brewfather.app/v2';

	const KG_LB = 2.20462262;
	const G_OZ  = 0.0352739619;
	const L_GAL = 0.264172052;

	const SPAN_START = '<!-- wp:paragraph {"className":"recipe-intro"} -->';
	const SPAN_END   = '<!-- wp:group {"className":"brew-day"';

	/**
	 * Import recipes from a BeerXML file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the BeerXML file.
	 *
	 * [--dry-run]
	 * : Report what would be created without writing anything.
	 *
	 * [--author=<id>]
	 * : Post author user ID. Defaults to 1.
	 *
	 * [--category=<slug>]
	 * : Category slug. Defaults to brewing.
	 *
	 * @when after_wp_load
	 */
	public function import( $args, $assoc ) {
		[ $file ] = $args;
		$dry_run  = ! empty( $assoc['dry-run'] );

		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Cannot read $file" );
		}
		$xml = simplexml_load_string( file_get_contents( $file ), SimpleXMLElement::class, LIBXML_NOCDATA );
		if ( ! $xml || ! isset( $xml->RECIPE ) ) {
			WP_CLI::error( 'Not a BeerXML file (no RECIPE elements).' );
		}

		$source  = basename( $file );
		$created = 0;
		$skipped = 0;
		foreach ( $xml->RECIPE as $recipe_xml ) {
			$recipe = $this->normalize_xml( $recipe_xml );
			$key    = $source . ( count( $xml->RECIPE ) > 1 ? '#' . $recipe['name'] : '' );

			$existing = $this->find_existing( self::META_SOURCE, $key );
			if ( $existing ) {
				WP_CLI::log( sprintf( 'skip   %s (post %d exists)', $recipe['name'], $existing ) );
				$skipped++;
				continue;
			}

			WP_CLI::log( sprintf( 'create %s — %s, OG %s, %s ABV', $recipe['name'], $recipe['style'], $recipe['og'], $recipe['abv'] ) );
			if ( $dry_run ) {
				$created++;
				continue;
			}

			$post_id = $this->create_post( $recipe, null, [ self::META_SOURCE => $key ], $assoc );
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( $post_id->get_error_message() );
				$skipped++;
				continue;
			}
			WP_CLI::log( "       post $post_id" );
			$created++;
		}

		WP_CLI::success( sprintf( '%d created, %d skipped%s.', $created, $skipped, $dry_run ? ' (dry run)' : '' ) );
	}

	/**
	 * List batches in Brewfather, newest first, with the post each one has.
	 *
	 * @when after_wp_load
	 */
	public function batches( $args, $assoc ) {
		$rows = [];
		foreach ( $this->api_batches() as $b ) {
			$post   = $this->find_existing( self::META_BATCH, $b['_id'] );
			$rows[] = [
				'batch'  => $b['batchNo'] ?? '',
				'date'   => $this->date( $b['brewDate'] ?? 0, 'Y-m-d' ),
				'status' => $b['status'] ?? '',
				'recipe' => $b['recipe']['name'] ?? ( $b['name'] ?? '' ),
				'post'   => $post ?: '',
				'id'     => $b['_id'],
			];
		}
		usort( $rows, fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'batch', 'date', 'status', 'recipe', 'post', 'id' ] );
	}

	/**
	 * Create or update a draft from a Brewfather batch.
	 *
	 * ## OPTIONS
	 *
	 * <batch>
	 * : Batch number, Brewfather batch id, or part of the recipe name.
	 *
	 * [--update]
	 * : Rewrite the generated recipe and brew day span of the existing post,
	 * keeping anything written after it.
	 *
	 * [--post=<id>]
	 * : Attach the batch to this existing post instead of creating one. The
	 * generated span is replaced, or the whole content if the post has none.
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * [--author=<id>]
	 * : Post author user ID for a new post. Defaults to 1.
	 *
	 * [--category=<slug>]
	 * : Category slug for a new post. Defaults to brewing.
	 *
	 * @when after_wp_load
	 */
	public function batch( $args, $assoc ) {
		[ $wanted ] = $args;
		$dry_run    = ! empty( $assoc['dry-run'] );
		$update     = ! empty( $assoc['update'] );
		$attach     = (int) ( $assoc['post'] ?? 0 );

		$summary = $this->pick_batch( $wanted );
		$batch   = $this->api_get( '/batches/' . $summary['_id'] );
		if ( empty( $batch['recipe'] ) ) {
			WP_CLI::error( 'Batch has no recipe data.' );
		}
		$batch['readings'] = $this->api_get( '/batches/' . $summary['_id'] . '/readings' ) ?: [];

		$recipe = $this->normalize_api( $batch['recipe'] );
		$brew   = $this->normalize_batch( $batch );
		$label  = sprintf( 'Batch #%s %s (%s, brewed %s)', $brew['no'], $recipe['name'], $brew['status'], $brew['brew_date'] );

		$post_id = $attach ?: $this->find_existing( self::META_BATCH, $batch['_id'] );
		if ( $attach && ! get_post( $attach ) ) {
			WP_CLI::error( "Post $attach not found." );
		}

		if ( ! $post_id ) {
			WP_CLI::log( "create $label" );
			if ( $dry_run ) {
				WP_CLI::success( 'Would create 1 post (dry run).' );
				return;
			}
			$post_id = $this->create_post( $recipe, $brew, [ self::META_BATCH => $batch['_id'] ], $assoc );
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::error( $post_id->get_error_message() );
			}
			WP_CLI::success( "Created draft $post_id." );
			return;
		}

		if ( ! $update && ! $attach ) {
			WP_CLI::log( "skip   $label (post $post_id exists; pass --update to refresh it)" );
			return;
		}

		$post    = get_post( $post_id );
		$content = $this->replace_span( $post->post_content, $this->content( $recipe, $brew ), $replaced_whole );
		WP_CLI::log( sprintf( 'update %s -> post %d (%s)', $label, $post_id, $replaced_whole ? 'whole content replaced' : 'generated span replaced, your text kept' ) );
		if ( $dry_run ) {
			WP_CLI::success( 'Would update 1 post (dry run).' );
			return;
		}
		$data = [ 'ID' => $post_id, 'post_content' => $content, 'edit_date' => true ];
		if ( $brew['brew_ts'] && ! get_post_meta( $post_id, self::META_BATCH, true ) ) {
			// First attach: date the draft on brew day. Later updates keep whatever date it has.
			$data['post_date']     = wp_date( 'Y-m-d H:i:s', $brew['brew_ts'] );
			$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $brew['brew_ts'] );
		}
		$result = wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		update_post_meta( $post_id, self::META_BATCH, $batch['_id'] );
		WP_CLI::success( "Updated post $post_id." );
	}

	/* ---------------------------------------------------------------- posts */

	private function create_post( array $recipe, ?array $brew, array $meta, array $assoc ) {
		$category = get_category_by_slug( $assoc['category'] ?? self::CATEGORY );
		if ( ! $category ) {
			return new \WP_Error( 'no_category', 'Category not found.' );
		}
		$tag_id = $this->ensure_tag();
		$data   = [
			'post_type'     => 'post',
			'post_status'   => 'draft',
			'post_title'    => $recipe['name'],
			'post_content'  => $this->content( $recipe, $brew ) . "\n\n" . $this->paragraph( self::PLACEHOLDER ),
			'post_author'   => (int) ( $assoc['author'] ?? 1 ),
			'post_category' => [ $category->term_id ],
			'tags_input'    => [ $tag_id ],
			'meta_input'    => $meta,
		];
		if ( $brew && $brew['brew_ts'] ) {
			// Date the post on brew day and pin it, as the race posts are.
			$data['post_date']     = wp_date( 'Y-m-d H:i:s', $brew['brew_ts'] );
			$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $brew['brew_ts'] );
			$data['edit_date']     = true;
		}
		return wp_insert_post( $data, true );
	}

	/**
	 * Swap the generated span (intro paragraph through the brew-day group) for
	 * new markup, keeping whatever follows it. Without markers the whole
	 * content is replaced.
	 */
	private function replace_span( string $content, string $generated, ?bool &$replaced_whole ): string {
		$start = strpos( $content, self::SPAN_START );
		$open  = false === $start ? false : strpos( $content, self::SPAN_END, $start );
		$close = false === $open ? false : strpos( $content, '<!-- /wp:group -->', $open );
		if ( false === $close ) {
			$replaced_whole = true;
			return $generated . "\n\n" . $this->paragraph( self::PLACEHOLDER );
		}
		$replaced_whole = false;
		$end            = $close + strlen( '<!-- /wp:group -->' );
		return substr( $content, 0, $start ) . $generated . substr( $content, $end );
	}

	private function ensure_tag(): int {
		[ $name, $slug ] = self::TAG;
		$term            = get_term_by( 'slug', $slug, 'post_tag' );
		if ( $term ) {
			if ( $term->name !== $name ) {
				// Tags are kept in Title Case; an older lowercase tag with this slug gets renamed.
				wp_update_term( $term->term_id, 'post_tag', [ 'name' => $name ] );
			}
			return (int) $term->term_id;
		}
		$result = wp_insert_term( $name, 'post_tag', [ 'slug' => $slug ] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		return (int) $result['term_id'];
	}

	private function find_existing( string $meta_key, string $value ): ?int {
		$ids = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => $meta_key,
				'meta_value'     => $value,
			]
		);
		return $ids ? (int) $ids[0] : null;
	}

	/* ------------------------------------------------------------------ API */

	private function api_get( string $path, array $args = [] ) {
		$user = env( 'BREWFATHER_USER_ID' );
		$key  = env( 'BREWFATHER_API_KEY' );
		if ( ! $user || ! $key ) {
			WP_CLI::error( 'Set BREWFATHER_USER_ID and BREWFATHER_API_KEY in .env.' );
		}
		$url      = self::API . $path . ( $args ? '?' . http_build_query( $args ) : '' );
		$response = wp_remote_get(
			$url,
			[
				'headers' => [ 'Authorization' => 'Basic ' . base64_encode( "$user:$key" ) ],
				'timeout' => 30,
			]
		);
		if ( is_wp_error( $response ) ) {
			WP_CLI::error( 'Brewfather API: ' . $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			WP_CLI::error( sprintf( 'Brewfather API %s returned HTTP %d.', $path, $code ) );
		}
		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	private function api_batches(): array {
		$all  = [];
		$args = [ 'limit' => 50, 'include' => 'recipe.name' ];
		do {
			$page = $this->api_get( '/batches', $args );
			$all  = array_merge( $all, $page );
			$last = end( $page );
			$args['start_after'] = $last['_id'] ?? null;
		} while ( count( $page ) === 50 && count( $all ) < 500 && $args['start_after'] );
		return $all;
	}

	private function pick_batch( string $wanted ): array {
		$batches = $this->api_batches();
		$matches = array_values(
			array_filter(
				$batches,
				function ( $b ) use ( $wanted ) {
					if ( $b['_id'] === $wanted ) {
						return true;
					}
					if ( ctype_digit( $wanted ) && (int) ( $b['batchNo'] ?? -1 ) === (int) $wanted ) {
						return true;
					}
					return false !== stripos( $b['recipe']['name'] ?? '', $wanted );
				}
			)
		);
		if ( ! $matches ) {
			WP_CLI::error( "No batch matches \"$wanted\". Run `wp beer batches` to see them." );
		}
		if ( count( $matches ) > 1 ) {
			$list = implode( "\n", array_map( fn( $b ) => sprintf( '  #%s %s %s', $b['batchNo'] ?? '?', $this->date( $b['brewDate'] ?? 0, 'Y-m-d' ), $b['recipe']['name'] ?? '' ), $matches ) );
			WP_CLI::error( "\"$wanted\" matches several batches; use the batch number:\n$list" );
		}
		return $matches[0];
	}

	/* ------------------------------------------------------------ normalize */

	/**
	 * Common recipe shape. Weights in kg (fermentables) and g (hops),
	 * volumes in liters, temperatures in Celsius.
	 */
	private function normalize_api( array $r ): array {
		$style = $r['style'] ?? [];
		return [
			'name'         => $this->clean( $r['name'] ?? '' ),
			'brewer'       => '',
			'type'         => $this->clean( $r['type'] ?? '' ),
			'style'        => $this->style_name( $style['name'] ?? '', $style['styleGuide'] ?? '', $style['categoryNumber'] ?? '', $style['styleLetter'] ?? '' ),
			'batch_l'      => (float) ( $r['batchSize'] ?? 0 ),
			'boil_l'       => (float) ( $r['boilSize'] ?? 0 ),
			'boil_time'    => (int) ( $r['boilTime'] ?? 0 ),
			'efficiency'   => (float) ( $r['efficiency'] ?? 0 ),
			'og'           => $this->gravity( $r['og'] ?? 0 ),
			'fg'           => $this->gravity( $r['fg'] ?? 0 ),
			'abv'          => $this->pct( $r['abv'] ?? 0 ),
			'ibu'          => $this->num( (float) ( $r['ibu'] ?? 0 ), 1 ),
			'srm'          => ! empty( $r['color'] ) ? $this->num( (float) $r['color'], 1 ) . ' SRM' : '',
			'carbonation'  => ! empty( $r['carbonation'] ) ? $this->num( (float) $r['carbonation'], 1 ) : '',
			'fermentables' => array_map(
				fn( $x ) => [
					'name'     => $this->clean( $x['name'] ?? '' ),
					'supplier' => $this->clean( $x['supplier'] ?? '' ),
					'kg'       => (float) ( $x['amount'] ?? 0 ),
					'pct'      => (float) ( $x['percentage'] ?? 0 ),
					'lovibond' => (float) ( $x['lovibond'] ?? $x['color'] ?? 0 ),
				],
				$r['fermentables'] ?? []
			),
			'hops'         => array_map(
				fn( $x ) => [
					'name'  => $this->clean( $x['name'] ?? '' ),
					'g'     => (float) ( $x['amount'] ?? 0 ),
					'alpha' => (float) ( $x['alpha'] ?? 0 ),
					'use'   => $this->clean( $x['use'] ?? '' ),
					'time'  => (int) ( $x['time'] ?? 0 ),
					'form'  => $this->clean( $x['type'] ?? '' ),
				],
				$r['hops'] ?? []
			),
			'miscs'        => array_map(
				fn( $x ) => [
					'name'   => $this->clean( $x['name'] ?? '' ),
					'amount' => $this->misc_amount( (float) ( $x['amount'] ?? 0 ), $x['unit'] ?? '' ),
					'use'    => $this->clean( $x['use'] ?? '' ),
					'time'   => (int) ( $x['time'] ?? 0 ),
					'days'   => ! empty( $x['timeIsDays'] ),
				],
				$r['miscs'] ?? []
			),
			'yeasts'       => array_map(
				fn( $y ) => [
					'name'        => $this->clean( trim( ( $y['laboratory'] ?? '' ) . ' ' . ( $y['name'] ?? '' ) . ( ! empty( $y['productId'] ) ? ' ' . $y['productId'] : '' ) ) ),
					'amount'      => $this->num( (float) ( $y['amount'] ?? 0 ) ) . ' ' . ( $y['unit'] ?? '' ),
					'attenuation' => (float) ( $y['attenuation'] ?? 0 ),
					'min_c'       => (float) ( $y['minTemp'] ?? 0 ),
					'max_c'       => (float) ( $y['maxTemp'] ?? 0 ),
				],
				$r['yeasts'] ?? []
			),
			'mash_name'    => $this->clean( $r['mash']['name'] ?? '' ),
			'mash_steps'   => array_map(
				fn( $m ) => [
					'name'   => $this->clean( $m['name'] ?? '' ) ?: $this->clean( $m['type'] ?? 'Rest' ),
					'temp_c' => (float) ( $m['stepTemp'] ?? 0 ),
					'time'   => (int) ( $m['stepTime'] ?? 0 ),
					'infuse' => 0,
				],
				$r['mash']['steps'] ?? []
			),
			'strike_l'     => (float) ( $r['data']['strikeWaterAmount'] ?? 0 ),
			'sparge_l'     => (float) ( $r['data']['spargeWaterAmount'] ?? 0 ),
			'ferm_name'    => $this->clean( $r['fermentation']['name'] ?? '' ),
			'ferm_steps'   => array_map(
				fn( $s ) => [
					'name'   => $this->clean( $s['type'] ?? 'Primary' ),
					'temp_c' => (float) ( $s['stepTemp'] ?? 0 ),
					'days'   => (int) ( $s['stepTime'] ?? 0 ),
					'psi'    => (float) ( $s['pressure'] ?? 0 ),
				],
				$r['fermentation']['steps'] ?? []
			),
			'equipment'    => $this->clean( $r['equipment']['name'] ?? '' ),
			'notes'        => $this->clean( $r['notes'] ?? '', true ),
		];
	}

	private function normalize_xml( SimpleXMLElement $r ): array {
		$s = fn( $v ) => $this->clean( (string) $v );
		$f = fn( $v ) => (float) $v;

		$fermentables = [];
		$total_kg     = 0;
		foreach ( $r->FERMENTABLES->FERMENTABLE ?? [] as $x ) {
			$total_kg      += $f( $x->AMOUNT );
			$fermentables[] = [ 'name' => $s( $x->NAME ), 'supplier' => $s( $x->SUPPLIER ), 'kg' => $f( $x->AMOUNT ), 'lovibond' => $f( $x->COLOR ) ];
		}
		foreach ( $fermentables as &$fm ) {
			$fm['pct'] = $total_kg > 0 ? $fm['kg'] / $total_kg * 100 : 0;
		}
		unset( $fm );

		$hops = [];
		foreach ( $r->HOPS->HOP ?? [] as $x ) {
			$hops[] = [ 'name' => $s( $x->NAME ), 'g' => $f( $x->AMOUNT ) * 1000, 'alpha' => $f( $x->ALPHA ), 'use' => $s( $x->USE ), 'time' => (int) $f( $x->TIME ), 'form' => $s( $x->FORM ) ];
		}
		$miscs = [];
		foreach ( $r->MISCS->MISC ?? [] as $x ) {
			$miscs[] = [ 'name' => $s( $x->NAME ), 'amount' => $this->misc_amount( $f( $x->AMOUNT ), '', $s( $x->DISPLAY_AMOUNT ) ), 'use' => $s( $x->USE ), 'time' => (int) $f( $x->TIME ), 'days' => false ];
		}
		$yeasts = [];
		foreach ( $r->YEASTS->YEAST ?? [] as $x ) {
			$yeasts[] = [
				'name'        => trim( $s( $x->LABORATORY ) . ' ' . $s( $x->NAME ) . ( $s( $x->PRODUCT_ID ) ? ' ' . $s( $x->PRODUCT_ID ) : '' ) ),
				'amount'      => $s( $x->DISPLAY_AMOUNT ) ?: $s( $x->AMOUNT ),
				'attenuation' => $f( $x->ATTENUATION ),
				'min_c'       => $f( $x->MIN_TEMPERATURE ),
				'max_c'       => $f( $x->MAX_TEMPERATURE ),
			];
		}
		$mash_steps = [];
		foreach ( $r->MASH->MASH_STEPS->MASH_STEP ?? [] as $x ) {
			$mash_steps[] = [ 'name' => $s( $x->NAME ) ?: 'Rest', 'temp_c' => $f( $x->STEP_TEMP ), 'time' => (int) $f( $x->STEP_TIME ), 'infuse' => $f( $x->INFUSE_AMOUNT ) ];
		}
		$ferm_steps = [];
		if ( $f( $r->PRIMARY_AGE ) ) {
			$ferm_steps[] = [ 'name' => 'Primary', 'temp_c' => $f( $r->PRIMARY_TEMP ), 'days' => (int) $f( $r->PRIMARY_AGE ), 'psi' => 0 ];
		}
		if ( $f( $r->SECONDARY_AGE ) ) {
			$ferm_steps[] = [ 'name' => 'Secondary', 'temp_c' => $f( $r->SECONDARY_TEMP ), 'days' => (int) $f( $r->SECONDARY_AGE ), 'psi' => 0 ];
		}

		return [
			'name'         => $s( $r->NAME ),
			'brewer'       => $s( $r->BREWER ),
			'type'         => $s( $r->TYPE ),
			'style'        => $this->style_name( $s( $r->STYLE->NAME ), $s( $r->STYLE->STYLE_GUIDE ), $s( $r->STYLE->CATEGORY_NUMBER ), $s( $r->STYLE->STYLE_LETTER ) ),
			'batch_l'      => $f( $r->BATCH_SIZE ),
			'boil_l'       => $f( $r->BOIL_SIZE ),
			'boil_time'    => (int) $f( $r->BOIL_TIME ),
			'efficiency'   => $f( $r->EFFICIENCY ),
			'og'           => $this->gravity( $s( $r->OG ) ?: $s( $r->EST_OG ) ),
			'fg'           => $this->gravity( $s( $r->FG ) ?: $s( $r->EST_FG ) ),
			'abv'          => $this->pct( $s( $r->ABV ) ?: $s( $r->EST_ABV ) ),
			'ibu'          => $s( $r->IBU ),
			'srm'          => $f( $r->EST_COLOR ) ? $this->num( $f( $r->EST_COLOR ), 1 ) . ' SRM' : '',
			'carbonation'  => $s( $r->CARBONATION ),
			'fermentables' => $fermentables,
			'hops'         => $hops,
			'miscs'        => $miscs,
			'yeasts'       => $yeasts,
			'mash_name'    => $s( $r->MASH->NAME ),
			'mash_steps'   => $mash_steps,
			'strike_l'     => 0,
			'sparge_l'     => 0,
			'ferm_name'    => $s( $r->BF_FERMENTATION_PROFILE_NAME ),
			'ferm_steps'   => $ferm_steps,
			'equipment'    => $s( $r->EQUIPMENT->NAME ),
			'notes'        => $this->clean( (string) $r->NOTES, true ),
		];
	}

	private function normalize_batch( array $b ): array {
		$timeline = [];
		foreach ( $b['notes'] ?? [] as $n ) {
			if ( ( $n['type'] ?? '' ) === 'statusChanged' && ! empty( $n['status'] ) ) {
				$timeline[] = [ 'ts' => (int) ( $n['timestamp'] ?? 0 ) / 1000, 'text' => $n['status'] ];
			} elseif ( ! empty( $n['note'] ) ) {
				$timeline[] = [ 'ts' => (int) ( $n['timestamp'] ?? 0 ) / 1000, 'text' => $this->clean( $n['note'] ) ];
			}
		}
		usort( $timeline, fn( $a, $b ) => $a['ts'] <=> $b['ts'] );

		$manual = [];
		$device = 0;
		foreach ( $b['readings'] ?? [] as $r ) {
			if ( ( $r['type'] ?? '' ) === 'manual' || ! empty( $r['comment'] ) ) {
				$manual[] = $r;
			} else {
				$device++;
			}
		}
		usort( $manual, fn( $a, $b ) => ( $a['time'] ?? 0 ) <=> ( $b['time'] ?? 0 ) );

		$brew_ts  = (int) ( $b['brewDate'] ?? 0 ) / 1000;
		$planning = ( $b['status'] ?? '' ) === 'Planning';
		if ( $planning ) {
			// A planned batch's "measured" fields hold the recipe estimates.
			foreach ( array_keys( $b ) as $k ) {
				if ( str_starts_with( $k, 'measured' ) ) {
					unset( $b[ $k ] );
				}
			}
		}
		return [
			'no'          => $b['batchNo'] ?? '',
			'status'      => $b['status'] ?? '',
			'planning'    => $planning,
			'brew_year'   => $brew_ts ? (int) wp_date( 'Y', (int) $brew_ts ) : 0,
			'brew_ts'     => $brew_ts,
			'brew_date'   => $this->date( $b['brewDate'] ?? 0 ),
			'ferm_date'   => $this->date( $b['fermentationStartDate'] ?? 0 ),
			'package_date' => ! $planning && ( ! empty( $b['bottlingDateSet'] ) || ! empty( $b['bottlingDate'] ) ) ? $this->date( $b['bottlingDate'] ?? 0 ) : '',
			'og'          => $this->gravity( $b['measuredOg'] ?? 0 ),
			'fg'          => $this->gravity( $b['measuredFg'] ?? 0 ),
			'abv'         => $this->pct( $b['measuredAbv'] ?? 0 ),
			'attenuation' => (float) ( $b['measuredAttenuation'] ?? 0 ),
			'pre_boil'    => $this->gravity( $b['measuredPreBoilGravity'] ?? 0 ),
			'batch_l'     => (float) ( $b['measuredBatchSize'] ?? 0 ),
			'mash_ph'     => (float) ( $b['measuredMashPh'] ?? 0 ),
			'mash_eff'    => (float) ( $b['measuredMashEfficiency'] ?? 0 ),
			'brewhouse'   => (float) ( $b['measuredEfficiency'] ?? 0 ),
			'carb_type'   => $planning ? '' : $this->clean( $b['carbonationType'] ?? '' ),
			'carb_psi'    => $planning ? 0 : (float) ( $b['carbonationForce'] ?? 0 ),
			'notes'       => $this->clean( $b['batchNotes'] ?? '', true ),
			'taste'       => $this->clean( $b['tasteNotes'] ?? '', true ),
			'rating'      => (float) ( $b['tasteRating'] ?? 0 ),
			'timeline'    => $timeline,
			'readings'    => $manual,
			'device_readings' => $device,
		];
	}

	/* ------------------------------------------------------------- helpers */

	private function clean( string $text, bool $keep_lines = false ): string {
		$text = str_replace( "\r\n", "\n", $text );
		return $keep_lines ? trim( preg_replace( '/[ \t]+/', ' ', $text ) ) : trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	private function style_name( string $name, string $guide, string $number, string $letter ): string {
		if ( $number ) {
			$name .= sprintf( ' (%s %s%s)', $guide ?: 'BJCP', ltrim( $number, '0' ), $letter );
		}
		return $name;
	}

	private function gravity( $v ): string {
		$v = (float) $v;
		return $v > 0 ? number_format( $v, 3 ) : '';
	}

	private function pct( $v ): string {
		$n = (float) $v;
		return $n > 0 ? $this->num( $n, 1 ) . '%' : '';
	}

	private function lb( float $kg ): string {
		return sprintf( '%s lb (%s kg)', $this->num( $kg * self::KG_LB ), $this->num( $kg ) );
	}

	private function oz( float $g ): string {
		return sprintf( '%s oz (%s g)', $this->num( $g * self::G_OZ ), $this->num( $g, 0 ) );
	}

	private function gal( float $l ): string {
		return sprintf( '%s gal (%s L)', $this->num( $l * self::L_GAL, 1 ), $this->num( $l, 1 ) );
	}

	private function temp( float $c ): string {
		return sprintf( '%s°F (%s°C)', round( $c * 9 / 5 + 32 ), $this->num( $c, 1 ) );
	}

	private function num( float $n, int $decimals = 2 ): string {
		$out = number_format( $n, $decimals );
		return $decimals > 0 ? rtrim( rtrim( $out, '0' ), '.' ) : $out;
	}

	private function date( $ms, string $format = 'M j, Y' ): string {
		$ms = (int) $ms;
		return $ms > 0 ? wp_date( $format, (int) ( $ms / 1000 ) ) : '';
	}

	/** "Nov 19" within the brew year, "Apr 6, 2024" outside it. */
	private function day( int $ts, int $brew_year ): string {
		if ( $ts <= 0 ) {
			return '';
		}
		return wp_date( (int) wp_date( 'Y', $ts ) === $brew_year ? 'M j' : 'M j, Y', $ts );
	}

	/** Brewfather's generic "items" unit reads badly in a post. */
	private function misc_amount( float $amount, string $unit, string $display = '' ): string {
		if ( $display ) {
			return str_replace( ' items', '', $display );
		}
		$unit = 'items' === $unit ? '' : $unit;
		return trim( $this->num( $amount ) . ' ' . $unit );
	}

	private function hop_use( array $hop ): string {
		$use = strtolower( $hop['use'] );
		if ( 'boil' === $use ) {
			return $hop['time'] . ' min';
		}
		if ( 'dry hop' === $use ) {
			return 'Dry hop, ' . max( 1, round( $hop['time'] / 1440 ) ) . ' days';
		}
		if ( 'aroma' === $use ) {
			return 'Whirlpool' . ( $hop['time'] ? ', ' . $hop['time'] . ' min' : '' );
		}
		return ucfirst( $use ) . ( $hop['time'] ? ', ' . $hop['time'] . ' min' : '' );
	}

	/* -------------------------------------------------------------- markup */

	private function content( array $r, ?array $brew ): string {
		$intro = sprintf( '%s. %s batch, %s on the %s.', $r['style'], $this->gal( $r['batch_l'] ), strtolower( $r['type'] ) ?: 'all grain', $r['equipment'] ?: 'brew rig' );
		if ( $brew ) {
			$intro = sprintf( 'Batch #%s, brewed %s. ', $brew['no'], $brew['brew_date'] ) . $intro;
		} elseif ( $r['brewer'] ) {
			$intro = sprintf( 'Recipe from %s. ', $r['brewer'] ) . $intro;
		}
		$out = $this->paragraph( $intro, 'recipe-intro' );

		$out .= $this->stats_group(
			'recipe-stats',
			[
				'Style'       => $r['style'],
				'Batch size'  => $this->gal( $r['batch_l'] ),
				'Boil'        => sprintf( '%d min, %s pre-boil', $r['boil_time'], $this->gal( $r['boil_l'] ) ),
				'OG'          => $r['og'],
				'FG'          => $r['fg'],
				'ABV'         => $r['abv'],
				'IBU'         => $r['ibu'],
				'Color'       => $r['srm'],
				'Efficiency'  => $r['efficiency'] ? $this->num( $r['efficiency'], 0 ) . '%' : '',
				'Carbonation' => $r['carbonation'] ? $r['carbonation'] . ' vol' : '',
			]
		);

		if ( $r['fermentables'] ) {
			$rows = array_map( fn( $x ) => [ $x['name'] . ( $x['supplier'] ? ' (' . $x['supplier'] . ')' : '' ), $this->lb( $x['kg'] ), $this->num( $x['pct'], 1 ) . '%', $this->num( $x['lovibond'], 1 ) . ' °L' ], $r['fermentables'] );
			$out .= $this->heading( 'Fermentables' ) . $this->table( [ 'Malt', 'Amount', 'Grist', 'Color' ], $rows );
		}
		if ( $r['hops'] ) {
			$rows = array_map( fn( $x ) => [ $x['name'] . ( $x['form'] ? ' (' . strtolower( $x['form'] ) . ')' : '' ), $this->oz( $x['g'] ), $this->num( $x['alpha'], 1 ) . '%', $this->hop_use( $x ) ], $r['hops'] );
			$out .= $this->heading( 'Hops' ) . $this->table( [ 'Hop', 'Amount', 'Alpha', 'Addition' ], $rows );
		}
		if ( $r['miscs'] ) {
			$rows = array_map( fn( $x ) => [ $x['name'], $x['amount'], ucfirst( strtolower( $x['use'] ) ) . ( $x['time'] ? ', ' . $x['time'] . ( $x['days'] ? ' days' : ' min' ) : '' ) ], $r['miscs'] );
			$out .= $this->heading( 'Other additions' ) . $this->table( [ 'Item', 'Amount', 'When' ], $rows );
		}
		if ( $r['yeasts'] ) {
			$items = array_map(
				fn( $y ) => sprintf( '%s, %s%s%s', $y['name'], $y['amount'], $y['attenuation'] ? ', ' . $this->num( $y['attenuation'], 0 ) . '% attenuation' : '', $y['max_c'] ? ', ' . round( $y['min_c'] * 9 / 5 + 32 ) . '–' . round( $y['max_c'] * 9 / 5 + 32 ) . '°F' : '' ),
				$r['yeasts']
			);
			$out .= $this->heading( 'Yeast' ) . $this->list( $items );
		}
		if ( $r['mash_steps'] ) {
			$items = array_map(
				fn( $m ) => sprintf( '%s: %s for %d min%s', $m['name'], $this->temp( $m['temp_c'] ), $m['time'], $m['infuse'] ? ', ' . $this->gal( $m['infuse'] ) . ' strike water' : '' ),
				$r['mash_steps']
			);
			if ( $r['strike_l'] ) {
				$items[] = sprintf( 'Water: %s strike, %s sparge', $this->gal( $r['strike_l'] ), $this->gal( $r['sparge_l'] ) );
			}
			$out .= $this->heading( 'Mash' . ( $r['mash_name'] ? ' (' . $r['mash_name'] . ')' : '' ) ) . $this->list( $items );
		}
		if ( $r['ferm_steps'] ) {
			$items = array_map(
				fn( $s ) => sprintf( '%s: %s for %d days%s', $s['name'], $this->temp( $s['temp_c'] ), $s['days'], $s['psi'] ? ' at ' . $this->num( $s['psi'], 0 ) . ' psi' : '' ),
				$r['ferm_steps']
			);
			$out .= $this->heading( 'Fermentation' . ( $r['ferm_name'] ? ' (' . $r['ferm_name'] . ')' : '' ) ) . $this->list( $items );
		}
		if ( $r['notes'] ) {
			$out .= $this->heading( 'Recipe notes' ) . $this->paragraphs( $r['notes'] );
		}

		$out .= $this->heading( 'Brew day' ) . $this->brew_day( $brew );
		return rtrim( $out );
	}

	/**
	 * The brew-day group closes the generated span, so it is always written,
	 * empty for a BeerXML import.
	 */
	private function brew_day( ?array $b ): string {
		$inner = '';
		if ( $b ) {
			$inner .= $this->stats_list(
				[
					( $b['planning'] ? 'Brew day planned' : 'Brewed' ) => $b['brew_date'],
					'Status'          => $b['status'],
					'Packaged'        => $b['package_date'],
					'Pre-boil'        => $b['pre_boil'],
					'OG'              => $b['og'],
					'FG'              => $b['fg'],
					'ABV'             => $b['abv'],
					'Attenuation'     => $b['attenuation'] ? $this->num( $b['attenuation'], 0 ) . '%' : '',
					'Into fermenter'  => $b['batch_l'] ? $this->gal( $b['batch_l'] ) : '',
					'Mash pH'         => $b['mash_ph'] ? $this->num( $b['mash_ph'] ) : '',
					'Mash efficiency' => $b['mash_eff'] ? $this->num( $b['mash_eff'], 0 ) . '%' : '',
					'Brewhouse efficiency' => $b['brewhouse'] ? $this->num( $b['brewhouse'], 0 ) . '%' : '',
					'Carbonation'     => $b['carb_type'] . ( $b['carb_psi'] ? ', ' . $this->num( $b['carb_psi'], 1 ) . ' psi' : '' ),
				]
			);
			if ( $b['notes'] ) {
				$inner .= $this->paragraphs( $b['notes'] );
			}
			if ( $b['timeline'] ) {
				$inner .= $this->list( array_map( fn( $t ) => $this->day( (int) $t['ts'], $b['brew_year'] ) . ': ' . $t['text'], $b['timeline'] ) );
			}
			if ( $b['readings'] ) {
				$rows = array_map(
					fn( $r ) => [
						$this->day( (int) ( ( $r['time'] ?? 0 ) / 1000 ), $b['brew_year'] ),
						isset( $r['sg'] ) && $r['sg'] ? number_format( (float) $r['sg'], 3 ) : '',
						isset( $r['temp'] ) && null !== $r['temp'] ? round( (float) $r['temp'] * 9 / 5 + 32 ) . '°F' : '',
						! empty( $r['pressure'] ) ? $this->num( (float) $r['pressure'], 1 ) . ' psi' : '',
						! empty( $r['ph'] ) ? $this->num( (float) $r['ph'] ) : '',
						$this->clean( $r['comment'] ?? '' ),
					],
					$b['readings']
				);
				$head = [ 'Date', 'SG', 'Temp', 'Pressure', 'pH', 'Note' ];
				foreach ( array_reverse( array_keys( $head ) ) as $i ) {
					if ( '' === implode( '', array_column( $rows, $i ) ) ) {
						unset( $head[ $i ] );
						foreach ( $rows as &$row ) {
							unset( $row[ $i ] );
						}
						unset( $row );
					}
				}
				$inner .= $this->table( array_values( $head ), array_map( 'array_values', $rows ) );
			}
			if ( $b['device_readings'] ) {
				$inner .= $this->paragraph( sprintf( '%d more readings were logged by a device.', $b['device_readings'] ) );
			}
			if ( $b['taste'] ) {
				$inner .= $this->paragraphs( $b['taste'] );
			}
		}
		return "<!-- wp:group {\"className\":\"brew-day\",\"layout\":{\"type\":\"constrained\"}} -->\n"
			. "<div class=\"wp-block-group brew-day\">" . $inner . "</div>\n"
			. "<!-- /wp:group -->\n\n";
	}

	private function paragraph( string $text, string $class = '' ): string {
		$attrs = $class ? ' {"className":"' . $class . '"}' : '';
		$cls   = $class ? ' class="' . esc_attr( $class ) . '"' : '';
		return "<!-- wp:paragraph$attrs -->\n<p$cls>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->\n\n";
	}

	/** Blank lines separate paragraphs, single newlines become breaks. */
	private function paragraphs( string $text ): string {
		$out = '';
		foreach ( preg_split( '/\n\s*\n/', $text ) as $para ) {
			$lines = array_filter( array_map( 'trim', explode( "\n", trim( $para ) ) ), fn( $l ) => '' !== $l );
			if ( $lines ) {
				$out .= "<!-- wp:paragraph -->\n<p>" . implode( '<br>', array_map( 'esc_html', $lines ) ) . "</p>\n<!-- /wp:paragraph -->\n\n";
			}
		}
		return $out;
	}

	private function heading( string $text ): string {
		return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $text ) . "</h2>\n<!-- /wp:heading -->\n\n";
	}

	private function list( array $items ): string {
		$li = '';
		foreach ( $items as $item ) {
			$li .= "<!-- wp:list-item -->\n<li>" . esc_html( $item ) . "</li>\n<!-- /wp:list-item -->\n\n";
		}
		return "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $li . "</ul>\n<!-- /wp:list -->\n\n";
	}

	private function stats_list( array $rows ): string {
		$li = '';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			$li .= "<!-- wp:list-item -->\n<li><strong>" . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . "</li>\n<!-- /wp:list-item -->\n\n";
		}
		return $li ? "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $li . "</ul>\n<!-- /wp:list -->\n\n" : '';
	}

	private function stats_group( string $class, array $rows ): string {
		return "<!-- wp:group {\"className\":\"$class\",\"layout\":{\"type\":\"constrained\"}} -->\n"
			. "<div class=\"wp-block-group $class\">" . $this->stats_list( $rows ) . "</div>\n"
			. "<!-- /wp:group -->\n\n";
	}

	private function table( array $head, array $rows ): string {
		$th = implode( '', array_map( fn( $h ) => '<th>' . esc_html( $h ) . '</th>', $head ) );
		$tr = implode( '', array_map( fn( $row ) => '<tr>' . implode( '', array_map( fn( $c ) => '<td>' . esc_html( (string) $c ) . '</td>', $row ) ) . '</tr>', $rows ) );
		return "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table class=\"has-fixed-layout\"><thead><tr>$th</tr></thead><tbody>$tr</tbody></table></figure>\n<!-- /wp:table -->\n\n";
	}
}
