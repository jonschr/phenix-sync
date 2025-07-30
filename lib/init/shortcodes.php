<?php

function phenix_get_page_related_s3_index() {
	
	$s3_index = get_post_meta( get_the_ID(), '_phenix_s3_index', true );
	
	if ( ! empty( $s3_index ) ) {
		return $s3_index;
	}
	
	return false;
}

function phenix_get_location_meta_by_s3_index( $s3_index, $meta_key ) {
	// if no s3_index provided, try to get it from the current page
	if ( ! $s3_index ) {
		$s3_index = phenix_get_page_related_s3_index();
	}
	
	if ( ! $s3_index ) {
		return 'No S3 index found.';
	}
	
	// query for the location with the s3_index value 
	$location = get_posts( array(
		'post_type' => 'locations',
		'meta_query' => array(
			array(
				'key' => 's3_index',
				'value' => $s3_index,
				'compare' => '='
			)
		),
		'numberposts' => 1
	) );
	
	// get the meta value from that post
	if ( ! $location || empty( $location[0] ) ) {
		return null;
	}
	
	$meta_value = get_post_meta( $location[0]->ID, $meta_key, true );
	if ( ! $meta_value ) {
		return null;
	}
	
	return $meta_value;
}

function phenix_location_phone_shortcode_func( $atts ) {
	$a = shortcode_atts( array(
		's3_index' => null,
	), $atts );

	
	ob_start();

	echo phenix_get_location_meta_by_s3_index( $a['s3_index'], 'phone' );

	return ob_get_clean();
}
add_shortcode( 'phenix_location_phone', 'phenix_location_phone_shortcode_func' );

function phenix_location_address_shortcode_func( $atts ) {
	$a = shortcode_atts( array(
		's3_index' => null,
	), $atts );

	
	ob_start();

	$address1 = phenix_get_location_meta_by_s3_index( $a['s3_index'], 'address1' );
	$address2 = phenix_get_location_meta_by_s3_index( $a['s3_index'], 'address2' );
	$city = phenix_get_location_meta_by_s3_index( $a['s3_index'], 'city' );
	$state = phenix_get_location_meta_by_s3_index( $a['s3_index'], 'state' );
	$zip = phenix_get_location_meta_by_s3_index( $a['s3_index'], 'zip' );
	
	// output the address as a string separated by commas in the normal locations for an address. Don't assume that all data is present.
	$string = '';
	if ( $address1 ) {
		$string .= esc_html( $address1 );
	}
	if ( $address2 ) {
		if ( $string ) {
			$string .= ' ';
		}
		$string .= esc_html( $address2 );
	}
	if ( $city ) {
		if ( $string ) {
			$string .= ', ';
		}
		$string .= esc_html( $city );
	}
	if ( $state ) {
		if ( $string ) {
			$string .= ', ';
		}
		$string .= esc_html( $state );
	}
	if ( $zip ) {
		if ( $string ) {
			$string .= ', ';
		}
		$string .= esc_html( $zip );
	}
	
	echo $string;

	return ob_get_clean();
}
add_shortcode( 'phenix_location_address', 'phenix_location_address_shortcode_func' );

function phenix_location_city_state_shortcode_func( $atts ) {
	$a = shortcode_atts( array(
		's3_index' => null,
	), $atts );

	
	ob_start();

	$city = phenix_get_location_meta_by_s3_index( $a['s3_index'], 'city' );
	$state = phenix_get_location_meta_by_s3_index( $a['s3_index'], 'state' );
	
	// output the city and state as a string separated by a comma
	$string = '';
	if ( $city ) {
		$string .= esc_html( $city );
	}
	if ( $state ) {
		if ( $string ) {
			$string .= ', ';
		}
		$string .= esc_html( $state );
	}
	
	echo $string;

	return ob_get_clean();
}
add_shortcode( 'phenix_location_city_state', 'phenix_location_city_state_shortcode_func' );

function phenix_location_name_shortcode_func( $atts ) {
	$a = shortcode_atts( array(
		's3_index' => null,
	), $atts );

	
	ob_start();

	// if no s3_index provided, try to get it from the current page
	$s3_index = $a['s3_index'];
	if ( ! $s3_index ) {
		$s3_index = phenix_get_page_related_s3_index();
	}
	
	if ( ! $s3_index ) {
		echo 'No S3 index found.';
		return ob_get_clean();
	}
	
	// query for the location with the s3_index value 
	$location = get_posts( array(
		'post_type' => 'locations',
		'meta_query' => array(
			array(
				'key' => 's3_index',
				'value' => $s3_index,
				'compare' => '='
			)
		),
		'numberposts' => 1
	) );
	
	// get the post title
	if ( ! $location || empty( $location[0] ) ) {
		echo '';
		return ob_get_clean();
	}
	
	echo esc_html( $location[0]->post_title );

	return ob_get_clean();
}
add_shortcode( 'phenix_location_name', 'phenix_location_name_shortcode_func' );




