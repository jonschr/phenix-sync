<?php
/**
 * Enqueue scripts and stylesheets
 *
 * @package phenixsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Register and enqueue scripts and stylesheets
 *
 * @return void.
 */
function phenixsync_enqueue_scripts_stylesheets() {

	// Plugin styles.
	wp_enqueue_style( 
		'phenixsync-styles', 
		PHENIX_SYNC_PATH . 'dist/css/phenixsync-styles.css', 
		array(), 
		PHENIX_SYNC_VERSION, 
		'screen'
	);
	
	// Plugin scripts.
	wp_enqueue_script( 
		'phenixsync-scripts', 
		PHENIX_SYNC_PATH . 'dist/js/phenixsync-scripts.js', 
		array( 'jquery' ), 
		PHENIX_SYNC_VERSION, 
		true 
	);
}
add_action( 'wp_enqueue_scripts', 'phenixsync_enqueue_scripts_stylesheets' );

/**
 * Admin enqueues
 */
function phenixsync_enqueue_scripts_stylesheets_admin() {
	wp_enqueue_style(
		'phenixsync-admin-columns-scroll',
		plugins_url( '../assets/css/admin-columns-scroll.css', __FILE__ ),
		array(),
		filemtime( plugin_dir_path( __FILE__ ) . '../assets/css/admin-columns-scroll.css' )
	);
	wp_enqueue_style(
		'phenixsync-admin-professionals-columns',
		plugins_url( '../assets/css/admin-professionals-columns.css', __FILE__ ),
		array(),
		filemtime( plugin_dir_path( __FILE__ ) . '../assets/css/admin-professionals-columns.css' )
	);
}
add_action( 'admin_enqueue_scripts', 'phenixsync_enqueue_scripts_stylesheets_admin' );
