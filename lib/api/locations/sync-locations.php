<?php

/**
 * Delete legacy individual-location data transients.
 *
 * The sync now passes an individual response directly between functions, so
 * these hour-long payload copies are no longer needed.
 *
 * @return int Number of distinct transient keys targeted for deletion.
 */
function phenixsync_clear_legacy_individual_location_transients() {
	global $wpdb;

	$transient_prefix      = 'phenixsync_locations_data_';
	$value_option_prefix   = '_transient_' . $transient_prefix;
	$timeout_option_prefix = '_transient_timeout_' . $transient_prefix;
	$transient_names       = array();

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
		$transient_names[] = $transient_prefix . $location_index;
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
 * Run the individual-location transient cleanup once after deployment.
 */
function phenixsync_maybe_clear_legacy_individual_location_transients() {
	$cleanup_version = '1';

	if ( $cleanup_version === get_option( 'phenixsync_location_transient_cleanup_version' ) ) {
		return;
	}

	$cleared_count = phenixsync_clear_legacy_individual_location_transients();
	update_option( 'phenixsync_location_transient_cleanup_version', $cleanup_version, false );

	error_log( "Phenix Sync: Cleared legacy individual-location transient storage for {$cleared_count} known keys." );
}
add_action( 'init', 'phenixsync_maybe_clear_legacy_individual_location_transients', 20 );

/**
 * Schedule the locations sync process.
 *
 * @return void
 */
function phenixsync_schedule_locations_sync() {
	if ( ! phenix_sync_is_enabled() ) {
		wp_clear_scheduled_hook( 'phenixsync_locations_cron_hook' );
		wp_clear_scheduled_hook( 'phenixsync_do_process_batch' );
		return;
	}

	if ( ! wp_next_scheduled( 'phenixsync_locations_cron_hook' ) ) {
		wp_schedule_event( time(), 'daily', 'phenixsync_locations_cron_hook' );
	}
}
add_action( 'wp', 'phenixsync_schedule_locations_sync' );

/**
 * Initialize the sync process by fetching data and scheduling batch processing.
 *
 * @param string $run_id        Existing run ID when retrying initialization.
 * @param int    $retry_attempt Number of initialization retries already attempted.
 * @return string|false Run ID on success, false when skipped or failed.
 */
function phenixsync_locations_sync_init( $run_id = '', $retry_attempt = 0 ) {
	if ( ! phenix_sync_is_enabled() ) {
		error_log( 'Phenix Sync: Locations sync init skipped (sync disabled).' );
		return false;
	}

	$run_id = phenixsync_acquire_full_sync_lock( $run_id );

	if ( ! $run_id ) {
		error_log( 'Phenix Sync: Locations sync init skipped because another full sync is active.' );
		return false;
	}

	phenixsync_update_full_sync_status(
		$run_id,
		array(
			'state'         => 'running',
			'stage'         => 'initializing_locations',
			'retry_attempt' => absint( $retry_attempt ),
		)
	);
	phenixsync_log_memory_usage( 'location pipeline initialization', array( 'run_id' => $run_id ) );

	$raw_response = phenixsync_locations_api_request( null );
	phenixsync_log_memory_usage(
		'location list response received',
		array(
			'run_id'         => $run_id,
			'response_bytes' => is_string( $raw_response ) ? strlen( $raw_response ) : 0,
		)
	);
	$locations_array = phenixsync_locations_json_to_php_array( $raw_response );
	phenixsync_log_memory_usage(
		'location list decoded',
		array(
			'run_id'        => $run_id,
			'location_count'=> is_array( $locations_array ) ? count( $locations_array ) : 0,
		)
	);

	if ( ! phenixsync_full_sync_lock_matches( $run_id ) ) {
		return false;
	}

	if ( empty( $locations_array ) || ! is_array( $locations_array ) ) {
		if ( $retry_attempt < PHENIXSYNC_MAX_RETRY_ATTEMPTS ) {
			$next_attempt = $retry_attempt + 1;
			$scheduled = phenixsync_schedule_worker_event(
				time() + PHENIXSYNC_RETRY_DELAY,
				'phenixsync_retry_locations_sync_init',
				array( $run_id, $next_attempt )
			);

				if ( $scheduled ) {
					phenixsync_record_full_sync_error(
						$run_id,
						'The location list response was empty or invalid.',
						array( 'retry_attempt' => $next_attempt )
					);
					error_log( "Phenix Sync: Locations sync init received an empty or invalid response. Retry {$next_attempt} scheduled in at least one minute." );
				} else {
					phenixsync_record_full_sync_error( $run_id, 'The location list response was invalid and its retry could not be scheduled.' );
					phenixsync_finish_full_sync_status( $run_id, 'failed', 'The location initialization retry could not be scheduled.' );
					phenixsync_release_full_sync_lock( $run_id );
					error_log( 'Phenix Sync: Locations sync init failed and its retry could not be scheduled; the pipeline lock was released.' );
				}
			} else {
				phenixsync_record_full_sync_error( $run_id, 'The location list response was empty or invalid after all retries.' );
				phenixsync_finish_full_sync_status( $run_id, 'failed', 'Location initialization failed after all retries.' );
				phenixsync_release_full_sync_lock( $run_id );
				error_log( 'Phenix Sync: Locations sync init failed after all retry attempts; the pipeline lock was released.' );
		}

		return false;
	}
	
	// from the locations array, we need to get all of the locations S3_index values, and put those into a new array.
	$api_location_indices = array_column( $locations_array, 'S3_index' );
	
	// allow for filtering this list to only include s3_index values that also appear in the setting for the location to sync, if applicable.
	$locations_s3_indices = apply_filters( 'phenixsync_locations_s3_indices', $api_location_indices );
		
	if ( ! is_array( $locations_s3_indices ) ) {
		error_log( 'Phenix Sync: Filtered locations S3 index list is invalid. Skipping.' );
		phenixsync_finish_full_sync_status( $run_id, 'failed', 'The filtered location list was invalid.' );
		phenixsync_release_full_sync_lock( $run_id );
		return false;
	}

	// Keep only a compact, unique list of IDs for later batch requests.
	$locations_s3_indices = array_values(
		array_unique(
			array_filter(
				array_map( 'strval', $locations_s3_indices ),
				'strlen'
			)
		)
	);

	// Preserve the API response order and exclude IDs not present in the response.
	$location_queue_ids = array_values(
		array_filter(
			array_map( 'strval', $api_location_indices ),
			function ( $location_index ) use ( $locations_s3_indices ) {
				return in_array( $location_index, $locations_s3_indices, true );
			}
		)
	);

	// The complete records are not used again; every location is fetched by ID.
	unset( $raw_response, $locations_array, $api_location_indices );

	error_log( "Phenix Sync: Processing " . count( $location_queue_ids ) . " S3_indices from API. Sample values: " . implode( ', ', array_slice( $location_queue_ids, 0, 5 ) ) );
	
	// Check for locations on our site that no longer exist in the API response and cull them.
	phenixsync_remove_deleted_locations( $locations_s3_indices );
	
	// Check for tenants on our site that no longer have corresponding locations and cull them.
	phenixsync_remove_orphaned_tenants( $locations_s3_indices );

	if ( empty( $location_queue_ids ) ) {
		phenixsync_finish_full_sync_status( $run_id, 'failed', 'The filtered location queue was empty.' );
		phenixsync_release_full_sync_lock( $run_id );
		error_log( 'Phenix Sync: The filtered location queue is empty; the sequential pipeline was stopped.' );
		return false;
	}

	// Store only location IDs plus a run identifier for the worker queue.
	set_transient(
		'phenixsync_locations_data',
		array(
			'run_id'     => $run_id,
			'ids'        => $location_queue_ids,
			'created_at' => time(),
		),
		2 * DAY_IN_SECONDS
	);
	phenixsync_update_full_sync_status(
		$run_id,
		array(
			'state'            => 'running',
			'stage'            => 'locations',
			'completed'        => 0,
			'total'            => count( $location_queue_ids ),
			'current_s3_index' => '',
			'retry_attempt'    => 0,
		)
	);
	
	$worker_scheduled = phenixsync_dispatch_pipeline_worker(
		time(),
		'phenixsync_do_process_batch',
		array( 0, $run_id, 0 )
	);

	if ( ! $worker_scheduled ) {
		delete_transient( 'phenixsync_locations_data' );
		phenixsync_finish_full_sync_status( $run_id, 'failed', 'The first location worker could not be scheduled.' );
		phenixsync_release_full_sync_lock( $run_id );
		error_log( 'Phenix Sync: The first location worker could not be scheduled; the pipeline was stopped.' );
		return false;
	}

	return $run_id;
}
add_action( 'phenixsync_locations_cron_hook', 'phenixsync_locations_sync_init' );
add_action( 'phenixsync_retry_locations_sync_init', 'phenixsync_locations_sync_init', 10, 2 );
// add_action( 'wp_footer', 'phenixsync_locations_sync_init' ); // for testing only.

/** 
 * Remove locations that no longer exist in the API response.
 */
function phenixsync_remove_deleted_locations( $locations_s3_indices ) {

	// Validate that we have a proper array of location indices
	if ( ! is_array( $locations_s3_indices ) || empty( $locations_s3_indices ) ) {
		return;
	}

	global $wpdb;

	/*
	 * Fetch only the values needed for comparison. A normal get_posts() call
	 * primes the complete post-meta cache for every location, including any
	 * legacy debug histories that have not migrated yet.
	 */
	$existing_posts = $wpdb->get_results(
		"SELECT p.ID, p.post_title, pm.meta_value AS s3_index
		FROM {$wpdb->posts} p
		LEFT JOIN (
			SELECT post_id, MAX(meta_value) AS meta_value
			FROM {$wpdb->postmeta}
			WHERE meta_key = 's3_index'
			GROUP BY post_id
		) pm ON p.ID = pm.post_id
		WHERE p.post_type = 'locations'"
	);
	$posts_to_delete = array();
	
	// Filter to find posts that should be deleted
	foreach ( $existing_posts as $post ) {
		$s3_index = $post->s3_index;
		
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
		$s3_index = $post->s3_index;
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

	global $wpdb;

	// Prepare placeholders for the IN clause
	$placeholders = implode( ',', array_fill( 0, count( $locations_s3_indices ), '%s' ) );

	// Fetch only IDs here; wp_delete_post() must perform the actual deletion so
	// post meta, taxonomy relationships, and search-index hooks are cleaned.
	$sql = $wpdb->prepare( "
		SELECT DISTINCT p.ID FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 's3_location_id'
		WHERE p.post_type = 'professionals'
		AND p.post_status IN ('publish', 'draft', 'private', 'trash', 'auto-draft', 'inherit')
		AND (pm.meta_value IS NULL OR pm.meta_value NOT IN ($placeholders))
		ORDER BY p.ID
	", $locations_s3_indices );

	$professional_ids = array_map( 'absint', $wpdb->get_col( $sql ) );
	$deleted          = 0;

	foreach ( $professional_ids as $professional_id ) {
		if ( wp_delete_post( $professional_id, true ) ) {
			$deleted++;
		}
	}

	error_log( "Phenix Sync: Deleted {$deleted} orphaned tenants (professionals without valid s3_location_id)" );
}

/**
 * Process one location in the sequential queue.
 *
 * @param int    $offset        Queue offset.
 * @param string $run_id        Pipeline run identifier.
 * @param int    $retry_attempt Retry attempts already made for this offset.
 * @return void
 */
function phenixsync_process_batch( $offset, $run_id = '', $retry_attempt = 0 ) {
	if ( ! phenix_sync_is_enabled() ) {
		error_log( 'Phenix Sync: Batch processing skipped (sync disabled).' );
		phenixsync_finish_full_sync_status( $run_id, 'stopped', 'Sync was disabled while the location queue was running.' );
		phenixsync_release_full_sync_lock( $run_id );
		return;
	}

	$run_id = (string) $run_id;
	if ( $run_id && ! phenixsync_full_sync_worker_owns_stage( $run_id, 'locations' ) ) {
		return;
	}

	$locations_queue = get_transient( 'phenixsync_locations_data' );
	if ( ! $locations_queue ) {
		if ( $run_id && ! phenixsync_full_sync_worker_owns_stage_offset( $run_id, 'locations', $offset ) ) {
			return;
		}

		error_log('Phenix Sync: Locations data transient not found in phenixsync_process_batch.');
		phenixsync_finish_full_sync_status( $run_id, 'failed', 'The location queue could not be found.' );
		phenixsync_release_full_sync_lock( $run_id );
		return;
	}

	if ( ! is_array( $locations_queue ) ) {
		if ( $run_id && ! phenixsync_full_sync_worker_owns_stage_offset( $run_id, 'locations', $offset ) ) {
			return;
		}

		error_log('Phenix Sync: Locations data transient is not an array in phenixsync_process_batch.');
		phenixsync_finish_full_sync_status( $run_id, 'failed', 'The location queue was invalid.' );
		phenixsync_release_full_sync_lock( $run_id );
		return;
	}

	$is_current_queue = isset( $locations_queue['run_id'], $locations_queue['ids'] )
		&& is_array( $locations_queue['ids'] );

	if ( $is_current_queue ) {
		$queue_run_id = (string) $locations_queue['run_id'];
		$location_ids = $locations_queue['ids'];
		$run_id       = $run_id ? (string) $run_id : $queue_run_id;

		if ( $run_id !== $queue_run_id || ! phenixsync_full_sync_lock_matches( $run_id ) ) {
			return;
		}
	} else {
		// Adopt a queue written by the previous plugin version exactly once.
		$location_ids = array();

		foreach ( $locations_queue as $queue_item ) {
			if ( is_array( $queue_item ) && isset( $queue_item['S3_index'] ) ) {
				$location_ids[] = (string) $queue_item['S3_index'];
			} elseif ( is_scalar( $queue_item ) && '' !== (string) $queue_item ) {
				$location_ids[] = (string) $queue_item;
			}
		}

		$run_id = phenixsync_acquire_full_sync_lock( $run_id );
		if ( ! $run_id ) {
			error_log( 'Phenix Sync: A legacy location queue was ignored because another full sync owns the lock.' );
			return;
		}

		set_transient(
			'phenixsync_locations_data',
			array(
				'run_id'     => $run_id,
				'ids'        => $location_ids,
				'created_at' => time(),
			),
			2 * DAY_IN_SECONDS
		);
	}

	$location_ids = array_values(
		array_unique(
			array_filter(
				array_map( 'strval', $location_ids ),
				'strlen'
			)
		)
	);

	if ( empty( $location_ids ) ) {
		if ( $run_id && ! phenixsync_full_sync_worker_owns_stage_offset( $run_id, 'locations', $offset ) ) {
			return;
		}

		error_log( 'Phenix Sync: Locations queue contains no valid S3 indices.' );
		delete_transient( 'phenixsync_locations_data' );
		phenixsync_finish_full_sync_status( $run_id, 'failed', 'The location queue contained no valid location IDs.' );
		phenixsync_release_full_sync_lock( $run_id );
		return;
	}

	unset( $locations_queue );

	$offset = absint( $offset );
	$total  = count( $location_ids );
	$status = phenixsync_get_full_sync_status();

	if (
		isset( $status['run_id'], $status['stage'], $status['completed'] )
		&& (string) $status['run_id'] === (string) $run_id
		&& (
			'locations' !== (string) $status['stage']
			|| absint( $status['completed'] ) !== $offset
		)
	) {
		return;
	}

	if ( ! isset( $location_ids[ $offset ] ) ) {
		error_log( "Phenix Sync: Location queue offset {$offset} is outside the {$total}-item queue." );
		delete_transient( 'phenixsync_locations_data' );
		phenixsync_finish_full_sync_status( $run_id, 'failed', 'The location queue offset was invalid.' );
		phenixsync_release_full_sync_lock( $run_id );
		return;
	}

	phenixsync_touch_full_sync_lock( $run_id );
	$s3_index   = $location_ids[ $offset ];
	phenixsync_update_full_sync_status(
		$run_id,
		array(
			'stage'            => 'locations',
			'completed'        => $offset,
			'total'            => $total,
			'current_s3_index' => $s3_index,
			'retry_attempt'    => absint( $retry_attempt ),
		)
	);
	$sync_result = phenixsync_single_location_sync( $s3_index );

	if ( ! phenixsync_full_sync_worker_owns_stage( $run_id, 'locations' ) ) {
		return;
	}
	$status = phenixsync_get_full_sync_status();
	if (
		isset( $status['run_id'], $status['stage'], $status['completed'] )
		&& (string) $status['run_id'] === (string) $run_id
		&& (
			'locations' !== (string) $status['stage']
			|| absint( $status['completed'] ) !== $offset
		)
	) {
		return;
	}

	if ( ! $sync_result && $retry_attempt < PHENIXSYNC_MAX_RETRY_ATTEMPTS ) {
		$next_attempt = $retry_attempt + 1;
		phenixsync_record_full_sync_error(
			$run_id,
			"Location {$s3_index} failed.",
			array(
				'current_s3_index' => $s3_index,
				'retry_attempt'    => $next_attempt,
			)
		);
		$retry_scheduled = phenixsync_schedule_worker_event(
			time() + PHENIXSYNC_RETRY_DELAY,
			'phenixsync_do_process_batch',
			array( $offset, $run_id, $next_attempt )
		);

		if ( $retry_scheduled ) {
			error_log( "Phenix Sync: Location {$s3_index} failed at queue offset {$offset}; retry {$next_attempt} was scheduled in at least one minute." );
			phenixsync_log_memory_usage(
				'location retry scheduled',
				array(
					'run_id'   => $run_id,
					'offset'   => $offset,
					's3_index' => $s3_index,
					'attempt'  => $next_attempt,
				)
			);
			return;
		}

		error_log( "Phenix Sync: Location {$s3_index} failed and retry {$next_attempt} could not be scheduled; advancing the queue." );
	}

	if ( ! $sync_result ) {
		if ( $retry_attempt >= PHENIXSYNC_MAX_RETRY_ATTEMPTS ) {
			phenixsync_record_full_sync_error(
				$run_id,
				"Location {$s3_index} failed after all retries.",
				array( 'current_s3_index' => $s3_index )
			);
		}
		error_log( "Phenix Sync: Location {$s3_index} will not be retried again; advancing the queue." );
	}

	$next_offset = $offset + 1;
	phenixsync_update_full_sync_status(
		$run_id,
		array(
			'stage'            => 'locations',
			'completed'        => $next_offset,
			'total'            => $total,
			'current_s3_index' => '',
			'retry_attempt'    => 0,
		)
	);
	error_log( "Phenix Sync: Location worker complete. Offset: {$offset}, Next offset: {$next_offset}, Total: {$total}" );

	if ( 0 === $next_offset % 25 || $next_offset >= $total ) {
		phenixsync_log_memory_usage(
			'location queue progress',
			array(
				'run_id'   => $run_id,
				'complete' => $next_offset,
				'total'    => $total,
			)
		);
	}

	if ( $next_offset < $total ) {
		$next_scheduled = phenixsync_dispatch_pipeline_worker(
			time() + PHENIXSYNC_WORKER_DELAY,
			'phenixsync_do_process_batch',
			array( $next_offset, $run_id, 0 )
		);

		if ( ! $next_scheduled ) {
			delete_transient( 'phenixsync_locations_data' );
			phenixsync_finish_full_sync_status( $run_id, 'failed', "Location worker {$next_offset} could not be scheduled." );
			phenixsync_release_full_sync_lock( $run_id );
			error_log( "Phenix Sync: Location worker {$next_offset} could not be scheduled; the pipeline was stopped and its lock released." );
		}
	} else {
		delete_transient( 'phenixsync_locations_data' );
		wp_clear_scheduled_hook( 'phenixsync_do_process_batch' );

		/* Publish the handoff before the next request is allowed to run. */
		if ( ! phenixsync_full_sync_worker_owns_stage( $run_id, 'locations' ) ) {
			return;
		}

		phenixsync_touch_full_sync_lock( $run_id );
		phenixsync_update_full_sync_status(
			$run_id,
			array(
				'stage'            => 'preparing_professionals',
				'completed'        => $total,
				'total'            => $total,
				'current_s3_index' => '',
			)
		);
		$scheduled = phenixsync_dispatch_pipeline_worker(
			time() + PHENIXSYNC_WORKER_DELAY,
			'phenixsync_start_professionals_queue',
			array( $run_id )
		);

		if ( $scheduled ) {
			error_log( "Phenix Sync: All {$total} locations were processed. The professional stage was scheduled next." );
		} else {
			phenixsync_finish_full_sync_status( $run_id, 'failed', 'The professional stage could not be scheduled.' );
			phenixsync_release_full_sync_lock( $run_id );
			error_log( "Phenix Sync: All {$total} locations were processed, but the professional stage could not be scheduled; the full-sync lock was released." );
		}
	}
}

/**
 * Run a location worker under the cross-request pipeline lease.
 *
 * @param int    $offset        Queue offset.
 * @param string $run_id        Pipeline run identifier.
 * @param int    $retry_attempt Retry attempts already made for this offset.
 */
function phenixsync_run_location_pipeline_worker( $offset, $run_id = '', $retry_attempt = 0 ) {
	phenixsync_execute_claimed_pipeline_worker(
		'phenixsync_do_process_batch',
		array( absint( $offset ), (string) $run_id, absint( $retry_attempt ) ),
		'phenixsync_process_batch'
	);
}
add_action( 'phenixsync_do_process_batch', 'phenixsync_run_location_pipeline_worker', 10, 3 );

/**
 * Syncs a single location based on its S3_index.
 * Requests one location and passes that record directly through the update.
 *
 * @param string|int $S3_index The S3_index of the location to sync.
 * @return bool True on successful sync attempt, false otherwise.
 */
function phenixsync_single_location_sync( $S3_index ) {
	if ( ! phenix_sync_is_enabled() ) {
		error_log( 'Phenix Sync: Single location sync skipped (sync disabled).' );
		return false;
	}

	$location_request = phenixsync_locations_api_request_with_debug( $S3_index );
	$raw_response = $location_request['body'];
	$locations_array = phenixsync_locations_json_to_php_array( $raw_response );
	if ( empty( $locations_array ) || ! is_array( $locations_array ) ) {
		$existing_post_id = phenixsync_locations_get_post_by_external_id( $S3_index );
		if ( $existing_post_id ) {
			phenixsync_save_locations_sync_details_to_location( $existing_post_id, $location_request['debug'] );
		}
		error_log( "Phenix Sync: No location data returned for S3_index {$S3_index}. Skipping single sync." );
		return false;
	}

	$location = $locations_array[0] ?? null;

	if ( ! is_array( $location ) ) {
		error_log( "Phenix Sync: Individual response for S3_index {$S3_index} contains no location record." );
		return false;
	}

	unset( $raw_response, $locations_array );
	
	if ( empty( $S3_index ) ) {
		error_log( 'Phenix Sync: S3_index cannot be empty for phenixsync_single_location_sync.' );
		return false;
	}

	$post_id = phenixsync_locations_maybe_create_post( $S3_index, $location );

	if ( ! $post_id ) {
		error_log( "Phenix Sync: Failed to create or find post for S3_index {$S3_index} during single sync." );
		return false;
	}

	phenixsync_save_locations_sync_details_to_location( $post_id, $location_request['debug'] );

	if ( ! phenixsync_location_post_needs_update( $post_id, $location ) ) {
		error_log( "Phenix Sync: Location {$S3_index} is unchanged; its post and search indexes were not updated." );
		return true;
	}

	/*
	 * Avoid FacetWP rebuilding the location for the taxonomy mutation. The
	 * final wp_update_post() indexes the completed location once.
	 */
	add_filter( 'facetwp_indexer_is_enabled', '__return_false', PHP_INT_MAX );
	try {
		phenixsync_locations_update_post_taxonomies( $S3_index, $post_id, $location );
	} finally {
		remove_filter( 'facetwp_indexer_is_enabled', '__return_false', PHP_INT_MAX );
	}

	phenixsync_locations_update_post( $S3_index, $post_id, $location );
	
	error_log( "Phenix Sync: Location {$S3_index} changed and was updated successfully. Post ID: {$post_id}." );
	return true;
}

/**
 * Function to make an API request for locations with a custom timeout and POST data.
 *
 * @return string API response or error message.
 */
function phenixsync_locations_api_request( $s3_index ) {
	$request = phenixsync_locations_api_request_with_debug( $s3_index, false );
	return $request['body'];
}

/**
 * Request location data and retain a concise diagnostic record for individual location syncs.
 *
 * @param string|int|null $s3_index                Location index, if requesting one location.
 * @param bool            $include_response_preview Whether to build the complete debug preview.
 * @return array{body: string, debug: array}
 */
function phenixsync_locations_api_request_with_debug( $s3_index, $include_response_preview = true ) {
	
	$password = phenix_sync_get_api_password();
	$base_url = 'https://admin.ginasplatform.com/utilities/phenix_portal_locations_sender.aspx';
	
	// build the API url with the base URL and password.
	$api_url = add_query_arg( 'password', $password, $base_url );
	
	// add a query arg with the date and time of the request, with a parameter for unique_string
	$unique_string = date('YmdHis');
	$api_url = add_query_arg( 'unique_string', $unique_string, $api_url );
	
	// build the API URL (with the s3_index if provided, or without if not)
	if ( ! empty( $s3_index ) ) {
		$api_url = add_query_arg( 'location_index', $s3_index, $api_url );
	}

	set_time_limit( 60 );

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
		if (
			false !== stripos( $error_message, 'timed out' )
			|| false !== stripos( $error_message, 'timeout' )
		) {
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

		// If it timed out, note that in the message
		if ( $timed_out ) {
			$message .= "\n\nNOTE: The request TIMED OUT.";
		}

		// Always attempt to provide a resync URL when an email is being sent.
		$resync_location_index = null;

		// Prefer the provided $s3_index; otherwise attempt to parse from the API URL
		if ( ! empty( $s3_index ) ) {
			$resync_location_index = $s3_index;
		} else {
			$query = parse_url( $api_url, PHP_URL_QUERY );
			if ( $query ) {
				parse_str( $query, $query_params );
				if ( ! empty( $query_params['location_index'] ) ) {
					$resync_location_index = $query_params['location_index'];
				}
			}
		}

		if ( ! empty( $resync_location_index ) ) {
			$resync_base = 'https://admin.ginasplatform.com/utilities/phenix_website_resync.aspx';
			$resync_url  = add_query_arg( 'location_index', $resync_location_index, $resync_base );
			$message    .= "\n\nResync URL: {$resync_url}";
		}

		foreach ( $recipients as $recipient ) {
			wp_mail( $recipient, $subject, $message );
		}
	}

	$response_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
	$response_body = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
	$debug = array(
		'response_code'      => $response_code,
		'response_time'      => current_time( 'mysql' ),
		'response_time_gmt'  => current_time( 'mysql', true ),
		'response_timestamp' => time(),
		'response_size'      => is_wp_error( $response ) ? '' : ( wp_remote_retrieve_header( $response, 'Content-Length' ) ?: strlen( $response_body ) ),
		'response_date'      => is_wp_error( $response ) ? '' : wp_remote_retrieve_header( $response, 'Date' ),
	);

	if ( is_wp_error( $response ) ) {
		$error_message = $response->get_error_message();
		$result        = "Error: " . esc_html( $error_message );
		$debug['request_error'] = $error_message;
		$error_label = $timed_out ? 'timeout' : 'request failure';
		$location_label = $s3_index ? (string) $s3_index : 'ALL';
		error_log( "Phenix Sync: Location API {$error_label} for S3_index {$location_label}: {$error_message}" );
	} else {
		if ( 200 === (int) $response_code ) {
			$result = $response_body; // Store raw response
		} else {
			$result = "Request failed with status code: " . esc_html( (string) $response_code );
			$error_label = in_array( (int) $response_code, array( 408, 504, 524 ), true )
				? 'timeout'
				: 'HTTP failure';
			$location_label = $s3_index ? (string) $s3_index : 'ALL';
			error_log( "Phenix Sync: Location API {$error_label} for S3_index {$location_label}: {$result}" );
		}
	}

	if ( $include_response_preview ) {
		$debug = array_merge( $debug, phenixsync_get_locations_response_debug_summary( $response_body ? $response_body : $result ) );
	}

	return array( 'body' => $result, 'debug' => $debug );
}

/** Store the latest five location API diagnostics outside post meta. */
function phenixsync_save_locations_sync_details_to_location( $post_id, $details ) {
	phenixsync_save_sync_debug_details( $post_id, 'locations', $details );
}

/** Build a compact debug summary for a location API response. */
function phenixsync_get_locations_response_debug_summary( $raw_response ) {
	$raw_response = is_string( $raw_response ) ? $raw_response : '';
	$trimmed      = trim( $raw_response );
	$summary = array(
		'response_shape'       => 'empty_string',
		'raw_response_length'  => strlen( $raw_response ),
		'location_count'       => 0,
		'api_status'           => '',
		'top_level_keys'       => array(),
		'json_error'           => '',
	);
	$summary = array_merge( $summary, phenixsync_build_compressed_debug_response( $raw_response ) );

	if ( '' === $trimmed ) { return $summary; }
	if ( strpos( $raw_response, 'Error:' ) === 0 || strpos( $raw_response, 'Request failed with status code:' ) === 0 ) {
		$summary['response_shape'] = 'error_string';
		return $summary;
	}

	if ( '{' !== substr( $trimmed, 0, 1 ) ) {
		$summary['response_shape'] = 'unknown';
		return $summary;
	}

	$summary['response_shape'] = 'json_object';
	$summary['location_count'] = substr_count( $raw_response, '"S3_index"' );

	if ( strlen( $raw_response ) <= 32768 ) {
		$decoded = json_decode( $raw_response, true );
		if ( is_array( $decoded ) ) {
			$summary['top_level_keys'] = array_slice( array_keys( $decoded ), 0, 20 );
			$summary['api_status'] = isset( $decoded['status'] ) ? (string) $decoded['status'] : '';
		}
	}
	return $summary;
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
		return array();
	}

	$response_php_array = json_decode($raw_response, true);

	if (json_last_error() !== JSON_ERROR_NONE) {
		$json_error_message = json_last_error_msg();
		error_log('JSON decode error: ' . $json_error_message . ' - Raw response: ' . substr($raw_response, 0, 500) . '...');
		return array();
	}

	if (!isset($response_php_array['locations']) || !is_array($response_php_array['locations'])) {
		if ( isset( $response_php_array['status'] ) ) {
			error_log( 'Phenix Sync: Locations API status response: ' . $response_php_array['status'] );
		}
		return array();
	}

	$locations = $response_php_array['locations'];

	return $locations;
}

/**
 * Create a new post if there isn't one already.
 *
 * @param   string|int $S3_index  The S3_index of the location.
 * @param   array      $location  The decoded individual location record.
 *
 * @return  int|false The post ID if successful, false otherwise.
 */
function phenixsync_locations_maybe_create_post( $S3_index, $location ) {
	if ( ! is_array( $location ) ) {
		error_log( "Phenix Sync: Invalid location data for S3_index {$S3_index} in phenixsync_locations_maybe_create_post." );
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
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'no_found_rows'  => true,
		'meta_key'       => 's3_index',
		'meta_value'     => $external_id,
	);

	$post_ids = array_map( 'absint', get_posts( $args ) );
	
	// delete all posts except the first one
	if ( count( $post_ids ) > 1 ) {
		foreach ( array_slice( $post_ids, 1 ) as $duplicate_post_id ) {
			wp_delete_post( $duplicate_post_id, true );
			error_log( "Phenix Sync: Deleted duplicate location post {$duplicate_post_id} for S3_index {$external_id}." );
		}
	}

	if ( ! empty( $post_ids ) ) {
		return $post_ids[0];
	}

	return false;
}

/**
 * Return the canonical U.S. state names used by the states taxonomy.
 *
 * @return array
 */
function phenixsync_us_state_names() {
	return array(
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
	);
}

/**
 * Calculate the one expected states-taxonomy term for a location.
 *
 * @param array $location Location API record.
 * @return string
 */
function phenixsync_location_expected_state_term( $location ) {
	$country = isset( $location['country'] ) ? strtoupper( (string) $location['country'] ) : '';

	if ( 'UK' === $country ) {
		return isset( $location['city'] ) ? sanitize_text_field( $location['city'] ) : '';
	}

	if ( 'USA' !== $country ) {
		return '';
	}

	$state  = isset( $location['state'] ) ? strtoupper( (string) $location['state'] ) : '';
	$states = phenixsync_us_state_names();

	return isset( $states[ $state ] ) ? $states[ $state ] : sanitize_text_field( $state );
}

/**
 * Determine whether a location's searchable post state differs from the API.
 *
 * Debug history is written before this comparison, so unchanged sync attempts
 * remain visible without triggering FacetWP or Relevanssi.
 *
 * @param int   $post_id  Location post ID.
 * @param array $location Location API record.
 * @return bool
 */
function phenixsync_location_post_needs_update( $post_id, $location ) {
	$post = get_post( $post_id );

	if ( ! $post || 'publish' !== $post->post_status ) {
		return true;
	}

	$desired_title = isset( $location['location_name'] )
		? sanitize_text_field( $location['location_name'] )
		: 'Untitled Location';

	if ( $post->post_title !== $desired_title ) {
		return true;
	}

	$location_meta = $location;
	unset( $location_meta['suites'] );

	foreach ( $location_meta as $key => $value ) {
		$meta_key   = sanitize_key( $key );
		$meta_value = ( null === $value || '' === $value ) ? '' : sanitize_text_field( $value );

		if ( ! phenixsync_meta_values_match( get_post_meta( $post_id, $meta_key, true ), $meta_value ) ) {
			return true;
		}
	}

	$expected_state = phenixsync_location_expected_state_term( $location );
	$current_states = wp_get_object_terms(
		$post_id,
		'states',
		array( 'fields' => 'names' )
	);

	if ( is_wp_error( $current_states ) ) {
		return true;
	}

	$current_states = array_values( array_map( 'strval', $current_states ) );
	sort( $current_states );
	$expected_states = $expected_state ? array( $expected_state ) : array();

	return $current_states !== $expected_states;
}

function phenixsync_locations_update_post( $S3_index, $post_id, $location ) {
	if ( ! is_array( $location ) ) {
		error_log( "Phenix Sync: Invalid location data for S3_index {$S3_index} in phenixsync_locations_update_post. Post ID: {$post_id}" );
		return;
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

	/*
	 * Save the post only after all meta and taxonomy work is complete. FacetWP
	 * and Relevanssi both listen to this save and will index the final record.
	 */
	$location_post_details = array(
		'ID'          => $post_id,
		'post_title'  => isset($location['location_name']) ? $location['location_name'] : 'Untitled Location',
		'post_type'   => 'locations',
		'post_status' => 'publish'
	);

	$update_result = wp_update_post( $location_post_details, true );

	if ( is_wp_error( $update_result ) ) {
		error_log( "Phenix Sync: Error updating post {$post_id} for S3_index {$S3_index}: " . $update_result->get_error_message() );
	}
}

function phenixsync_locations_update_post_taxonomies( $S3_index, $post_id, $location ) {
	if ( ! is_array( $location ) ) {
		error_log( "Phenix Sync: Invalid location data for S3_index {$S3_index} in phenixsync_locations_update_post_taxonomies. Post ID: {$post_id}" );
		return;
	}

	$state = phenixsync_location_expected_state_term( $location );

	if ( '' === $state ) {
		wp_set_post_terms( $post_id, array(), 'states', false );
		return;
	}
	
	$state_term = term_exists( $state, 'states' );

	if ( !$state_term ) {
		$state_term = wp_insert_term( $state, 'states' );
	}

	if ( is_wp_error( $state_term ) ) {
		return;
	}

	$state_term_id = is_array( $state_term ) ? $state_term['term_id'] : $state_term;
	wp_set_post_terms( $post_id, array( (int) $state_term_id ), 'states', false );
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
		'permission_callback' => '__return_true',
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
 * Retry a REST- or admin-triggered single-location sync.
 *
 * @param string|int $S3_index      Location identifier.
 * @param int        $retry_attempt Current retry attempt.
 * @return void
 */
function phenixsync_retry_single_location_sync( $S3_index, $retry_attempt = 1 ) {
	$result = phenixsync_single_location_sync( $S3_index );

	if ( $result ) {
		return;
	}

	$retry_attempt = absint( $retry_attempt );
	phenixsync_log_memory_usage(
		'standalone location retry',
		array(
			's3_index' => $S3_index,
			'attempt'  => $retry_attempt,
		)
	);

	if ( $retry_attempt < PHENIXSYNC_MAX_RETRY_ATTEMPTS ) {
		$next_attempt = $retry_attempt + 1;
		error_log( "Phenix Sync: Standalone location sync failed for S3_index {$S3_index}; retry {$next_attempt} will run in at least one minute." );
		phenixsync_schedule_worker_event(
			time() + PHENIXSYNC_RETRY_DELAY,
			'phenixsync_retry_single_location_sync',
			array( $S3_index, $next_attempt )
		);
		return;
	}

	error_log( "Phenix Sync: Standalone location sync failed for S3_index {$S3_index} after all retries." );
}
add_action( 'phenixsync_retry_single_location_sync', 'phenixsync_retry_single_location_sync', 10, 2 );

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

	if ( ! phenix_sync_is_enabled() ) {
		return new WP_REST_Response( array( 'message' => 'Sync is disabled in settings.' ), 403 );
	}

	// Rate limiting: check if this S3_index was requested in the last 10 seconds.
	$transient_key = 'phenixsync_ratelimit_' . sanitize_key( $S3_index );
	if ( get_transient( $transient_key ) ) {
		return new WP_REST_Response( array( 'message' => 'Too many requests. Please wait a moment before trying again.' ), 429 );
	}

	set_transient( $transient_key, time(), 10 );

	// Trigger the single location sync
	$sync_result = phenixsync_single_location_sync( $S3_index );

	if ( $sync_result ) {
		return new WP_REST_Response( array( 'message' => "Location sync initiated for S3_index: {$S3_index}." ), 200 );
	} else {
		phenixsync_schedule_worker_event(
			time() + PHENIXSYNC_RETRY_DELAY,
			'phenixsync_retry_single_location_sync',
			array( $S3_index, 1 )
		);
		error_log( "Phenix Sync: REST location sync failed for S3_index {$S3_index}; retry 1 will run in at least one minute." );
		return new WP_REST_Response( array( 'message' => "Failed to initiate sync for S3_index: {$S3_index}. Check logs for details." ), 500 );
	}
}
