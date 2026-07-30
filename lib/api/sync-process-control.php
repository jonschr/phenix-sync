<?php

define( 'PHENIXSYNC_FULL_SYNC_LOCK_OPTION', 'phenixsync_full_sync_lock' );
define( 'PHENIXSYNC_FULL_SYNC_STATUS_OPTION', 'phenixsync_full_sync_status' );
define( 'PHENIXSYNC_ORPHAN_CLEANUP_STATUS_OPTION', 'phenixsync_orphan_cleanup_status' );
define( 'PHENIXSYNC_MAX_RETRY_ATTEMPTS', 2 );
define( 'PHENIXSYNC_RETRY_DELAY', MINUTE_IN_SECONDS );
define( 'PHENIXSYNC_WORKER_DELAY', 10 );
define( 'PHENIXSYNC_LOCK_STALE_AFTER', DAY_IN_SECONDS - HOUR_IN_SECONDS );
define( 'PHENIXSYNC_ORPHAN_CLEANUP_VERSION', '1' );
define( 'PHENIXSYNC_ORPHAN_CLEANUP_BATCH_SIZE', 10000 );

/**
 * Return the active full-sync lock.
 *
 * @return array
 */
function phenixsync_get_full_sync_lock() {
	$lock = get_option( PHENIXSYNC_FULL_SYNC_LOCK_OPTION, array() );

	return is_array( $lock ) ? $lock : array();
}

/**
 * Return the latest full-sync status.
 *
 * @return array
 */
function phenixsync_get_full_sync_status() {
	$status = get_option( PHENIXSYNC_FULL_SYNC_STATUS_OPTION, array() );

	return is_array( $status ) ? $status : array();
}

/**
 * Store a bounded status snapshot for the active or most recent full sync.
 *
 * @param string $run_id  Full-sync run identifier.
 * @param array  $changes Fields to merge into the current status.
 * @return bool
 */
function phenixsync_update_full_sync_status( $run_id, $changes = array() ) {
	$run_id = (string) $run_id;

	if ( '' === $run_id ) {
		return false;
	}

	$status = phenixsync_get_full_sync_status();

	if ( ! empty( $status['run_id'] ) && (string) $status['run_id'] !== $run_id ) {
		return false;
	}

	$defaults = array(
		'run_id'          => $run_id,
		'state'           => 'running',
		'stage'           => 'initializing_locations',
		'completed'       => 0,
		'total'           => 0,
		'current_s3_index'=> '',
		'retry_attempt'   => 0,
		'error_count'     => 0,
		'last_error'      => '',
		'started_at'      => time(),
		'updated_at'      => time(),
		'completed_at'    => 0,
		'memory_current'  => 0,
		'memory_peak'     => 0,
	);
	$status = wp_parse_args( $status, $defaults );

	$allowed_keys = array_keys( $defaults );
	foreach ( $changes as $key => $value ) {
		if ( in_array( $key, $allowed_keys, true ) ) {
			$status[ $key ] = $value;
		}
	}

	$status['run_id']         = $run_id;
	$status['completed']      = absint( $status['completed'] );
	$status['total']          = absint( $status['total'] );
	$status['retry_attempt']  = absint( $status['retry_attempt'] );
	$status['error_count']    = absint( $status['error_count'] );
	$status['started_at']     = absint( $status['started_at'] );
	$status['completed_at']   = absint( $status['completed_at'] );
	$status['updated_at']     = time();
	$status['memory_current'] = memory_get_usage( true );
	$status['memory_peak']    = memory_get_peak_usage( true );

	if ( false === get_option( PHENIXSYNC_FULL_SYNC_STATUS_OPTION, false ) ) {
		return add_option( PHENIXSYNC_FULL_SYNC_STATUS_OPTION, $status, '', false );
	}

	return update_option( PHENIXSYNC_FULL_SYNC_STATUS_OPTION, $status, false );
}

