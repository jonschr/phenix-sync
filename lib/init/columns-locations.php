<?php

/**
 * Add custom columns to the locations CPT edit screen
 *
 * @param array $columns The existing columns.
 * @return array Modified columns.
 */
function phenix_locations_custom_columns( $columns ) {
    // Remove default columns we don't want
    unset( $columns['date'] ); // We'll add it back in the right order

    // Define the new column order
    $new_columns = array(
        'cb' => isset($columns['cb']) ? $columns['cb'] : '', // Checkbox column
        'title' => isset($columns['title']) ? $columns['title'] : __( 'Title' ), // Title column
        'api_link' => __( 'API Link', 'phenixsync-textdomain' ),
        'sync' => __( 'Sync', 'phenixsync-textdomain' ),
        'last_modified' => __( 'Last Modified', 'phenixsync-textdomain' ),
        'phenix_franchise_license_index' => __( 'Franchise License Index', 'phenixsync-textdomain' ),
        's3_index' => __( 'S3 Index', 'phenixsync-textdomain' ),
        'address1' => __( 'Address 1', 'phenixsync-textdomain' ),
        'address2' => __( 'Address 2', 'phenixsync-textdomain' ),
        'city' => __( 'City', 'phenixsync-textdomain' ),
        'state' => __( 'State', 'phenixsync-textdomain' ),
        'zip' => __( 'ZIP', 'phenixsync-textdomain' ),
        'country' => __( 'Country', 'phenixsync-textdomain' ),
        'phone' => __( 'Phone', 'phenixsync-textdomain' ),
        'phone_tree_number' => __( 'Phone Tree Number', 'phenixsync-textdomain' ),
        'two_way_texting_number' => __( 'Two Way Texting Number', 'phenixsync-textdomain' ),
        'email' => __( 'Email', 'phenixsync-textdomain' ),
        'website_url' => __( 'Website URL', 'phenixsync-textdomain' ),
        'latitude' => __( 'Latitude', 'phenixsync-textdomain' ),
        'longitude' => __( 'Longitude', 'phenixsync-textdomain' ),
        'direction' => __( 'Direction', 'phenixsync-textdomain' ),
        'time_zone' => __( 'Time Zone', 'phenixsync-textdomain' ),
        'states' => __( 'States', 'phenixsync-textdomain' ),
        'landscape_url' => __( 'Landscape URL', 'phenixsync-textdomain' ),
        'image1_url' => __( 'Image 1 URL', 'phenixsync-textdomain' ),
        'image2_url' => __( 'Image 2 URL', 'phenixsync-textdomain' ),
        'image3_url' => __( 'Image 3 URL', 'phenixsync-textdomain' ),
        'portrait_image_URL' => __( 'Portrait Image URL', 'phenixsync-textdomain' ),
        'floor_plan_image_URL' => __( 'Floor Plan Image URL', 'phenixsync-textdomain' ),
        'suite_count' => __( 'Suite Count', 'phenixsync-textdomain' ),
        'coming_soon' => __( 'Coming Soon', 'phenixsync-textdomain' ),
        'location_token' => __( 'Location Token', 'phenixsync-textdomain' ),
        'facebook_url' => __( 'Facebook URL', 'phenixsync-textdomain' ),
        'instagram_url' => __( 'Instagram URL', 'phenixsync-textdomain' ),
        'date' => __( 'Date' ),
    );
    return $new_columns;
}
add_filter( 'manage_locations_posts_columns', 'phenix_locations_custom_columns' );
add_filter( 'manage_edit-locations_sortable_columns', 'phenix_locations_sortable_columns' );
add_action( 'pre_get_posts', 'phenix_locations_orderby' );

/**
 * Populate custom columns with data
 *
 * @param string $column The column name.
 * @param int $post_id The post ID.
 */
