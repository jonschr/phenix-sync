<?php

define( 'PHENIXSYNC_DEBUG_DB_VERSION', '2' );
define( 'PHENIXSYNC_DEBUG_HISTORY_LIMIT', 5 );

/**
 * Return the site-specific debug log table name.
 *
 * @return string
 */
function phenixsync_debug_table_name() {
	global $wpdb;

	return $wpdb->prefix . 'phenix_sync_debug_log';
}

/**
 * Create or update the debug log table.
 *
 * @return bool Whether the table is available after the operation.
 */
function phenixsync_install_debug_table() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = phenixsync_debug_table_name();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		location_post_id bigint(20) unsigned NOT NULL,
		sync_type varchar(32) NOT NULL,
		response_timestamp bigint(20) unsigned NOT NULL DEFAULT 0,
		details longtext NOT NULL,
		entry_hash char(64) NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY location_type_hash (location_post_id, sync_type, entry_hash),
		KEY location_type_time (location_post_id, sync_type, response_timestamp)
	) {$charset_collate};";

	dbDelta( $sql );

	$table_exists = $table_name === $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) )
	);

	if ( $table_exists && phenixsync_prune_all_sync_debug_details() ) {
		update_option( 'phenixsync_debug_db_version', PHENIXSYNC_DEBUG_DB_VERSION, false );
	}

	return $table_exists;
}

/**
 * Install the table after deployment without requiring plugin reactivation.
 */
function phenixsync_maybe_install_debug_table() {
	if ( PHENIXSYNC_DEBUG_DB_VERSION !== get_option( 'phenixsync_debug_db_version' ) ) {
		phenixsync_install_debug_table();
	}
}
add_action( 'init', 'phenixsync_maybe_install_debug_table', 5 );
register_activation_hook( PHENIX_SYNC_FILE, 'phenixsync_install_debug_table' );

/**
 * Ensure the table is ready before a read or write.
 *
 * @param bool $force Force dbDelta to run even if the schema version matches.
 * @return bool
 */
function phenixsync_ensure_debug_table( $force = false ) {
	static $is_ready = null;

	if ( ! $force && null !== $is_ready ) {
		return $is_ready;
	}

	if ( ! $force && PHENIXSYNC_DEBUG_DB_VERSION === get_option( 'phenixsync_debug_db_version' ) ) {
		$is_ready = true;
		return true;
	}

	$is_ready = phenixsync_install_debug_table();
	return $is_ready;
}

/**
 * Delete entries outside the retained history window.
 *
 * @param int    $location_post_id Location post ID.
 * @param string $sync_type        Sync type.
 * @return void
 */
function phenixsync_prune_sync_debug_details( $location_post_id, $sync_type ) {
	global $wpdb;

	$table_name = phenixsync_debug_table_name();
	$entry_ids  = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id
			FROM {$table_name}
			WHERE location_post_id = %d
				AND sync_type = %s
			ORDER BY response_timestamp DESC, id DESC",
			$location_post_id,
			$sync_type
		)
	);

	if ( count( $entry_ids ) <= PHENIXSYNC_DEBUG_HISTORY_LIMIT ) {
		return;
	}

	$delete_ids = array_map(
		'absint',
		array_slice( $entry_ids, PHENIXSYNC_DEBUG_HISTORY_LIMIT )
	);

	if ( empty( $delete_ids ) ) {
		return;
	}

	$wpdb->query(
		"DELETE FROM {$table_name}
		WHERE id IN (" . implode( ',', $delete_ids ) . ')'
	);
}

/**
 * Apply the current history limit to every existing location/type pair.
 *
 * @return bool Whether the existing rows were inspected and pruned.
 */
