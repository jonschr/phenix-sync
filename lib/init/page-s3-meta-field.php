<?php

/**
 * Add S3 Index metabox to pages
 *
 * @return void
 */
function phenix_add_s3_index_metabox() {
	add_meta_box(
		'phenix_s3_index_metabox',
		'S3 Index',
		'phenix_s3_index_metabox_callback',
		'page',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'phenix_add_s3_index_metabox' );

/**
 * Metabox callback function
 *
 * @param WP_Post $post The post object.
 * @return void
 */
function phenix_s3_index_metabox_callback( $post ) {
	// Add nonce field for security
	wp_nonce_field( 'phenix_s3_index_nonce_action', 'phenix_s3_index_nonce' );
	
	// Get current value
	$s3_index = get_post_meta( $post->ID, '_phenix_s3_index', true );
	
	// Output the field
	echo '<p>';
	echo '<label for="phenix_s3_index">S3 Index</label><br>';
	echo '<input type="text" id="phenix_s3_index" name="phenix_s3_index" value="' . esc_attr( $s3_index ) . '" style="width: 100%;" />';
	echo '</p>';
	echo '<p style="font-size: 14px; line-height: 1.2;" class="description">If you would like to use shortcodes on this page to pull in information for an individual Phenix location, please enter the S3 index of that location here.</p>';
}

/**
 * Save metabox data
 *
 * @param int $post_id The post ID.
 * @return void
 */
function phenix_save_s3_index_metabox( $post_id ) {
	// Verify nonce
	if ( ! isset( $_POST['phenix_s3_index_nonce'] ) || ! wp_verify_nonce( $_POST['phenix_s3_index_nonce'], 'phenix_s3_index_nonce_action' ) ) {
		return;
	}
	
	// Check if user has permission to edit this post
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	
	// Check if this is an autosave
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	
	// Check if this is a revision
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	
	// Only save for pages
	if ( get_post_type( $post_id ) !== 'page' ) {
		return;
	}
	
	// Save the data
	if ( isset( $_POST['phenix_s3_index'] ) ) {
		$s3_index = sanitize_text_field( $_POST['phenix_s3_index'] );
		
		if ( ! empty( $s3_index ) ) {
			update_post_meta( $post_id, '_phenix_s3_index', $s3_index );
		} else {
			delete_post_meta( $post_id, '_phenix_s3_index' );
		}
	}
}
add_action( 'save_post', 'phenix_save_s3_index_metabox' );

/**
 * Helper function to get S3 index for a page
 *
 * @param int $post_id The post ID (optional, defaults to current post).
 * @return string The S3 index identifier or empty string if not set.
 */
function phenix_get_s3_index( $post_id = null ) {
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}
	
	return get_post_meta( $post_id, '_phenix_s3_index', true );
}
