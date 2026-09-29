<?php

/**
 * Delete legacy raw professional response transients.
 *
 * These responses were written for two hours but were never read. Look up both
 * database-backed transient rows and current location IDs so delete_transient()
 * also clears known copies from a persistent object cache.
 *
 * @return int Number of distinct transient keys targeted for deletion.
 */
function phenixsync_clear_legacy_professionals_response_transients() {
	global $wpdb;

	$transient_prefix       = 'phenixsync_professionals_raw_response_';
	$value_option_prefix    = '_transient_' . $transient_prefix;
	$timeout_option_prefix  = '_transient_timeout_' . $transient_prefix;
	$transient_names        = array();

	$option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name
			FROM {$wpdb->options}
			WHERE option_name LIKE %s
				OR option_name LIKE %s",
			$wpdb->esc_like( $value_option_prefix ) . '%',
			$wpdb->esc_like( $timeout_option_prefix ) . '%'
		)
	);

	foreach ( $option_names as $option_name ) {
		if ( 0 === strpos( $option_name, $timeout_option_prefix ) ) {
			$transient_names[] = substr( $option_name, strlen( '_transient_timeout_' ) );
		} elseif ( 0 === strpos( $option_name, $value_option_prefix ) ) {
			$transient_names[] = substr( $option_name, strlen( '_transient_' ) );
		}
	}

	$location_indices = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT meta_value
			FROM {$wpdb->postmeta}
			WHERE meta_key = %s
				AND meta_value <> ''",
			's3_index'
		)
	);

	foreach ( $location_indices as $location_index ) {
		$transient_names[] = $transient_prefix . (int) $location_index;
	}

	$transient_names = array_unique( array_filter( $transient_names ) );

	foreach ( $transient_names as $transient_name ) {
		delete_transient( $transient_name );
		delete_option( '_transient_' . $transient_name );
		delete_option( '_transient_timeout_' . $transient_name );
	}

	return count( $transient_names );
}

/**
 * Run the legacy transient cleanup once after this code is deployed.
 */
function phenixsync_maybe_clear_legacy_professionals_response_transients() {
	$cleanup_version = '1';

	if ( $cleanup_version === get_option( 'phenixsync_professionals_transient_cleanup_version' ) ) {
		return;
	}

	$cleared_count = phenixsync_clear_legacy_professionals_response_transients();
	update_option( 'phenixsync_professionals_transient_cleanup_version', $cleanup_version, false );

	error_log( "Phenix Sync: Cleared legacy professional response transient storage for {$cleared_count} known keys." );
}
add_action( 'init', 'phenixsync_maybe_clear_legacy_professionals_response_transients', 20 );

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
// add_action( 'wp_footer', 'phenixsync_professionals_test_sync_location' );

/**
 * Remove the obsolete standalone professionals schedule.
 *
 * Professionals now run only after the location queue completes, under the
 * same full-sync lock.
 *
 * @return void
 */
function phenixsync_schedule_professionals_sync() {
	wp_clear_scheduled_hook( 'phenixsync_professionals_cron_hook' );

	if ( phenix_sync_is_enabled() ) {
		return;
	}

	wp_clear_scheduled_hook( 'phenixsync_start_professionals_queue' );
	wp_clear_scheduled_hook( 'phenixsync_process_professionals_queue' );
	wp_clear_scheduled_hook( 'phenixsync_retry_single_professionals_sync' );
	delete_transient( 'phenixsync_professionals_queue' );
}
add_action( 'wp', 'phenixsync_schedule_professionals_sync' );

/**
 * Start the professional stage for a full-sync run.
 *
 * @param string $run_id Full-sync run identifier.
 * @return void
 */
function phenixsync_professionals_manage_sync_process( $run_id ) {
	if ( ! phenix_sync_is_enabled() ) {
		error_log( 'Phenix Sync: Professionals sync skipped (sync disabled).' );
		phenixsync_finish_full_sync_status( $run_id, 'stopped', 'Sync was disabled before the professional queue started.' );
		phenixsync_release_full_sync_lock( $run_id );
		return;
	}

	if ( ! phenixsync_full_sync_lock_matches( $run_id ) ) {
		phenixsync_finish_full_sync_status( $run_id, 'failed', 'The professional stage no longer owned the full-sync lock.' );
		return;
	}

	$status = phenixsync_get_full_sync_status();
	if (
		isset( $status['run_id'], $status['stage'] )
		&& (string) $status['run_id'] === (string) $run_id
		&& 'preparing_professionals' !== (string) $status['stage']
	) {
		return;
	}

	$location_ids = array_values(
		array_unique(
			array_filter( phenixsync_professionals_loop_through_locations_and_get_s3_location_ids() )
		)
	);

	if ( ! phenixsync_full_sync_lock_matches( $run_id ) ) {
		return;
	}

	if ( empty( $location_ids ) ) {
		phenixsync_finish_full_sync_status( $run_id, 'completed' );
		phenixsync_release_full_sync_lock( $run_id );
		error_log( "Phenix Sync: Full sync run {$run_id} finished with no professional locations to process." );
		return;
	}

	set_transient(
		'phenixsync_professionals_queue',
		array(
			'run_id'     => (string) $run_id,
			'ids'        => $location_ids,
			'created_at' => time(),
		),
		2 * DAY_IN_SECONDS
	);

	phenixsync_touch_full_sync_lock( $run_id );
	phenixsync_update_full_sync_status(
		$run_id,
		array(
			'state'            => 'running',
			'stage'            => 'professionals',
			'completed'        => 0,
			'total'            => count( $location_ids ),
			'current_s3_index' => '',
			'retry_attempt'    => 0,
		)
	);
	phenixsync_log_memory_usage(
		'professional queue started',
		array(
			'run_id' => $run_id,
			'total'  => count( $location_ids ),
		)
	);
	$worker_scheduled = phenixsync_dispatch_pipeline_worker(
		time() + PHENIXSYNC_WORKER_DELAY,
		'phenixsync_process_professionals_queue',
		array( 0, $run_id, 0 )
	);

	if ( ! $worker_scheduled ) {
		delete_transient( 'phenixsync_professionals_queue' );
		phenixsync_finish_full_sync_status( $run_id, 'failed', 'The first professional worker could not be scheduled.' );
		phenixsync_release_full_sync_lock( $run_id );
		error_log( "Phenix Sync: The first professional worker could not be scheduled for run {$run_id}; the pipeline was stopped." );
	}
}

