<?php

// Add admin menu
add_action('admin_menu', 'phenix_sync_add_admin_menu');
add_action('admin_init', 'phenix_sync_settings_init');

function phenix_sync_add_admin_menu() {
	add_options_page(
		'Phenix Sync Settings',
		'Phenix Sync',
		'manage_options',
		'phenix-sync',
		'phenix_sync_options_page'
	);
}

function phenix_sync_settings_init() {
	register_setting('phenix_sync_settings', 'phenix_sync_options', 'phenix_sync_sanitize_options');

	add_settings_section(
		'phenix_sync_main_section',
		'Sync Configuration',
		'phenix_sync_settings_section_callback',
		'phenix_sync_settings'
	);

	add_settings_field(
		'phenix_synced_location_s3_ids',
		'Synced Location S3 Indexes',
		'phenix_sync_property_ids_render',
		'phenix_sync_settings',
		'phenix_sync_main_section'
	);

	add_settings_field(
		'phenix_api_password',
		'API Password',
		'phenix_sync_api_password_render',
		'phenix_sync_settings',
		'phenix_sync_main_section'
	);
}

function phenix_sync_settings_section_callback() {
	echo '<p>Configure the properties to sync with Phenix Sync.</p>';
}

function phenix_sync_property_ids_render() {
	$options = get_option('phenix_sync_options');
	$property_ids = isset($options['phenix_synced_location_s3_ids']) ? $options['phenix_synced_location_s3_ids'] : array();
	$property_ids_string = is_array($property_ids) ? implode(', ', $property_ids) : '';
	
	echo '<textarea name="phenix_sync_options[phenix_synced_location_s3_ids]" rows="4" cols="60">' . esc_textarea($property_ids_string) . '</textarea>';
	echo '<p class="description">Enter location s3_index identifiers separated by commas (e.g., 123, 456, 789)</p>';
}

function phenix_sync_api_password_render() {
	$options = get_option('phenix_sync_options');
	$api_password = isset($options['phenix_api_password']) ? $options['phenix_api_password'] : '';
	
	echo '<input type="text" name="phenix_sync_options[phenix_api_password]" value="' . esc_attr($api_password) . '" class="regular-text" />';
	echo '<p class="description">Enter the API password for Phenix Sync authentication</p>';
}

function phenix_sync_sanitize_options($input) {
	$sanitized = array();
	
	if (isset($input['phenix_synced_location_s3_ids'])) {
		$raw_input = $input['phenix_synced_location_s3_ids'];
		
		// Handle both string and array inputs
		if (is_string($raw_input)) {
			// Convert comma-separated string to array
			$property_ids = explode(',', $raw_input);
			$property_ids = array_map('trim', $property_ids);
		} elseif (is_array($raw_input)) {
			// Already an array, just trim each value
			$property_ids = array_map('trim', $raw_input);
		} else {
			$property_ids = array();
		}
		
		$property_ids = array_filter($property_ids, function($id) {
			return !empty($id) && is_numeric($id);
		});
		$sanitized['phenix_synced_location_s3_ids'] = array_values($property_ids);
	}
	
	if (isset($input['phenix_api_password'])) {
		$sanitized['phenix_api_password'] = sanitize_text_field($input['phenix_api_password']);
	}
	
	// Clear all transients and cron events when settings are saved
	phenix_sync_clear_transients_and_cron_events();
	
	return $sanitized;
}

