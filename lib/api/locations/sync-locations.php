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
	
	// from the locations array, we need to get all of the locations S3_index values, and put those into a new array.
	$locations_s3_indices = array_column( $locations_array, 'S3_index' );
	
	// allow for filtering this list to only include s3_index values that also appear in the setting for the location to sync, if applicable.
	$locations_s3_indices = apply_filters( 'phenixsync_locations_s3_indices', $locations_s3_indices );
		
	// Filter the locations array to only include locations that are in the filtered S3_indices array
	$filtered_locations_array = array_filter( $locations_array, function( $location ) use ( $locations_s3_indices ) {
		return isset( $location['S3_index'] ) && in_array( $location['S3_index'], $locations_s3_indices );
	});
	
	// Ensure all S3_index values are strings for consistent comparison
	$locations_s3_indices = array_map( 'strval', $locations_s3_indices );
	
	error_log( "Phenix Sync: Processing " . count( $locations_s3_indices ) . " S3_indices from API. Sample values: " . implode( ', ', array_slice( $locations_s3_indices, 0, 5 ) ) );
	
	// Check for locations on our site that no longer exist in the API response and cull them.
	phenixsync_remove_deleted_locations( $locations_s3_indices );
	
	// Check for tenants on our site that no longer have corresponding locations and cull them.
	phenixsync_remove_orphaned_tenants( $locations_s3_indices );
	
	// Store the filtered locations array in a transient
	set_transient( 'phenixsync_locations_data', $filtered_locations_array, HOUR_IN_SECONDS );
	
	// Schedule the first batch
	wp_schedule_single_event( time(), 'phenixsync_do_process_batch', array( 0 ) );
}
add_action( 'phenixsync_locations_cron_hook', 'phenixsync_locations_sync_init' );
// add_action( 'wp_footer', 'phenixsync_locations_sync_init' ); // for testing only.

/** 
 * Remove locations that no longer exist in the API response.
 */
function phenixsync_remove_deleted_locations( $locations_s3_indices ) {

	// Validate that we have a proper array of location indices
	if ( ! is_array( $locations_s3_indices ) || empty( $locations_s3_indices ) ) {
		return;
	}
		
	// Get all existing locations first
	$args = array(
		'post_type'      => 'locations',
		'posts_per_page' => -1,
		'post_status'    => 'any',
	);
	
	$existing_posts = get_posts( $args );
	$posts_to_delete = array();
	
	// Filter to find posts that should be deleted
	foreach ( $existing_posts as $post ) {
		$s3_index = get_post_meta( $post->ID, 's3_index', true );
		
		// Delete posts that don't have an s3_index or whose s3_index is not in the API response
		if ( empty( $s3_index ) ) {
			$posts_to_delete[] = $post;
			continue;
		}
		
		$s3_index = strval( $s3_index ); // Ensure consistent string comparison
		
		if ( ! in_array( $s3_index, $locations_s3_indices, true ) ) { // Strict comparison
			$posts_to_delete[] = $post;
		}
	}
	
	error_log( "Phenix Sync: Found " . count( $posts_to_delete ) . " locations to delete (missing s3_index or s3_index not in current API response)" );
	
	foreach ( $posts_to_delete as $post ) {
		$s3_index = get_post_meta( $post->ID, 's3_index', true );
		$s3_index_display = empty( $s3_index ) ? 'MISSING' : $s3_index;
		error_log( "Phenix Sync: Deleting location - ID: {$post->ID}, Title: '{$post->post_title}', S3_index: '{$s3_index_display}' (type: " . gettype( $s3_index ) . ")" );
		wp_delete_post( $post->ID, true );
	}
}

/** 
 * Remove tenants that no longer have a corresponding location in the API response.
 */
