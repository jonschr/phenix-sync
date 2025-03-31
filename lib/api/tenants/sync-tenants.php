<?php

function phenixsync_professionals_test_sync_location() {
	
	// check if the current user is an admin
	if ( ! current_user_can( 'administrator' ) ) {
		return;
	}
	
	// // get the 'sync' url parameter from the URL
	// $sync = isset( $_GET['sync'] ) ? sanitize_text_field( $_GET['sync'] ) : false;
	
	// if ( $sync ) {
	// 	// if the sync parameter is set, run the sync process for this location
	// 	phenixsync_sync_individual_location_professionals( $sync );
	// } else {
		
	// }
	
	// phenixsync_sync_individual_location_professionals( 179 ); // 179 is the s3_index for the Greenfield location.
	// phenixsync_sync_individual_location_professionals( 1351 ); // 755 is the s3_index for the Bellevue, WA location.
}
add_action( 'wp_footer', 'phenixsync_professionals_test_sync_location' );

/**
 * Schedule the locations sync process.
 *
 * @return void
 */
function phenixsync_schedule_professionals_sync() {
	if ( ! wp_next_scheduled( 'phenixsync_professionals_cron_hook' ) ) {
		wp_schedule_event( time(), 'daily', 'phenixsync_professionals_cron_hook' );
	}
}
add_action( 'wp', 'phenixsync_schedule_professionals_sync' );

/**
 * Do the sync process.
 *
 * @return  void.
 */
function phenixsync_professionals_manage_sync_process() {
	$location_ids = phenixsync_professionals_loop_through_locations_and_get_s3_location_ids();
	$location_ids = array_unique( $location_ids );
	
	foreach( $location_ids as $key => $s3_index ) {
		// Schedule each location sync with 30 second intervals
		wp_schedule_single_event( 
			time() + ( 30 * ($key + 1) ), 
			'phenixsync_sync_individual_location_professionals_event', 
			array( $s3_index ) 
		);
	}
}

// Hook should be outside the function
add_action( 'phenixsync_professionals_cron_hook', 'phenixsync_professionals_manage_sync_process' );
add_action( 'phenixsync_sync_individual_location_professionals_event', 'phenixsync_sync_individual_location_professionals' );

/**
 * Sync an individual location's professionals.
 *
 * @param   [type]  $s3_index  [$s3_index description]
 *
 * @return  [type]             [return description]
 */
function phenixsync_sync_individual_location_professionals( $s3_index ) {
	// 179 is the s3_index for the Greenfield location.
	$raw_response = phenixsync_professionals_api_request( $s3_index );
	$php_array = phenixsync_professionals_get_php_array_from_raw_response( $raw_response );
	
	foreach( $php_array as $professional ) {
		$post_id = phenixsync_professionals_maybe_create_post( $professional );
		
		if ( ! $post_id ) {
			continue; // Skip if post creation failed
		}
		
		phenixsync_professionals_update_post( $professional, $post_id );
		phenixsync_professionals_update_post_taxonomies( $professional, $post_id );
	}
}

function phenixsync_professionals_update_post_taxonomies( $professional, $post_id ) {
	if ( ! is_array( $professional ) || ! isset( $professional['standard_services'] ) ) {
		return;
	}
	
	// clear the services taxonomy for this post: 
	$terms = get_the_terms( $post_id, 'services' );
	if ( $terms && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			wp_remove_object_terms( $post_id, $term->term_id, 'services' );
		}
	}

	$standard_services = $professional['standard_services'];
	
	if ( ! is_array( $standard_services ) || empty( $standard_services ) ) {
		return;
	}
	
	foreach ( $standard_services as $service ) {
		if ( ! isset( $service['standard_category'] ) || empty( $service['standard_category'] ) ) {
			continue;
		}
		
		$term = str_replace('-', ' ', ucwords(strtolower($service['standard_category'])));
		$service_term = term_exists( $term, 'services' );
		
		if ( ! $service_term ) {
			$service_term = wp_insert_term( $term, 'services' );
			if ( is_wp_error( $service_term ) ) {
				continue;
			}
		}

		wp_set_post_terms( $post_id, $service_term['term_id'], 'services', true );
	}
}

/**
 * Loop through all of the locations and get the s3_index field from each one.
 *
 * @return  array an array of the s3_index fields from the locations CPT (just those values).
 */
function phenixsync_professionals_loop_through_locations_and_get_s3_location_ids() {
	// loop through the 'locations' CPT and get the s3_index field from each one.
	$args = array(
		'post_type'      => 'locations',
		'posts_per_page' => -1,
	);
	$posts = get_posts( $args );
	$location_ids = array();

	foreach ( $posts as $post ) {
		$s3_index = get_post_meta( $post->ID, 's3_index', true );
		if ( $s3_index ) {
			$location_ids[] = $s3_index;
		}
	}

	return $location_ids;
}

