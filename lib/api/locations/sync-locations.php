<?php

/**
 * Schedule the locations sync process.
 *
 * @return void
 */
function phenixsync_schedule_locations_sync() {
	if ( ! wp_next_scheduled( 'phenixsync_locations_cron_hook' ) ) {
		wp_schedule_event( time(), 'daily', 'phenixsync_locations_cron_hook' );
	}
}
add_action( 'wp', 'phenixsync_schedule_locations_sync' );

/**
 * Initialize the sync process by fetching data and scheduling batch processing.
 *
 * @return void
 */
function phenixsync_locations_sync_init() {
	$raw_response = phenixsync_locations_api_request();
	$locations_array = phenixsync_locations_json_to_php_array( $raw_response );
	
	// Store the locations array in a transient
	set_transient( 'phenixsync_locations_data', $locations_array, DAY_IN_SECONDS );
	
	// Schedule the first batch
	wp_schedule_single_event( time(), 'phenixsync_process_batch', array( 0 ) );
}
add_action( 'phenixsync_locations_cron_hook', 'phenixsync_locations_sync_init' );

/**
 * Process a batch of locations.
 *
 * @param int $offset The offset to start processing from.
 * @return void
 */
function phenixsync_process_batch( $offset ) {
	$locations_array = get_transient( 'phenixsync_locations_data' );
	if ( ! $locations_array ) {
		return;
	}
	
	$batch_size = 10; // Process 10 locations per batch
	$processed = 0;
	$total = count( $locations_array );
	
	for ( $i = $offset; $i < $total && $processed < $batch_size; $i++ ) {
		$location = $locations_array[$i];
		$post_id = phenixsync_locations_maybe_create_post( $location );
		phenixsync_locations_update_post( $location, $post_id );
		phenixsync_locations_update_post_taxonomies( $location, $post_id );
		$processed++;
	}
	
	// Schedule the next batch if there are more locations to process
	if ( $offset + $processed < $total ) {
		wp_schedule_single_event( time() + 30, 'phenixsync_process_batch', array( $offset + $processed ) );
	}
}
add_action( 'phenixsync_process_batch', 'phenixsync_process_batch' );

/**
 * Manual trigger for the sync process (for testing or admin-triggered syncs).
 *
 * @return void
 */
function phenixsync_trigger_sync() {
	do_action( 'phenixsync_locations_cron_hook' );
}

/**
 * Function to make an API request for locations with a custom timeout and POST data.
 *
 * @return string API response or error message.
 */
function phenixsync_locations_api_request() {
	$api_url       = 'https://admin.ginasplatform.com/utilities/phenix_portal_locations_sender.aspx';
	$transient_key = 'phenixsync_locations_raw_response';
	$cache_duration = 6000000000000000; // 10 minutes in seconds

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
		),
	);

	$response = wp_remote_request( $api_url, $args );

	if ( is_wp_error( $response ) ) {
		$error_message = $response->get_error_message();
		$result        = "Error: " . esc_html( $error_message );
		error_log( 'phenixsync_locations_api_request WP_Error: ' . $error_message );
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
			error_log( 'phenixsync_locations_api_request HTTP Error: ' . $response_code . ' - ' . $result );

		}
	}

	return $result;
}

/**
 * Take the raw JSON response and convert it to a PHP array.
 *
 * @param string $raw_response The raw JSON response.
 * @return array The decoded JSON response.
 */
function phenixsync_locations_json_to_php_array( $raw_response ) {

	// Check for error responses
	if ( strpos( $raw_response, "Error:" ) === 0 || strpos( $raw_response, "Request failed" ) === 0 ) {
		error_log( 'API request failed, cannot decode JSON. Response: ' . $raw_response );
		return array( 'error' => 'API request failed: ' . $raw_response );
	}

	$response_php_array = json_decode( $raw_response, true );

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		// Handle JSON decode error
		$json_error_message = json_last_error_msg();
		error_log( 'JSON decode error: ' . $json_error_message . ' - Raw response: ' . substr( $raw_response, 0, 500 ) . '...' ); // Log the error and a snippet of the response
		return array( 'error' => 'Failed to decode JSON: ' . $json_error_message );  // Return an error array
	}

	if ( !isset( $response_php_array['locations'] ) || !is_array( $response_php_array['locations'] ) ) {
		return; // Don't proceed if there was an error
	}
	
	return $response_php_array['locations'];
}

/**
 * Create a new post if there isn't one already.
 *
 * @param   array  $location  The location data.
 *
 * @return  void.
 */
function phenixsync_locations_maybe_create_post( $location ) {
	
	// Check if the location already exists
	$existing_post_id = phenixsync_locations_get_post_by_external_id( $location['S3_index'] );

	if ( $existing_post_id ) {
		return $existing_post_id;
	}
	
	$location_post_details = array(
		'post_title'  => $location['location_name'],
		'post_type'   => 'locations',
		'post_status' => 'publish',
		'meta_input'  => array(
			's3_index'     => $location['S3_index'],
		),
	);
	
	$new_location_post_id = wp_insert_post( $location_post_details );
	return $new_location_post_id;
}