/**
 * Prepare the professional stage under the cross-request pipeline lease.
 *
 * @param string $run_id Full-sync run identifier.
 */
function phenixsync_run_professional_stage_worker( $run_id ) {
	phenixsync_execute_claimed_pipeline_worker(
		'phenixsync_start_professionals_queue',
		array( (string) $run_id ),
		'phenixsync_professionals_manage_sync_process'
	);
}
add_action( 'phenixsync_start_professionals_queue', 'phenixsync_run_professional_stage_worker', 10, 1 );

/**
 * Process one location's professionals in each cron request.
 *
 * @param int    $offset        Queue offset.
 * @param string $run_id        Full-sync run identifier.
 * @param int    $retry_attempt Number of retries already attempted.
 * @return void
 */
function phenixsync_process_professionals_queue( $offset, $run_id, $retry_attempt = 0 ) {
	$run_id = (string) $run_id;
	if ( $run_id && ! phenixsync_full_sync_worker_owns_stage( $run_id, 'professionals' ) ) {
		return;
	}

	$queue = get_transient( 'phenixsync_professionals_queue' );

	if (
		! is_array( $queue )
		|| empty( $queue['ids'] )
		|| empty( $queue['run_id'] )
		|| (string) $queue['run_id'] !== (string) $run_id
		|| ! phenixsync_full_sync_lock_matches( $run_id )
	) {
		$queue_run_id = is_array( $queue ) && ! empty( $queue['run_id'] )
			? (string) $queue['run_id']
			: '';

		if (
			phenixsync_full_sync_worker_owns_stage_offset( $run_id, 'professionals', $offset )
			&& ( '' === $queue_run_id || (string) $run_id === $queue_run_id )
		) {
			delete_transient( 'phenixsync_professionals_queue' );
			phenixsync_finish_full_sync_status( $run_id, 'failed', 'The professional queue was missing or invalid.' );
			phenixsync_release_full_sync_lock( $run_id );
		}
		error_log( "Phenix Sync: Professional worker stopped because queue or lock state was invalid for run {$run_id}." );
		return;
	}

	$location_ids = array_values( $queue['ids'] );
	$total        = count( $location_ids );
	$offset       = absint( $offset );
	$retry_attempt = absint( $retry_attempt );
	$status       = phenixsync_get_full_sync_status();

	if (
		isset( $status['run_id'], $status['stage'], $status['completed'] )
		&& (string) $status['run_id'] === (string) $run_id
		&& (
			'professionals' !== (string) $status['stage']
			|| absint( $status['completed'] ) !== $offset
		)
	) {
		return;
	}

	if ( $offset >= $total ) {
		delete_transient( 'phenixsync_professionals_queue' );
		wp_clear_scheduled_hook( 'phenixsync_process_professionals_queue' );
		phenixsync_log_memory_usage(
			'full sync completed',
			array(
				'run_id' => $run_id,
				'total'  => $total,
			)
		);
		phenixsync_update_full_sync_status(
			$run_id,
			array(
				'completed' => $total,
				'total'     => $total,
			)
		);
		phenixsync_finish_full_sync_status( $run_id, 'completed' );
		phenixsync_release_full_sync_lock( $run_id );
		error_log( "Phenix Sync: Full sync run {$run_id} completed after processing {$total} professional locations." );
		return;
	}

	$s3_index = $location_ids[ $offset ];
	phenixsync_touch_full_sync_lock( $run_id );
	phenixsync_update_full_sync_status(
		$run_id,
		array(
			'stage'            => 'professionals',
			'completed'        => $offset,
			'total'            => $total,
			'current_s3_index' => $s3_index,
			'retry_attempt'    => $retry_attempt,
		)
	);
	$result = phenixsync_sync_individual_location_professionals( $s3_index );

	if ( ! phenixsync_full_sync_worker_owns_stage( $run_id, 'professionals' ) ) {
		return;
	}
	$status = phenixsync_get_full_sync_status();
	if (
		isset( $status['run_id'], $status['stage'], $status['completed'] )
		&& (string) $status['run_id'] === (string) $run_id
		&& (
			'professionals' !== (string) $status['stage']
			|| absint( $status['completed'] ) !== $offset
		)
	) {
		return;
	}

	if ( is_wp_error( $result ) || false === $result ) {
		$error_message = is_wp_error( $result ) ? $result->get_error_message() : 'The professional sync returned false.';
		phenixsync_record_full_sync_error(
			$run_id,
			"Professional sync failed for location {$s3_index}: {$error_message}",
			array(
				'current_s3_index' => $s3_index,
				'retry_attempt'    => $retry_attempt,
			)
		);

		if ( $retry_attempt < PHENIXSYNC_MAX_RETRY_ATTEMPTS ) {
			$next_attempt = $retry_attempt + 1;
			phenixsync_update_full_sync_status(
				$run_id,
				array( 'retry_attempt' => $next_attempt )
			);
			phenixsync_log_memory_usage(
				'professional retry scheduled',
				array(
					'run_id'  => $run_id,
					'offset'  => $offset,
					's3_index'=> $s3_index,
					'attempt' => $next_attempt,
				)
			);
			error_log( "Phenix Sync: Professional sync failed for S3_index {$s3_index}; retry {$next_attempt} will run in at least one minute. {$error_message}" );
			$retry_scheduled = phenixsync_schedule_worker_event(
				time() + PHENIXSYNC_RETRY_DELAY,
				'phenixsync_process_professionals_queue',
				array( $offset, $run_id, $next_attempt )
			);

			if ( $retry_scheduled ) {
				return;
			}

			error_log( "Phenix Sync: Professional retry {$next_attempt} for S3_index {$s3_index} could not be scheduled; advancing the queue." );
		}

		error_log( "Phenix Sync: Professional sync failed for S3_index {$s3_index} and will not be retried again; advancing the queue. {$error_message}" );
	}

	$next_offset = $offset + 1;
	phenixsync_update_full_sync_status(
		$run_id,
		array(
			'stage'            => 'professionals',
			'completed'        => $next_offset,
			'total'            => $total,
			'current_s3_index' => '',
			'retry_attempt'    => 0,
		)
	);

	if ( 0 === $next_offset % 25 || $next_offset >= $total ) {
		phenixsync_log_memory_usage(
			'professional queue progress',
			array(
				'run_id'   => $run_id,
				'complete' => $next_offset,
				'total'    => $total,
			)
		);
	}

	$next_scheduled = phenixsync_dispatch_pipeline_worker(
		time() + PHENIXSYNC_WORKER_DELAY,
		'phenixsync_process_professionals_queue',
		array( $next_offset, $run_id, 0 )
	);

	if ( ! $next_scheduled ) {
		delete_transient( 'phenixsync_professionals_queue' );
		phenixsync_finish_full_sync_status( $run_id, 'failed', "Professional worker {$next_offset} could not be scheduled." );
		phenixsync_release_full_sync_lock( $run_id );
		error_log( "Phenix Sync: Professional worker {$next_offset} could not be scheduled; the pipeline was stopped and its lock released." );
	}
}

