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

function phenix_location_phone_link_shortcode_func( $atts ) {
	$a = shortcode_atts( array(
		's3_index' => null,
	), $atts );

	$phone = phenix_get_location_meta_by_s3_index( $a['s3_index'], 'phone' );
	
	if ( ! $phone ) {
		return '';
	}
	
	// Strip all non-digits for the tel: href
	$phone_digits = preg_replace( '/[^0-9]/', '', $phone );
	
	// Format the display version as (XXX) XXX-XXXX if we have 10 digits
	if ( strlen( $phone_digits ) === 10 ) {
		$phone_display = sprintf(
			'(%s) %s-%s',
			substr( $phone_digits, 0, 3 ),
			substr( $phone_digits, 3, 3 ),
			substr( $phone_digits, 6, 4 )
		);
	} elseif ( strlen( $phone_digits ) === 11 && $phone_digits[0] === '1' ) {
		// Handle 11-digit numbers starting with 1 (US country code)
		$phone_display = sprintf(
			'+1 (%s) %s-%s',
			substr( $phone_digits, 1, 3 ),
			substr( $phone_digits, 4, 3 ),
			substr( $phone_digits, 7, 4 )
		);
	} else {
		// Fallback to original format if not standard length
		$phone_display = esc_html( $phone );
	}
	
	return sprintf( '<a href="tel:%s">%s</a>', esc_attr( $phone_digits ), $phone_display );
}
add_shortcode( 'phenix_location_phone_link', 'phenix_location_phone_link_shortcode_func' );

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

function phenix_location_address_link_shortcode_func( $atts ) {
	$a = shortcode_atts( array(
		's3_index' => null,
	), $atts );

	// Get the address using the existing shortcode function
	$address = phenix_location_address_shortcode_func( $a );
	
	if ( ! $address ) {
		return '';
	}
	
	// Build the Google Maps URL
	$maps_url = 'https://www.google.com/maps/search/?api=1&query=' . urlencode( $address );
	
	return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $maps_url ), esc_html( $address ) );
}
add_shortcode( 'phenix_location_address_link', 'phenix_location_address_link_shortcode_func' );

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

/**
 * Resolve the current location S3 index from shortcode context.
 *
 * @param string|null $s3_index Optional shortcode-provided S3 index.
 * @return string|false
 */
function phenix_global_contact_get_s3_index( $s3_index = null ) {
	if ( $s3_index ) {
		return sanitize_text_field( $s3_index );
	}

	if ( is_singular( 'locations' ) ) {
		$location_s3_index = get_post_meta( get_queried_object_id(), 's3_index', true );
		if ( $location_s3_index ) {
			return sanitize_text_field( $location_s3_index );
		}
	}

	return phenix_get_page_related_s3_index();
}

/**
 * Get all synced location tokens.
 *
 * @param int $limit Maximum number of location tokens to return. Use 0 for no limit.
 * @return array
 */
function phenix_get_all_synced_location_tokens( $limit = 0 ) {
	$limit = absint( $limit );

	$meta_query = array(
		array(
			'key'     => 'location_token',
			'value'   => '',
			'compare' => '!=',
		),
	);

	$synced_location_s3_ids = function_exists( 'phenix_sync_get_property_ids' ) ? phenix_sync_get_property_ids() : array();
	if ( is_array( $synced_location_s3_ids ) && ! empty( $synced_location_s3_ids ) ) {
		$meta_query[] = array(
			'key'     => 's3_index',
			'value'   => array_map( 'strval', $synced_location_s3_ids ),
			'compare' => 'IN',
		);
	}

	$locations = get_posts( array(
		'post_type'      => 'locations',
		'post_status'    => 'publish',
		'posts_per_page' => $limit ? $limit : -1,
		'fields'         => 'ids',
		'meta_query'     => $meta_query,
		'orderby'        => 'title',
		'order'          => 'ASC',
	) );

	$tokens = array();
	foreach ( $locations as $location_id ) {
		$token = get_post_meta( $location_id, 'location_token', true );
		if ( $token ) {
			$tokens[] = sanitize_text_field( $token );
		}
	}

	return array_values( array_unique( array_filter( $tokens ) ) );
}