/**
 * Get the location post by the external ID.
 *
 * @param   string  $external_id  the phenix_franchise_license_index.
 *
 * @return  string The WordPress post ID.
 */
function phenixsync_locations_get_post_by_external_id( $external_id ) {
	$args = array(
		'post_type'      => 'locations',
		'posts_per_page' => 1,
		'meta_key'       => 's3_index',
		'meta_value'     => $external_id,
	);

	$posts = get_posts( $args );
	
	// delete all posts except the first one
	if ( count( $posts ) > 1 ) {
		$posts_to_delete = array_slice( $posts, 1 );
		foreach( $posts_to_delete as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}

	if ( ! empty( $posts ) ) {
		return $posts[0]->ID;
	}

	return false;
}

function phenixsync_locations_update_post( $location, $post_id ) {

	// Update the post
	$location_post_details = array(
		'post_title'  => $location['location_name'],
		'post_type'   => 'locations',
		'post_status' => 'publish'
	);

	$location_post_details['ID'] = $post_id;
	wp_update_post( $location_post_details );

	// TODO UPDATE THE POST META
	// let's just grab all of the meta keys and values from the location array and update the post meta with them. We need to remove the 'suites' key. 
	// We should sanitize all of this data before updating the post meta.
	$location_meta = $location;
	unset( $location_meta['suites'] );
	
	foreach( $location_meta as $key => $value ) {
		$sanitized_key = sanitize_key( $key );
		$sanitized_value = ( $value === "" || $value === null ) ? null : sanitize_text_field( $value );
		update_post_meta( $post_id, $sanitized_key, $sanitized_value );
	}
}

function phenixsync_locations_update_post_taxonomies( $location, $post_id ) {
	
	// get the 'country' post meta field
	$country = $location['country'];
	
	// make this all caps
	$country = strtoupper( $country );
	
	if ( $country === 'USA' ) {
		// The country is the USA, so we need to set the state taxonomy
		$state = $location['state'];
		
		// make this all caps
		$state = strtoupper( $state );
		
		$states = [
			// US States
			'AL' => 'Alabama',
			'AK' => 'Alaska', 
			'AZ' => 'Arizona',
			'AR' => 'Arkansas',
			'CA' => 'California',
			'CO' => 'Colorado',
			'CT' => 'Connecticut',
			'DE' => 'Delaware',
			'FL' => 'Florida',
			'GA' => 'Georgia',
			'HI' => 'Hawaii',
			'ID' => 'Idaho',
			'IL' => 'Illinois',
			'IN' => 'Indiana',
			'IA' => 'Iowa',
			'KS' => 'Kansas',
			'KY' => 'Kentucky',
			'LA' => 'Louisiana',
			'ME' => 'Maine',
			'MD' => 'Maryland',
			'MA' => 'Massachusetts',
			'MI' => 'Michigan',
			'MN' => 'Minnesota',
			'MS' => 'Mississippi',
			'MO' => 'Missouri',
			'MT' => 'Montana',
			'NE' => 'Nebraska',
			'NV' => 'Nevada',
			'NH' => 'New Hampshire',
			'NJ' => 'New Jersey',
			'NM' => 'New Mexico',
			'NY' => 'New York',
			'NC' => 'North Carolina',
			'ND' => 'North Dakota',
			'OH' => 'Ohio',
			'OK' => 'Oklahoma',
			'OR' => 'Oregon',
			'PA' => 'Pennsylvania',
			'RI' => 'Rhode Island',
			'SC' => 'South Carolina',
			'SD' => 'South Dakota',
			'TN' => 'Tennessee',
			'TX' => 'Texas',
			'UT' => 'Utah',
			'VT' => 'Vermont',
			'VA' => 'Virginia',
			'WA' => 'Washington',
			'WV' => 'West Virginia',
			'WI' => 'Wisconsin',
			'WY' => 'Wyoming',
			'DC' => 'District of Columbia',
		];
	
		if ( array_key_exists( $state, $states ) ) {
			$state = $states[$state];
		}

	} elseif ( $country === 'UK' ) {
		// The country is the UK, so we need to set the taxonomy to be the city.
		$state = $location['city'];
	} else {
		// The country is neither the USA nor the UK, so we can't set a state or county taxonomy
		return;
	}
	
	$state_term = term_exists( $state, 'states' );
	
	if ( !$state_term ) {
		$state_term = wp_insert_term( $state, 'states' );
	} else {
		wp_set_post_terms( $post_id, $state_term['term_id'], 'states' );
	}
}

function phenix_locations_delete_all_locations() {
	$args = array(
		'post_type'      => 'locations',
		'posts_per_page' => -1,
	);

	$posts = get_posts( $args );
	
	foreach( $posts as $post ) {
		wp_delete_post( $post->ID, true );
	}
}
// add_action( 'init', 'phenix_locations_delete_all_locations' );