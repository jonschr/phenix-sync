<?php

// we need to add to the backend editor for each website, and we want to show the meta information there.

function phenix_add_professionals_sync_information_to_locations() {
	add_meta_box(
		'phenix_professionals_sync_debug_information',
		'Professionals Sync Debug Information',
		'phenix_professionals_info_on_locations_callback',
		'locations',
		'normal',
		'high'
	);

	add_meta_box(
		'phenix_locations_sync_debug_information',
		'Location Sync Debug Information',
		'phenix_locations_sync_debug_information_callback',
		'locations',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'phenix_add_professionals_sync_information_to_locations' );

/** Encode a debug payload as valid, pretty JSON for the foldable response viewer. */
function phenixsync_encode_debug_json_preview( $payload ) {
	$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
	$preview = wp_json_encode( $payload, $flags );

	if ( is_string( $preview ) && '' !== $preview ) {
		return $preview;
	}

	// Preserve a valid JSON document even if an unexpected value cannot be encoded.
	return '{"debug_error":"The response preview could not be encoded."}';
}

function phenix_professionals_info_on_locations_callback( $post ) {
	phenixsync_render_sync_debug_panel(
		get_post_meta( $post->ID, 'professionals_sync_details', true ),
		get_post_meta( $post->ID, 's3_index', true ),
		'professionals'
	);
}

/** Render the location API debug panel. */
function phenix_locations_sync_debug_information_callback( $post ) {
	phenixsync_render_sync_debug_panel(
		get_post_meta( $post->ID, 'locations_sync_details', true ),
		get_post_meta( $post->ID, 's3_index', true ),
		'locations'
	);
}

/** Render a compact, readable history of sync requests. */
function phenixsync_render_sync_debug_panel( $details, $s3_index, $sync_type ) {
	if ( ! is_array( $details ) || empty( $details ) ) {
		echo '<p>No ' . esc_html( $sync_type ) . ' sync requests have been recorded for this location yet.</p>';
		return;
	}

	echo '<p><strong>Location S3 Index:</strong> ' . esc_html( $s3_index ? $s3_index : 'n/a' ) . '</p>';
	echo '<table class="widefat striped phenixsync-debug-table"><thead><tr><th>Details</th><th>When</th><th>Result</th><th>Response</th><th>Records</th></tr></thead><tbody>';
	$modals = '';
	foreach ( $details as $index => $request ) {
		if ( ! is_array( $request ) ) {
			continue;
		}
		$code = isset( $request['response_code'] ) ? (string) $request['response_code'] : 'n/a';
		$shape = isset( $request['response_shape'] ) ? $request['response_shape'] : 'n/a';
		$count = 'professionals' === $sync_type ? ( isset( $request['professional_count'] ) ? $request['professional_count'] : 0 ) : ( isset( $request['location_count'] ) ? $request['location_count'] : 0 );
		$modal_id = 'phenixsync-debug-modal-' . sanitize_html_class( $sync_type ) . '-' . absint( $index );
		echo '<tr><td><a href="#' . esc_attr( $modal_id ) . '" class="phenixsync-debug-modal-open">View details</a></td><td>' . esc_html( phenixsync_format_debug_time( $request ) ) . '</td><td>' . esc_html( $code ) . '</td><td>' . esc_html( $shape ) . '</td><td>' . esc_html( $count ) . '</td></tr>';
		ob_start();
		echo '<div id="' . esc_attr( $modal_id ) . '" class="phenixsync-debug-modal" role="dialog" aria-modal="true" aria-labelledby="' . esc_attr( $modal_id ) . '-title" aria-hidden="true" hidden><div class="phenixsync-debug-modal-backdrop" data-phenixsync-modal-close></div><div class="phenixsync-debug-modal-dialog" role="document" tabindex="-1"><div class="phenixsync-debug-modal-header"><h2 id="' . esc_attr( $modal_id ) . '-title">' . esc_html( ucfirst( $sync_type ) ) . ' sync request</h2><button type="button" class="button-link phenixsync-debug-modal-close" aria-label="Close details" data-phenixsync-modal-close>&times;</button></div><div class="phenixsync-debug-modal-content">';
		echo '<dl class="phenixsync-debug-details">';
		foreach ( array( 'response_size' => 'Response size', 'api_status' => 'API status', 'request_error' => 'Request error', 'json_error' => 'JSON error', 'top_level_keys' => 'Top-level keys' ) as $key => $label ) {
			if ( empty( $request[ $key ] ) && '0' !== (string) ( $request[ $key ] ?? '' ) ) { continue; }
			$value = is_array( $request[ $key ] ) ? implode( ', ', $request[ $key ] ) : $request[ $key ];
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl>';
		if ( ! empty( $request['raw_response_preview'] ) ) {
			phenixsync_render_debug_response_preview(
				$request['raw_response_preview'],
				isset( $request['raw_response_preview_encoding'] ) ? $request['raw_response_preview_encoding'] : ''
			);
		}
		echo '</div></div></div>';
		$modals .= ob_get_clean();
	}
	echo '</tbody></table>';
	echo $modals; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated and escaped above.
}

/** Render a saved response as a syntax-coloured, foldable JSON tree when possible. */
function phenixsync_render_debug_response_preview( $preview, $encoding = '' ) {
	if ( 'base64' === $encoding ) {
		$decoded_preview = base64_decode( $preview, true );
		if ( false === $decoded_preview ) {
			echo '<pre class="phenixsync-debug-preview">The saved response preview could not be decoded.</pre>';
			return;
		}
		$preview = $decoded_preview;
	}

	$decoded = json_decode( $preview, true );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		echo '<pre class="phenixsync-debug-preview">' . esc_html( $preview ) . '</pre>';
		return;
	}

	echo '<p class="phenixsync-debug-json-controls"><button type="button" class="button-link" data-phenixsync-json-collapse>Collapse all</button><span aria-hidden="true"> | </span><button type="button" class="button-link" data-phenixsync-json-expand>Expand all</button></p>';
	echo '<div class="phenixsync-debug-json" role="region" aria-label="Response preview">';
	phenixsync_render_debug_json_value( $decoded );
	echo '</div>';
}

/** Render one JSON value, allowing nested objects and lists to be folded independently. */
function phenixsync_render_debug_json_value( $value, $depth = 0 ) {
	if ( is_array( $value ) ) {
		$is_list = phenixsync_is_sequential_array( $value );
		$open_char = $is_list ? '[' : '{';
		$close_char = $is_list ? ']' : '}';
		$count = count( $value );

		if ( 0 === $count ) {
			echo '<span class="phenixsync-json-punctuation">' . esc_html( $open_char . $close_char ) . '</span>';
			return;
		}

		echo '<details class="phenixsync-json-group" open>';
		echo '<summary><span class="phenixsync-json-punctuation">' . esc_html( $open_char ) . '</span> <span class="phenixsync-json-count">' . esc_html( $count . ( $is_list ? ' items' : ' fields' ) ) . '</span></summary>';
		echo '<ul class="phenixsync-json-children">';
		$position = 0;
		foreach ( $value as $key => $item ) {
			echo '<li class="phenixsync-json-line">';
			if ( ! $is_list ) {
				echo '<span class="phenixsync-json-key">' . esc_html( wp_json_encode( (string) $key ) ) . '</span><span class="phenixsync-json-punctuation">: </span>';
			}
			phenixsync_render_debug_json_value( $item, $depth + 1 );
			if ( $position < $count - 1 ) {
				echo '<span class="phenixsync-json-punctuation">,</span>';
			}
			echo '</li>';
			$position++;
		}
		echo '</ul><span class="phenixsync-json-punctuation">' . esc_html( $close_char ) . '</span></details>';
		return;
	}

	if ( is_bool( $value ) || null === $value ) {
		echo '<span class="phenixsync-json-literal">' . esc_html( wp_json_encode( $value ) ) . '</span>';
		return;
	}

	$class = is_numeric( $value ) ? 'phenixsync-json-number' : 'phenixsync-json-string';
	echo '<span class="' . esc_attr( $class ) . '">' . esc_html( wp_json_encode( $value ) ) . '</span>';
}

/** Format a stored UTC sync timestamp in the WordPress site's configured timezone. */
function phenixsync_format_debug_time( $request ) {
	$timestamp = isset( $request['response_timestamp'] ) ? absint( $request['response_timestamp'] ) : 0;
	if ( ! $timestamp && ! empty( $request['response_time_gmt'] ) ) {
		$timestamp = strtotime( $request['response_time_gmt'] . ' UTC' );
	}
	if ( ! $timestamp && ! empty( $request['response_time'] ) ) {
		$timestamp = strtotime( $request['response_time'] . ' UTC' );
	}
	return $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $timestamp ) : 'n/a';
}
