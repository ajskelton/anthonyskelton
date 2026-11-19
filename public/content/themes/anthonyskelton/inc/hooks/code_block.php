<?php
/**
 * Code block highlighting.
 *
 * Core Code blocks carry a `language` and `showLineNumbers` attribute (added
 * in src/js/editor.js). On the front end this adds the Prism class names so
 * src/js/code.js can highlight them in the browser; token colours live in
 * src/scss/blocks/core/code.scss and follow the visitor's light/dark mode.
 *
 * @package BlockTheme
 */

namespace BlockTheme\Hooks\CodeBlock;

/**
 * Languages offered in the editor, keyed by Prism grammar name.
 *
 * @return array<string, string> Grammar => label.
 */
function languages(): array {
	return [
		'markup'     => 'HTML',
		'css'        => 'CSS',
		'scss'       => 'SCSS',
		'javascript' => 'JavaScript',
		'jsx'        => 'JSX',
		'typescript' => 'TypeScript',
		'tsx'        => 'TSX',
		'json'       => 'JSON',
		'php'        => 'PHP',
		'bash'       => 'Shell',
		'yaml'       => 'YAML',
		'sql'        => 'SQL',
		'diff'       => 'Diff',
		'markdown'   => 'Markdown',
	];
}

/**
 * Add Prism classes and the language label to a rendered Code block.
 *
 * @param string $content Block HTML.
 * @param array  $block   Parsed block.
 * @return string
 */
function render( string $content, array $block ): string {
	$language = $block['attrs']['language'] ?? '';
	$labels   = languages();
	$label    = $labels[ $language ] ?? '';

	// Plain JavaScript on this site is usually block-editor code, so use the
	// JSX grammar, which is a superset.
	$grammar = 'javascript' === $language ? 'jsx' : $language;

	if ( ! $grammar && empty( $block['attrs']['showLineNumbers'] ) ) {
		return $content;
	}

	$tags = new \WP_HTML_Tag_Processor( $content );
	if ( ! $tags->next_tag( 'pre' ) ) {
		return $content;
	}
	if ( $grammar ) {
		$tags->add_class( 'language-' . $grammar );
	}
	if ( $label ) {
		$tags->set_attribute( 'data-language', $label );
	}
	if ( ! empty( $block['attrs']['showLineNumbers'] ) ) {
		$tags->add_class( 'line-numbers' );
	}

	wp_enqueue_script( 'block-theme-code' );

	return $tags->get_updated_html();
}
add_filter( 'render_block_core/code', __NAMESPACE__ . '\render', 10, 2 );

/**
 * Register the Prism bundle. It is only enqueued when a Code block renders.
 */
function register_script(): void {
	$asset_path = get_template_directory() . '/js/code.asset.php';
	if ( ! file_exists( $asset_path ) ) {
		return;
	}
	$asset = include $asset_path;
	wp_register_script( 'block-theme-code', get_template_directory_uri() . '/js/code.js', $asset['dependencies'], $asset['version'], true );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\register_script' );

/**
 * Hand the language list to the editor script.
 */
function editor_languages(): void {
	wp_add_inline_script(
		'block-theme-editor-modifications',
		'const blockThemeCodeLanguages = ' . wp_json_encode( languages() ) . ';',
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\editor_languages', 20 );
