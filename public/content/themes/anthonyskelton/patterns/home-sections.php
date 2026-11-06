<?php
/**
 * Title: Home Sections
 * Slug: block-theme/home-sections
 * Description: One block per site section (category) with its description and three latest posts. Category IDs are resolved by slug so the pattern works on any environment.
 * Inserter: no
 *
 * @package BlockTheme
 */

$home_sections = array( 'code', 'beer', 'broadcast', 'running', 'books' );
?>
<!-- wp:group {"align":"wide","className":"home-sections","style":{"spacing":{"blockGap":"var:preset|spacing|90"}},"layout":{"type":"grid","minimumColumnWidth":"18rem"}} -->
<div class="wp-block-group alignwide home-sections">
<?php
foreach ( $home_sections as $home_section_slug ) :
	$home_section = get_term_by( 'slug', $home_section_slug, 'category' );
	if ( ! $home_section instanceof WP_Term ) {
		continue;
	}
	$home_section_link  = esc_url( get_term_link( $home_section ) );
	$home_section_name  = esc_html( $home_section->name );
	$home_section_terms = array_merge( array( (int) $home_section->term_id ), array_map( 'intval', get_term_children( $home_section->term_id, 'category' ) ) );
	$home_section_terms = wp_json_encode( array_values( $home_section_terms ) );
	?>
<!-- wp:group {"className":"home-section","style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group home-section">
<!-- wp:heading {"level":2,"fontSize":"h4","style":{"spacing":{"padding":{"top":"0"}}}} -->
<h2 class="wp-block-heading has-h-4-font-size" style="padding-top:0"><a href="<?php echo $home_section_link; ?>"><?php echo $home_section_name; ?></a></h2>
<!-- /wp:heading -->

<?php if ( $home_section->description ) : ?>
<!-- wp:paragraph {"fontSize":"sm"} -->
<p class="has-sm-font-size"><?php echo esc_html( $home_section->description ); ?></p>
<!-- /wp:paragraph -->
<?php endif; ?>

<?php
if ( 'books' === $home_section_slug && post_type_exists( 'book' ) ) :
	$home_shelves = array_filter( array_map( fn( $slug ) => ( $t = get_term_by( 'slug', $slug, 'shelf' ) ) ? (int) $t->term_id : 0, [ 'read', 'currently-reading' ] ) );
	$home_shelves = wp_json_encode( array_values( $home_shelves ) );
	?>
<!-- wp:query {"queryId":30,"query":{"perPage":6,"pages":0,"offset":0,"postType":"book","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false,"taxQuery":{"shelf":<?php echo $home_shelves; ?>}},"className":"home-section__covers"} -->
<div class="wp-block-query home-section__covers">
<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"grid","minimumColumnWidth":"5.5rem"}} -->
<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"2/3","scale":"cover"} /-->
<!-- /wp:post-template -->
</div>
<!-- /wp:query -->

<!-- wp:paragraph {"fontSize":"sm"} -->
<p class="has-sm-font-size"><a href="<?php echo esc_url( get_post_type_archive_link( 'book' ) ); ?>"><?php esc_html_e( 'Browse the Library →', 'block-theme' ); ?></a></p>
<!-- /wp:paragraph -->
<?php else : ?>
<!-- wp:query {"queryId":<?php echo 20 + (int) $home_section->term_id; ?>,"query":{"perPage":3,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"exclude","inherit":false,"taxQuery":{"category":<?php echo $home_section_terms; ?>}},"className":"home-section__posts"} -->
<div class="wp-block-query home-section__posts">
<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"default"}} -->
<!-- wp:post-title {"isLink":true,"level":3,"fontSize":"paragraph"} /-->
<!-- wp:post-date {"className":"no-date-label","fontSize":"sm"} /-->
<!-- /wp:post-template -->

<!-- wp:query-no-results -->
<!-- wp:paragraph {"fontSize":"sm"} -->
<p class="has-sm-font-size"><?php esc_html_e( 'Nothing here yet.', 'block-theme' ); ?></p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
<?php endif; ?>

<!-- wp:paragraph {"fontSize":"sm"} -->
<p class="has-sm-font-size"><a href="<?php echo $home_section_link; ?>"><?php printf( /* translators: %s: section name */ esc_html__( 'All %s posts →', 'block-theme' ), $home_section_name ); ?></a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
<?php endforeach; ?>
</div>
<!-- /wp:group -->