/**
 * Initialize status for a new full-sync run.
 *
 * @param string $run_id     Full-sync run identifier.
 * @param int    $started_at Start timestamp.
 * @return bool
 */
function phenixsync_start_full_sync_status( $run_id, $started_at = 0 ) {
	delete_option( PHENIXSYNC_FULL_SYNC_STATUS_OPTION );

	return phenixsync_update_full_sync_status(
		$run_id,
		array(
			'state'        => 'running',
			'stage'        => 'initializing_locations',
			'started_at'   => $started_at ? absint( $started_at ) : time(),
			'completed_at' => 0,
		)
	);
}

/**
 * Record an error without ending a retryable full sync.
 *
 * @param string $run_id  Full-sync run identifier.
 * @param string $message Error message.
 * @param array  $changes Additional status changes.
 * @return bool
 */
function phenixsync_record_full_sync_error( $run_id, $message, $changes = array() ) {
	$status = phenixsync_get_full_sync_status();
	$changes['error_count'] = isset( $status['error_count'] )
		? absint( $status['error_count'] ) + 1
		: 1;
	$changes['last_error'] = sanitize_text_field( $message );

	return phenixsync_update_full_sync_status( $run_id, $changes );
}

/**
 * Mark a full sync complete or failed.
 *
 * @param string $run_id  Full-sync run identifier.
 * @param string $state   Either completed, failed, or stopped.
 * @param string $message Optional final message.
 * @return bool
 */
function phenixsync_finish_full_sync_status( $run_id, $state, $message = '' ) {
	$state = in_array( $state, array( 'completed', 'failed', 'stopped' ), true )
		? $state
		: 'failed';
	$changes = array(
		'state'            => $state,
		'current_s3_index' => '',
		'retry_attempt'    => 0,
		'completed_at'     => time(),
	);

	if ( 'completed' === $state ) {
		$changes['stage'] = 'completed';
	} elseif ( 'stopped' === $state ) {
		$changes['stage'] = 'stopped';
	}

	if ( '' !== $message ) {
		$changes['last_error'] = sanitize_text_field( $message );
	}

	return phenixsync_update_full_sync_status( $run_id, $changes );
}

/**
 * Adopt a run that began before status tracking was deployed.
 *
 * @return array The recovered or existing status.
 */
function phenixsync_recover_active_full_sync_status() {
	$lock   = phenixsync_get_full_sync_lock();
	$status = phenixsync_get_full_sync_status();
	$run_id = isset( $lock['run_id'] ) ? (string) $lock['run_id'] : '';

	if ( '' === $run_id ) {
		return $status;
	}

	if ( ! empty( $status['run_id'] ) && (string) $status['run_id'] === $run_id ) {
		return $status;
	}

	$changes = array(
		'state'      => 'running',
		'stage'      => 'initializing_locations',
		'completed'  => 0,
		'total'      => 0,
		'started_at' => isset( $lock['started_at'] ) ? absint( $lock['started_at'] ) : time(),
	);
	$location_queue = get_transient( 'phenixsync_locations_data' );
	$professional_queue = get_transient( 'phenixsync_professionals_queue' );

	if (
		is_array( $professional_queue )
		&& isset( $professional_queue['run_id'], $professional_queue['ids'] )
		&& (string) $professional_queue['run_id'] === $run_id
		&& is_array( $professional_queue['ids'] )
	) {
		$changes['stage'] = 'professionals';
		$changes['total'] = count( $professional_queue['ids'] );
	} elseif (
		is_array( $location_queue )
		&& isset( $location_queue['run_id'], $location_queue['ids'] )
		&& (string) $location_queue['run_id'] === $run_id
		&& is_array( $location_queue['ids'] )
	) {
		$changes['stage'] = 'locations';
		$changes['total'] = count( $location_queue['ids'] );
	}

	$cron = _get_cron_array();
	foreach ( is_array( $cron ) ? $cron : array() as $hooks ) {
		foreach ( array( 'phenixsync_do_process_batch', 'phenixsync_process_professionals_queue' ) as $hook ) {
			if ( empty( $hooks[ $hook ] ) ) {
				continue;
			}

			foreach ( $hooks[ $hook ] as $event ) {
				$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
				if ( ! isset( $args[1] ) || (string) $args[1] !== $run_id ) {
					continue;
				}

				$changes['stage']         = 'phenixsync_do_process_batch' === $hook ? 'locations' : 'professionals';
				$changes['completed']     = isset( $args[0] ) ? absint( $args[0] ) : 0;
				$changes['retry_attempt'] = isset( $args[2] ) ? absint( $args[2] ) : 0;
				break 3;
			}
		}
	}

	phenixsync_start_full_sync_status( $run_id, $changes['started_at'] );
	phenixsync_update_full_sync_status( $run_id, $changes );
	return phenixsync_get_full_sync_status();
}