function phenix_locations_custom_column_content( $column, $post_id ) {
    switch ( $column ) {
        case 'api_link':
            $s3_index = get_post_meta( $post_id, 's3_index', true );
            if ( $s3_index ) {
                $password = phenix_sync_get_api_password();
                $base_url = 'https://utility24.salonsuitesolutions.com/utilities/phenix_portal_locations_sender.aspx';
                $api_url = add_query_arg( array(
                    'password' => $password,
                    'unique_string' => date('YmdHis'),
                    'location_index' => $s3_index,
                ), $base_url );
                echo '<a href="' . esc_url( $api_url ) . '" target="_blank" rel="noopener noreferrer">View API</a>';
            } else {
                echo '-';
            }
            break;
        case 'sync':
            $s3_index = get_post_meta( $post_id, 's3_index', true );
            if ( $s3_index ) {
                echo '<button type="button" class="button button-small sync-location-btn" data-s3-index="' . esc_attr( $s3_index ) . '" data-post-id="' . esc_attr( $post_id ) . '">Sync Now</button>';
            } else {
                echo '-';
            }
            break;
        case 'last_modified':
            $modified_date = get_the_modified_date( 'Y/m/d H:i', $post_id );
            echo esc_html( $modified_date );
            break;
        case 'phenix_franchise_license_index':
            $value = get_post_meta( $post_id, 'phenix_franchise_license_index', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 's3_index':
            $value = get_post_meta( $post_id, 's3_index', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'address1':
            $value = get_post_meta( $post_id, 'address1', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'address2':
            $value = get_post_meta( $post_id, 'address2', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'city':
            $value = get_post_meta( $post_id, 'city', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'state':
            $value = get_post_meta( $post_id, 'state', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'zip':
            $value = get_post_meta( $post_id, 'zip', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'country':
            $value = get_post_meta( $post_id, 'country', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'phone':
            $value = get_post_meta( $post_id, 'phone', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'phone_tree_number':
            $value = get_post_meta( $post_id, 'phone_tree_number', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'two_way_texting_number':
            $value = get_post_meta( $post_id, 'two_way_texting_number', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'email':
            $value = get_post_meta( $post_id, 'email', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'website_url':
            $value = get_post_meta( $post_id, 'website_url', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'latitude':
            $value = get_post_meta( $post_id, 'latitude', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'longitude':
            $value = get_post_meta( $post_id, 'longitude', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'direction':
            $value = get_post_meta( $post_id, 'direction', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'time_zone':
            $value = get_post_meta( $post_id, 'time_zone', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'states':
            $terms = get_the_terms( $post_id, 'states' );
            if ( $terms && ! is_wp_error( $terms ) ) {
                $term_names = wp_list_pluck( $terms, 'name' );
                echo esc_html( implode( ', ', $term_names ) );
            } else {
                echo '-';
            }
            break;
        case 'landscape_url':
            $value = get_post_meta( $post_id, 'landscape_url', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $value ) . '" width="30" height="30" loading="lazy" alt="Landscape" /></a>';
            } else {
                echo '-';
            }
            break;
        case 'image1_url':
            $value = get_post_meta( $post_id, 'image1_url', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $value ) . '" width="30" height="30" loading="lazy" alt="Image 1" /></a>';
            } else {
                echo '-';
            }
            break;
        case 'image2_url':
            $value = get_post_meta( $post_id, 'image2_url', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $value ) . '" width="30" height="30" loading="lazy" alt="Image 2" /></a>';
            } else {
                echo '-';
            }
            break;
        case 'image3_url':
            $value = get_post_meta( $post_id, 'image3_url', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $value ) . '" width="30" height="30" loading="lazy" alt="Image 3" /></a>';
            } else {
                echo '-';
            }
            break;
        case 'portrait_image_URL':
            $value = get_post_meta( $post_id, 'portrait_image_URL', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $value ) . '" width="30" height="30" loading="lazy" alt="Portrait" /></a>';
            } else {
                echo '-';
            }
            break;
        case 'floor_plan_image_URL':
            $value = get_post_meta( $post_id, 'floor_plan_image_URL', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $value ) . '" width="30" height="30" loading="lazy" alt="Floor Plan" /></a>';
            } else {
                echo '-';
            }
            break;
        case 'suite_count':
            $value = get_post_meta( $post_id, 'suite_count', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'coming_soon':
            $value = get_post_meta( $post_id, 'coming_soon', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'location_token':
            $value = get_post_meta( $post_id, 'location_token', true );
            echo esc_html( $value ? $value : '-' );
            break;
        case 'facebook_url':
            $value = get_post_meta( $post_id, 'facebook_url', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
            } else {
                echo '-';
            }
            break;
        case 'instagram_url':
            $value = get_post_meta( $post_id, 'instagram_url', true );
            if ( $value ) {
                echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
            } else {
                echo '-';
            }
            break;
    }
}
add_action( 'manage_locations_posts_custom_column', 'phenix_locations_custom_column_content', 10, 2 );

/**
 * Make columns sortable
 *
 * @param array $columns The sortable columns.
 * @return array Modified sortable columns.
 */
function phenix_locations_sortable_columns( $columns ) {
    $columns['last_modified'] = 'modified';
    $columns['phenix_franchise_license_index'] = 'phenix_franchise_license_index';
    $columns['s3_index'] = 's3_index';
    $columns['city'] = 'city';
    $columns['state'] = 'state';
    $columns['zip'] = 'zip';
    $columns['country'] = 'country';
    $columns['phone'] = 'phone';
    $columns['email'] = 'email';
    $columns['suite_count'] = 'suite_count';
    return $columns;
}

/**
 * Handle sorting for custom columns
 *
 * @param WP_Query $query The query object.
 */
function phenix_locations_orderby( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }

    if ( $query->get( 'post_type' ) !== 'locations' ) {
        return;
    }

    $orderby = $query->get( 'orderby' );

    if ( $orderby ) {
        switch ( $orderby ) {
            case 'phenix_franchise_license_index':
                $query->set( 'meta_key', 'phenix_franchise_license_index' );
                $query->set( 'orderby', 'meta_value_num' );
                break;
            case 's3_index':
                $query->set( 'meta_key', 's3_index' );
                $query->set( 'orderby', 'meta_value_num' );
                break;
            case 'city':
                $query->set( 'meta_key', 'city' );
                $query->set( 'orderby', 'meta_value' );
                break;
            case 'state':
                $query->set( 'meta_key', 'state' );
                $query->set( 'orderby', 'meta_value' );
                break;
            case 'zip':
                $query->set( 'meta_key', 'zip' );
                $query->set( 'orderby', 'meta_value' );
                break;
            case 'country':
                $query->set( 'meta_key', 'country' );
                $query->set( 'orderby', 'meta_value' );
                break;
            case 'phone':
                $query->set( 'meta_key', 'phone' );
                $query->set( 'orderby', 'meta_value' );
                break;
            case 'email':
                $query->set( 'meta_key', 'email' );
                $query->set( 'orderby', 'meta_value' );
                break;
            case 'suite_count':
                $query->set( 'meta_key', 'suite_count' );
                $query->set( 'orderby', 'meta_value_num' );
                break;
        }
    }
}