/**
 * Run one professional-location worker under the cross-request pipeline lease.
 *
 * @param int    $offset        Queue offset.
 * @param string $run_id        Full-sync run identifier.
 * @param int    $retry_attempt Number of retries already attempted.
 */
function phenixsync_run_professionals_pipeline_worker( $offset, $run_id, $retry_attempt = 0 ) {
	phenixsync_execute_claimed_pipeline_worker(
		'phenixsync_process_professionals_queue',
		array( absint( $offset ), (string) $run_id, absint( $retry_attempt ) ),
		'phenixsync_process_professionals_queue'
	);
}
add_action( 'phenixsync_process_professionals_queue', 'phenixsync_run_professionals_pipeline_worker', 10, 3 );

/**
 * Retry a REST- or admin-triggered professional sync.
 *
 * @param string|int $s3_index      Location identifier.
 * @param int        $retry_attempt Current retry attempt.
 * @return void
 */
function phenixsync_retry_single_professionals_sync( $s3_index, $retry_attempt = 1 ) {
	$result = phenixsync_sync_individual_location_professionals( $s3_index );

	if ( ! is_wp_error( $result ) && false !== $result ) {
		return;
	}

	$error_message = is_wp_error( $result ) ? $result->get_error_message() : 'The professional sync returned false.';
	$retry_attempt = absint( $retry_attempt );

	if ( $retry_attempt < PHENIXSYNC_MAX_RETRY_ATTEMPTS ) {
		$next_attempt = $retry_attempt + 1;
		error_log( "Phenix Sync: Standalone professional sync failed for S3_index {$s3_index}; retry {$next_attempt} will run in at least one minute. {$error_message}" );
		phenixsync_schedule_worker_event(
			time() + PHENIXSYNC_RETRY_DELAY,
			'phenixsync_retry_single_professionals_sync',
			array( $s3_index, $next_attempt )
		);
		return;
	}

	error_log( "Phenix Sync: Standalone professional sync failed for S3_index {$s3_index} after all retries. {$error_message}" );
}
add_action( 'phenixsync_retry_single_professionals_sync', 'phenixsync_retry_single_professionals_sync', 10, 2 );

/**
 * Sync an individual location's professionals.
 *
 * @param string $s3_index The S3 index (s3_location_id) of the location.
 * @return bool|WP_Error True on success, WP_Error on failure or if API request fails.
 */
