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
	$raw_response = phenixsync_locations_api_request( null );
	$locations_array = phenixsync_locations_json_to_php_array( $raw_response );
	
	// Check for locations on our site that no longer exist in the API response and cull them.
	phenixsync_remove_deleted_locations( $locations_array );
	
	// Store the locations array in a transient
	set_transient( 'phenixsync_locations_data', $locations_array, HOUR_IN_SECONDS );
	
	// Schedule the first batch
	wp_schedule_single_event( time(), 'phenixsync_do_process_batch', array( 0 ) );
}
add_action( 'phenixsync_locations_cron_hook', 'phenixsync_locations_sync_init' );

/** 
 * Remove locations that no longer exist in the API response.
 */
function phenixsync_remove_deleted_locations( $locations_array ) {
	
	if ( ! is_array( $locations_array ) || empty( $locations_array ) ) {
		return;
	}
	
	// if the locations array has less than 50 items, we don't need to do anything.
	// this is because we only want to remove locations if we're sure this is a real array with location data.
	if ( count( $locations_array ) < 50 ) {
		return;
	}
	
	// Get all existing locations
	$args = array(
		'post_type'      => 'locations',
		'posts_per_page' => -1,
		'post_status'    => 'any',
	);
	
	$existing_posts = get_posts( $args );
	
	foreach ( $existing_posts as $post ) {
		$post_meta = get_post_meta( $post->ID, 's3_index', true );
		
		if ( ! in_array( $post_meta, array_column( $locations_array, 'S3_index' ) ) ) {
			wp_delete_post( $post->ID, true );
		}
	}
}

/**
 * Process a batch of locations.
 *
 * @param int $offset The offset to start processing from.
 * @return void
 */
function phenixsync_process_batch( $offset ) {
	$locations_array = get_transient( 'phenixsync_locations_data' );
	if ( ! $locations_array ) {
		error_log('Phenix Sync: Locations data transient not found in phenixsync_process_batch.');
		return;
	}

	if ( ! is_array( $locations_array ) ) {
		error_log('Phenix Sync: Locations data transient is not an array in phenixsync_process_batch.');
		return;
	}
	
	$batch_size = 5; // Process 5 locations per batch
	$processed = 0;
	$total = count( $locations_array );
	
	for ( $i = $offset; $i < $total && $processed < $batch_size; $i++ ) {
		$location_item = $locations_array[$i]; 

		if ( ! is_array( $location_item ) || ! isset( $location_item['S3_index'] ) ) {
			error_log("Phenix Sync: Invalid location data or missing S3_index at offset {$i} in batch.");
			continue; 
		}
		
		$S3_index = $location_item['S3_index'];
		
		$sync_result = phenixsync_single_location_sync( $S3_index );
		
		if ( !$sync_result ) {
			error_log("Phenix Sync: Failed to get or create post_id for S3_index {$S3_index} in batch processing.");
		}
		
		$processed++;
	}
	
	// Schedule the next batch if there are more locations to process
	if ( $offset + $processed < $total ) {
		wp_schedule_single_event( time() + 10, 'phenixsync_do_process_batch', array( $offset + $processed ) );
	} else {
		// Optional: Clear transient after all batches are processed if desired
		// delete_transient( 'phenixsync_locations_data' );
		error_log("Phenix Sync: All location batches processed. Total: {$total}");
	}
}
add_action( 'phenixsync_do_process_batch', 'phenixsync_process_batch' );

/**
 * Syncs a single location based on its S3_index.
 * Fetches location data from transient and updates/creates the corresponding post.
 *
 * @param string|int $S3_index The S3_index of the location to sync.
 * @return bool True on successful sync attempt, false otherwise.
 */
function phenixsync_single_location_sync( $S3_index ) {
	
	// clear the transient first for phenixsync_locations_data
	delete_transient( 'phenixsync_locations_data_' . $S3_index );
	
	$raw_response = phenixsync_locations_api_request( $S3_index);
	$locations_array = phenixsync_locations_json_to_php_array( $raw_response );
		
	// Store the locations array in a transient
	set_transient( 'phenixsync_locations_data_' . $S3_index, $locations_array, HOUR_IN_SECONDS );
	
	if ( empty( $S3_index ) ) {
		error_log( 'Phenix Sync: S3_index cannot be empty for phenixsync_single_location_sync.' );
		return false;
	}

	// The individual functions called below will fetch their own data using the S3_index.
	$post_id = phenixsync_locations_maybe_create_post( $S3_index );

	if ( ! $post_id ) {
		error_log( "Phenix Sync: Failed to create or find post for S3_index {$S3_index} during single sync." );
		return false;
	}

	phenixsync_locations_update_post( $S3_index, $post_id );
	phenixsync_locations_update_post_taxonomies( $S3_index, $post_id );
	
	error_log( "Phenix Sync: Successfully processed single location sync for S3_index {$S3_index}, Post ID: {$post_id}." );
	return true;
}

