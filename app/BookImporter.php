<?php

namespace DeliciousBrains\SpinupWPComposerSite;

use WP_CLI;

/**
 * Import books from a StoryGraph library export.
 *
 * ## EXAMPLES
 *
 *     wp book import ~/storygraph.csv --dry-run
 *     wp book import ~/storygraph.csv
 *     wp book covers
 */
class BookImporter {

	const COVER_BY_ISBN = 'https://covers.openlibrary.org/b/isbn/%s-L.jpg?default=false';
	const COVER_BY_ID   = 'https://covers.openlibrary.org/b/id/%s-L.jpg';
	const SEARCH        = 'https://openlibrary.org/search.json?title=%s&author=%s&limit=1&fields=key,cover_i,isbn';

	/**
	 * Map StoryGraph "Read Status" values to shelf terms.
	 */
	const SHELVES = [
		'read'              => [ 'Read', 'read' ],
		'currently-reading' => [ 'Currently reading', 'currently-reading' ],
		'to-read'           => [ 'Want to read', 'to-read' ],
		'did-not-finish'    => [ 'Did not finish', 'did-not-finish' ],
	];

	/**
	 * Import a StoryGraph CSV export. Existing books are matched by ISBN,
	 * then by title, and updated in place.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the StoryGraph export CSV.
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * [--no-covers]
	 * : Skip fetching covers from Open Library.
	 *
	 * [--status=<status>]
	 * : Only import rows with this Read Status (read, currently-reading, to-read, did-not-finish).
	 *
	 * @when after_wp_load
	 */
	public function import( $args, $assoc ) {
		[ $file ]  = $args;
		$dry_run   = ! empty( $assoc['dry-run'] );
		$covers    = ! isset( $assoc['covers'] ) || false !== $assoc['covers'];
		$only      = $assoc['status'] ?? null;

		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Cannot read $file" );
		}

		$this->ensure_shelves( $dry_run );

		$rows    = $this->read_csv( $file );
		$created = 0;
		$updated = 0;
		$skipped = 0;

		foreach ( $rows as $row ) {
			$book = $this->normalize( $row );
			if ( ! $book['title'] ) {
				$skipped++;
				continue;
			}
			if ( $only && $book['status'] !== $only ) {
				$skipped++;
				continue;
			}

			$existing = $this->find_existing( $book );
			$verb     = $existing ? 'update' : 'create';
			WP_CLI::log( sprintf( '%-6s %s — %s [%s]', $verb, $book['title'], $book['author'], $book['status'] ) );

			if ( $dry_run ) {
				$existing ? $updated++ : $created++;
				continue;
			}

			$post_id = $this->save( $book, $existing );
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( $post_id->get_error_message() );
				$skipped++;
				continue;
			}
			$existing ? $updated++ : $created++;

