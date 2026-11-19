<?php

namespace DeliciousBrains\SpinupWPComposerSite;

/**
 * The Library: a `book` post type with a `shelf` taxonomy and a few meta
 * fields, fed by the StoryGraph importer (see BookImporter).
 */
class Books {

	const POST_TYPE = 'book';
	const TAXONOMY  = 'shelf';

	/**
	 * Shelves shown on the main Library page and the home page.
	 */
	const LIBRARY_SHELVES = [ 'read' ];

	/**
	 * Meta keys exposed to the editor and REST API, with their types.
	 */
	const META = [
		'book_author'    => 'string',
		'book_isbn'      => 'string',
		'book_url'       => 'string',
		'book_rating'    => 'number',
		'book_date_read' => 'string',
	];

	public function register() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_taxonomy' ] );
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'pre_get_posts', [ $this, 'library_query' ] );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'book', BookImporter::class );
		}
	}

	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'              => [
					'name'          => 'Library',
					'singular_name' => 'Book',
					'menu_name'     => 'Library',
					'add_new_item'  => 'Add New Book',
					'edit_item'     => 'Edit Book',
					'all_items'     => 'Library',
				],
				'public'              => true,
				'exclude_from_search' => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-book',
				'menu_position'       => 6,
				'has_archive'         => 'library',
				'rewrite'             => [ 'slug' => 'library', 'with_front' => false ],
				'supports'            => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'excerpt' ],
				'template'            => [
					[ 'core/paragraph', [ 'placeholder' => 'Notes on the book (optional).' ] ],
				],
			]
		);
	}

	public function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			[
				'labels'       => [
					'name'          => 'Shelves',
					'singular_name' => 'Shelf',
				],
				'hierarchical' => true,
				'public'       => true,
				'show_in_rest' => true,
				'rewrite'      => [ 'slug' => 'shelf', 'with_front' => false ],
			]
		);
	}

	public function register_meta() {
		foreach ( self::META as $key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				[
					'type'         => $type,
					'single'       => true,
					'show_in_rest' => true,
					'default'      => 'number' === $type ? 0 : '',
				]
			);
		}
	}

	/**
	 * The Library archive shows many covers per page, newest read first.
	 */
	public function library_query( \WP_Query $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->is_post_type_archive( self::POST_TYPE ) || $query->is_tax( self::TAXONOMY ) ) {
			$query->set( 'posts_per_page', 48 );
		}
		// Books are excluded from search, which also drops them from the implicit
		// post type a shelf page would resolve to, so name the type explicitly.
		if ( $query->is_tax( self::TAXONOMY ) ) {
			$query->set( 'post_type', self::POST_TYPE );
		}
		// The main Library page is books read or in progress; the other shelves have their own pages.
		if ( $query->is_post_type_archive( self::POST_TYPE ) && ! $query->is_tax( self::TAXONOMY ) ) {
			$query->set(
				'tax_query',
				[
					[
						'taxonomy' => self::TAXONOMY,
						'field'    => 'slug',
						'terms'    => self::LIBRARY_SHELVES,
					],
				]
			);
		}
	}
}