/**
 * Function to make an API request for locations with a custom timeout and POST data.
 *
 * @return string API response or error message.
 */
function phenixsync_locations_api_request( $s3_index = null ) {
	
	$password = 'LPJph7g3tT263BIfJ1';
	$base_url = 'https://utility24.salonsuitesolutions.com/utilities/phenix_portal_locations_sender.aspx';
	
	// build the API url with the base URL and password.
	$api_url = add_query_arg( 'password', $password, $base_url );
	
	// build the API URL (with the s3_index if provided, or without if not)
	if ( ! empty( $s3_index ) ) {
		$api_url = add_query_arg( 'location_index', $s3_index, $api_url );
	}

	// Set time limit and memory limit
	set_time_limit( 60 ); // Try setting a higher time limit
	@ini_set( 'memory_limit', '256M' ); // Try setting a higher memory limit

	$args = array(
		'timeout'  => 60,
		'blocking' => true,
		'headers'  => array(
			'Cache-Control' => 'no-cache',
			'Pragma' => 'no-cache',
			'Expires' => '0',
		),
		'method'   => 'GET',
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
function phenixsync_locations_json_to_php_array($raw_response) {

	// Check for error responses
	if (strpos($raw_response, "Error:") === 0 || strpos($raw_response, "Request failed") === 0) {
		error_log('API request failed, cannot decode JSON. Response: ' . $raw_response);
		return array('error' => 'API request failed: ' . $raw_response);
	}

	$response_php_array = json_decode($raw_response, true);

	if (json_last_error() !== JSON_ERROR_NONE) {
		$json_error_message = json_last_error_msg();
		error_log('JSON decode error: ' . $json_error_message . ' - Raw response: ' . substr($raw_response, 0, 500) . '...');
		return array('error' => 'Failed to decode JSON: ' . $json_error_message);
	}

	if (!isset($response_php_array['locations']) || !is_array($response_php_array['locations'])) {
		return;
	}

	$locations = $response_php_array['locations'];

	return $locations;
}

/**
 * Retrieve a specific location's data from the transient by S3_index.
 *
 * @param string|int $S3_index The S3_index of the location to find.
 * @return array|null The location data array if found, otherwise null.
 */
function phenixsync_get_location_data_from_transient( $S3_index ) {
	
	$location_array = get_transient( 'phenixsync_locations_data_' . $S3_index );

	if ( ! $location_array || ! is_array( $location_array ) ) {
		error_log( 'Phenix Sync: Locations data transient is not set or not an array when trying to get S3_index: ' . $S3_index );
		return null;
	}

	$location = $location_array[0] ?? null; // Get the first location if it exists
	return $location;
}

/**
 * Create a new post if there isn't one already.
 *
 * @param   string|int $S3_index  The S3_index of the location.
 *
 * @return  int|false The post ID if successful, false otherwise.
 */
function phenixsync_locations_maybe_create_post( $S3_index ) {
	
	$location = phenixsync_get_location_data_from_transient( $S3_index );
	if ( ! $location ) {
		error_log( "Phenix Sync: Could not retrieve location data for S3_index {$S3_index} in phenixsync_locations_maybe_create_post." );
		return false;
	}
	
	// Check if the location already exists
	$existing_post_id = phenixsync_locations_get_post_by_external_id( $S3_index ); // Pass S3_index

	if ( $existing_post_id ) {
		return $existing_post_id;
	}
	
	$location_post_details = array(
		'post_title'  => isset($location['location_name']) ? $location['location_name'] : 'Untitled Location',
		'post_type'   => 'locations',
		'post_status' => 'publish',
		'meta_input'  => array(
			's3_index'     => $S3_index, 
		),
	);
	
	$new_location_post_id = wp_insert_post( $location_post_details );

	if ( is_wp_error( $new_location_post_id ) ) {
		error_log( "Phenix Sync: Error creating post for S3_index {$S3_index}: " . $new_location_post_id->get_error_message() );
		return false;
	}
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

function phenixsync_locations_update_post( $S3_index, $post_id ) {

	$location = phenixsync_get_location_data_from_transient( $S3_index );
		
	if ( ! $location ) {
		error_log( "Phenix Sync: Could not retrieve location data for S3_index {$S3_index} in phenixsync_locations_update_post. Post ID: {$post_id}" );
		return;
	}

	// Update the post
	$location_post_details = array(
		'ID'          => $post_id, 
		'post_title'  => isset($location['location_name']) ? $location['location_name'] : 'Untitled Location',
		'post_type'   => 'locations',
		'post_status' => 'publish'
	);

	$update_result = wp_update_post( $location_post_details, true ); 

	if ( is_wp_error( $update_result ) ) {
		error_log( "Phenix Sync: Error updating post {$post_id} for S3_index {$S3_index}: " . $update_result->get_error_message() );
		// Do not return here, proceed to update meta
	}

	// TODO UPDATE THE POST META (This comment was in the original code)
	// let's just grab all of the meta keys and values from the location array and update the post meta with them. We need to remove the 'suites' key. 
	// We should sanitize all of this data before updating the post meta.
	$location_meta = $location;
	unset( $location_meta['suites'] );
	
	foreach( $location_meta as $key => $value ) {
		$sanitized_key = sanitize_key( $key );
		// Allow null values to clear meta if needed, otherwise sanitize.
		$sanitized_value = ( $value === null || $value === "" ) ? '' : sanitize_text_field( $value );
		update_post_meta( $post_id, $sanitized_key, $sanitized_value );
	}
}

function phenixsync_locations_update_post_taxonomies( $S3_index, $post_id ) {
	
	$location = phenixsync_get_location_data_from_transient( $S3_index );
	if ( ! $location ) {
		error_log( "Phenix Sync: Could not retrieve location data for S3_index {$S3_index} in phenixsync_locations_update_post_taxonomies. Post ID: {$post_id}" );
		return;
	}
	
	// get the 'country' post meta field
	$country = isset($location['country']) ? $location['country'] : '';
	
	// make this all caps
	$country = strtoupper( $country );
	
	if ( $country === 'USA' ) {
		// The country is the USA, so we need to set the state taxonomy
		$state = isset($location['state']) ? $location['state'] : '';
		
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

/**
 * Registers the REST API endpoint for syncing a single location.
 *
 * @return void
 */
function phenixsync_register_single_location_sync_endpoint() {
    register_rest_route( 'phenix-sync/v1', '/location/(?P<S3_index>[a-zA-Z0-9_-]+)', array(
        'methods'             => WP_REST_Server::READABLE, // GET request
        'callback'            => 'phenixsync_rest_sync_single_location_callback',
        'args'                => array(
            'S3_index' => array(
                'validate_callback' => function( $param, $request, $key ) {
                    return ! empty( $param ); // Basic validation: not empty
                },
                'required' => true,
				'description' => __( 'The S3 index of the location to sync.', 'phenix-sync' ),
            ),
        ),
    ) );
}
add_action( 'rest_api_init', 'phenixsync_register_single_location_sync_endpoint' );

/**
 * Callback function for the single location sync REST API endpoint.
 *
 * @param WP_REST_Request $request The REST API request object.
 * @return WP_REST_Response The REST API response.
 */
function phenixsync_rest_sync_single_location_callback( WP_REST_Request $request ) {
    $S3_index = $request->get_param( 'S3_index' );

    if ( empty( $S3_index ) ) {
        return new WP_REST_Response( array( 'message' => 'S3_index parameter is required.' ), 400 );
    }

    // Rate limiting: Check if a request for this S3_index was made in the last 5 seconds
    $transient_key = 'phenixsync_ratelimit_' . sanitize_key( $S3_index );
    if ( get_transient( $transient_key ) ) {
        return new WP_REST_Response( array( 'message' => 'Too many requests. Please wait a moment before trying again.' ), 429 );
    }

    // Set a transient to mark this request, expires in 5 seconds
    set_transient( $transient_key, time(), 5 );

    // Trigger the single location sync
    $sync_result = phenixsync_single_location_sync( $S3_index );

    if ( $sync_result ) {
        return new WP_REST_Response( array( 'message' => "Location sync initiated for S3_index: {$S3_index}." ), 200 );
    } else {
        return new WP_REST_Response( array( 'message' => "Failed to initiate sync for S3_index: {$S3_index}. Check logs for details." ), 500 );
    }
}