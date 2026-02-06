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
	echo 'Changes made here will be overwritten on next sync. Please do not make changes to here. Those need to be made within Gina\'s Platform';
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
	wp_nonce_field( 'phenixsync_location_meta_fields', 'phenixsync_location_meta_fields_nonce' );

	$fields = phenixsync_get_location_meta_fields();

	echo '<table class="form-table">';

	foreach ( $fields as $key => $field ) {
		$label = isset( $field['label'] ) ? $field['label'] : $key;
		$type  = isset( $field['type'] ) ? $field['type'] : 'text';
		$value = get_post_meta( $post->ID, $key, true );

		$field_id = 'phenixsync_location_meta_' . $key;

		echo '<tr>';
		echo '<th scope="row"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td>';

		$input_type = 'text';
		if ( 'url' === $type ) {
			$input_type = 'url';
		}

		echo '<input type="' . esc_attr( $input_type ) . '" id="' . esc_attr( $field_id ) . '" name="phenixsync_location_meta[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="regular-text" />';

		if ( ! empty( $field['description'] ) ) {
			echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
		}

		echo '</td>';
		echo '</tr>';
	}

	echo '</table>';
}

/**
 * Save location meta fields.
 *
 * @param int $post_id The post ID.
 * @return void
 */
function phenixsync_save_location_meta_fields( $post_id ) {
	if ( ! isset( $_POST['phenixsync_location_meta_fields_nonce'] ) || ! wp_verify_nonce( $_POST['phenixsync_location_meta_fields_nonce'], 'phenixsync_location_meta_fields' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( get_post_type( $post_id ) !== 'locations' ) {
		return;
	}

	$posted = isset( $_POST['phenixsync_location_meta'] ) && is_array( $_POST['phenixsync_location_meta'] ) ? $_POST['phenixsync_location_meta'] : array();
	$fields = phenixsync_get_location_meta_fields();

	foreach ( $fields as $key => $field ) {
		if ( ! array_key_exists( $key, $posted ) ) {
			continue;
		}

		$value = sanitize_text_field( $posted[ $key ] );
		update_post_meta( $post_id, $key, $value );
	}

	if ( array_key_exists( 'location_name', $posted ) ) {
		$location_name = sanitize_text_field( $posted['location_name'] );
		if ( $location_name !== '' ) {
			$current_title = get_post_field( 'post_title', $post_id );
			if ( $current_title !== $location_name ) {
				remove_action( 'save_post', 'phenixsync_save_location_meta_fields' );
				wp_update_post( array(
					'ID'         => $post_id,
					'post_title' => $location_name,
				) );
				add_action( 'save_post', 'phenixsync_save_location_meta_fields' );
			}
		}
	}
}
add_action( 'save_post', 'phenixsync_save_location_meta_fields' );
