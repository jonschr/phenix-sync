<?php

/**
 * Add custom columns to the professionals CPT edit screen
 *
 * @param array $columns The existing columns.
 * @return array Modified columns.
 */
function phenix_professionals_custom_columns( $columns ) {
    // Remove default columns we don't want
    unset( $columns['date'] ); // We'll add it back in the right order

    // Define the new column order
    $new_columns = array(
        'cb' => isset($columns['cb']) ? $columns['cb'] : '', // Checkbox column
        'title' => isset($columns['title']) ? $columns['title'] : __( 'Title' ), // Title column
        'api_link' => __( 'API Link', 'phenixsync-textdomain' ),
        'name' => __( 'Name', 'phenixsync-textdomain' ),
        'last_modified' => __( 'Last Modified', 'phenixsync-textdomain' ),
        's3_tenant_id' => __( 'S3 Tenant ID', 'phenixsync-textdomain' ),
        's3_location_id' => __( 'S3 Location ID', 'phenixsync-textdomain' ),
        'suites' => __( 'Suites', 'phenixsync-textdomain' ),
        'location_name' => __( 'Location Name', 'phenixsync-textdomain' ),
        'services' => __( 'Services', 'phenixsync-textdomain' ),
        'bio' => __( 'Bio', 'phenixsync-textdomain' ),
        'email' => __( 'Email', 'phenixsync-textdomain' ),
        'phone' => __( 'Phone', 'phenixsync-textdomain' ),
        'profile_image' => __( 'Profile Image', 'phenixsync-textdomain' ),
        'instagram' => __( 'Instagram', 'phenixsync-textdomain' ),
        'facebook' => __( 'Facebook', 'phenixsync-textdomain' ),
        'x' => __( 'X (Twitter)', 'phenixsync-textdomain' ),
        'website' => __( 'Website', 'phenixsync-textdomain' ),
        'booking_link' => __( 'Booking Link', 'phenixsync-textdomain' ),
        'photo' => __( 'Photo', 'phenixsync-textdomain' ),
        'latitude' => __( 'Latitude', 'phenixsync-textdomain' ),
        'longitude' => __( 'Longitude', 'phenixsync-textdomain' ),
        'custom_field' => __( 'Custom Field', 'phenixsync-textdomain' ),
        'date' => __( 'Date' ),
    );
    return $new_columns;
}
add_filter( 'manage_professionals_posts_columns', 'phenix_professionals_custom_columns' );

/**
 * Populate custom columns with data
 *
 * @param string $column The column name.
 * @param int $post_id The post ID.
 */
function phenix_professionals_custom_column_content( $column, $post_id ) {
    switch ( $column ) {
        case 'api_link':
            $s3_location_id = get_post_meta( $post_id, 's3_location_id', true );
            if ( $s3_location_id ) {
                $password = phenix_sync_get_api_password();
                $base_url = 'https://utility24.salonsuitesolutions.com/utilities/phenix_portal_sender.aspx';
                $api_url = add_query_arg( array(
                    'password' => $password,
                    'unique_string' => date('YmdHis'),
                    'location_index' => $s3_location_id,
                ), $base_url );
                echo '<a href="' . esc_url( $api_url ) . '" target="_blank" rel="noopener noreferrer">View API</a>';
            } else {
                echo '-';
            }
            break;
        case 'last_modified':
            $modified_date = get_the_modified_date( 'Y/m/d H:i', $post_id );
            echo esc_html( $modified_date );
            break;
        case 'name':
            $value = get_post_meta( $post_id, 'name', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 's3_tenant_id':
            $value = get_post_meta( $post_id, 's3_tenant_id', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 's3_location_id':
            $value = get_post_meta( $post_id, 's3_location_id', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'suites':
            $value = get_post_meta( $post_id, 'suites', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'location_name':
            $value = get_post_meta( $post_id, 'location_name', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'services':
            $value = get_post_meta( $post_id, 'services', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'bio':
            $value = get_post_meta( $post_id, 'bio', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'email':
            $value = get_post_meta( $post_id, 'email', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'phone':
            $value = get_post_meta( $post_id, 'phone', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'profile_image':
            $value = get_post_meta( $post_id, 'profile_image', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $value ) . '" width="30" height="30" loading="lazy" alt="Profile" /></a>';
            } else {
                echo '-';
            }
            break;
        case 'instagram':
            $value = get_post_meta( $post_id, 'instagram', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
            } else {
                echo '-';
            }
            break;
        case 'facebook':
            $value = get_post_meta( $post_id, 'facebook', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
            } else {
                echo '-';
            }
            break;
        case 'x':
            $value = get_post_meta( $post_id, 'x', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
            } else {
                echo '-';
            }
            break;
        case 'website':
            $value = get_post_meta( $post_id, 'website', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
            } else {
                echo '-';
            }
            break;
        case 'booking_link':
            $value = get_post_meta( $post_id, 'booking_link', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
            } else {
                echo '-';
            }
            break;
        case 'photo':
            $value = get_post_meta( $post_id, 'photo', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $value ) . '" width="30" height="30" loading="lazy" alt="Photo" /></a>';
            } else {
                echo '-';
            }
            break;
        case 'latitude':
            $value = get_post_meta( $post_id, 'latitude', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'longitude':
            $value = get_post_meta( $post_id, 'longitude', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'custom_field':
            $value = get_post_meta( $post_id, 'custom_field', true );
            echo esc_html( $value ? $value : '-' );
            break;
    }
}
add_action( 'manage_professionals_posts_custom_column', 'phenix_professionals_custom_column_content', 10, 2 );