/**
 * Render the Find a Suite global contact widget script.
 *
 * Examples:
 * [phenix_global_contact]
 * [phenix_global_contact s3_index="123"]
 * [phenix_global_contact ltok="token-one,token-two" redirect="https://example.com/thank-you/"]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function phenix_global_contact_shortcode_func( $atts ) {
	wp_enqueue_style(
		'phenixsync-findasuite-widget',
		PHENIX_SYNC_PATH . 'assets/css/findasuite-widget.css',
		array(),
		filemtime( PHENIX_SYNC_DIR . 'assets/css/findasuite-widget.css' )
	);

	$a = shortcode_atts( array(
		's3_index'       => null,
		'ltok'           => null,
		'location_token' => null,
		'redirect'       => null,
	), $atts );

	$tokens = array();
	$token_override = ! empty( $a['ltok'] ) ? $a['ltok'] : $a['location_token'];

	if ( $token_override ) {
		$tokens = array_map( 'trim', explode( ',', $token_override ) );
		$tokens = array_map( 'sanitize_text_field', $tokens );
	} else {
		$s3_index = phenix_global_contact_get_s3_index( $a['s3_index'] );

		if ( $s3_index ) {
			$location_token = phenix_get_location_meta_by_s3_index( $s3_index, 'location_token' );
			if ( $location_token ) {
				$tokens[] = sanitize_text_field( $location_token );
			}
		}

		if ( empty( $tokens ) && ! $s3_index ) {
			$tokens = phenix_get_all_synced_location_tokens( 20 );
		}
	}

	$tokens = array_values( array_unique( array_filter( $tokens ) ) );
	if ( empty( $tokens ) ) {
		return '';
	}

	$redirect = $a['redirect'] ? esc_url_raw( $a['redirect'] ) : home_url( '/' );
	$src = sprintf(
		'https://www.findasuite.com/findasuite/findasuite_widget2.aspx?ltok=%s&redirect=%s',
		implode( ',', array_map( 'rawurlencode', $tokens ) ),
		$redirect
	);

	return sprintf( '<script language="javascript" src="%s"></script>', esc_url( $src ) );
}
add_shortcode( 'phenix_global_contact', 'phenix_global_contact_shortcode_func' );

function phenix_location_professionals_shortcode_func( $atts ) {
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
	
	// create a new query for professionals with the corresponding s3_location_id
	$professionals_query = new WP_Query( array(
		'post_type' => 'professionals',
		'meta_query' => array(
			array(
				'key' => 's3_location_id',
				'value' => $s3_index,
				'compare' => '='
			)
		),
		'meta_key' => 'suites',
		'orderby' => 'meta_value_num',
		'order' => 'ASC',
		'posts_per_page' => -1 // get all matching professionals
	) );
	
	// check if we have professionals and loop through them
	if ( $professionals_query->have_posts() ) {
		
		echo '<div class="phenix-location-professionals-grid">';
		
			while ( $professionals_query->have_posts() ) {
				$professionals_query->the_post();
				
				// use the existing function to display each professional
				do_action( 'phenix_location_do_professional_each' );
			}
		
		echo '</div>'; // close the grid div
		
		// restore original post data
		wp_reset_postdata();
	} else {
		echo 'No professionals found for this location.';
	}

	return ob_get_clean();
}
add_shortcode( 'phenix_location_professionals', 'phenix_location_professionals_shortcode_func' );

function phenix_location_professional_each() {
	global $post;
			
	// vars
	$title = get_the_title( get_the_ID() );
	$name = get_post_meta( get_the_ID(), 'name', true );
	$phone = get_post_meta( get_the_ID(), 'phone', true );
	$email = get_post_meta( get_the_ID(), 'email', true );
	$website = get_post_meta( get_the_ID(), 'website', true );
	$booking_link = get_post_meta( get_the_ID(), 'booking_link', true );
	$facebook = get_post_meta( get_the_ID(), 'facebook', true );
	$x = get_post_meta( get_the_ID(), 'x', true );
	$instagram = get_post_meta( get_the_ID(), 'instagram', true );
	$s3_location_id = get_post_meta( get_the_ID(), 's3_location_id', true );
	$address1 = get_post_meta( get_the_ID(), 'address1', true );
	$address2 = get_post_meta( get_the_ID(), 'address2', true );
	$city = get_post_meta( get_the_ID(), 'city', true );
	$state = get_post_meta( get_the_ID(), 'state', true );
	$zip = get_post_meta( get_the_ID(), 'zip', true );
	$country = get_post_meta( get_the_ID(), 'country', true );
	$location_name = get_post_meta( get_the_ID(), 'location_name', true );
	
	$suites = get_post_meta( get_the_ID(), 'suites', true );
	// // let's turn this into an array
	$suites = explode( ',', $suites );
	
	// build the address string. Know that we might not always have all of the information, but we should be as complete as possible
	$address = '';
	if ( $address1 ) {
		$address .= $address1;
	}
	if ( $address2 ) {
		$address .= ' ' . $address2;
	}
	if ( $city ) {
		$address .= ', ' . $city;
	}
	if ( $state ) {
		$address .= ', ' . $state;
	}
	if ( $zip ) {
		$address .= ' ' . $zip;
	}
	if ( $country ) {
		$address .= ' ' . $country;
	}
	
	// // if we have no suites, make $suites null.
	if ( empty( $suites ) ) {
		$suites = null;
	}
	
	// if we have 1, make it a string: 'Suite #999'
	if ( count( $suites ) == 1 ) {
		$suites = 'Suite #' . $suites[0];
	}
	
	// if we have more than 1, make it a string like 'Suites #999, #1000, and #1001'
	if ( is_array( $suites ) && count( $suites ) > 1 ) {
		$suites = 'Suites #' . implode( ', ', $suites );
	}
	
	// get the services (tax)
	$services = get_the_terms( get_the_ID(), 'services' );
	
	
	
	// markup
	$post_class_array = get_post_class( $class = '', get_the_ID() );
	$post_class = implode( ' ', $post_class_array );
	
	printf( '<div class="%s">', $post_class );
	
		echo '<div class="location-info">';
		
			if ( $location_name ) {
				// filter the location name to remove stuff after the final dash
				$location_name = preg_replace( '/\s*-\s*[^-]*$/', '', $location_name );
				printf( '<p class="location-name">%s</p>', $location_name );
			}
		
			// output the address
			if ( $address ) {
				printf( '<p class="address">%s</p>', $address );
			}
						
		echo '</div>'; // end location info
		
		echo '<div class="professional-info">';
		
			if ( $title ) {
				printf( '<p class="title">%s</p>', $title );
			}
			
			if ( $name && !$suites ) {
				printf( '<p class="name">%s</p>', $name );	
			} elseif ( $name && $suites ) {
				printf( '<p class="name">%s</p>', $name . ', ' . $suites );	
			} elseif ( !$name && $suites ) {
				printf( '<p class="name">%s</p>', $suites );	
			}

			if ( $phone || $email || $website ) {
				echo '<ul class="contact-info">';
					if ( $phone ) {
						printf( '<li class="phone"><a href="tel:%s">%s</a></li>', $phone, $phone );
					}
					
					if ( $email ) {
						printf( '<li class="email"><a href="mailto:%s">%s</a></li>', $email, $email );
					}
					
					if ( $website ) {
						
						// get a vesrion of the website without https:// or http:// or www
						$website_display = trim( preg_replace( '#^(https?:\/\/)?(www\.)?([^\/?]+)[\/]*$#', '$3', $website ) );
						
						printf( '<li class="website"><a href="%s" target="_blank">%s</a></li>', $website, $website_display );
					}

				echo '</ul>';
			}
			
			$social_links = '<ul class="wp-block-social-links has-icon-color has-icon-background-color is-layout-flex wp-block-social-links-is-layout-flex">';

			if ( ! empty( $facebook ) ) {
				$social_links .= sprintf(
					'<li style="color: var(--white-color); background-color: var(--contrast);" class="wp-social-link wp-social-link-facebook has-white-color-color has-contrast-background-color wp-block-social-link">
						<a href="%s" target="_blank" class="wp-block-social-link-anchor">
							<svg width="24" height="24" viewBox="0 0 24 24" version="1.1" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
								<path d="M12 2C6.5 2 2 6.5 2 12c0 5 3.7 9.1 8.4 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.3v7C18.3 21.1 22 17 22 12c0-5.5-4.5-10-10-10z"></path>
							</svg>
							<span class="wp-block-social-link-label screen-reader-text">Facebook</span>
						</a>
					</li>',
					esc_url( $facebook )
				);
			}

			if ( ! empty( $x ) ) {
				$social_links .= sprintf(
					'<li style="color: var(--white-color); background-color: var(--contrast);" class="wp-social-link wp-social-link-x has-white-color-color has-contrast-background-color wp-block-social-link">
						<a href="%s" target="_blank" class="wp-block-social-link-anchor">
							<svg width="24" height="24" viewBox="0 0 24 24" version="1.1" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
								<path d="M13.982 10.622 20.54 3h-1.554l-5.693 6.618L8.745 3H3.5l6.876 10.007L3.5 21h1.554l6.012-6.989L15.868 21h5.245l-7.131-10.378Zm-2.128 2.474-.697-.997-5.543-7.93H8l4.474 6.4.697.996 5.815 8.318h-2.387l-4.745-6.787Z"></path>
							</svg>
							<span class="wp-block-social-link-label screen-reader-text">X</span>
						</a>
					</li>',
					esc_url( $x )
				);
			}

			if ( ! empty( $instagram ) ) {
				$social_links .= sprintf(
					'<li style="color: var(--white-color); background-color: var(--contrast);" class="wp-social-link wp-social-link-instagram has-white-color-color has-contrast-background-color wp-block-social-link">
						<a href="%s" target="_blank" class="wp-block-social-link-anchor">
							<svg width="24" height="24" viewBox="0 0 24 24" version="1.1" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
								<path d="M12,2.2c3.2,0,3.6,0,4.9,0.1c3.3,0.1,4.8,1.7,4.9,4.9c0.1,1.3,0.1,1.6,0.1,4.8s0,3.6-0.1,4.8 c-0.1,3.2-1.7,4.8-4.9,4.9c-1.3,0.1-1.6,0.1-4.8,0.1s-3.6,0-4.8-0.1c-3.2-0.1-4.8-1.7-4.9-4.9c-0.1-1.3-0.1-1.6-0.1-4.8 s0-3.6,0.1-4.8c0.1-3.2,1.7-4.8,4.9-4.9C8.4,2.2,8.8,2.2,12,2.2 M12,7c-2.8,0-5,2.2-5,5s2.2,5,5,5s5-2.2,5-5S14.8,7,12,7z M12,9.9c1.2,0,2.1,0.9,2.1,2.1s-0.9,2.1-2.1,2.1s-2.1-0.9-2.1-2.1S10.8,9.9,12,9.9z M18.4,4.6c0,0.7-0.6,1.2-1.2,1.2 s-1.2-0.6-1.2-1.2s0.6-1.2,1.2-1.2S18.4,4,18.4,4.6z"></path>
							</svg>
							<span class="wp-block-social-link-label screen-reader-text">Instagram</span>
						</a>
					</li>',
					esc_url( $instagram )
				);
			}

			$social_links .= '</ul>';

			// Only output if we have at least one social link
			if ( ! empty( $facebook ) || ! empty( $x ) || ! empty( $instagram ) ) {
				echo $social_links;
			}
			
			$services_string = '';
			if ( $services && ! is_wp_error( $services ) ) {
				
				echo '<p class="services"><span class="services-label">Services</span>';
				
				echo '<div class="services-list">';
				
					foreach( $services as $service ) {
						$label = $service->name;
						
						printf( '<span class="service">%s</span>', $label );
					}
				
				echo '</div>';
				
			}
			
			if ( $booking_link ) {
				printf( 
					'<div class="wp-block-buttons is-layout-flex wp-block-buttons-is-layout-flex">
						<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" target="_blank" href="%s">Book Now</a></div>
					</div>', 
					esc_url( $booking_link ) 
				); 
			}

		echo '</div>'; // end professional info
	echo '</div>';
}
add_action( 'phenix_location_do_professional_each', 'phenix_location_professional_each' );
