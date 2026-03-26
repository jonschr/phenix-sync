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
		$last_shape = isset( $professionals_sync_details[0]['response_shape'] ) ? $professionals_sync_details[0]['response_shape'] : '';
		$last_count = isset( $professionals_sync_details[0]['professional_count'] ) ? $professionals_sync_details[0]['professional_count'] : '';
		$last_status = isset( $professionals_sync_details[0]['api_status'] ) ? $professionals_sync_details[0]['api_status'] : '';
		$last_empty_list = ! empty( $professionals_sync_details[0]['is_empty_list'] ) ? 'yes' : 'no';
			
		printf(
			'<p>Last response code: %s<br/>Last response time: %s<br/>Last response shape: %s<br/>Last professional count: %s<br/>Last empty list: %s%s</p>',
			esc_html( '' !== (string) $last_code ? (string) $last_code : 'n/a' ),
			esc_html( $last_time ? $last_time : 'n/a' ),
			esc_html( $last_shape ? $last_shape : 'n/a' ),
			esc_html( '' !== (string) $last_count ? (string) $last_count : 'n/a' ),
			esc_html( $last_empty_list ),
			$last_status ? '<br/>Last API status: ' . esc_html( $last_status ) : ''
		);
				
		foreach ( $professionals_sync_details as $request ) {

			printf( '<details><summary>Request %s</summary>', esc_html( $request['response_time'] ) );
				echo '<p>';
				printf( '<strong>Response code:</strong> %s<br/>', esc_html( isset( $request['response_code'] ) ? $request['response_code'] : '' ) );
				printf( '<strong>Response shape:</strong> %s<br/>', esc_html( isset( $request['response_shape'] ) ? $request['response_shape'] : 'n/a' ) );
				printf( '<strong>Professional count:</strong> %s<br/>', esc_html( isset( $request['professional_count'] ) ? $request['professional_count'] : '0' ) );
				printf( '<strong>List count:</strong> %s<br/>', esc_html( isset( $request['list_count'] ) ? $request['list_count'] : '0' ) );
				printf( '<strong>Empty list:</strong> %s<br/>', ! empty( $request['is_empty_list'] ) ? 'yes' : 'no' );
				printf( '<strong>Response size:</strong> %s<br/>', esc_html( isset( $request['response_size'] ) ? $request['response_size'] : '' ) );
				if ( ! empty( $request['api_status'] ) ) {
					printf( '<strong>API status:</strong> %s<br/>', esc_html( $request['api_status'] ) );
				}
				if ( ! empty( $request['request_error'] ) ) {
					printf( '<strong>Request error:</strong> %s<br/>', esc_html( $request['request_error'] ) );
				}
				if ( ! empty( $request['json_error'] ) ) {
					printf( '<strong>JSON error:</strong> %s<br/>', esc_html( $request['json_error'] ) );
				}
				if ( ! empty( $request['top_level_keys'] ) && is_array( $request['top_level_keys'] ) ) {
					printf( '<strong>Top-level keys:</strong> %s<br/>', esc_html( implode( ', ', $request['top_level_keys'] ) ) );
				}
				echo '</p>';

				if ( array_key_exists( 'raw_response_preview', $request ) ) {
					echo '<h4 style="margin-bottom: 6px;">Response Preview</h4>';
					echo '<pre style="white-space: pre-wrap; max-height: 320px; overflow: auto;">';
					echo esc_html( $request['raw_response_preview'] );
					echo '</pre>';
				}

				echo '<h4 style="margin-bottom: 6px;">Stored Debug Payload</h4>';
				echo '<pre>';
				print_r( $request );
				echo '</pre>';
			
			echo '</details>';

		}
	}
}
