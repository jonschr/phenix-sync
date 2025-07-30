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