<?php
/**
 * Code block highlighting.
 *
 * The Syntax-highlighting Code Block plugin highlights core Code blocks on the
 * server. Its stylesheets (one fixed highlight.js theme plus structural CSS)
 * are turned off here so the theme can supply a palette that follows the
 * visitor's light/dark mode. See src/scss/blocks/core/code.scss.
 *
 * @package BlockTheme
 */

namespace BlockTheme\Hooks\CodeBlock;

add_filter( 'syntax_highlighting_code_block_styling', '__return_false' );
