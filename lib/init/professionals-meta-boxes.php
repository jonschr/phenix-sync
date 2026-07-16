<?php

/** Add read-only sync information panels to professional edit screens. */
function phenixsync_add_professional_sync_meta_boxes() {
	add_meta_box(
		'phenixsync_professional_location',
		'Associated Location',
		'phenixsync_render_professional_location_meta_box',
		'professionals',
		'side',
		'high'
	);

	add_meta_box(
		'phenixsync_professional_current_data',
		'Current Synced Professional',
		'phenixsync_render_professional_current_data_meta_box',
		'professionals',
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'phenixsync_add_professional_sync_meta_boxes' );

/** Link a professional to the location identified by its synced S3 index. */
function phenixsync_render_professional_location_meta_box( $post ) {
	$s3_location_id = get_post_meta( $post->ID, 's3_location_id', true );
	$location_id    = $s3_location_id ? phenixsync_locations_get_post_by_external_id( $s3_location_id ) : false;

	if ( ! $location_id ) {
		echo '<p>No associated location could be found for this professional.</p>';
		if ( $s3_location_id ) {
			echo '<p><strong>Location S3 Index:</strong> ' . esc_html( $s3_location_id ) . '</p>';
		}
		return;
	}

	echo '<p><strong>' . esc_html( get_the_title( $location_id ) ) . '</strong></p>';
	echo '<p><strong>Location S3 Index:</strong> ' . esc_html( $s3_location_id ) . '</p>';
	echo '<p><a class="button button-secondary" href="' . esc_url( get_edit_post_link( $location_id, '' ) ) . '">Open associated location</a></p>';
	echo '<p class="description">The location edit screen contains the professionals sync history and debug details.</p>';
}

/** Show the professional's latest synced data without allowing edits. */
function phenixsync_render_professional_current_data_meta_box( $post ) {
	$fields = array(
		'Name'          => 'name',
		'S3 Tenant ID'  => 's3_tenant_id',
		'Suites'        => 'suites',
		'Email'         => 'email',
		'Phone'         => 'phone',
		'Website'       => 'website',
		'Booking Link'  => 'booking_link',
		'Instagram'     => 'instagram',
		'Facebook'      => 'facebook',
		'X (Twitter)'   => 'x',
		'Last Synced'   => 'updated',
	);
	$url_fields = array( 'website', 'booking_link', 'instagram', 'facebook' );

	echo '<p class="description">This is the latest data supplied by the professionals sync. Sync history and request debugging are available on the associated location.</p>';
	$profile_image = get_post_meta( $post->ID, 'profile_image', true );
	if ( $profile_image ) {
		echo '<a class="phenixsync-professional-profile-image-link" href="' . esc_url( $profile_image ) . '" target="_blank" rel="noopener noreferrer"><img class="phenixsync-professional-profile-image" src="' . esc_url( $profile_image ) . '" alt="' . esc_attr( get_the_title( $post ) ) . ' profile image" loading="lazy" /></a>';
	}

	echo '<dl class="phenixsync-professional-current-data">';
	foreach ( $fields as $label => $key ) {
		$value = get_post_meta( $post->ID, $key, true );
		if ( '' === (string) $value ) {
			continue;
		}
		echo '<dt>' . esc_html( $label ) . '</dt><dd>';
		if ( in_array( $key, $url_fields, true ) ) {
			echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
		} else {
			echo esc_html( $value );
		}
		echo '</dd>';
	}
	echo '</dl>';

	$bio = get_post_meta( $post->ID, 'bio', true );
	if ( $bio ) {
		echo '<h3>Bio</h3><div class="phenixsync-professional-bio">' . wp_kses_post( wpautop( $bio ) ) . '</div>';
	}
}