/**
 * Return the one-time orphan cleanup status.
 *
 * @return array
 */
function phenixsync_get_orphan_cleanup_status() {
	$status = get_option( PHENIXSYNC_ORPHAN_CLEANUP_STATUS_OPTION, array() );

	return is_array( $status ) ? $status : array();
}

/**
 * Update the one-time orphan cleanup status.
 *
 * @param array $changes Status changes.
 * @return bool
 */
function phenixsync_update_orphan_cleanup_status( $changes = array() ) {
	$defaults = array(
		'state'                      => 'running',
		'batches'                    => 0,
		'postmeta_rows_removed'      => 0,
		'term_relationships_removed' => 0,
		'last_batch_postmeta'        => 0,
		'last_batch_terms'           => 0,
		'started_at'                 => time(),
		'updated_at'                 => time(),
		'completed_at'               => 0,
	);
	$status = wp_parse_args( phenixsync_get_orphan_cleanup_status(), $defaults );

	foreach ( $changes as $key => $value ) {
		if ( array_key_exists( $key, $defaults ) ) {
			$status[ $key ] = $value;
		}
	}

	foreach ( array( 'batches', 'postmeta_rows_removed', 'term_relationships_removed', 'last_batch_postmeta', 'last_batch_terms', 'started_at', 'completed_at' ) as $key ) {
		$status[ $key ] = absint( $status[ $key ] );
	}
	$status['updated_at'] = time();

	if ( false === get_option( PHENIXSYNC_ORPHAN_CLEANUP_STATUS_OPTION, false ) ) {
		return add_option( PHENIXSYNC_ORPHAN_CLEANUP_STATUS_OPTION, $status, '', false );
	}

	return update_option( PHENIXSYNC_ORPHAN_CLEANUP_STATUS_OPTION, $status, false );
}

/**
 * Acquire the one lock shared by the location and professional sync stages.
 *
 * @param string $run_id Existing run ID when resuming a stage.
 * @return string|false The acquired run ID, or false when another run is active.
 */
function phenixsync_acquire_full_sync_lock( $run_id = '' ) {
	$current_lock = phenixsync_get_full_sync_lock();
	$current_id   = isset( $current_lock['run_id'] ) ? (string) $current_lock['run_id'] : '';
	$heartbeat    = isset( $current_lock['heartbeat'] ) ? absint( $current_lock['heartbeat'] ) : 0;

	if ( $run_id && $current_id === (string) $run_id ) {
		phenixsync_touch_full_sync_lock( $run_id );
		return (string) $run_id;
	}

	if ( $current_id && $heartbeat > time() - PHENIXSYNC_LOCK_STALE_AFTER ) {
		return false;
	}

	if ( $current_id ) {
		phenixsync_finish_full_sync_status( $current_id, 'failed', 'The previous full-sync lock became stale.' );
		delete_option( PHENIXSYNC_FULL_SYNC_LOCK_OPTION );
		error_log( "Phenix Sync: Removed stale full-sync lock for run {$current_id}." );
	}

	$run_id = $run_id ? (string) $run_id : wp_generate_uuid4();
	$lock   = array(
		'run_id'     => $run_id,
		'started_at' => time(),
		'heartbeat'  => time(),
	);

	if ( ! add_option( PHENIXSYNC_FULL_SYNC_LOCK_OPTION, $lock, '', false ) ) {
		return false;
	}

	phenixsync_start_full_sync_status( $run_id, $lock['started_at'] );
	return $run_id;
}