/**
 * Function to make an API request for professionals with a custom timeout and POST data.
 *
 * @return string API response or error message.
 */
function phenixsync_professionals_api_request( $s3_index ) {
	$api_url       = 'https://admin.ginasplatform.com/utilities/phenix_portal_sender.aspx';
	$transient_key = 'phenixsync_professionals_raw_response_' . (int) $s3_index;
	$cache_duration = 12 * HOUR_IN_SECONDS;

	// Set time limit and memory limit
	set_time_limit( 60 ); // Try setting a higher time limit
	@ini_set( 'memory_limit', '256M' ); // Try setting a higher memory limit

	// Try to retrieve the cached response
	$cached_response = get_transient( $transient_key );
	
	// at the moment, the API doesn't work, so instead, we're going to get test-data.json from the same dir as this file, and return the content of that.
	// $cached_response = file_get_contents( __DIR__ . '/test-data.json' );

	if ( false !== $cached_response ) {
		return $cached_response; // Return cached response
	}

	$args = array(
		'timeout'  => 60,
		'blocking' => true,
		'headers'  => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
		'method'   => 'POST',
		'body'     => array(
			'password' => 'LPJph7g3tT263BIfJ1',
			'location_index' => $s3_index,
		),
	);

	$response = wp_remote_request( $api_url, $args );

	if ( is_wp_error( $response ) ) {
		$error_message = $response->get_error_message();
		$result        = "Error: " . esc_html( $error_message );
		error_log( 'phenixsync_professionals_api_request WP_Error: ' . $error_message );		
	} else {
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 === (int) $response_code ) {
			$result = $response_body; // Store raw response
			
			// Store the response in a transient, expires after $cache_duration seconds
			$set_transient_result = set_transient( $transient_key, $result, $cache_duration );
			if ( ! $set_transient_result ) {
				error_log( 'Failed to set transient: ' . $transient_key );
			}
			
		} else {
			$result = "Request failed with status code: " . esc_html( (string) $response_code );
			error_log( 'phenixsync_professionals_api_request HTTP Error: ' . $response_code . ' - ' . $result );

		}
	}
	
	// save some details about how this sync went.
	phenix_save_pros_sync_details_to_location( $response, $s3_index );

	return $result;
}

function phenix_save_pros_sync_details_to_location( $response, $s3_index ) {
	// use the $s3_index to get the location post ID.
	$args = array(
		'post_type'      => 'locations',
		'posts_per_page' => 1,
		'meta_key'       => 's3_index',
		'meta_value'     => $s3_index,
	);
	$posts = get_posts( $args );
	if ( empty( $posts ) ) {
		return;
	}
	$location_post_id = $posts[0]->ID;
	
	// get the response code and body
	$response_code = wp_remote_retrieve_response_code( $response );
	$response_body = wp_remote_retrieve_body( $response );
	
	// get the response time from our perspective
	$response_time = date( 'Y-m-d H:i:s' );
	// get the response size
	$response_size = wp_remote_retrieve_header( $response, 'Content-Length' );
	// get the response date
	$response_date = wp_remote_retrieve_header( $response, 'Date' );

	// get the existing details from the location post meta (we'll call this 'professionals_sync_details')
	$existing_details = get_post_meta( $location_post_id, 'professionals_sync_details', true );
	if ( ! $existing_details ) {
		$existing_details = array();
	}
	// if the existing details are not an array, make them an array.
	if ( ! is_array( $existing_details ) ) {
		$existing_details = array();
	}
	
	// save the response details to the location post meta. I'd like to insert this at the beginning of the array.
	$details = array(
		'response_code'        => $response_code,
		// 'response_body'        => $response_body,
		'response_time'        => $response_time,
		'response_size'        => $response_size,
		'response_date'        => $response_date,
	);
	
	// add this to the beginning of the array
	array_unshift( $existing_details, $details );
	
	// trim the array to 10 items.
	if ( count( $existing_details ) > 10 ) {
		$existing_details = array_slice( $existing_details, 0, 10 );
	}
	
	// save the details to the location post meta.
	$update_result = update_post_meta( $location_post_id, 'professionals_sync_details', $existing_details );
	
}

function phenixsync_professionals_get_php_array_from_raw_response( $raw_response ) {
	$php_array = json_decode( $raw_response, true );

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		error_log( 'JSON decode error: ' . json_last_error_msg() );
		return array();
	}

	return $php_array;
	
}