function phenix_sync_options_page() {
	?>
	<div class="wrap">
		<h1>Phenix Sync Settings</h1>
		<form action="options.php" method="post">
			<?php
			settings_fields('phenix_sync_settings');
			do_settings_sections('phenix_sync_settings');
			submit_button();
			?>
		</form>
		
		<hr style="margin: 40px 0;">
		
		<h2>Available Shortcodes</h2>
		<p>Use these shortcodes in your pages and posts to display location-specific information. All shortcodes support an optional <code>s3_index</code> parameter to specify which location to display. If no <code>s3_index</code> is provided, the shortcode will try to get the location from the current page's <code>_phenix_s3_index</code> meta field.</p>
		
		<div style="display: grid; gap: 30px; margin-top: 30px;">
			
			<div style="border: 1px solid #ddd; padding: 20px; border-radius: 5px; background: white;">
				<h3 style="margin-top: 0;">Location Phone Number</h3>
				<p>Displays the phone number for a location.</p>
				<h4>Examples:</h4>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_phone]</code>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_phone s3_index="123"]</code>
				<p><strong>Parameters:</strong></p>
				<ul style="margin-bottom: 0;">
					<li><code>s3_index</code> (optional) - The S3 index of the specific location to display</li>
				</ul>
			</div>
			
			<div style="border: 1px solid #ddd; padding: 20px; border-radius: 5px; background: white;">
				<h3 style="margin-top: 0;">Location Address</h3>
				<p>Displays the full formatted address for a location (address1, address2, city, state, zip).</p>
				<h4>Examples:</h4>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_address]</code>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_address s3_index="123"]</code>
				<p><strong>Parameters:</strong></p>
				<ul style="margin-bottom: 0;">
					<li><code>s3_index</code> (optional) - The S3 index of the specific location to display</li>
				</ul>
			</div>
			
			<div style="border: 1px solid #ddd; padding: 20px; border-radius: 5px; background: white;">
				<h3 style="margin-top: 0;">Location City & State</h3>
				<p>Displays the city and state for a location, separated by a comma.</p>
				<h4>Examples:</h4>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_city_state]</code>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_city_state s3_index="123"]</code>
				<p><strong>Parameters:</strong></p>
				<ul style="margin-bottom: 0;">
					<li><code>s3_index</code> (optional) - The S3 index of the specific location to display</li>
				</ul>
			</div>
			
			<div style="border: 1px solid #ddd; padding: 20px; border-radius: 5px; background: white;">
				<h3 style="margin-top: 0;">Location Name</h3>
				<p>Displays the name/title of a location.</p>
				<h4>Examples:</h4>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_name]</code>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_name s3_index="123"]</code>
				<p><strong>Parameters:</strong></p>
				<ul style="margin-bottom: 0;">
					<li><code>s3_index</code> (optional) - The S3 index of the specific location to display</li>
				</ul>
			</div>
			
			<div style="border: 1px solid #ddd; padding: 20px; border-radius: 5px; background: white;">
				<h3 style="margin-top: 0;">Location Professionals</h3>
				<p>Displays a grid of all professionals associated with a location. This includes their contact information, services, social links, and booking buttons.</p>
				<h4>Examples:</h4>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_professionals]</code>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_professionals s3_index="123"]</code>
				<p><strong>Parameters:</strong></p>
				<ul style="margin-bottom: 0;">
					<li><code>s3_index</code> (optional) - The S3 index of the specific location to display professionals for</li>
				</ul>
				<p><strong>Note:</strong> This shortcode outputs a complete professional grid with styling and will display all professionals assigned to the specified location.</p>
			</div>
			
		</div>
		
		<div style="background: #e7f3ff; border: 1px solid #b3d4fc; padding: 15px; margin-top: 30px; border-radius: 5px;">
			<h3 style="margin-top: 0;">Tips for Using Shortcodes</h3>
			<ul style="margin-bottom: 0;">
				<li><strong>Auto-detection:</strong> When used on a page that has a <code>_phenix_s3_index</code> meta field, all shortcodes will automatically use that location's data.</li>
				<li><strong>Specific locations:</strong> Use the <code>s3_index</code> parameter to display information for a specific location regardless of the current page.</li>
				<li><strong>S3 Index values:</strong> Use the S3 Index values from your synced locations list above (e.g., 123, 456, 789).</li>
				<li><strong>Empty results:</strong> If a location doesn't have data for a particular field, the shortcode will display nothing or a default message.</li>
			</ul>
		</div>
		
	</div>
	<?php
}

// Helper function to get synced property IDs
function phenix_sync_get_property_ids() {
	$options = get_option('phenix_sync_options');
	return isset($options['phenix_synced_location_s3_ids']) ? $options['phenix_synced_location_s3_ids'] : array();
}

// Helper function to get API password
function phenix_sync_get_api_password() {
	$options = get_option('phenix_sync_options');
	return isset($options['phenix_api_password']) ? $options['phenix_api_password'] : '';
}

// Helper function to clear all transients and cron events
function phenix_sync_clear_transients_and_cron_events() {
	// Clear main transients
	delete_transient('phenixsync_locations_data');
	delete_transient('phenixsync_valid_location_ids');
	
	// Clear location-specific transients (we need to get all possible location IDs)
	// First try to get from current settings
	$current_options = get_option('phenix_sync_options');
	$location_ids = isset($current_options['phenix_synced_location_s3_ids']) ? $current_options['phenix_synced_location_s3_ids'] : array();
	
	// Also get all existing location posts to clear their transients
	$existing_locations = get_posts(array(
		'post_type' => 'locations',
		'posts_per_page' => -1,
		'fields' => 'ids',
		'meta_query' => array(
			array(
				'key' => 's3_index',
				'compare' => 'EXISTS'
			)
		)
	));
	
	foreach ($existing_locations as $location_id) {
		$s3_index = get_post_meta($location_id, 's3_index', true);
		if ($s3_index) {
			delete_transient('phenixsync_locations_data_' . $s3_index);
		}
	}
	
	// Clear any additional location-specific transients from settings
	foreach ($location_ids as $s3_index) {
		delete_transient('phenixsync_locations_data_' . $s3_index);
	}
	
	// Clear rate limiting and other dynamic transients (we can't know all of them, but clear common patterns)
	global $wpdb;
	$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_phenixsync_%' OR option_name LIKE '_transient_timeout_phenixsync_%'");
	
	// Clear scheduled cron events
	wp_clear_scheduled_hook('phenixsync_locations_cron_hook');
	wp_clear_scheduled_hook('phenixsync_professionals_cron_hook');
	wp_clear_scheduled_hook('phenixsync_do_process_batch');
	wp_clear_scheduled_hook('phenixsync_cleanup_orphaned_tenants');
	wp_clear_scheduled_hook('phenixsync_sync_individual_location_professionals_event');
	
	error_log('Phenix Sync: Cleared all transients and cron events due to settings change');
}

// let's add a filter.
function phenix_sync_get_location_ids_from_setting( $locations_s3_indices ) {
	
	$options = get_option('phenix_sync_options');
	$locations_from_setting = isset($options['phenix_synced_location_s3_ids']) ? $options['phenix_synced_location_s3_ids'] : array();
	
	// Check if setting exists and is an array with at least one location ID
	if (!is_array($locations_from_setting) || empty($locations_from_setting)) {
		return $locations_s3_indices;
	}
	
	$intersected_locations = array_intersect( $locations_s3_indices, $locations_from_setting );
	
	return $intersected_locations;
}
add_filter( 'phenixsync_locations_s3_indices', 'phenix_sync_get_location_ids_from_setting' );