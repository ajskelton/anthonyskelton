<?php
/**
 * Book meta rows.
 *
 * templates/single-book.html wraps the "Finished <date>" and "Rating x / 5"
 * rows in groups with the classes book-meta--finished and book-meta--rating.
 * Drop a row when the book has no date read (still reading) or no rating.
 *
 * @package BlockTheme
 */

namespace BlockTheme\Hooks\BookMeta;

function render( string $content, array $block ): string {
	$class = $block['attrs']['className'] ?? '';
	if ( false === strpos( $class, 'book-meta--' ) ) {
		return $content;
	}
	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return $content;
	}
	if ( false !== strpos( $class, 'book-meta--finished' ) && '' === get_post_meta( $post_id, 'book_date_read', true ) ) {
		return '';
	}
	if ( false !== strpos( $class, 'book-meta--rating' ) && '' === get_post_meta( $post_id, 'book_rating', true ) ) {
		return '';
	}
	return $content;
}
add_filter( 'render_block_core/group', __NAMESPACE__ . '\render', 10, 2 );