			if ( $covers && ! has_post_thumbnail( $post_id ) ) {
				$this->fetch_cover( $post_id, $book );
			}
		}

		WP_CLI::success( sprintf( '%d created, %d updated, %d skipped%s.', $created, $updated, $skipped, $dry_run ? ' (dry run)' : '' ) );
	}

	/**
	 * Fetch missing covers from Open Library for books that have none.
	 *
	 * @when after_wp_load
	 */
	public function covers( $args, $assoc ) {
		$ids = get_posts(
			[
				'post_type'      => Books::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => [ [ 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ] ],
			]
		);
		WP_CLI::log( count( $ids ) . ' books without a cover.' );
		$found = 0;
		foreach ( $ids as $id ) {
			$book = [
				'title'  => get_the_title( $id ),
				'author' => get_post_meta( $id, 'book_author', true ),
				'isbn'   => get_post_meta( $id, 'book_isbn', true ),
			];
			if ( $this->fetch_cover( $id, $book ) ) {
				$found++;
			}
		}
		WP_CLI::success( "$found covers added." );
	}

	private function read_csv( string $file ): array {
		$handle = fopen( $file, 'r' );
		$header = fgetcsv( $handle );
		if ( ! $header ) {
			WP_CLI::error( 'Empty CSV.' );
		}
		// Strip a UTF-8 BOM and normalize header names.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		$header    = array_map( fn( $h ) => strtolower( trim( $h ) ), $header );

		$rows = [];
		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			if ( count( $data ) < count( $header ) ) {
				$data = array_pad( $data, count( $header ), '' );
			}
			$rows[] = array_combine( $header, array_slice( $data, 0, count( $header ) ) );
		}
		fclose( $handle );
		return $rows;
	}

	private function normalize( array $row ): array {
		$get = fn( string $key ) => trim( $row[ $key ] ?? '' );

		$status = strtolower( $get( 'read status' ) ) ?: 'read';
		if ( ! isset( self::SHELVES[ $status ] ) ) {
			$status = 'read';
		}

		// StoryGraph dates look like 2024/08/06.
		$date_read  = $this->date( $get( 'last date read' ) );
		$date_added = $this->date( $get( 'date added' ) );

		$isbn = preg_replace( '/[^0-9Xx]/', '', $get( 'isbn/uid' ) );
		if ( strlen( $isbn ) !== 10 && strlen( $isbn ) !== 13 ) {
			$isbn = '';
		}

		return [
			'title'      => $get( 'title' ),
			'author'     => $get( 'authors' ),
			'isbn'       => $isbn,
			'status'     => $status,
			'rating'     => (float) $get( 'star rating' ),
			'review'     => $get( 'review' ),
			'date_read'  => $date_read,
			'post_date'  => $date_read ?: $date_added ?: current_time( 'Y-m-d' ),
		];
	}

	private function date( string $value ): string {
		if ( ! $value ) {
			return '';
		}
		$ts = strtotime( str_replace( '/', '-', $value ) );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}

	private function find_existing( array $book ): ?int {
		if ( $book['isbn'] ) {
			$ids = get_posts(
				[
					'post_type'      => Books::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => 'book_isbn',
					'meta_value'     => $book['isbn'],
				]
			);
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		$ids = get_posts(
			[
				'post_type'      => Books::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'title'          => $book['title'],
			]
		);
		return $ids ? (int) $ids[0] : null;
	}

	/**
	 * @return int|\WP_Error
	 */
	private function save( array $book, ?int $existing ) {
		$postarr = [
			'post_type'   => Books::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $book['title'],
			'post_date'   => $book['post_date'] . ' 12:00:00',
			'meta_input'  => [
				'book_author'    => $book['author'],
				'book_isbn'      => $book['isbn'],
				'book_rating'    => $book['rating'],
				'book_date_read' => $book['date_read'],
			],
		];
		if ( $book['review'] ) {
			$postarr['post_content'] = $this->review_blocks( $book['review'] );
		}
		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		[ , $slug ] = self::SHELVES[ $book['status'] ];
		wp_set_object_terms( $post_id, $slug, Books::TAXONOMY );

		if ( $book['isbn'] && ! get_post_meta( $post_id, 'book_url', true ) ) {
			update_post_meta( $post_id, 'book_url', 'https://openlibrary.org/isbn/' . $book['isbn'] );
		}
		return $post_id;
	}

	private function review_blocks( string $review ): string {
		$paragraphs = preg_split( '/\R{2,}/', trim( $review ) );
		return implode(
			"\n\n",
			array_map(
				fn( $p ) => "<!-- wp:paragraph -->\n<p>" . wp_kses_post( nl2br( trim( $p ) ) ) . "</p>\n<!-- /wp:paragraph -->",
				$paragraphs
			)
		);
	}

	private function ensure_shelves( bool $dry_run ): void {
		foreach ( self::SHELVES as [ $name, $slug ] ) {
			if ( ! term_exists( $slug, Books::TAXONOMY ) && ! $dry_run ) {
				wp_insert_term( $name, Books::TAXONOMY, [ 'slug' => $slug ] );
			}
		}
	}

	/**
	 * Try the ISBN cover first, then an Open Library title/author search.
	 */
	private function fetch_cover( int $post_id, array $book ): bool {
		$urls = [];
		if ( ! empty( $book['isbn'] ) ) {
			$urls[] = sprintf( self::COVER_BY_ISBN, rawurlencode( $book['isbn'] ) );
		}

		$search = wp_remote_get( sprintf( self::SEARCH, rawurlencode( $book['title'] ), rawurlencode( $book['author'] ) ), [ 'timeout' => 15 ] );
		if ( ! is_wp_error( $search ) && 200 === wp_remote_retrieve_response_code( $search ) ) {
			$json = json_decode( wp_remote_retrieve_body( $search ), true );
			$doc  = $json['docs'][0] ?? null;
			if ( $doc ) {
				if ( ! empty( $doc['cover_i'] ) ) {
					$urls[] = sprintf( self::COVER_BY_ID, (int) $doc['cover_i'] );
				}
				if ( ! empty( $doc['key'] ) && ! get_post_meta( $post_id, 'book_url', true ) ) {
					update_post_meta( $post_id, 'book_url', 'https://openlibrary.org' . $doc['key'] );
				}
			}
		}

		foreach ( $urls as $url ) {
			// Cover URLs redirect to archive.org, so follow redirects and make
			// sure we got a real image rather than a 404 or a 1x1 placeholder.
			$probe = wp_remote_get( $url, [ 'timeout' => 20, 'redirection' => 5 ] );
			if ( is_wp_error( $probe ) || 200 !== wp_remote_retrieve_response_code( $probe ) ) {
				continue;
			}
			if ( ! str_starts_with( (string) wp_remote_retrieve_header( $probe, 'content-type' ), 'image/' ) || strlen( wp_remote_retrieve_body( $probe ) ) < 1000 ) {
				continue;
			}
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			// Sideload without a parent so the file lands in the current month's
			// folder rather than the book's read-date folder, then attach it.
			$attachment_id = media_sideload_image( $url, 0, $book['title'] . ' cover', 'id' );
			if ( is_wp_error( $attachment_id ) ) {
				WP_CLI::warning( 'Cover failed for ' . $book['title'] . ': ' . $attachment_id->get_error_message() );
				continue;
			}
			wp_update_post( [ 'ID' => $attachment_id, 'post_parent' => $post_id ] );
			set_post_thumbnail( $post_id, $attachment_id );
			WP_CLI::log( '       cover: ' . $url );
			usleep( 300000 );
			return true;
		}
		WP_CLI::log( '       no cover found for ' . $book['title'] );
		return false;
	}
}