function phenixsync_sync_individual_location_professionals( $s3_index ) {
	if ( ! phenix_sync_is_enabled() ) {
		error_log( 'Phenix Sync: Professional sync skipped (sync disabled).' );
		return false;
	}

	$raw_response = phenixsync_professionals_api_request( $s3_index );
	$response_bytes = is_string( $raw_response ) ? strlen( $raw_response ) : 0;

	if ( $response_bytes >= MB_IN_BYTES ) {
		phenixsync_log_memory_usage(
			'large professional response received',
			array(
				's3_index'      => $s3_index,
				'response_bytes'=> $response_bytes,
			)
		);
	}

	// Check if API request resulted in an error string.
	if ( strpos( $raw_response, "Error:" ) === 0 || strpos( $raw_response, "Request failed with status code:" ) === 0 ) {
		error_log( "Phenix Sync: Professional API request failed for S3_index {$s3_index}. {$raw_response}" );
		return new WP_Error( 'api_request_failed', $raw_response, array( 'status' => 500 ) );
	}

	$php_array = phenixsync_professionals_get_php_array_from_raw_response( $raw_response );

	if ( ! is_array( $php_array ) ) {
		$message = "Invalid or empty professional API response for S3_index {$s3_index}.";
		error_log( "Phenix Sync: {$message}" );
		return new WP_Error( 'invalid_api_response', $message, array( 'status' => 502 ) );
	}

	if ( isset( $php_array['status'] ) ) {
		$status = strtolower( trim( (string) $php_array['status'] ) );
		$message = "Professional API returned status \"{$status}\" for S3_index {$s3_index}; deletion was skipped.";
		error_log( "Phenix Sync: {$message}" );
		return new WP_Error( 'api_status_response', $message, array( 'status' => 502 ) );
	}

	if ( ! phenixsync_is_sequential_array( $php_array ) ) {
		$message = "Unexpected non-list professional API response for S3_index {$s3_index}; deletion was skipped.";
		error_log( "Phenix Sync: {$message}" );
		return new WP_Error( 'unexpected_api_response', $message, array( 'status' => 502 ) );
	}

	$professionals = array();
	foreach ( $php_array as $professional ) {
		if ( is_array( $professional ) && isset( $professional['S3_tenantID'] ) ) {
			$professionals[] = $professional;
		}
	}

	if ( count( $professionals ) >= 100 ) {
		phenixsync_log_memory_usage(
			'large professional list decoded',
			array(
				's3_index'          => $s3_index,
				'professional_count'=> count( $professionals ),
				'response_bytes'    => $response_bytes,
			)
		);
	}

	if ( empty( $professionals ) ) {
		if ( empty( $php_array ) ) {
			error_log( "phenixsync_sync_individual_location_professionals: API returned an authoritative empty professionals list for s3_index {$s3_index}. Removing all associated professionals." );
			phenixsync_remove_all_professionals_for_location( $s3_index );
			return true;
		}

		$message = "Professional API returned records but no valid professionals for S3_index {$s3_index}; deletion was skipped.";
		error_log( "Phenix Sync: {$message}" );
		return new WP_Error( 'invalid_professional_records', $message, array( 'status' => 502 ) );
	}
	
	// Remove professionals that no longer exist in the API response for this location
	phenixsync_remove_deleted_professionals( $professionals, $s3_index );
	
	foreach( $professionals as $professional ) {
		$post_id = phenixsync_professionals_maybe_create_post( $professional );
		
		if ( ! $post_id || is_wp_error( $post_id ) ) {
			// Log error if $post_id is WP_Error or false
			$error_message = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'Post creation/retrieval failed.';
			error_log( "phenixsync_sync_individual_location_professionals: Failed to create or retrieve post for professional with S3_tenantID: " . (isset($professional['S3_tenantID']) ? $professional['S3_tenantID'] : 'N/A') . ". Error: " . $error_message );
			continue; // Skip if post creation failed
		}
		
		phenixsync_professionals_update_post( $professional, $post_id );
	}
	return true;
}

/**
 * Register the REST API endpoint for syncing professionals by s3_location_id.
 */
function phenixsync_register_sync_professionals_by_location_endpoint() {
	register_rest_route( 'phenix-sync/v1', '/professionals/(?P<s3_location_id>[a-zA-Z0-9_-]+)', array(
		'methods'             => 'GET',
		'callback'            => 'phenixsync_rest_sync_professionals_by_location_callback',
		'permission_callback' => '__return_true',
		'args'                => array(
			's3_location_id' => array(
				'validate_callback' => function( $param, $request, $key ) {
					return is_string( $param ) && preg_match( '/^[a-zA-Z0-9_-]+$/', $param );
				},
				'required' => true,
				'description' => 'The S3 Location ID.',
			),
		),
	) );
}
add_action( 'rest_api_init', 'phenixsync_register_sync_professionals_by_location_endpoint' );

/**
 * Callback for the REST API endpoint to sync professionals by s3_location_id.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error The response object or WP_Error on failure.
 */
function phenixsync_rest_sync_professionals_by_location_callback( WP_REST_Request $request ) {
	
	$s3_location_id = $request->get_param( 's3_location_id' );

	if ( ! phenix_sync_is_enabled() ) {
		return new WP_Error(
			'sync_disabled',
			'Sync is disabled in settings.',
			array( 'status' => 403 )
		);
	}

	// Rate Limiting: Check if this s3_location_id has been processed recently
	$rate_limit_transient_key = 'phenixsync_pro_loc_ratelimit_' . sanitize_key( $s3_location_id );
	if ( get_transient( $rate_limit_transient_key ) ) {
		return new WP_Error(
			'too_many_requests',
			'Too many requests for this location. Please try again later.',
			array( 'status' => 429 )
		);
	}

	// Prevent duplicate REST-triggered syncs for ten seconds.
	set_transient( $rate_limit_transient_key, true, 10 );

	// Directly sync professionals for the given s3_location_id.
	$result = phenixsync_sync_individual_location_professionals( $s3_location_id );

	if ( is_wp_error( $result ) ) {
		phenixsync_schedule_worker_event(
			time() + PHENIXSYNC_RETRY_DELAY,
			'phenixsync_retry_single_professionals_sync',
			array( $s3_location_id, 1 )
		);
		error_log( "Phenix Sync: REST professional sync failed for S3_index {$s3_location_id}; retry 1 will run in at least one minute. " . $result->get_error_message() );

		$error_data = $result->get_error_data();
		$status = isset( $error_data['status'] ) ? $error_data['status'] : 500;
		return new WP_REST_Response( array(
			'success' => false,
			'message' => $result->get_error_message(),
			'code'    => $result->get_error_code(),
		), $status );
	}

	if ( $result === true ) {
		$message = 'Successfully initiated sync for professionals at s3_location_id: ' . esc_html( $s3_location_id ) . '.';
		return new WP_REST_Response( array(
			'success' => true,
			'message' => $message,
		), 200 );
	}

	return new WP_REST_Response( array(
		'success' => false,
		'message' => 'An unknown error occurred during the sync process for s3_location_id: ' . esc_html( $s3_location_id ),
	), 500 );
}

/**
 * Resolve the complete desired services assignment for a professional.
 *
 * @param array $professional Professional API record.
 * @return int[]|null Null when the response omitted a usable service list.
 */
