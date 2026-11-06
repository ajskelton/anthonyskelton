<?php
/**
 * Title: Library Grid
 * Slug: block-theme/library-grid
 * Description: Grid of book covers for the Library archive and shelf archives. Inherits the page query.
 * Inserter: no
 *
 * @package BlockTheme
 */

?>
<!-- wp:query {"queryId":40,"query":{"perPage":48,"pages":0,"offset":0,"postType":"book","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":true},"align":"wide","className":"library-grid","layout":{"type":"default"}} -->
<div class="wp-block-query alignwide library-grid">
	<!-- wp:post-template {"style":{"spacing":{"blockGap":"var:preset|spacing|60"}},"layout":{"type":"grid","minimumColumnWidth":"9rem"}} -->
	<!-- wp:group {"className":"book-card","style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical"}} -->
	<div class="wp-block-group book-card">
		<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"2/3","scale":"contain","className":"book-card__cover"} /-->

		<!-- wp:post-title {"isLink":true,"level":3,"fontSize":"sm","className":"book-card__title"} /-->

		<!-- wp:paragraph {"className":"book-card__author","fontSize":"sm","metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"book_author"}}}}} -->
		<p class="book-card__author has-sm-font-size"></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
	<!-- /wp:post-template -->

	<!-- wp:query-no-results -->
	<!-- wp:paragraph -->
	<p><?php esc_html_e( 'Nothing on this shelf yet.', 'block-theme' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- /wp:query-no-results -->

	<!-- wp:query-pagination {"paginationArrow":"arrow","align":"wide","layout":{"type":"flex","justifyContent":"space-between"}} -->
	<!-- wp:query-pagination-previous {"label":"Newer"} /-->
	<!-- wp:query-pagination-next {"label":"Older"} /-->
	<!-- /wp:query-pagination -->
</div>
<!-- /wp:query -->