function phenixsync_remove_orphaned_tenants( $locations_s3_indices ) {
	
	if ( ! is_array( $locations_s3_indices ) || empty( $locations_s3_indices ) ) {
		return;
	}
	
	// Convert to hash map for O(1) lookup instead of O(n) in_array
	$valid_location_ids = array_flip( array_map( 'strval', $locations_s3_indices ) );
	
	// Use WordPress scheduled events to process this asynchronously
	// This prevents crashes by spreading the work across multiple requests
	
	// Store the valid location IDs in a transient for the background process
	set_transient( 'phenixsync_valid_location_ids', $valid_location_ids, HOUR_IN_SECONDS );
	
	// Schedule the background cleanup to start immediately
	if ( ! wp_next_scheduled( 'phenixsync_cleanup_orphaned_tenants' ) ) {
		wp_schedule_single_event( time(), 'phenixsync_cleanup_orphaned_tenants', array( 0 ) );
	}
	
	error_log( "Phenix Sync: Orphaned tenant cleanup scheduled as background process" );
}

/**
 * Background process to clean up orphaned tenants in very small batches
 */
function phenixsync_cleanup_orphaned_tenants_background( $offset = 0 ) {
	
	$valid_location_ids = get_transient( 'phenixsync_valid_location_ids' );
	if ( ! $valid_location_ids || ! is_array( $valid_location_ids ) ) {
		error_log( "Phenix Sync: Valid location IDs transient not found, stopping cleanup" );
		return;
	}
	
	$batch_size = 50; // Very small batch size
	$max_deletions_per_batch = 5; // Only delete 5 posts maximum per batch
	$max_execution_time = 15; // Even shorter execution time
	$start_time = time();
	
	// Set conservative limits
	@ini_set( 'memory_limit', '256M' );
	@set_time_limit( 30 );
	
	global $wpdb;
	
	// Get a small batch of professionals
	$sql = $wpdb->prepare( "
		SELECT p.ID, pm.meta_value as s3_location_id, p.post_title
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 's3_location_id'
		WHERE p.post_type = 'professionals'
		AND p.post_status IN ('publish', 'draft', 'private', 'trash', 'auto-draft', 'inherit')
		ORDER BY p.ID
		LIMIT %d OFFSET %d
	", $batch_size, $offset );
	
	$results = $wpdb->get_results( $sql );
	
	if ( empty( $results ) ) {
		// We're done, clean up
		delete_transient( 'phenixsync_valid_location_ids' );
		error_log( "Phenix Sync: Orphaned tenant cleanup completed" );
		return;
	}
	
	$deletions_this_batch = 0;
	$processed_this_batch = 0;
	
	foreach ( $results as $row ) {
		// Stop if we've been running too long
		if ( ( time() - $start_time ) > $max_execution_time ) {
			break;
		}
		
		// Stop if we've deleted enough for this batch
		if ( $deletions_this_batch >= $max_deletions_per_batch ) {
			break;
		}
		
		$post_id = $row->ID;
		$s3_location_id = $row->s3_location_id;
		$post_title = $row->post_title;
		$processed_this_batch++;
		
		$should_delete = false;
		$delete_reason = '';
		
		// Delete if no s3_location_id meta
		if ( empty( $s3_location_id ) ) {
			$should_delete = true;
			$delete_reason = 'missing s3_location_id';
		} else {
			// Delete if s3_location_id not in valid locations
			$s3_location_id = strval( $s3_location_id );
			if ( ! isset( $valid_location_ids[ $s3_location_id ] ) ) {
				$should_delete = true;
				$delete_reason = 's3_location_id not in current API response';
			}
		}
		
		if ( $should_delete ) {
			try {
				error_log( "Phenix Sync: Deleting tenant - ID: {$post_id}, Title: '{$post_title}', S3_location_id: '{$s3_location_id}' ({$delete_reason})" );
				
				$delete_result = wp_delete_post( $post_id, true );
				
				if ( $delete_result ) {
					$deletions_this_batch++;
				} else {
					error_log( "Phenix Sync: Failed to delete post ID {$post_id}" );
				}
				
				// Small delay between deletions
				usleep( 100000 ); // 0.1 second
				
			} catch ( Exception $e ) {
				error_log( "Phenix Sync: Exception while deleting post ID {$post_id}: " . $e->getMessage() );
			}
		}
	}
	
	// Calculate next offset
	$next_offset = $offset + $processed_this_batch;
	
	error_log( "Phenix Sync: Background cleanup batch complete. Processed: {$processed_this_batch}, Deleted: {$deletions_this_batch}, Next offset: {$next_offset}" );
	
	// Schedule the next batch with a delay
	if ( count( $results ) === $batch_size || $processed_this_batch > 0 ) {
		wp_schedule_single_event( time() + 5, 'phenixsync_cleanup_orphaned_tenants', array( $next_offset ) );
	} else {
		// We're done
		delete_transient( 'phenixsync_valid_location_ids' );
		error_log( "Phenix Sync: Orphaned tenant cleanup fully completed" );
	}
	
	// Clear cache
	wp_cache_flush();
}
add_action( 'phenixsync_cleanup_orphaned_tenants', 'phenixsync_cleanup_orphaned_tenants_background' );

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
	$skipped = 0;
	$total = count( $locations_array );
	
	// Convert to indexed array to handle non-sequential keys
	$locations_values = array_values( $locations_array );
	
	// Calculate the end position for this batch
	$batch_end = min( $offset + $batch_size, $total );
	
	for ( $i = $offset; $i < $batch_end; $i++ ) {
		// Check if the array index exists before accessing it
		if ( ! isset( $locations_values[$i] ) ) {
			error_log("Phenix Sync: Array index {$i} does not exist in locations array.");
			$skipped++;
			continue;
		}
		
		$location_item = $locations_values[$i]; 

		if ( ! is_array( $location_item ) || ! isset( $location_item['S3_index'] ) ) {
			error_log("Phenix Sync: Invalid location data or missing S3_index at offset {$i} in batch.");
			$skipped++;
			continue; 
		}
		
		$S3_index = $location_item['S3_index'];
		
		$sync_result = phenixsync_single_location_sync( $S3_index );
		
		if ( !$sync_result ) {
			error_log("Phenix Sync: Failed to get or create post_id for S3_index {$S3_index} in batch processing.");
		}
		
		$processed++;
	}
	
	$next_offset = $offset + $batch_size; // Use consistent batch size for offset calculation
	
	error_log("Phenix Sync: Batch complete. Processed: {$processed}, Skipped: {$skipped}, Next offset: {$next_offset}, Total: {$total}");
	
	// Schedule the next batch if there are more locations to process
	if ( $next_offset < $total ) {
		wp_schedule_single_event( time() + 10, 'phenixsync_do_process_batch', array( $next_offset ) );
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
function phenixsync_locations_api_request( $s3_index ) {
	
	$password = phenix_sync_get_api_password();
	$base_url = 'https://utility24.salonsuitesolutions.com/utilities/phenix_portal_locations_sender.aspx';
	
	// build the API url with the base URL and password.
	$api_url = add_query_arg( 'password', $password, $base_url );
	
	// add a query arg with the date and time of the request, with a parameter for unique_string
	$unique_string = date('YmdHis');
	$api_url = add_query_arg( 'unique_string', $unique_string, $api_url );
	
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

	$start_time = microtime(true);
	$response = wp_remote_request( $api_url, $args );
	$end_time = microtime(true);
	$duration = $end_time - $start_time;

    $timed_out = false;
    if ( is_wp_error( $response ) ) {
        $error_message = $response->get_error_message();
        if ( strpos( strtolower( $error_message ), 'timed out' ) !== false ) {
            $timed_out = true;
        }
    }

    // If the request took more than 10 seconds, or if it timed out, send an email alert
    if ( $duration > 10 || $timed_out ) {
        $recipients = [
            'jon@brindledigital.com',
            'tim@salonsuitesolutions.com'
        ];
        $subject = sprintf(
            'Phenix Sync: [locations] API Request Took Too Long (S3_index: %s)',
            !empty($s3_index) ? $s3_index : 'ALL'
        );
        $message = sprintf(
            "The API request to %s took %.2f seconds to complete.",
            $api_url,
            $duration
        );
        if ( $timed_out ) {
            $message .= "\n\nNOTE: The request TIMED OUT.";
        }
        foreach ( $recipients as $recipient ) {
            wp_mail( $recipient, $subject, $message );
        }
    }

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