/**
 * WordPress dependencies
 */
import {
	unregisterBlockType,
	unregisterBlockStyle,
	unregisterBlockVariation,
	registerBlockStyle,
	registerBlockVariation,
} from '@wordpress/blocks';
import { dispatch } from '@wordpress/data';
import domReady from '@wordpress/dom-ready';

/**
 * Register block styles
 *
 * @type {Object} Add the names of blocks and styles to register here
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-styles/
 */
const registerBlockStyles = {
	// "core/quote": [
	// 	{
	// 		name: "console",
	// 		label: "Console"
	// 	},
	// 	{
	// 		name: "test",
	// 		label: "Test"
	// 	}
	// ]
};

/**
 * Register block variations
 *
 * @type {Object} Add the names of blocks and variations to register here
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-variations/
 */
const registerBlockVariations = {
	// "core/columns": {
	// 	name: "three-columns-wide-left",
	// 	title: "50 / 25 / 25",
	// 	description: "Three columns; wide left column",
	// 	innerBlocks: [
	// 		["core/column", { width: "50%" }],
	// 		["core/column", { width: "25%" }],
	// 		["core/column", { width: "25%" }],
	// 	],
	// 	scope: ["block"],
	// },
};

/**
 * Unregister blocks
 *
 * @type {Array} Add the names of blocks to unregister here
 * @see https://developer.wordpress.org/block-editor/reference-guides/filters/block-filters/#using-a-deny-list
 */
const unregisterBlocks = [
	// "core/verse"
];

/**
 * Remove editor panels
 *
 * @type {Array} Add the names of panels to remove here
 * @see https://developer.wordpress.org/block-editor/reference-guides/data/data-core-edit-post/#removeeditorpanel
 */
const removeEditorPanels = [
	//"discussion-panel"
];

/**
 * Remove block styles
 *
 * @type {Object} Add the names of blocks and styles to remove here
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-styles/
 */
const unregisterBlockStyles = {
	// "core/button": "outline",
};

/**
 * Remove block variations
 *
 * @type {Object} Add the names of blocks and variations to remove here
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-variations/
 */
const unregisterBlockVariations = {
	// "core/columns": "two-columns-two-thirds-one-third",
};

/**
 * Here we hook into the editor initialization and unregister the blocks,
 * remove editor panels, remove block styles, remove block variations,
 * register block styles, and register block variations – all as defined above.
 */
domReady( function () {
	Object.keys( registerBlockStyles ).forEach( ( block ) => {
		wp.blocks.registerBlockStyle( block, registerBlockStyles[ block ] );
	} );
	Object.keys( registerBlockVariations ).forEach( ( block ) => {
		wp.blocks.registerBlockVariation( block, registerBlockVariations[ block ] );
	} );
	unregisterBlocks.forEach( ( block ) => {
		wp.blocks.unregisterBlockType( block );
	} );

	// Only run if we are in the post editor
	if ( null !== dispatch( 'core/edit-post' ) ) {
		const { removeEditorPanel } = dispatch( 'core/edit-post' );
		removeEditorPanels.forEach( ( panel ) => {
			removeEditorPanel( panel );
		} );
	}
	Object.keys( unregisterBlockStyles ).forEach( ( block ) => {
		wp.blocks.unregisterBlockStyle( block, unregisterBlockStyles[ block ] );
	} );
	Object.keys( unregisterBlockVariations ).forEach( ( block ) => {
		wp.blocks.unregisterBlockVariation( block, unregisterBlockVariations[ block ] );
	} );
} );

/**
 * Code block: language and line-number attributes.
 *
 * The front end (inc/hooks/code_block.php + src/js/code.js) highlights with
 * Prism based on these. The language list comes from PHP as
 * `blockThemeCodeLanguages`.
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

addFilter(
	'blocks.registerBlockType',
	'block-theme/code-attributes',
	( settings, name ) => {
		if ( 'core/code' !== name ) {
			return settings;
		}
		return {
			...settings,
			attributes: {
				...settings.attributes,
				language: { type: 'string', default: '' },
				showLineNumbers: { type: 'boolean', default: false },
			},
		};
	}
);

const withCodeControls = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		if ( 'core/code' !== props.name ) {
			return <BlockEdit { ...props } />;
		}
		const { attributes, setAttributes } = props;
		const languages =
			typeof blockThemeCodeLanguages !== 'undefined'
				? blockThemeCodeLanguages
				: {};
		const options = [
			{ value: '', label: __( 'None (plain text)' ) },
			...Object.keys( languages ).map( ( value ) => ( {
				value,
				label: languages[ value ],
			} ) ),
		];
		return (
			<>
				<BlockEdit { ...props } />
				<InspectorControls>
					<PanelBody title={ __( 'Code' ) } initialOpen>
						<SelectControl
							label={ __( 'Language' ) }
							value={ attributes.language || '' }
							options={ options }
							onChange={ ( language ) =>
								setAttributes( { language } )
							}
						/>
						<ToggleControl
							label={ __( 'Show line numbers' ) }
							checked={ !! attributes.showLineNumbers }
							onChange={ ( showLineNumbers ) =>
								setAttributes( { showLineNumbers } )
							}
						/>
					</PanelBody>
				</InspectorControls>
			</>
		);
	};
}, 'withCodeControls' );

addFilter( 'editor.BlockEdit', 'block-theme/code-controls', withCodeControls );