function phenixsync_professionals_get_service_term_ids( $professional ) {
	if ( ! is_array( $professional ) || ! isset( $professional['standard_services'] ) ) {
		return null;
	}

	$standard_services = $professional['standard_services'];

	if ( ! is_array( $standard_services ) ) {
		return null;
	}

	$service_term_ids = array();

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

		$service_term_id = is_array( $service_term ) ? $service_term['term_id'] : $service_term;
		$service_term_ids[] = (int) $service_term_id;
	}

	$service_term_ids = array_values( array_unique( $service_term_ids ) );
	sort( $service_term_ids, SORT_NUMERIC );
	return $service_term_ids;
}

function phenixsync_professionals_update_post_taxonomies( $professional, $post_id ) {
	$service_term_ids = phenixsync_professionals_get_service_term_ids( $professional );

	if ( null === $service_term_ids ) {
		return;
	}

	// Replace the complete assignment in one operation instead of reindexing
	// once for every removed and added service.
	wp_set_post_terms( $post_id, $service_term_ids, 'services', false );
}

/**
 * Loop through all of the locations and get the s3_index field from each one.
 *
 * @return  array an array of the s3_index fields from the locations CPT (just those values).
 */
function phenixsync_professionals_loop_through_locations_and_get_s3_location_ids() {
	global $wpdb;

	return $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT pm.meta_value
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_type = %s
				AND p.post_status = %s
				AND pm.meta_key = %s
				AND pm.meta_value <> ''
			ORDER BY p.ID",
			'locations',
			'publish',
			's3_index'
		)
	);
}

/**
 * Function to make an API request for professionals with a custom timeout and POST data.
 *
 * @return string API response or error message.
 */
function phenixsync_professionals_api_request( $s3_index ) {
	$api_url = 'https://admin.ginasplatform.com/utilities/phenix_portal_sender.aspx';
	
	// add a query arg with the date and time of the request, with a parameter for unique_string
	$unique_string = date('YmdHis');
	$api_url = add_query_arg( 'unique_string', $unique_string, $api_url );

	set_time_limit( 60 );

	$password = phenix_sync_get_api_password();

	$args = array(
		'timeout'  => 60,
		'blocking' => true,
		'headers'  => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
		'method'   => 'POST',
		'body'     => array(
			'password' => $password,
			'location_index' => $s3_index,
		),
	);

	$response = wp_remote_request( $api_url, $args );

	if ( is_wp_error( $response ) ) {
		$error_message = $response->get_error_message();
		$result        = "Error: " . esc_html( $error_message );
		$is_timeout    = false !== stripos( $error_message, 'timed out' )
			|| false !== stripos( $error_message, 'timeout' );
		$error_type    = $is_timeout ? 'timeout' : 'request failure';
		error_log( "Phenix Sync: Professional API {$error_type} for S3_index {$s3_index}: {$error_message}" );
	} else {
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 === (int) $response_code ) {
			$result = $response_body; // Store raw response
		} else {
			$result = "Request failed with status code: " . esc_html( (string) $response_code );
			$error_type = in_array( (int) $response_code, array( 408, 504, 524 ), true )
				? 'timeout'
				: 'HTTP failure';
			error_log( "Phenix Sync: Professional API {$error_type} for S3_index {$s3_index}: {$result}" );

		}
	}
	
	// save some details about how this sync went.
	phenix_save_pros_sync_details_to_location( $response, $s3_index, $result );

	return $result;
}

function phenixsync_process_gallery_data( $gallery_data ) {
	if ( empty( $gallery_data ) || ! is_array( $gallery_data ) ) {
		return array();
	}
	
	$processed_gallery = array();
	
	foreach ( $gallery_data as $item ) {
		$url = '';
		
		// Handle different possible structures
		if ( is_string( $item ) ) {
			$url = $item;
		} elseif ( is_array( $item ) && isset( $item['image'] ) ) {
			// New structure: $professional['gallery'][0]['image'] = "theimageurlstring.jpg"
			$url = $item['image'];
		} elseif ( is_array( $item ) && isset( $item['url'] ) ) {
			$url = $item['url'];
		} elseif ( is_array( $item ) && isset( $item[0] ) && is_string( $item[0] ) ) {
			$url = $item[0];
		}
		
		// Sanitize and validate the URL
		if ( ! empty( $url ) && is_string( $url ) ) {
			$processed_gallery[] = esc_url_raw( $url );
		}
	}
	
	return $processed_gallery;
}

function phenix_save_pros_sync_details_to_location( $response, $s3_index, $raw_response = '' ) {
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
	$response_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
	$response_body = is_string( $raw_response ) ? $raw_response : '';

	if ( '' === $response_body && ! is_wp_error( $response ) ) {
		$response_body = wp_remote_retrieve_body( $response );
	}

	// get the response time from our perspective
	$response_time = current_time( 'mysql' );
	// get the response size
	$response_size = is_wp_error( $response ) ? '' : wp_remote_retrieve_header( $response, 'Content-Length' );
	if ( empty( $response_size ) && is_string( $response_body ) ) {
		$response_size = strlen( $response_body );
	}
	// get the response date
	$response_date = is_wp_error( $response ) ? '' : wp_remote_retrieve_header( $response, 'Date' );

	$details = array(
		'response_code'        => $response_code,
		'response_time'        => $response_time,
		'response_time_gmt'    => current_time( 'mysql', true ),
		'response_timestamp'   => time(),
		'response_size'        => $response_size,
		'response_date'        => $response_date,
	);

	if ( is_wp_error( $response ) ) {
		$details['request_error'] = $response->get_error_message();
	}

	$details = array_merge( $details, phenixsync_get_professionals_response_debug_summary( $response_body ) );

	phenixsync_save_sync_debug_details( $location_post_id, 'professionals', $details );
}

/**
 * Build a compact debug summary for a professionals API response.
 *
 * @param string $raw_response The raw API response body.
 * @return array
 */
