<?php

namespace DeliciousBrains\SpinupWPComposerSite;

use function Env\env;

class App {

	/**
	 * 	Load custom site code
	 */
	public function register() {
		( new Books() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'race', RaceImporter::class );
			\WP_CLI::add_command( 'beer', BeerImporter::class );
		}
	}

	/**
	 * Helper for defining constants if not already defined.
	 *
	 * @param string $key
	 * @param mixed  $value
	 */
	public static function define( $key, $value ) {
		if ( defined( $key ) ) {
			return;
		}

		define( $key, $value );
	}

	/**
	 * @param $name
	 * @param $arguments
	 *
	 * @return bool
	 */
	public static function __callStatic( $name, $arguments ) {
		if ( 'is_env_' === substr( $name, 0, 7 ) ) {
			$env = substr( $name, 7 );
			return $env === env( 'WP_ENV' );
		}
	}
}