/**
 * Confirm that a worker still owns the active pipeline.
 *
 * @param string $run_id Run identifier.
 * @return bool
 */
function phenixsync_full_sync_lock_matches( $run_id ) {
	$lock = phenixsync_get_full_sync_lock();

	return ! empty( $run_id )
		&& isset( $lock['run_id'] )
		&& (string) $lock['run_id'] === (string) $run_id;
}

/**
 * Refresh the active pipeline lock.
 *
 * @param string $run_id Run identifier.
 * @return bool
 */
function phenixsync_touch_full_sync_lock( $run_id ) {
	$lock = phenixsync_get_full_sync_lock();

	if ( empty( $lock['run_id'] ) || (string) $lock['run_id'] !== (string) $run_id ) {
		return false;
	}

	$lock['heartbeat'] = time();
	update_option( PHENIXSYNC_FULL_SYNC_LOCK_OPTION, $lock, false );
	return true;
}

/**
 * Release the pipeline only when the caller still owns it.
 *
 * @param string $run_id Run identifier.
 * @return bool
 */
function phenixsync_release_full_sync_lock( $run_id ) {
	if ( ! phenixsync_full_sync_lock_matches( $run_id ) ) {
		return false;
	}

	return delete_option( PHENIXSYNC_FULL_SYNC_LOCK_OPTION );
}

/**
 * Stop the active full-sync pipeline without disabling its daily schedule.
 *
 * This clears only events and queues belonging to the combined location and
 * professional pipeline. Standalone single-location retries are intentionally
 * left alone.
 *
 * @param string $message Final status message.
 * @return bool True when an active run was stopped.
 */
function phenixsync_stop_full_sync( $message = 'The full sync was stopped by an administrator.' ) {
	$lock   = phenixsync_get_full_sync_lock();
	$status = phenixsync_get_full_sync_status();
	$run_id = isset( $lock['run_id'] ) ? (string) $lock['run_id'] : '';

	if (
		'' === $run_id
		&& isset( $status['state'], $status['run_id'] )
		&& 'running' === (string) $status['state']
	) {
		$run_id = (string) $status['run_id'];
	}

	foreach (
		array(
			'phenixsync_do_process_batch',
			'phenixsync_retry_locations_sync_init',
			'phenixsync_start_professionals_queue',
			'phenixsync_process_professionals_queue',
		) as $hook
	) {
		wp_clear_scheduled_hook( $hook );
	}

	delete_transient( 'phenixsync_locations_data' );
	delete_transient( 'phenixsync_professionals_queue' );

	if ( '' === $run_id ) {
		return false;
	}

	phenixsync_finish_full_sync_status( $run_id, 'stopped', $message );

	if (
		isset( $lock['run_id'] )
		&& (string) $lock['run_id'] === $run_id
	) {
		delete_option( PHENIXSYNC_FULL_SYNC_LOCK_OPTION );
	}

	error_log( "Phenix Sync: Full sync run {$run_id} was stopped by an administrator; queued pipeline work was cleared." );
	return true;
}

/**
 * Schedule an event unless an identical event is already pending.
 *
 * @param int    $timestamp Unix timestamp.
 * @param string $hook      Cron hook.
 * @param array  $args      Cron arguments.
 * @return bool
 */
function phenixsync_schedule_worker_event( $timestamp, $hook, $args ) {
	if ( wp_next_scheduled( $hook, $args ) ) {
		return true;
	}

	return (bool) wp_schedule_single_event( $timestamp, $hook, $args );
}