function phenixsync_get_professionals_response_debug_summary( $raw_response ) {
	$raw_response = is_string( $raw_response ) ? $raw_response : '';
	$trimmed      = trim( $raw_response );

	$summary = array(
		'response_shape'      => 'empty_string',
		'raw_response_length' => strlen( $raw_response ),
		'list_count'          => 0,
		'professional_count'  => 0,
		'is_empty_list'       => false,
		'api_status'          => '',
		'top_level_keys'      => array(),
		'json_error'          => '',
	);
	$summary = array_merge( $summary, phenixsync_build_compressed_debug_response( $raw_response ) );

	if ( '' === $trimmed ) {
		return $summary;
	}

	if ( strpos( $raw_response, 'Error:' ) === 0 || strpos( $raw_response, 'Request failed with status code:' ) === 0 ) {
		$summary['response_shape'] = 'error_string';
		return $summary;
	}

	if ( '[' === substr( $trimmed, 0, 1 ) ) {
		$summary['response_shape'] = 'json_list';
		$summary['professional_count'] = substr_count( $raw_response, '"S3_tenantID"' );
		$summary['list_count']         = $summary['professional_count'];
		$summary['is_empty_list']      = '[]' === preg_replace( '/\s+/', '', $trimmed );
		return $summary;
	}

	if ( '{' === substr( $trimmed, 0, 1 ) ) {
		$summary['response_shape'] = 'json_object';

		if ( strlen( $raw_response ) <= 32768 ) {
			$decoded = json_decode( $raw_response, true );
			if ( is_array( $decoded ) ) {
				$summary['top_level_keys'] = array_slice( array_keys( $decoded ), 0, 20 );
				$summary['api_status'] = isset( $decoded['status'] ) ? (string) $decoded['status'] : '';
			}
		}
		return $summary;
	}

	$summary['response_shape'] = 'unknown';
	return $summary;
}

function phenixsync_professionals_get_php_array_from_raw_response( $raw_response ) {
	$php_array = json_decode( $raw_response, true );

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		error_log( 'JSON decode error: ' . json_last_error_msg() );
		return null;
	}

	return $php_array;
	
}

/**
 * Determine whether an array uses sequential numeric keys starting at zero.
 *
 * @param mixed $array The value to inspect.
 * @return bool
 */
function phenixsync_is_sequential_array( $array ) {
	if ( ! is_array( $array ) ) {
		return false;
	}

	if ( empty( $array ) ) {
		return true;
	}

	$expected_keys = range( 0, count( $array ) - 1 );

	return array_keys( $array ) === $expected_keys;
}

function phenixsync_professionals_maybe_create_post( $professional ) {
	if ( ! is_array( $professional ) || empty( $professional['S3_tenantID'] ) ) {
		return new WP_Error( 'missing_tenant_id', 'Professional record is missing S3_tenantID.' );
	}

	// Check if the professional already exists
	$existing_post_id = phenixsync_professionals_get_post_by_external_id( $professional['S3_tenantID'] );

	if ( $existing_post_id ) {
		return $existing_post_id;
	}
	
	$professional_post_details = array(
		'post_title'  => isset( $professional['salon_name'] ) ? sanitize_text_field( $professional['salon_name'] ) : '',
		'post_type'   => 'professionals',
		'post_status' => 'publish',
		'meta_input'  => array(
			's3_tenant_id'     => $professional['S3_tenantID'],
		),
	);
	
	$new_professional_post_id = wp_insert_post( $professional_post_details );
	return $new_professional_post_id;
}

function phenixsync_clean_booking_link( $value ) {
	$value = trim( (string) $value );
	return preg_match( '~^(?:https?://)?none/?$~i', $value ) ? '' : esc_url_raw( $value );
}

/**
 * Update the post
 *
 * @param   array  $professional  	[$professional description]
 * @param   [type]  $post_id       [$post_id description]
 *
 * @return bool True when the post changed, false when no write was needed.
 */