function phenixsync_prune_all_sync_debug_details() {
	global $wpdb;

	$table_name = phenixsync_debug_table_name();
	$rows       = $wpdb->get_results(
		"SELECT id, location_post_id, sync_type
		FROM {$table_name}
		ORDER BY location_post_id, sync_type, response_timestamp DESC, id DESC"
	);

	if ( ! is_array( $rows ) ) {
		return false;
	}

	$counts     = array();
	$delete_ids = array();

	foreach ( $rows as $row ) {
		$group = absint( $row->location_post_id ) . ':' . sanitize_key( $row->sync_type );
		$counts[ $group ] = isset( $counts[ $group ] ) ? $counts[ $group ] + 1 : 1;

		if ( $counts[ $group ] > PHENIXSYNC_DEBUG_HISTORY_LIMIT ) {
			$delete_ids[] = absint( $row->id );
		}
	}

	foreach ( array_chunk( $delete_ids, 500 ) as $delete_batch ) {
		$deleted = $wpdb->query(
			"DELETE FROM {$table_name}
			WHERE id IN (" . implode( ',', $delete_batch ) . ')'
		);

		if ( false === $deleted ) {
			return false;
		}
	}

	return true;
}

/**
 * Insert one debug history entry.
 *
 * @param int         $location_post_id Location post ID.
 * @param string      $sync_type        Either "locations" or "professionals".
 * @param mixed       $details          Complete debug details.
 * @param string|null $entry_identity   Stable migration identity, if applicable.
 * @param bool        $prune            Whether to enforce the history limit.
 * @return bool
 */
function phenixsync_store_sync_debug_details( $location_post_id, $sync_type, $details, $entry_identity = null, $prune = true ) {
	global $wpdb;

	$location_post_id = absint( $location_post_id );
	$sync_type        = sanitize_key( $sync_type );

	if ( ! $location_post_id || ! in_array( $sync_type, array( 'locations', 'professionals' ), true ) ) {
		return false;
	}

	if ( ! phenixsync_ensure_debug_table() ) {
		return false;
	}

	$table_name          = phenixsync_debug_table_name();
	$serialized_details  = maybe_serialize( $details );
	$response_timestamp  = is_array( $details ) && isset( $details['response_timestamp'] )
		? absint( $details['response_timestamp'] )
		: time();
	$entry_identity      = null === $entry_identity ? 'entry' : (string) $entry_identity;
	$entry_hash          = hash( 'sha256', $entry_identity . '|' . $serialized_details );
	$created_at           = gmdate( 'Y-m-d H:i:s' );

	$insert_result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table_name}
				(location_post_id, sync_type, response_timestamp, details, entry_hash, created_at)
			VALUES (%d, %s, %d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE id = id",
			$location_post_id,
			$sync_type,
			$response_timestamp,
			$serialized_details,
			$entry_hash,
			$created_at
		)
	);

	if ( false === $insert_result && phenixsync_ensure_debug_table( true ) ) {
		$insert_result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table_name}
					(location_post_id, sync_type, response_timestamp, details, entry_hash, created_at)
				VALUES (%d, %s, %d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE id = id",
				$location_post_id,
				$sync_type,
				$response_timestamp,
				$serialized_details,
				$entry_hash,
				$created_at
			)
		);
	}

	if ( false === $insert_result ) {
		error_log( "Phenix Sync: Unable to store {$sync_type} debug details for location post {$location_post_id}." );
		return false;
	}

	if ( $prune ) {
		phenixsync_prune_sync_debug_details( $location_post_id, $sync_type );
	}

	return true;
}

/**
 * Fetch retained debug history from the custom table.
 *
 * @param int    $location_post_id Location post ID.
 * @param string $sync_type        Sync type.
 * @param int    $limit            Maximum number of entries.
 * @return array
 */
function phenixsync_get_stored_sync_debug_details( $location_post_id, $sync_type, $limit = PHENIXSYNC_DEBUG_HISTORY_LIMIT ) {
	global $wpdb;

	$location_post_id = absint( $location_post_id );
	$sync_type        = sanitize_key( $sync_type );
	$limit            = max( 1, absint( $limit ) );

	if ( ! $location_post_id || ! phenixsync_ensure_debug_table() ) {
		return array();
	}

	$table_name = phenixsync_debug_table_name();
	$rows       = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT details
			FROM {$table_name}
			WHERE location_post_id = %d
				AND sync_type = %s
			ORDER BY response_timestamp DESC, id DESC
			LIMIT %d",
			$location_post_id,
			$sync_type,
			$limit
		)
	);

	if ( null === $rows && phenixsync_ensure_debug_table( true ) ) {
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT details
				FROM {$table_name}
				WHERE location_post_id = %d
					AND sync_type = %s
				ORDER BY response_timestamp DESC, id DESC
				LIMIT %d",
				$location_post_id,
				$sync_type,
				$limit
			)
		);
	}

	return array_map( 'maybe_unserialize', is_array( $rows ) ? $rows : array() );
}

/**
 * Move both legacy post-meta histories for a location into the custom table.
 *
 * A meta field is deleted only after every entry in that field has been
 * inserted successfully. Content hashes plus occurrence numbers make retries
 * idempotent while preserving duplicate historical entries.
 *
 * @param int $location_post_id Location post ID.
 * @return bool Whether all applicable legacy fields were migrated.
 */