/**
 * Compare a desired meta value with the value returned by get_post_meta().
 *
 * WordPress returns scalar meta values as strings while retaining arrays, so
 * strict comparison alone would incorrectly mark unchanged numeric fields.
 *
 * @param mixed $current Current stored value.
 * @param mixed $desired Desired sanitized value.
 * @return bool
 */
function phenixsync_meta_values_match( $current, $desired ) {
	if ( is_array( $current ) || is_array( $desired ) || is_object( $current ) || is_object( $desired ) ) {
		return maybe_serialize( $current ) === maybe_serialize( $desired );
	}

	$current = null === $current ? '' : (string) $current;
	$desired = null === $desired ? '' : (string) $desired;

	return $current === $desired;
}

/**
 * Record bounded memory telemetry at meaningful pipeline checkpoints.
 *
 * @param string $stage   Human-readable pipeline stage.
 * @param array  $context Optional scalar context.
 */
function phenixsync_log_memory_usage( $stage, $context = array() ) {
	$current = memory_get_usage( true );
	$peak    = memory_get_peak_usage( true );
	$message = sprintf(
		'Phenix Sync: Memory checkpoint "%s": current=%s, peak=%s',
		sanitize_text_field( $stage ),
		size_format( $current, 2 ),
		size_format( $peak, 2 )
	);

	if ( ! empty( $context ) ) {
		$message .= ', context=' . wp_json_encode( $context );
	}

	error_log( $message );
}

/**
 * Remove abandoned pre-pipeline workers once after this version is deployed.
 */
function phenixsync_maybe_upgrade_sync_schedule() {
	$upgrade_version = '3';

	if ( $upgrade_version === get_option( 'phenixsync_schedule_upgrade_version' ) ) {
		return;
	}

	wp_clear_scheduled_hook( 'phenixsync_professionals_cron_hook' );
	wp_clear_scheduled_hook( 'phenixsync_sync_individual_location_professionals_event' );
	wp_clear_scheduled_hook( 'phenixsync_do_process_batch' );
	wp_clear_scheduled_hook( 'phenixsync_process_professionals_queue' );
	wp_clear_scheduled_hook( 'phenixsync_start_professionals_queue' );
	wp_clear_scheduled_hook( 'phenixsync_retry_locations_sync_init' );
	wp_clear_scheduled_hook( 'phenixsync_retry_single_location_sync' );
	wp_clear_scheduled_hook( 'phenixsync_retry_single_professionals_sync' );
	delete_transient( 'phenixsync_locations_data' );
	delete_transient( 'phenixsync_professionals_queue' );
	delete_option( PHENIXSYNC_FULL_SYNC_LOCK_OPTION );

	update_option( 'phenixsync_schedule_upgrade_version', $upgrade_version, false );
	error_log( 'Phenix Sync: Cleared legacy overlapping sync workers for the sequential pipeline upgrade.' );
}
add_action( 'init', 'phenixsync_maybe_upgrade_sync_schedule', 30 );

/**
 * Schedule bounded removal of database rows whose parent post no longer exists.
 */
function phenixsync_maybe_schedule_legacy_orphan_cleanup() {
	if ( PHENIXSYNC_ORPHAN_CLEANUP_VERSION === get_option( 'phenixsync_orphan_cleanup_version' ) ) {
		$cleanup_status = phenixsync_get_orphan_cleanup_status();
		if ( empty( $cleanup_status ) || 'completed' !== ( $cleanup_status['state'] ?? '' ) ) {
			phenixsync_update_orphan_cleanup_status(
				array(
					'state'        => 'completed',
					'completed_at' => time(),
				)
			);
		}
		return;
	}

	if ( empty( phenixsync_get_orphan_cleanup_status() ) ) {
		phenixsync_update_orphan_cleanup_status( array( 'state' => 'running' ) );
	}
	phenixsync_schedule_worker_event(
		time() + PHENIXSYNC_RETRY_DELAY,
		'phenixsync_cleanup_legacy_orphan_rows',
		array()
	);
}
add_action( 'init', 'phenixsync_maybe_schedule_legacy_orphan_cleanup', 35 );

