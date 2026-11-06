<?php

namespace DeliciousBrains\SpinupWPComposerSite;

use SimpleXMLElement;
use WP_CLI;

/**
 * Create a draft recipe post from a BeerXML export (Brewfather, BeerSmith).
 *
 * The recipe's vitals, grain bill, hops, other additions, yeast, mash and
 * fermentation schedule are written as blocks, in US units with metric
 * alongside, followed by a placeholder paragraph for brew day notes. The
 * export file name is kept in post meta so re-running skips recipes that
 * already have a post.
 *
 * ## EXAMPLES
 *
 *     wp beer import ~/Brewfather_BeerXML_Oktoberfest.xml --dry-run
 *     wp beer import ~/Brewfather_BeerXML_Oktoberfest.xml
 */
class BeerImporter {

	const META_KEY    = 'beerxml_source';
	const CATEGORY    = 'brewing';
	const TAG         = [ 'Recipe', 'recipe' ];
	const PLACEHOLDER = 'Brew day notes to come.';

	const KG_LB  = 2.20462262;
	const KG_OZ  = 35.2739619;
	const L_GAL  = 0.264172052;

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
		$author   = (int) ( $assoc['author'] ?? 1 );
		$cat_slug = $assoc['category'] ?? self::CATEGORY;

		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Cannot read $file" );
		}
		$xml = simplexml_load_string( file_get_contents( $file ), SimpleXMLElement::class, LIBXML_NOCDATA );
		if ( ! $xml || ! isset( $xml->RECIPE ) ) {
			WP_CLI::error( 'Not a BeerXML file (no RECIPE elements).' );
		}

		$category = get_category_by_slug( $cat_slug );
		if ( ! $category ) {
			WP_CLI::error( sprintf( 'Category "%s" not found.', $cat_slug ) );
		}
		$tag_id = $this->ensure_tag( $dry_run );
		$source = basename( $file );

		$created = 0;
		$skipped = 0;
		foreach ( $xml->RECIPE as $i => $recipe_xml ) {
			$recipe = $this->normalize( $recipe_xml );
			$key    = $source . ( count( $xml->RECIPE ) > 1 ? '#' . $recipe['name'] : '' );

			$existing = $this->find_existing( $key );
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

			$post_id = wp_insert_post(
				[
					'post_type'     => 'post',
					'post_status'   => 'draft',
					'post_title'    => $recipe['name'],
					'post_content'  => $this->content( $recipe ),
					'post_author'   => $author,
					'post_category' => [ $category->term_id ],
					'tags_input'    => [ $tag_id ],
					'meta_input'    => [ self::META_KEY => $key ],
				],
				true
			);
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

	private function find_existing( string $key ): ?int {
		$ids = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_KEY,
				'meta_value'     => $key,
			]
		);
		return $ids ? (int) $ids[0] : null;
	}

	/**
	 * Pull the fields the post needs out of a RECIPE element. BeerXML
	 * amounts are kg and liters; temperatures are Celsius.
	 */
	private function normalize( SimpleXMLElement $r ): array {
		$s = fn( $v ) => trim( preg_replace( '/\s+/', ' ', (string) $v ) );
		$f = fn( $v ) => (float) $v;

		$style = $r->STYLE;
		$style_name = $s( $style->NAME );
		if ( $s( $style->CATEGORY_NUMBER ) ) {
			$style_name .= sprintf( ' (%s %s%s)', $s( $style->STYLE_GUIDE ) ?: 'BJCP', ltrim( $s( $style->CATEGORY_NUMBER ), '0' ), $s( $style->STYLE_LETTER ) );
		}

		$fermentables = [];
		$total_kg     = 0;
		foreach ( $r->FERMENTABLES->FERMENTABLE ?? [] as $x ) {
			$kg             = $f( $x->AMOUNT );
			$total_kg      += $kg;
			$fermentables[] = [
				'name'     => $s( $x->NAME ),
				'supplier' => $s( $x->SUPPLIER ),
				'kg'       => $kg,
				'color'    => $f( $x->COLOR ),
			];
		}
		foreach ( $fermentables as &$fm ) {
			$fm['pct'] = $total_kg > 0 ? $fm['kg'] / $total_kg * 100 : 0;
		}
		unset( $fm );

		$hops = [];
		foreach ( $r->HOPS->HOP ?? [] as $x ) {
			$hops[] = [
				'name'  => $s( $x->NAME ),
				'kg'    => $f( $x->AMOUNT ),
				'alpha' => $f( $x->ALPHA ),
				'use'   => $s( $x->USE ),
				'time'  => (int) $f( $x->TIME ),
				'form'  => $s( $x->FORM ),
			];
		}

		$miscs = [];
		foreach ( $r->MISCS->MISC ?? [] as $x ) {
			$miscs[] = [
				'name'   => $s( $x->NAME ),
				'amount' => $s( $x->DISPLAY_AMOUNT ) ?: $s( $x->AMOUNT ),
				'use'    => $s( $x->USE ),
				'time'   => (int) $f( $x->TIME ),
			];
		}

		$yeasts = [];
		foreach ( $r->YEASTS->YEAST ?? [] as $x ) {
			$yeasts[] = [
				'name'        => trim( $s( $x->LABORATORY ) . ' ' . $s( $x->NAME ) . ( $s( $x->PRODUCT_ID ) ? ' ' . $s( $x->PRODUCT_ID ) : '' ) ),
				'amount'      => $s( $x->DISPLAY_AMOUNT ) ?: $s( $x->AMOUNT ),
				'form'        => $s( $x->FORM ),
				'attenuation' => $f( $x->ATTENUATION ),
				'min_c'       => $f( $x->MIN_TEMPERATURE ),
				'max_c'       => $f( $x->MAX_TEMPERATURE ),
			];
		}

		$mash_steps = [];
		foreach ( $r->MASH->MASH_STEPS->MASH_STEP ?? [] as $x ) {
			$mash_steps[] = [
				'name'   => $s( $x->NAME ),
				'type'   => $s( $x->TYPE ),
				'temp_c' => $f( $x->STEP_TEMP ),
				'time'   => (int) $f( $x->STEP_TIME ),
				'infuse' => $f( $x->INFUSE_AMOUNT ),
			];
		}

		return [
			'name'         => $s( $r->NAME ),
			'brewer'       => $s( $r->BREWER ),
			'type'         => $s( $r->TYPE ),
			'style'        => $style_name,
			'batch_l'      => $f( $r->BATCH_SIZE ),
			'boil_l'       => $f( $r->BOIL_SIZE ),
			'boil_time'    => (int) $f( $r->BOIL_TIME ),
			'efficiency'   => $f( $r->EFFICIENCY ),
			'og'           => $this->gravity( $s( $r->OG ) ?: $s( $r->EST_OG ) ),
			'fg'           => $this->gravity( $s( $r->FG ) ?: $s( $r->EST_FG ) ),
			'abv'          => $this->unit( $s( $r->ABV ) ?: $s( $r->EST_ABV ), '%' ),
			'ibu'          => $s( $r->IBU ),
			'srm'          => $this->unit( $s( $r->EST_COLOR ), 'SRM' ),
			'carbonation'  => $s( $r->CARBONATION ),
			'fermentables' => $fermentables,
			'hops'         => $hops,
			'miscs'        => $miscs,
			'yeasts'       => $yeasts,
			'mash_name'    => $s( $r->MASH->NAME ),
			'mash_steps'   => $mash_steps,
			'ferm_profile' => $s( $r->BF_FERMENTATION_PROFILE_NAME ),
			'primary_days' => (int) $f( $r->PRIMARY_AGE ),
			'primary_c'    => $f( $r->PRIMARY_TEMP ),
			'equipment'    => $s( $r->EQUIPMENT->NAME ),
			'notes'        => $s( $r->NOTES ),
		];
	}

	private function gravity( string $v ): string {
		$v = (float) $v;
		return $v > 0 ? number_format( $v, 3 ) : '';
	}

	/** "6.3 %" / "8.2 SRM" -> "6.3%" / "8.2 SRM". */
	private function unit( string $v, string $unit ): string {
		$n = (float) $v;
		if ( $n <= 0 ) {
			return '';
		}
		return '%' === $unit ? rtrim( rtrim( number_format( $n, 1 ), '0' ), '.' ) . '%' : number_format( $n, 1 ) . " $unit";
	}

	private function lb( float $kg ): string {
		return sprintf( '%s lb (%s kg)', $this->num( $kg * self::KG_LB ), $this->num( $kg ) );
	}

	private function oz( float $kg ): string {
		return sprintf( '%s oz (%s g)', $this->num( $kg * self::KG_OZ ), $this->num( $kg * 1000, 0 ) );
	}

	private function gal( float $l ): string {
		return sprintf( '%s gal (%s L)', $this->num( $l * self::L_GAL, 1 ), $this->num( $l, 1 ) );
	}

	private function f( float $c ): string {
		return sprintf( '%s°F (%s°C)', round( $c * 9 / 5 + 32 ), $this->num( $c, 1 ) );
	}

	private function num( float $n, int $decimals = 2 ): string {
		return rtrim( rtrim( number_format( $n, $decimals ), '0' ), '.' );
	}

	private function hop_use( array $hop ): string {
		$use = strtolower( $hop['use'] );
		if ( 'boil' === $use ) {
			return $hop['time'] . ' min';
		}
		if ( 'dry hop' === $use ) {
			return 'Dry hop, ' . round( $hop['time'] / 1440 ) . ' days';
		}
		return ucfirst( $use ) . ( $hop['time'] ? ', ' . $hop['time'] . ' min' : '' );
	}

	private function content( array $r ): string {
		$out = '';

		$intro = sprintf( '%s. %s batch, %s on the %s.', $r['style'], $this->gal( $r['batch_l'] ), strtolower( $r['type'] ), $r['equipment'] ?: 'brew rig' );
		if ( $r['brewer'] ) {
			$intro = sprintf( 'Recipe from %s. ', $r['brewer'] ) . $intro;
		}
		$out .= $this->paragraph( $intro );

		$stats = [
			'Style'          => $r['style'],
			'Batch size'     => $this->gal( $r['batch_l'] ),
			'Boil'           => sprintf( '%d min, %s pre-boil', $r['boil_time'], $this->gal( $r['boil_l'] ) ),
			'OG'             => $r['og'],
			'FG'             => $r['fg'],
			'ABV'            => $r['abv'],
			'IBU'            => $r['ibu'],
			'Color'          => $r['srm'],
			'Efficiency'     => $r['efficiency'] ? $this->num( $r['efficiency'], 0 ) . '%' : '',
			'Carbonation'    => $r['carbonation'] ? $r['carbonation'] . ' vol' : '',
		];
		$out .= $this->stats_group( $stats );

		if ( $r['fermentables'] ) {
			$rows = array_map( fn( $x ) => [ $x['name'] . ( $x['supplier'] ? ' (' . $x['supplier'] . ')' : '' ), $this->lb( $x['kg'] ), $this->num( $x['pct'], 1 ) . '%', $this->num( $x['color'], 1 ) . ' °L' ], $r['fermentables'] );
			$out .= $this->heading( 'Fermentables' ) . $this->table( [ 'Malt', 'Amount', 'Grist', 'Color' ], $rows );
		}
		if ( $r['hops'] ) {
			$rows = array_map( fn( $x ) => [ $x['name'] . ( $x['form'] ? ' (' . strtolower( $x['form'] ) . ')' : '' ), $this->oz( $x['kg'] ), $this->num( $x['alpha'], 1 ) . '%', $this->hop_use( $x ) ], $r['hops'] );
			$out .= $this->heading( 'Hops' ) . $this->table( [ 'Hop', 'Amount', 'Alpha', 'Addition' ], $rows );
		}
		if ( $r['miscs'] ) {
			$rows = array_map( fn( $x ) => [ $x['name'], $x['amount'], ucfirst( strtolower( $x['use'] ) ) . ( $x['time'] ? ', ' . $x['time'] . ' min' : '' ) ], $r['miscs'] );
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
				fn( $m ) => sprintf( '%s: %s for %d min%s', $m['name'], $this->f( $m['temp_c'] ), $m['time'], $m['infuse'] ? ', ' . $this->gal( $m['infuse'] ) . ' strike water' : '' ),
				$r['mash_steps']
			);
			$out .= $this->heading( 'Mash' . ( $r['mash_name'] ? ' (' . $r['mash_name'] . ')' : '' ) ) . $this->list( $items );
		}
		if ( $r['primary_days'] ) {
			$out .= $this->heading( 'Fermentation' ) . $this->paragraph( sprintf( '%s%d days primary at %s.', $r['ferm_profile'] ? $r['ferm_profile'] . ' profile: ' : '', $r['primary_days'], $this->f( $r['primary_c'] ) ) );
		}
		if ( $r['notes'] ) {
			$out .= $this->heading( 'Recipe notes' ) . $this->paragraph( $r['notes'] );
		}

		$out .= $this->heading( 'Brew day' ) . $this->paragraph( self::PLACEHOLDER );
		return rtrim( $out );
	}

	private function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->\n\n";
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

	private function stats_group( array $rows ): string {
		$li = '';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$li .= "<!-- wp:list-item -->\n<li><strong>" . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . "</li>\n<!-- /wp:list-item -->\n\n";
		}
		return "<!-- wp:group {\"className\":\"recipe-stats\",\"layout\":{\"type\":\"constrained\"}} -->\n"
			. "<div class=\"wp-block-group recipe-stats\"><!-- wp:list -->\n<ul class=\"wp-block-list\">" . $li . "</ul>\n<!-- /wp:list --></div>\n"
			. "<!-- /wp:group -->\n\n";
	}

	private function table( array $head, array $rows ): string {
		$th = implode( '', array_map( fn( $h ) => '<th>' . esc_html( $h ) . '</th>', $head ) );
		$tr = implode( '', array_map( fn( $row ) => '<tr>' . implode( '', array_map( fn( $c ) => '<td>' . esc_html( $c ) . '</td>', $row ) ) . '</tr>', $rows ) );
		return "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table class=\"has-fixed-layout\"><thead><tr>$th</tr></thead><tbody>$tr</tbody></table></figure>\n<!-- /wp:table -->\n\n";
	}
}
