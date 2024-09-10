<?php

namespace BlockTheme\Hooks;

/**
 * Modify the output of the core/post-date block.
 *
 * @param string $block_content The block content about to be rendered.
 * @param array  $block         The full block, including name and attributes.
 * @param \WP_Block $instance   The block instance.
 *
 * @return string
 */
function modified_date(string $block_content, array $block, \WP_Block $instance ) {

	$display_type = $block['attrs']['displayType'] ?? '';

	if ( 'modified' !== $display_type ) {
		$string = 'Published: ';
		return str_replace( '<time ', "<span>$string</span><time ", $block_content );
	}

	$string = 'Last Updated: ';
	return str_replace( '<time ', "<span>$string</span><time ", $block_content );
}
add_filter( 'render_block_core/post-date', __NAMESPACE__ . '\modified_date', 10, 3 );
