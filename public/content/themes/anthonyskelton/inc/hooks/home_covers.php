<?php

namespace BlockTheme\Hooks;

/**
 * The home page Books tile shows a grid of recent covers (the Query Loop with
 * queryId 30 in patterns/home-sections.php). Not every book has a cover, and a
 * cover-less book would leave an empty cell, so limit that query to books with
 * a featured image.
 *
 * The filter receives the Post Template block, whose context carries the
 * parent Query Loop's id and query.
 *
 * @param array     $query The query vars for the Query Loop block.
 * @param \WP_Block $block The Post Template block instance.
 *
 * @return array
 */
function home_covers_query( array $query, \WP_Block $block ): array {
	$query_id  = (int) ( $block->context['queryId'] ?? 0 );
	$post_type = $block->context['query']['postType'] ?? '';
	if ( HOME_COVERS_QUERY_ID !== $query_id || 'book' !== $post_type ) {
		return $query;
	}

	$query['meta_query'][] = array(
		'key'     => '_thumbnail_id',
		'compare' => 'EXISTS',
	);

	return $query;
}
const HOME_COVERS_QUERY_ID = 30;
add_filter( 'query_loop_block_query_vars', __NAMESPACE__ . '\home_covers_query', 10, 2 );
