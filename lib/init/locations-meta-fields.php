<?php

/**
 * Location meta fields (from typical sync payload).
 *
 * @return array
 */
function phenixsync_get_location_meta_fields() {
	$fields = array(
		'location_name' => array(
			'label'       => 'Location Name',
			'type'        => 'text',
			'description' => 'Also updates the post title.',
		),
		'phenix_franchise_license_index' => array(
			'label' => 'Franchise License Index',
			'type'  => 'text',
		),
		's3_index' => array(
			'label'       => 'S3 Index',
			'type'        => 'text',
			'description' => 'Changing this affects syncing for the location.',
		),
		'address1' => array(
			'label' => 'Address 1',
			'type'  => 'text',
		),
		'address2' => array(
			'label' => 'Address 2',
			'type'  => 'text',
		),
		'city' => array(
			'label' => 'City',
			'type'  => 'text',
		),
		'state' => array(
			'label' => 'State',
			'type'  => 'text',
		),
		'zip' => array(
			'label' => 'ZIP',
			'type'  => 'text',
		),
		'country' => array(
			'label' => 'Country',
			'type'  => 'text',
		),
		'phone' => array(
			'label' => 'Phone',
			'type'  => 'text',
		),
		'phone_tree_number' => array(
			'label' => 'Phone Tree Number',
			'type'  => 'text',
		),
		'two_way_texting_number' => array(
			'label' => 'Two Way Texting Number',
			'type'  => 'text',
		),
		'email' => array(
			'label' => 'Email',
			'type'  => 'text',
		),
		'website_url' => array(
			'label' => 'Website URL',
			'type'  => 'url',
		),
		'latitude' => array(
			'label' => 'Latitude',
			'type'  => 'text',
		),
		'longitude' => array(
			'label' => 'Longitude',
			'type'  => 'text',
		),
		'direction' => array(
			'label' => 'Direction',
			'type'  => 'text',
		),
		'time_zone' => array(
			'label' => 'Time Zone',
			'type'  => 'text',
		),
		'landscape_url' => array(
			'label' => 'Landscape URL',
			'type'  => 'url',
		),
		'image1_url' => array(
			'label' => 'Image 1 URL',
			'type'  => 'url',
		),
		'image2_url' => array(
			'label' => 'Image 2 URL',
			'type'  => 'url',
		),
		'image3_url' => array(
			'label' => 'Image 3 URL',
			'type'  => 'url',
		),
		'portrait_image_url' => array(
			'label' => 'Portrait Image URL',
			'type'  => 'url',
		),
		'floor_plan_image_url' => array(
			'label' => 'Floor Plan Image URL',
			'type'  => 'url',
		),
		'suite_count' => array(
			'label' => 'Suite Count',
			'type'  => 'text',
		),
		'coming_soon' => array(
			'label'       => 'Coming Soon',
			'type'        => 'text',
			'description' => 'Use 1 for true, 0 for false.',
		),
		'location_token' => array(
			'label' => 'Location Token',
			'type'  => 'text',
		),
		'facebook_url' => array(
			'label' => 'Facebook URL',
			'type'  => 'url',
		),
		'instagram_url' => array(
			'label' => 'Instagram URL',
			'type'  => 'url',
		),
	);

	return apply_filters( 'phenixsync_location_meta_fields', $fields );
}

/**
 * Add location meta fields meta box.
 *
 * @return void
 */
function phenixsync_add_location_meta_fields_metabox() {
	add_meta_box(
		'phenixsync_location_meta_fields',
		'Location Sync Meta',
		'phenixsync_location_meta_fields_callback',
		'locations',
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'phenixsync_add_location_meta_fields_metabox' );

/**
 * Warn on location edit screens that sync will overwrite changes.
 *
 * @return void
 */
function phenixsync_locations_edit_warning_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || $screen->post_type !== 'locations' || $screen->base !== 'post' ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	echo 'Location sync data is read-only here and is managed in Gina\'s Platform.';
	echo '</p></div>';
}
add_action( 'admin_notices', 'phenixsync_locations_edit_warning_notice' );

/**
 * Render location meta fields meta box.
 *
 * @param WP_Post $post The post object.
 * @return void
 */
function phenixsync_location_meta_fields_callback( $post ) {
	$fields = phenixsync_get_location_meta_fields();
	$groups = array(
		'Location' => array( 'location_name', 'phenix_franchise_license_index', 's3_index', 'suite_count', 'coming_soon', 'time_zone', 'direction' ),
		'Address'  => array( 'address1', 'address2', 'city', 'state', 'zip', 'country', 'latitude', 'longitude' ),
		'Contact'  => array( 'phone', 'phone_tree_number', 'two_way_texting_number', 'email', 'website_url' ),
		'Images'   => array( 'landscape_url', 'image1_url', 'image2_url', 'image3_url', 'portrait_image_url', 'floor_plan_image_url' ),
		'Social'   => array( 'facebook_url', 'instagram_url' ),
		'System'   => array( 'location_token' ),
	);

	echo '<p class="description">This data is provided by the location sync and cannot be edited from WordPress.</p>';
	echo '<div class="phenixsync-location-meta-grid">';

	foreach ( $groups as $group_label => $keys ) {
		$items = array();
		foreach ( $keys as $key ) {
			if ( empty( $fields[ $key ] ) ) {
				continue;
			}
			$value = get_post_meta( $post->ID, $key, true );
			if ( '' === (string) $value ) {
				continue;
			}
			$items[ $key ] = $value;
		}

		if ( empty( $items ) ) {
			continue;
		}

		echo '<section class="phenixsync-location-meta-group"><h3>' . esc_html( $group_label ) . '</h3><dl>';
		foreach ( $items as $key => $value ) {
			$field = $fields[ $key ];
			$label = isset( $field['label'] ) ? $field['label'] : $key;
			echo '<dt>' . esc_html( $label ) . '</dt><dd>';
			if ( phenixsync_is_location_image_meta_field( $key ) ) {
				echo '<a class="phenixsync-location-image-link" href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">';
				echo '<img class="phenixsync-location-image-preview" src="' . esc_url( $value ) . '" alt="' . esc_attr( $label ) . ' preview" loading="lazy" />';
				echo '</a>';
				echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">Open full image</a>';
			} elseif ( isset( $field['type'] ) && 'url' === $field['type'] ) {
				echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
			} else {
				echo esc_html( $value );
			}
			echo '</dd>';
		}
		echo '</dl></section>';
	}

	echo '</div>';
}

/** Determine whether a synced location meta key contains an image URL. */
function phenixsync_is_location_image_meta_field( $key ) {
	return in_array(
		$key,
		array( 'landscape_url', 'image1_url', 'image2_url', 'image3_url', 'portrait_image_url', 'floor_plan_image_url' ),
		true
	);
}