function phenixsync_professionals_update_post( $professional, $post_id ) {
	$suites_string = '';
	$suites = isset( $professional['suites'] ) ? $professional['suites'] : array();
	if ( $suites && is_array( $suites ) ) {
		$suite_names = array();
		
		foreach ( $suites as $suite ) {
			if ( is_array( $suite ) && isset( $suite['suite_name'] ) ) {
				$suite_names[] = $suite['suite_name'];
			}
		}
		
		$suites_string = implode( ', ', $suite_names );
	}
	
	$location_id           = isset( $professional['S3_locationID'] ) ? $professional['S3_locationID'] : 0;
	$corresponding_location = $location_id
		? phenixsync_locations_get_post_by_external_id( $location_id )
		: false;
	
	// get the address1, address2, city, state, zip, and country from the location post
	$address1 = get_post_meta( $corresponding_location, 'address1', true );
	$address2 = get_post_meta( $corresponding_location, 'address2', true );
	$city = get_post_meta( $corresponding_location, 'city', true );
	$state = get_post_meta( $corresponding_location, 'state', true );
	$zip = get_post_meta( $corresponding_location, 'zip', true );
	$country = get_post_meta( $corresponding_location, 'country', true );
	$latitude = get_post_meta( $corresponding_location, 'latitude', true );
	$longitude = get_post_meta( $corresponding_location, 'longitude', true );
	
	$details_we_want = array(
		's3_location_id' => (int) $location_id,
		's3_tenant_id'   => isset( $professional['S3_tenantID'] ) ? (int) $professional['S3_tenantID'] : 0,
		'suites'         => sanitize_text_field( $suites_string ),
		'name'           => sanitize_text_field( isset( $professional['name'] ) ? $professional['name'] : '' ),
		'email'          => sanitize_email( isset( $professional['email'] ) ? $professional['email'] : '' ),
		'phone'          => sanitize_text_field( isset( $professional['phone'] ) ? $professional['phone'] : '' ),
		'profile_image'  => esc_url_raw( isset( $professional['profile_image'] ) ? $professional['profile_image'] : '' ),
		'instagram'      => esc_url_raw( isset( $professional['instagram'] ) ? $professional['instagram'] : '' ),
		'facebook'       => esc_url_raw( isset( $professional['facebook'] ) ? $professional['facebook'] : '' ),
		'x'              => sanitize_text_field( isset( $professional['x'] ) ? $professional['x'] : '' ),
		'website'        => esc_url_raw( isset( $professional['website'] ) ? $professional['website'] : '' ),
		'booking_link'   => phenixsync_clean_booking_link( isset( $professional['booking_link'] ) ? $professional['booking_link'] : '' ),
		'photo'          => esc_url_raw( isset( $professional['photo'] ) ? $professional['photo'] : '' ),
		'bio'            => sanitize_textarea_field( isset( $professional['bio'] ) ? $professional['bio'] : '' ),
		'gallery'        => phenixsync_process_gallery_data( isset( $professional['gallery'] ) ? $professional['gallery'] : array() ),
		'location_name'  => sanitize_text_field( isset( $professional['location_name'] ) ? $professional['location_name'] : '' ),
		'address1'       => sanitize_text_field( $address1 ),
		'address2'       => sanitize_text_field( $address2 ),
		'city'           => sanitize_text_field( $city ),
		'state'          => sanitize_text_field( $state ),
		'zip'            => sanitize_text_field( $zip ),
		'country'        => sanitize_text_field( $country ),
		'latitude'       => sanitize_text_field( $latitude ),
		'longitude'      => sanitize_text_field( $longitude ),
	);

	$post_title = sanitize_text_field( isset( $professional['salon_name'] ) ? $professional['salon_name'] : '' );
	$post_title = wp_strip_all_tags( $post_title );
	$post_title = substr( $post_title, 0, 100 );
	$post       = get_post( $post_id );
	$has_change = ! $post
		|| 'publish' !== $post->post_status
		|| $post_title !== $post->post_title;

	foreach ( $details_we_want as $key => $value ) {
		if ( ! phenixsync_meta_values_match( get_post_meta( $post_id, $key, true ), $value ) ) {
			$has_change = true;
			break;
		}
	}

	$desired_service_term_ids = phenixsync_professionals_get_service_term_ids( $professional );
	$service_terms_changed    = false;

	if ( null !== $desired_service_term_ids ) {
		$current_service_term_ids = wp_get_object_terms(
			$post_id,
			'services',
			array( 'fields' => 'ids' )
		);
		$current_service_term_ids = is_wp_error( $current_service_term_ids )
			? array()
			: array_map( 'intval', $current_service_term_ids );
		sort( $current_service_term_ids, SORT_NUMERIC );
		$service_terms_changed = $current_service_term_ids !== $desired_service_term_ids;
		$has_change            = $has_change || $service_terms_changed;
	}

	if ( ! $has_change ) {
		$tenant_id = isset( $professional['S3_tenantID'] ) ? $professional['S3_tenantID'] : 'unknown';
		error_log( "Phenix Sync: Professional {$tenant_id} is unchanged; its post and search indexes were not updated." );
		return false;
	}

	foreach ( $details_we_want as $key => $value ) {
		if ( ! phenixsync_meta_values_match( get_post_meta( $post_id, $key, true ), $value ) ) {
			update_post_meta( $post_id, $key, $value );
		}
	}
	update_post_meta( $post_id, 'updated', current_time( 'Y-m-d H:i:s' ) );

	if ( $service_terms_changed ) {
		add_filter( 'facetwp_indexer_is_enabled', '__return_false', PHP_INT_MAX );
		try {
			wp_set_post_terms( $post_id, $desired_service_term_ids, 'services', false );
		} finally {
			remove_filter( 'facetwp_indexer_is_enabled', '__return_false', PHP_INT_MAX );
		}
	}

	// One final save lets search plugins see the complete, changed record.
	wp_update_post( array(
		'ID'         => $post_id,
		'post_title' => $post_title,
		'post_status'=> 'publish',
	) );
	return true;
}

function phenixsync_professionals_get_post_by_external_id( $external_id ) {
	$args = array(
		'post_type'      => 'professionals',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'meta_key'       => 's3_tenant_id',
		'meta_value'     => $external_id,
		'no_found_rows'  => true,
	);

	$post_ids = get_posts( $args );
	
	// if there's more than one post, we need to delete the duplicates.
	if ( count( $post_ids ) > 1 ) {
		foreach ( array_slice( $post_ids, 1 ) as $duplicate_post_id ) {
			wp_delete_post( $duplicate_post_id, true );
			error_log( "Phenix Sync: Deleted duplicate professional post {$duplicate_post_id} for S3_tenantID {$external_id}." );
		}
	}
	// if there's no post, return false.
	if ( empty( $post_ids ) ) {
		return false;
	}
	
	return (int) $post_ids[0];
}

/**
 * Remove professionals (tenants) that no longer exist in the API response for a specific location.
 *
 * @param array $professionals_array Array of professional data from the API.
 * @param string|int $s3_location_id The S3 location ID to check professionals for.
 * @return void
 */