function phenixsync_migrate_legacy_sync_debug_meta( $location_post_id ) {
	static $migration_results = array();

	$location_post_id = absint( $location_post_id );

	if ( isset( $migration_results[ $location_post_id ] ) ) {
		return $migration_results[ $location_post_id ];
	}

	$legacy_fields = array(
		'locations_sync_details'     => 'locations',
		'professionals_sync_details' => 'professionals',
	);
	$all_migrated  = true;

	foreach ( $legacy_fields as $meta_key => $sync_type ) {
		if ( ! metadata_exists( 'post', $location_post_id, $meta_key ) ) {
			continue;
		}

		$legacy_value = get_post_meta( $location_post_id, $meta_key, true );
		$entries      = is_array( $legacy_value ) ? $legacy_value : array( $legacy_value );
		$occurrences  = array();
		$field_saved  = true;

		foreach ( $entries as $entry ) {
			$content_hash = hash( 'sha256', maybe_serialize( $entry ) );

			if ( ! isset( $occurrences[ $content_hash ] ) ) {
				$occurrences[ $content_hash ] = 0;
			}

			$occurrences[ $content_hash ]++;
			$entry_identity = 'legacy:' . $meta_key . ':' . $content_hash . ':' . $occurrences[ $content_hash ];

			if ( ! phenixsync_store_sync_debug_details( $location_post_id, $sync_type, $entry, $entry_identity, false ) ) {
				$field_saved = false;
				break;
			}
		}

		if ( $field_saved ) {
			phenixsync_prune_sync_debug_details( $location_post_id, $sync_type );
			$deleted = delete_post_meta( $location_post_id, $meta_key );

			if ( ! $deleted && metadata_exists( 'post', $location_post_id, $meta_key ) ) {
				$all_migrated = false;
			}
		} else {
			$all_migrated = false;
		}
	}

	$migration_results[ $location_post_id ] = $all_migrated;
	return $all_migrated;
}

/**
 * Preserve a new entry in legacy meta if the custom table is unavailable.
 *
 * @param int    $location_post_id Location post ID.
 * @param string $sync_type        Sync type.
 * @param mixed  $details          Debug details.
 * @return void
 */
function phenixsync_store_legacy_sync_debug_fallback( $location_post_id, $sync_type, $details ) {
	$meta_key = 'professionals' === $sync_type
		? 'professionals_sync_details'
		: 'locations_sync_details';
	$existing = get_post_meta( $location_post_id, $meta_key, true );
	$existing = is_array( $existing ) ? $existing : array();

	array_unshift( $existing, $details );
	update_post_meta( $location_post_id, $meta_key, array_slice( $existing, 0, PHENIXSYNC_DEBUG_HISTORY_LIMIT ) );
}

/**
 * Migrate legacy history and save a new debug entry.
 *
 * @param int    $location_post_id Location post ID.
 * @param string $sync_type        Sync type.
 * @param mixed  $details          Debug details.
 * @return bool
 */
function phenixsync_save_sync_debug_details( $location_post_id, $sync_type, $details ) {
	$migrated = phenixsync_migrate_legacy_sync_debug_meta( $location_post_id );

	if ( ! $migrated ) {
		phenixsync_store_legacy_sync_debug_fallback( $location_post_id, $sync_type, $details );
		return false;
	}

	if ( phenixsync_store_sync_debug_details( $location_post_id, $sync_type, $details ) ) {
		return true;
	}

	phenixsync_store_legacy_sync_debug_fallback( $location_post_id, $sync_type, $details );
	return false;
}

/**
 * Read table-backed history, falling back to legacy post meta before migration.
 *
 * @param int    $location_post_id Location post ID.
 * @param string $sync_type        Sync type.
 * @return array
 */
function phenixsync_get_sync_debug_details( $location_post_id, $sync_type ) {
	$stored = phenixsync_get_stored_sync_debug_details( $location_post_id, $sync_type );
	$meta_key = 'professionals' === $sync_type
		? 'professionals_sync_details'
		: 'locations_sync_details';
	$legacy   = get_post_meta( $location_post_id, $meta_key, true );
	$legacy   = is_array( $legacy ) ? $legacy : array();

	if ( empty( $legacy ) ) {
		return $stored;
	}

	$combined = array();
	$seen     = array();

	foreach ( array_merge( $stored, $legacy ) as $entry ) {
		$entry_hash = hash( 'sha256', maybe_serialize( $entry ) );

		if ( isset( $seen[ $entry_hash ] ) ) {
			continue;
		}

		$seen[ $entry_hash ] = true;
		$combined[]          = $entry;
	}

	usort(
		$combined,
		function ( $first, $second ) {
			$first_timestamp  = is_array( $first ) && isset( $first['response_timestamp'] ) ? absint( $first['response_timestamp'] ) : 0;
			$second_timestamp = is_array( $second ) && isset( $second['response_timestamp'] ) ? absint( $second['response_timestamp'] ) : 0;

			return $second_timestamp <=> $first_timestamp;
		}
	);

	return array_slice( $combined, 0, PHENIXSYNC_DEBUG_HISTORY_LIMIT );
}

/**
 * Remove custom debug history when its location post is permanently deleted.
 *
 * @param int $post_id Deleted post ID.
 * @return void
 */
function phenixsync_delete_sync_debug_details( $post_id ) {
	global $wpdb;

	if ( PHENIXSYNC_DEBUG_DB_VERSION !== get_option( 'phenixsync_debug_db_version' ) ) {
		return;
	}

	$table_name = phenixsync_debug_table_name();
	$wpdb->delete(
		$table_name,
		array( 'location_post_id' => absint( $post_id ) ),
		array( '%d' )
	);
}
add_action( 'deleted_post', 'phenixsync_delete_sync_debug_details' );
