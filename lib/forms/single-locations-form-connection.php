<?php

/**
 * Send the Quote form to Yardi
 *
 * @param   array $entry  details about the entry.
 * @param   array $form   details about the form.
 *
 * @return  void
 */
function phenix_send_form_to_api_2( $entry, $form ) { // phpcs:ignore
	
	// get the fingerprint for the location
	$location_token = phenix_get_location_token();
	
	// Set all vars to null to begin, so that we can easily comment out any that don't apply.
	$first_name = null;
	$last_name = null;
	$email = null;
	$phone = null;
	$message = null;

	//! Connect up our information with our fields.
	$first_name = rgar( $entry, '1.3' );
	$last_name = rgar( $entry, '1.6' );
	$email = rgar( $entry, '2' );
	$phone = rgar( $entry, '4' );	
	$message = rgar( $entry, '3' ); // Some fields may have a message field.

	
	//* Collect data from the form fields.
	$data = array(
		'location'           => $location_token,
		'text_notification'  => 1,
		'email_notification' => 1,
		'source'             => 'Our Website',
		'first_name'         => $first_name,
		'last_name'          => $last_name,
		'email'              => $email,
		'phone'              => $phone,
		'notes'          => $message,
	);

	// Set up the request.
	$base_url  = 'https://www.findasuite.com/findasuite/add_lead.aspx';
	
	// Add data as query parameters to the URL
	$url = add_query_arg( $data, $base_url );
	
	$args = array(); // Arguments are not typically needed for a simple GET request like this

	// Send the request.
	$response = wp_remote_get( $url, $args );

	// Optional: Handle response or errors.
	if ( is_wp_error( $response ) ) {
		error_log( 'Gravity Forms request failed: ' . $response->get_error_message() ); // phpcs:ignore
	} else {
		error_log( 'Gravity Forms request succeeded: ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore
	}
}
add_action( 'gform_after_submission_2', 'phenix_send_form_to_api_2', 10, 2 );


function phenix_get_location_token() {
	// bail if this isn't a single location post
	if ( ! is_singular( 'locations' ) ) {
		return;
	}
	
	// get the current post ID
	$post_id = get_the_ID();
	
	// get the location fingerprint from the post meta
	$location_token = get_post_meta( $post_id, 'location_token', true );
		
	return $location_token;
}