function phenixsync_remove_deleted_professionals( $professionals_array, $s3_location_id ) {
	
	if ( ! is_array( $professionals_array ) || empty( $professionals_array ) ) {
		return;
	}
	
	// If the professionals array has less than 1 item, we don't need to do anything.
	// This is to avoid accidentally deleting all professionals if API returns empty or invalid data.
	if ( count( $professionals_array ) < 1 ) {
		return;
	}
	
	// Step 1: Get an array of the possible values for s3_tenant_id from the API information
	$api_tenant_ids = array();
	foreach ( $professionals_array as $professional ) {
		if ( isset( $professional['S3_tenantID'] ) ) {
			$api_tenant_ids[] = (string) $professional['S3_tenantID'];
		}
	}
	
	if ( empty( $api_tenant_ids ) ) {
		return;
	}
	
	// Step 2: Query WordPress for professionals with the s3_location_id that we're syncing 
	// that ALSO have a s3_tenant_id from the array in step 1
	$args_valid = array(
		'post_type'      => 'professionals',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids', // Only get post IDs for performance
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'   => 's3_location_id',
				'value' => $s3_location_id,
			),
			array(
				'key'     => 's3_tenant_id',
				'value'   => $api_tenant_ids,
				'compare' => 'IN',
			),
		),
	);
	
	$valid_professional_ids = get_posts( $args_valid );
	
	// Step 3: Run a new WordPress query for professionals with the correct s3_location_id 
	// that are NOT in the list of post ids generated in step 2
	$args_to_delete = array(
		'post_type'      => 'professionals',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids', // Only get post IDs for performance
		'meta_query'     => array(
			array(
				'key'   => 's3_location_id',
				'value' => $s3_location_id,
			),
		),
	);
	
	// If we have valid professional IDs, exclude them from the deletion query
	if ( ! empty( $valid_professional_ids ) ) {
		$args_to_delete['post__not_in'] = $valid_professional_ids;
	}
	
	$professionals_to_delete = get_posts( $args_to_delete );
	
	// Step 4: Delete all of the professionals found in step 3
	foreach ( $professionals_to_delete as $post_id ) {
		$tenant_id = get_post_meta( $post_id, 's3_tenant_id', true );
		wp_delete_post( $post_id, true ); // true = force delete, skip trash
		error_log( "phenixsync_remove_deleted_professionals: Deleted professional post ID {$post_id} with s3_tenant_id {$tenant_id} for s3_location_id {$s3_location_id}" );
	}
	
	if ( ! empty( $professionals_to_delete ) ) {
		$deleted_count = count( $professionals_to_delete );
		error_log( "phenixsync_remove_deleted_professionals: Deleted {$deleted_count} professionals for s3_location_id {$s3_location_id}" );
	}
}

/**
 * Remove all professionals associated with a specific location.
 *
 * @param string|int $s3_location_id The S3 location ID to remove professionals for.
 * @return void
 */
function phenixsync_remove_all_professionals_for_location( $s3_location_id ) {
	$args = array(
		'post_type'      => 'professionals',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'   => 's3_location_id',
				'value' => $s3_location_id,
			),
		),
	);

	$professionals_to_delete = get_posts( $args );

	foreach ( $professionals_to_delete as $post_id ) {
		$tenant_id = get_post_meta( $post_id, 's3_tenant_id', true );
		wp_delete_post( $post_id, true );
		error_log( "phenixsync_remove_all_professionals_for_location: Deleted professional post ID {$post_id} with s3_tenant_id {$tenant_id} for s3_location_id {$s3_location_id}" );
	}

	$deleted_count = count( $professionals_to_delete );
	error_log( "phenixsync_remove_all_professionals_for_location: Deleted {$deleted_count} professionals for s3_location_id {$s3_location_id}" );
}

/**
 * AJAX handler for syncing individual professional
 */
function phenixsync_ajax_sync_professional() {
	// Check nonce for security
	if ( ! wp_verify_nonce( $_POST['nonce'], 'phenixsync_sync_nonce' ) ) {
		wp_die( 'Security check failed' );
	}
	
	// Check user capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions' );
	}

	if ( ! phenix_sync_is_enabled() ) {
		wp_send_json_error( 'Sync is disabled in settings.' );
	}
	
	$post_id = intval( $_POST['post_id'] );
	$location_id = sanitize_text_field( $_POST['location_id'] );
	
	// Run the sync
	$result = phenixsync_sync_individual_location_professionals( $location_id );
	
	if ( is_wp_error( $result ) || false === $result ) {
		$error_message = is_wp_error( $result ) ? $result->get_error_message() : 'Professional sync failed.';
		phenixsync_schedule_worker_event(
			time() + PHENIXSYNC_RETRY_DELAY,
			'phenixsync_retry_single_professionals_sync',
			array( $location_id, 1 )
		);
		error_log( "Phenix Sync: Admin professional sync failed for S3_index {$location_id}; retry 1 will run in at least one minute. {$error_message}" );
		wp_send_json_error( $error_message );
	} else {
		wp_send_json_success( 'Professional synced successfully' );
	}
}
add_action( 'wp_ajax_phenixsync_sync_professional', 'phenixsync_ajax_sync_professional' );

/**
 * AJAX handler for syncing individual location
 */
function phenixsync_ajax_sync_location() {
	// Check nonce for security
	if ( ! wp_verify_nonce( $_POST['nonce'], 'phenixsync_sync_nonce' ) ) {
		wp_die( 'Security check failed' );
	}
	
	// Check user capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions' );
	}

	if ( ! phenix_sync_is_enabled() ) {
		wp_send_json_error( 'Sync is disabled in settings.' );
	}
	
	$post_id = intval( $_POST['post_id'] );
	$s3_index = sanitize_text_field( $_POST['s3_index'] );
	
	// Use the location-specific sync function
	$result = phenixsync_single_location_sync( $s3_index );
	
	if ( ! $result ) {
		phenixsync_schedule_worker_event(
			time() + PHENIXSYNC_RETRY_DELAY,
			'phenixsync_retry_single_location_sync',
			array( $s3_index, 1 )
		);
		error_log( "Phenix Sync: Admin location sync failed for S3_index {$s3_index}; retry 1 will run in at least one minute." );
		wp_send_json_error( 'Location sync failed' );
	} else {
		wp_send_json_success( 'Location synced successfully' );
	}
}
add_action( 'wp_ajax_phenixsync_sync_location', 'phenixsync_ajax_sync_location' );
