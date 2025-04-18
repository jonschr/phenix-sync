<?php
/**
	Plugin Name:    Phenix Sync
	Plugin URI:     https://elod.in
	Description:    Just another WordPress plugin
	Version:        0.2
	Author:         Jon Schroeder
	Author URI:     https://elod.in
	Text Domain:    phenixsync-textdomain
	License:        GPLv2 or later
	License URI:    http://www.gnu.org/licenses/gpl-2.0.html
 *
	@package phenixsync
 */

// Prevent direct access to the plugin.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'Sorry, you are not allowed to access this page directly.' );
}

// Plugin base values.
define( 'PHENIX_SYNC', __DIR__ );
define( 'PHENIX_SYNC_VERSION', '0.2' );

// Set up plugin directories.
define( 'PHENIX_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PHENIX_SYNC_PATH', plugin_dir_url( __FILE__ ) );
define( 'PHENIX_SYNC_BASENAME', plugin_basename( __FILE__ ) );
define( 'PHENIX_SYNC_FILE', __FILE__ );

/**
 * Load the files
 *
 * @param   string $directory  the path to the directory to load.
 * @return  void
 */
function phenixsync_require_files_recursive( $directory ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && $file->getExtension() === 'php' ) {
			require_once $file->getPathname();
		}
	}
}

// Require_once all files in /lib and its subdirectories.
phenixsync_require_files_recursive( PHENIX_SYNC_DIR . 'lib' );