function phenixsync_professionals_maybe_create_post( $professional ) {
	
	// Check if the professional already exists
	$existing_post_id = phenixsync_professionals_get_post_by_external_id( $professional['S3_tenantID'] );

	if ( $existing_post_id ) {
		return $existing_post_id;
	}
	
	$professional_post_details = array(
		'post_title'  => $professional['salon_name'],
		'post_type'   => 'professionals',
		'post_status' => 'publish',
		'meta_input'  => array(
			's3_tenant_id'     => $professional['S3_tenantID'],
		),
	);
	
	$new_professional_post_id = wp_insert_post( $professional_post_details );
	return $new_professional_post_id;
}

/**
 * Update the post
 *
 * @param   array  $professional  	[$professional description]
 * @param   [type]  $post_id       [$post_id description]
 *
 * @return  [type]                 [return description]
 */
function phenixsync_professionals_update_post( $professional, $post_id ) {
	
	$suites = $professional['suites'];
	if ( $suites && is_array( $suites ) ) {
		$suite_names = array();
		
		foreach( $suites as $suite ) {
			$suite_names[] = $suite['suite_name'];
		}
		
		$suites_string = implode( ', ', $suite_names );
	}
	
	$corresponding_location = phenixsync_locations_get_post_by_external_id( $professional['S3_locationID'] );
	
	// get the address1, address2, city, state, zip, and country from the location post
	$address1 = get_post_meta( $corresponding_location, 'address1', true );
	$address2 = get_post_meta( $corresponding_location, 'address2', true );
	$city = get_post_meta( $corresponding_location, 'city', true );
	$state = get_post_meta( $corresponding_location, 'state', true );
	$zip = get_post_meta( $corresponding_location, 'zip', true );
	$country = get_post_meta( $corresponding_location, 'country', true );
	
	$details_we_want = array(
		's3_location_id' => (int) $professional['S3_locationID'],
		's3_tenant_id'   => (int) $professional['S3_tenantID'],
		'suites'         => sanitize_text_field( $suites_string ),
		'name'           => sanitize_text_field( $professional['name'] ),
		'email'          => sanitize_email( $professional['email'] ),
		'phone'          => sanitize_text_field( $professional['phone'] ),
		'profile_image'  => esc_url_raw( $professional['profile_image'] ),
		'instagram'      => esc_url_raw( $professional['instagram'] ),
		'facebook'       => esc_url_raw( $professional['facebook'] ),
		'x'              => sanitize_text_field( $professional['x'] ),
		'website'        => esc_url_raw( $professional['website'] ),
		'booking_link'   => esc_url_raw( $professional['booking_link'] ),
		'photo'          => esc_url_raw( $professional['photo'] ),
		'bio'            => sanitize_textarea_field( $professional['bio'] ),
		'location_name'  => sanitize_text_field( $professional['location_name'] ),
		'address1'  => sanitize_text_field( $address1 ),
		'address2'  => sanitize_text_field( $address2 ),
		'city'      => sanitize_text_field( $city ),
		'state'     => sanitize_text_field( $state ),
		'zip'       => sanitize_text_field( $zip ),
		'country'   => sanitize_text_field( $country ),
	);
	
	// let's update the post meta with these details
	foreach( $details_we_want as $key => $value ) {
		if ( ! empty( $value ) ) {
			update_post_meta( $post_id, $key, $value );
		}
	}
}

function phenixsync_professionals_get_post_by_external_id( $external_id ) {
	$args = array(
		'post_type'      => 'professionals',
		'posts_per_page' => 1,
		'meta_key'       => 's3_tenant_id',
		'meta_value'     => $external_id,
	);

	$posts = get_posts( $args );
	
	// if there's more than one post, we need to delete the duplicates.
	if ( count( $posts ) > 1 ) {
		foreach ( array_slice( $posts, 1 ) as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}
	// if there's no post, return false.
	if ( empty( $posts ) ) {
		return false;
	}
	
	// if there's only one post, return the ID of that post.
	if ( ! empty( $posts ) ) {
		return $posts[0]->ID;
	}
}

/**
 * Utility function to delete all professionals
 *
 * @return  void.
 */
function phenix_professionals_delete_all_professionals() {
	$args = array(
		'post_type'      => 'professionals',
		'posts_per_page' => 100,
		'fields'         => 'ids',
	);

	do {
		$posts = get_posts( $args );

		foreach ( $posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Pause for 5 seconds between batches
		if ( ! empty( $posts ) ) {
			sleep( 5 );
		}
	} while ( ! empty( $posts ) );
}
// add_action( 'wp_footer', 'phenix_professionals_delete_all_professionals' );