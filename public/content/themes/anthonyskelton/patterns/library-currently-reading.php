<?php
/**
 * Title: Library: currently reading
 * Slug: block-theme/library-currently-reading
 * Inserter: no
 *
 * Covers of the books on the currently-reading shelf, shown above the main
 * Library grid. Renders nothing when that shelf is empty.
 */

$library_current = post_type_exists( 'book' ) ? get_term_by( 'slug', 'currently-reading', 'shelf' ) : false;
if ( ! $library_current || ! $library_current->count ) {
	return;
}
?>
<!-- wp:group {"className":"library-current","layout":{"type":"constrained"}} -->
<div class="wp-block-group library-current">
<!-- wp:heading {"level":2,"fontSize":"h5"} -->
<h2 class="wp-block-heading has-h-5-font-size"><?php esc_html_e( 'Currently reading', 'block-theme' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:query {"queryId":41,"query":{"perPage":6,"pages":0,"offset":0,"postType":"book","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false,"taxQuery":{"shelf":[<?php echo (int) $library_current->term_id; ?>]}}} -->
<div class="wp-block-query">
<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"grid","minimumColumnWidth":"9rem"}} -->
<!-- wp:group {"className":"book-card","style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
<div class="wp-block-group book-card">
<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"2/3","scale":"contain","className":"book-card__cover"} /-->

<!-- wp:post-title {"isLink":true,"level":3,"fontSize":"sm"} /-->

<!-- wp:paragraph {"fontSize":"xs","metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"book_author"}}}}} -->
<p class="has-xs-font-size"></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
<!-- /wp:post-template -->
</div>
<!-- /wp:query -->
</div>
<!-- /wp:group -->
