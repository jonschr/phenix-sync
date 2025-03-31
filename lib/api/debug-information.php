<?php

// we need to add to the backend editor for each website, and we want to show the meta information there.

function phenix_add_professionals_sync_information_to_locations() {
	add_meta_box(
		'phenix_professionals_sync_debug_information',
		'Professionals Sync Debug Information',
		'phenix_professionals_info_on_locations_callback',
		'locations',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'phenix_add_professionals_sync_information_to_locations' );

function phenix_professionals_info_on_locations_callback() {
	global $post;

	// Get the current value.
	$professionals_sync_details = get_post_meta( $post->ID, 'professionals_sync_details', true );
	$permalink = get_the_permalink( $post->ID );

	// get the s3_index value for this location
	$s3_index = get_post_meta( $post->ID, 's3_index', true );
	
	// we need to show this as an array, so let's loop through it.
	printf( '<h3>Professionals sync debug: s3_index %s</h3>', $s3_index );
	
	// printf( '<p><a href="%s?sync=%s" target="_blank">Resync professionals for this location</a> (note: this can take up to 1 minute).', $permalink, $s3_index );
	
	if ( $professionals_sync_details && is_array( $professionals_sync_details[0] ) ) {
		
		$last_code = $professionals_sync_details[0]['response_code'];
		$last_time = $professionals_sync_details[0]['response_time'];
			
		if ( $last_code ) {
			printf( '<p>Last response code: %s<br/>Last response time: %s</p>', $last_code, $last_time );
		}
				
		foreach ( $professionals_sync_details as $request ) {

			printf( '<details><summary>Request %s</summary>', $request['response_time'] );
				echo '<pre>';
				print_r( $request );
				echo '</pre>';
			
			echo '</details>';

		}
	}
}