/**
 * Delete a bounded batch of post meta and taxonomy relationships left by the
 * former direct wp_posts DELETE query. Search indexes are intentionally left
 * for their normal full rebuilds.
 */
function phenixsync_cleanup_legacy_orphan_rows() {
	global $wpdb;

	$batch_size                 = PHENIXSYNC_ORPHAN_CLEANUP_BATCH_SIZE;
	$deleted_meta_count         = 0;
	$deleted_relationship_count = 0;
	$meta_ids                   = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT pm.meta_id
			FROM {$wpdb->postmeta} pm
			LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.ID IS NULL
			ORDER BY pm.meta_id
			LIMIT %d",
			$batch_size
		)
	);

	if ( ! empty( $meta_ids ) ) {
		$meta_ids = array_map( 'absint', $meta_ids );
		$deleted_meta_count = (int) $wpdb->query(
			"DELETE FROM {$wpdb->postmeta}
			WHERE meta_id IN (" . implode( ',', $meta_ids ) . ')'
		);
	}

	$orphan_object_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT tr.object_id
			FROM {$wpdb->term_relationships} tr
			LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			WHERE p.ID IS NULL
			ORDER BY tr.object_id
			LIMIT %d",
			$batch_size
		)
	);

	if ( ! empty( $orphan_object_ids ) ) {
		$orphan_object_ids = array_map( 'absint', $orphan_object_ids );
		$deleted_relationship_count = (int) $wpdb->query(
			"DELETE FROM {$wpdb->term_relationships}
			WHERE object_id IN (" . implode( ',', $orphan_object_ids ) . ')'
		);
	}

	$cleanup_status = phenixsync_get_orphan_cleanup_status();
	phenixsync_update_orphan_cleanup_status(
		array(
			'state'                      => 'running',
			'batches'                    => ( isset( $cleanup_status['batches'] ) ? absint( $cleanup_status['batches'] ) : 0 ) + 1,
			'postmeta_rows_removed'      => ( isset( $cleanup_status['postmeta_rows_removed'] ) ? absint( $cleanup_status['postmeta_rows_removed'] ) : 0 ) + $deleted_meta_count,
			'term_relationships_removed' => ( isset( $cleanup_status['term_relationships_removed'] ) ? absint( $cleanup_status['term_relationships_removed'] ) : 0 ) + $deleted_relationship_count,
			'last_batch_postmeta'        => $deleted_meta_count,
			'last_batch_terms'           => $deleted_relationship_count,
		)
	);

	$remaining_meta = (bool) $wpdb->get_var(
		"SELECT 1
		FROM {$wpdb->postmeta} pm
		LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE p.ID IS NULL
		LIMIT 1"
	);
	$remaining_terms = (bool) $wpdb->get_var(
		"SELECT 1
		FROM {$wpdb->term_relationships} tr
		LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
		WHERE p.ID IS NULL
		LIMIT 1"
	);

	if ( $remaining_meta || $remaining_terms ) {
		phenixsync_schedule_worker_event(
			time() + PHENIXSYNC_RETRY_DELAY,
			'phenixsync_cleanup_legacy_orphan_rows',
			array()
		);
			error_log(
				'Phenix Sync: Legacy orphan cleanup removed '
				. $deleted_meta_count
				. ' post-meta rows and '
				. $deleted_relationship_count
				. ' taxonomy relationship rows; another batch was scheduled.'
			);
		return;
	}

	update_option( 'phenixsync_orphan_cleanup_version', PHENIXSYNC_ORPHAN_CLEANUP_VERSION, false );
	phenixsync_update_orphan_cleanup_status(
		array(
			'state'        => 'completed',
			'completed_at' => time(),
		)
	);
	error_log( 'Phenix Sync: Legacy orphan post-meta and taxonomy cleanup is complete. Search indexes can now be rebuilt.' );
}
add_action( 'phenixsync_cleanup_legacy_orphan_rows', 'phenixsync_cleanup_legacy_orphan_rows' );
