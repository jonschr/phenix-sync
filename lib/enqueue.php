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
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$is_location_edit = $screen && 'locations' === $screen->post_type && 'post' === $screen->base;
	$is_professional_edit = $screen && 'professionals' === $screen->post_type && 'post' === $screen->base;
	if ( $is_location_edit || $is_professional_edit ) {
		wp_register_style( 'phenixsync-admin-location-sync-debug', false, array(), PHENIX_SYNC_VERSION );
		wp_enqueue_style( 'phenixsync-admin-location-sync-debug' );
		wp_add_inline_style( 'phenixsync-admin-location-sync-debug', '.phenixsync-location-meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.phenixsync-location-meta-group{margin:0;border:1px solid #dcdcde;border-radius:3px;background:#fff}.phenixsync-location-meta-group h3{margin:0;padding:8px 10px;border-bottom:1px solid #dcdcde;font-size:13px}.phenixsync-location-meta-group dl{display:grid;grid-template-columns:minmax(100px,40%) 1fr;gap:6px 10px;margin:0;padding:10px;font-size:12px}.phenixsync-location-meta-group dt{font-weight:600;color:#50575e}.phenixsync-location-meta-group dd{margin:0;overflow-wrap:anywhere}.phenixsync-location-image-link{display:block;margin-bottom:6px}.phenixsync-location-image-preview{display:block;width:100%;max-width:260px;height:120px;object-fit:contain;background:#f6f7f7;border:1px solid #dcdcde;border-radius:2px}.phenixsync-debug-table td{vertical-align:top}.phenixsync-debug-details{display:grid;grid-template-columns:max-content 1fr;gap:4px 12px;margin:0 0 12px}.phenixsync-debug-details dt{font-weight:600}.phenixsync-debug-details dd{margin:0}.phenixsync-debug-json-controls{margin:0 0 8px}.phenixsync-debug-json-controls .button-link{padding:0;text-decoration:underline}.phenixsync-debug-preview,.phenixsync-debug-json{max-height:calc(80vh - 180px);overflow:auto;white-space:pre-wrap;background:#1e1e1e;color:#d4d4d4;padding:12px;border:1px solid #dcdcde;font:12px/1.5 Consolas,Monaco,monospace}.phenixsync-debug-json details{margin:0;padding:0}.phenixsync-debug-json summary{cursor:pointer;color:#9cdcfe}.phenixsync-debug-json ul.phenixsync-json-children{display:block;margin:2px 0;padding:0 0 0 28px!important;list-style:none;border-left:1px solid #3e3e42}.phenixsync-debug-json li.phenixsync-json-line{display:block;min-width:max-content;min-height:18px;margin:0;padding:0}.phenixsync-json-key{color:#9cdcfe}.phenixsync-json-string{color:#ce9178}.phenixsync-json-number{color:#b5cea8}.phenixsync-json-literal{color:#569cd6}.phenixsync-json-punctuation{color:#d4d4d4}.phenixsync-json-count{color:#6a9955;font-style:italic}.phenixsync-debug-modal[hidden]{display:none}.phenixsync-debug-modal{position:fixed;z-index:100000;inset:0;display:grid;place-items:center;padding:30px}.phenixsync-debug-modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55)}.phenixsync-debug-modal-dialog{position:relative;width:min(1100px,100%);max-height:calc(100vh - 60px);overflow:auto;background:#fff;border-radius:4px;box-shadow:0 8px 30px rgba(0,0,0,.35)}.phenixsync-debug-modal-header{position:sticky;top:0;z-index:1;display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#fff;border-bottom:1px solid #dcdcde}.phenixsync-debug-modal-header h2{margin:0;font-size:16px}.phenixsync-debug-modal-close{font-size:28px;line-height:1;text-decoration:none}.phenixsync-debug-modal-content{padding:16px}.phenixsync-debug-modal-open{white-space:nowrap}.phenixsync-debug-modal-open:focus{outline:2px solid #2271b1;outline-offset:2px}.phenixsync-debug-modal-opened{overflow:hidden}' );
		wp_add_inline_style( 'phenixsync-admin-location-sync-debug', '.phenixsync-professional-profile-image-link{float:right;margin:0 0 12px 16px}.phenixsync-professional-profile-image{display:block;width:100px;height:100px;object-fit:cover;border-radius:50%;border:1px solid #dcdcde}.phenixsync-professional-current-data{display:grid;grid-template-columns:minmax(120px,30%) 1fr;gap:7px 12px;clear:both}.phenixsync-professional-current-data dt{font-weight:600;color:#50575e}.phenixsync-professional-current-data dd{margin:0;overflow-wrap:anywhere}.phenixsync-professional-bio{clear:both}' );
	}
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
	
	// Enqueue admin JavaScript for sync buttons
	wp_enqueue_script( 
		'phenixsync-admin-scripts', 
		PHENIX_SYNC_PATH . 'dist/js/phenixsync-scripts.js', 
		array( 'jquery' ), 
		PHENIX_SYNC_VERSION, 
		true 
	);

	if ( $is_location_edit ) {
		wp_add_inline_script( 'phenixsync-admin-scripts', "(function(){var activeModal,lastTrigger;function closeModal(){if(!activeModal){return;}activeModal.hidden=true;activeModal.setAttribute('aria-hidden','true');document.body.classList.remove('phenixsync-debug-modal-opened');if(lastTrigger){lastTrigger.focus();}activeModal=null;}document.addEventListener('click',function(event){var trigger=event.target.closest('.phenixsync-debug-modal-open');if(trigger){event.preventDefault();var modal=document.querySelector(trigger.getAttribute('href'));if(!modal){return;}lastTrigger=trigger;activeModal=modal;modal.hidden=false;modal.setAttribute('aria-hidden','false');document.body.classList.add('phenixsync-debug-modal-opened');modal.querySelector('.phenixsync-debug-modal-dialog').focus();return;}var collapse=event.target.closest('[data-phenixsync-json-collapse],[data-phenixsync-json-expand]');if(collapse){var expand=collapse.hasAttribute('data-phenixsync-json-expand');collapse.closest('.phenixsync-debug-modal-content').querySelectorAll('.phenixsync-json-group').forEach(function(group){group.open=expand;});return;}if(event.target.closest('[data-phenixsync-modal-close]')){closeModal();}});document.addEventListener('keydown',function(event){if('Escape'===event.key){closeModal();}});}());", 'after' );
	}
	
	// Localize script for AJAX
	wp_localize_script( 'phenixsync-admin-scripts', 'phenixsync_ajax', array(
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'phenixsync_sync_nonce' )
	) );
}
add_action( 'admin_enqueue_scripts', 'phenixsync_enqueue_scripts_stylesheets_admin' );
