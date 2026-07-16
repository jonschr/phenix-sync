<?php

/**
 * Add side meta boxes with sync controls to individual location and professional edit screens.
 *
 * @return void
 */
function phenixsync_add_individual_sync_meta_boxes() {
	add_meta_box(
		'phenixsync_location_professionals_sync',
		'Sync Professionals',
		'phenixsync_render_location_professionals_sync_meta_box',
		'locations',
		'side',
		'high'
	);

	add_meta_box(
		'phenixsync_location_sync',
		'Sync This Location',
		'phenixsync_render_location_sync_meta_box',
		'locations',
		'side',
		'low'
	);

	add_meta_box(
		'phenixsync_professional_location_sync',
		'Sync Professionals',
		'phenixsync_render_professional_location_sync_meta_box',
		'professionals',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'phenixsync_add_individual_sync_meta_boxes' );

/**
 * Render the sync control on a location edit screen.
 *
 * @param WP_Post $post The current location post.
 * @return void
 */
function phenixsync_render_location_professionals_sync_meta_box( $post ) {
	$s3_index = get_post_meta( $post->ID, 's3_index', true );

	phenixsync_render_location_professionals_sync_button(
		$post->ID,
		$s3_index,
		'Run the professionals sync for this location using its current S3 index.'
	);
}

/** Render the single-location sync control directly below the professionals sync control. */
function phenixsync_render_location_sync_meta_box( $post ) {
	$s3_index     = get_post_meta( $post->ID, 's3_index', true );
	$sync_enabled = phenix_sync_is_enabled();

	if ( empty( $s3_index ) ) {
		echo '<p>This location does not have an S3 index available for syncing.</p>';
		return;
	}

	$disabled_attr = $sync_enabled ? '' : ' disabled="disabled"';
	$title_attr    = $sync_enabled ? '' : ' title="Sync is disabled in settings."';
	$label         = $sync_enabled ? 'Sync This Location' : 'Sync Disabled';

	printf(
		'<p><button type="button" class="button button-secondary sync-location-btn"%1$s%2$s data-s3-index="%3$s" data-post-id="%4$d">%5$s</button></p>',
		$disabled_attr,
		$title_attr,
		esc_attr( $s3_index ),
		(int) $post->ID,
		esc_html( $label )
	);

	echo '<p>Refresh this location\'s synced data and record a new location sync debug entry.</p>';
	echo '<p><strong>Location S3 Index:</strong> ' . esc_html( $s3_index ) . '</p>';
}

/**
 * Render the sync control on a professional edit screen.
 *
 * @param WP_Post $post The current professional post.
 * @return void
 */
function phenixsync_render_professional_location_sync_meta_box( $post ) {
	$s3_location_id = get_post_meta( $post->ID, 's3_location_id', true );

	phenixsync_render_location_professionals_sync_button(
		$post->ID,
		$s3_location_id,
		'Run the professionals sync for the location associated with this professional.'
	);
}

/**
 * Output a professionals sync button that reuses the existing location-level professionals sync AJAX path.
 *
 * @param int    $post_id The current post ID.
 * @param string $location_id The location S3 index to sync professionals for.
 * @param string $description Help text shown under the button.
 * @return void
 */
function phenixsync_render_location_professionals_sync_button( $post_id, $location_id, $description ) {
	$sync_enabled = phenix_sync_is_enabled();

	if ( empty( $location_id ) ) {
		echo '<p>This record does not have a location S3 index available for professionals sync.</p>';
		return;
	}

	$disabled_attr = $sync_enabled ? '' : ' disabled="disabled"';
	$title_attr    = $sync_enabled ? '' : ' title="Sync is disabled in settings."';
	$label         = $sync_enabled ? 'Sync Professionals' : 'Sync Disabled';

	printf(
		'<p><button type="button" class="button button-secondary sync-professional-btn"%1$s%2$s data-location-id="%3$s" data-post-id="%4$d">%5$s</button></p>',
		$disabled_attr,
		$title_attr,
		esc_attr( $location_id ),
		(int) $post_id,
		esc_html( $label )
	);

	printf( '<p>%s</p>', esc_html( $description ) );
	printf( '<p><strong>Location S3 Index:</strong> %s</p>', esc_html( $location_id ) );
}
