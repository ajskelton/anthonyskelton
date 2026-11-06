/**
 * Syntax highlighting for core Code blocks with Prism.
 *
 * inc/hooks/code_block.php adds `language-*` (and `line-numbers`) classes to
 * the <pre> and enqueues this file only when a Code block is on the page.
 */
window.Prism = window.Prism || {};
window.Prism.manual = true;

import Prism from 'prismjs/components/prism-core';
import 'prismjs/components/prism-markup';
import 'prismjs/components/prism-css';
import 'prismjs/components/prism-scss';
import 'prismjs/components/prism-clike';
import 'prismjs/components/prism-javascript';
import 'prismjs/components/prism-jsx';
import 'prismjs/components/prism-typescript';
import 'prismjs/components/prism-tsx';
import 'prismjs/components/prism-json';
import 'prismjs/components/prism-markup-templating';
import 'prismjs/components/prism-php';
import 'prismjs/components/prism-bash';
import 'prismjs/components/prism-yaml';
import 'prismjs/components/prism-sql';
import 'prismjs/components/prism-diff';
import 'prismjs/components/prism-markdown';
import 'prismjs/plugins/line-numbers/prism-line-numbers';

function highlight() {
	Prism.highlightAll();
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', highlight );
} else {
	highlight();
}
