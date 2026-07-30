<?php

// Add admin menu
add_action('admin_menu', 'phenix_sync_add_admin_menu');
add_action('admin_init', 'phenix_sync_settings_init');
add_action('admin_notices', 'phenix_sync_admin_notice_sync_disabled');
add_action('admin_notices', 'phenixsync_admin_notice_locations_sync_triggered');
add_action('admin_post_phenixsync_run_locations_sync', 'phenixsync_handle_run_locations_sync');
add_action('admin_post_phenixsync_stop_full_sync', 'phenixsync_handle_stop_full_sync');
add_action('wp_ajax_phenixsync_get_sync_status', 'phenixsync_ajax_get_sync_status');

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
		'phenix_sync_enabled',
		'Enable Sync',
		'phenix_sync_enabled_render',
		'phenix_sync_settings',
		'phenix_sync_main_section'
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

function phenix_sync_enabled_render() {
	$options = get_option('phenix_sync_options');
	$enabled = true;
	if (is_array($options) && array_key_exists('phenix_sync_enabled', $options)) {
		$enabled = (bool) $options['phenix_sync_enabled'];
	}

	echo '<label><input type="checkbox" name="phenix_sync_options[phenix_sync_enabled]" value="1" ' . checked($enabled, true, false) . ' /> Enable sync</label>';
	echo '<p class="description">When disabled, all scheduled and manual syncs are paused.</p>';
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
	$input = is_array($input) ? $input : array();

	$sanitized['phenix_sync_enabled'] = !empty($input['phenix_sync_enabled']) ? 1 : 0;
	
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

/**
 * Format a saved status timestamp in the site's timezone.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function phenixsync_format_status_timestamp( $timestamp ) {
	$timestamp = absint( $timestamp );

	if ( ! $timestamp ) {
		return '—';
	}

	return wp_date(
		get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
		$timestamp,
		wp_timezone()
	);
}

/**
 * Build display-ready full-sync and maintenance status values.
 *
 * @return array
 */
function phenixsync_get_sync_status_view_data() {
	$status = phenixsync_recover_active_full_sync_status();
	$lock   = phenixsync_get_full_sync_lock();
	$state  = isset( $status['state'] ) ? sanitize_key( $status['state'] ) : 'idle';
	$stage  = isset( $status['stage'] ) ? sanitize_key( $status['stage'] ) : 'idle';

	$stage_labels = array(
		'idle'                    => 'Not running',
		'initializing_locations'  => 'Loading the location list',
		'locations'               => 'Syncing locations',
		'preparing_professionals' => 'Preparing the professional queue',
		'professionals'           => 'Syncing professionals',
		'completed'               => 'Completed',
		'failed'                  => 'Failed',
		'stopped'                 => 'Stopped',
	);
	$state_labels = array(
		'idle'      => 'No full sync recorded',
		'running'   => 'Full sync in progress',
		'completed' => 'Full sync completed',
		'failed'    => 'Full sync failed',
		'stopped'   => 'Full sync stopped',
	);
	$notice_classes = array(
		'idle'      => 'notice-info',
		'running'   => 'notice-info',
		'completed' => 'notice-success',
		'failed'    => 'notice-error',
		'stopped'   => 'notice-warning',
	);

	$completed  = isset( $status['completed'] ) ? absint( $status['completed'] ) : 0;
	$total      = isset( $status['total'] ) ? absint( $status['total'] ) : 0;
	$stage_percentage = $total > 0 ? min( 100, round( ( $completed / $total ) * 100, 1 ) ) : 0;
	$overall_progress = 0;

	if ( 'locations' === $stage ) {
		$overall_progress = $total > 0 ? min( 1, $completed / $total ) / 2 : 0;
	} elseif ( 'preparing_professionals' === $stage ) {
		$overall_progress = 0.5;
	} elseif ( 'professionals' === $stage ) {
		$overall_progress = 0.5 + ( $total > 0 ? min( 1, $completed / $total ) / 2 : 0 );
	} elseif ( 'completed' === $state ) {
		$overall_progress = 1;
	}
	$percentage = round( $overall_progress * 100, 1 );
	$updated_at = isset( $status['updated_at'] ) ? absint( $status['updated_at'] ) : 0;
	$started_at = isset( $status['started_at'] ) ? absint( $status['started_at'] ) : 0;
	$activity   = $updated_at
		? human_time_diff( $updated_at, time() ) . ' ago'
		: '—';
	$is_active  = 'running' === $state
		&& ! empty( $status['run_id'] )
		&& ! empty( $lock['run_id'] )
		&& (string) $status['run_id'] === (string) $lock['run_id'];

	if ( 'running' === $state && ! $is_active ) {
		$state = 'failed';
	}

	$elapsed                  = $started_at ? human_time_diff( $started_at, time() ) : '—';
	$estimated_remaining      = '—';
	$estimated_completion     = '—';

	if ( $is_active ) {
		if ( $started_at && $overall_progress > 0 && $overall_progress < 1 ) {
			$elapsed_seconds             = max( 1, time() - $started_at );
			$estimated_total_seconds     = (int) round( $elapsed_seconds / $overall_progress );
			$estimated_remaining_seconds = max( 1, $estimated_total_seconds - $elapsed_seconds );
			$estimated_remaining         = 'About ' . human_time_diff( time(), time() + $estimated_remaining_seconds );
			$estimated_completion        = phenixsync_format_status_timestamp( time() + $estimated_remaining_seconds );
		} else {
			$estimated_remaining  = 'Calculating after the first completed location';
			$estimated_completion = 'Calculating…';
		}
	}

	$cleanup = phenixsync_get_orphan_cleanup_status();
	$cleanup_complete = PHENIXSYNC_ORPHAN_CLEANUP_VERSION === get_option( 'phenixsync_orphan_cleanup_version' );
	$cleanup_state = $cleanup_complete ? 'completed' : ( isset( $cleanup['state'] ) ? sanitize_key( $cleanup['state'] ) : 'running' );
	$cleanup_completed_at = isset( $cleanup['completed_at'] ) ? absint( $cleanup['completed_at'] ) : 0;
	$cleanup_reference_time = $cleanup_completed_at
		? $cleanup_completed_at
		: ( isset( $cleanup['updated_at'] ) ? absint( $cleanup['updated_at'] ) : 0 );
	$show_cleanup = ! (
		'completed' === $cleanup_state
		&& $cleanup_reference_time
		&& $cleanup_reference_time < time() - DAY_IN_SECONDS
	);

	return array(
		'full_sync' => array(
			'state'            => $state,
			'state_label'      => isset( $state_labels[ $state ] ) ? $state_labels[ $state ] : ucfirst( $state ),
			'notice_class'     => isset( $notice_classes[ $state ] ) ? $notice_classes[ $state ] : 'notice-info',
			'stage_label'      => isset( $stage_labels[ $stage ] ) ? $stage_labels[ $stage ] : ucfirst( str_replace( '_', ' ', $stage ) ),
			'completed'        => $completed,
			'total'            => $total,
			'percentage'       => $percentage,
			'stage_percentage' => $stage_percentage,
			'current_s3_index' => isset( $status['current_s3_index'] ) ? (string) $status['current_s3_index'] : '',
			'retry_attempt'    => isset( $status['retry_attempt'] ) ? absint( $status['retry_attempt'] ) : 0,
			'error_count'      => isset( $status['error_count'] ) ? absint( $status['error_count'] ) : 0,
			'last_error'       => isset( $status['last_error'] ) ? (string) $status['last_error'] : '',
			'started'          => phenixsync_format_status_timestamp( $started_at ),
			'elapsed'          => $elapsed,
			'estimated_remaining'  => $estimated_remaining,
			'estimated_completion' => $estimated_completion,
			'updated'          => phenixsync_format_status_timestamp( $updated_at ),
			'activity'         => $activity,
			'completed_at'     => phenixsync_format_status_timestamp( $status['completed_at'] ?? 0 ),
			'memory_current'   => ! empty( $status['memory_current'] ) ? size_format( $status['memory_current'], 2 ) : '—',
			'memory_peak'      => ! empty( $status['memory_peak'] ) ? size_format( $status['memory_peak'], 2 ) : '—',
			'run_id'           => isset( $status['run_id'] ) ? (string) $status['run_id'] : '',
			'is_active'        => $is_active,
			'is_waiting'       => 'running' === $state && $updated_at && $updated_at < time() - ( 2 * MINUTE_IN_SECONDS ),
		),
		'cleanup' => array(
			'show'                       => $show_cleanup,
			'state'                      => $cleanup_state,
			'state_label'                => 'completed' === $cleanup_state ? 'One-time cleanup completed' : 'One-time cleanup in progress',
			'notice_class'               => 'completed' === $cleanup_state ? 'notice-success' : 'notice-warning',
			'batches'                    => isset( $cleanup['batches'] ) ? absint( $cleanup['batches'] ) : 0,
			'postmeta_rows_removed'      => isset( $cleanup['postmeta_rows_removed'] ) ? absint( $cleanup['postmeta_rows_removed'] ) : 0,
			'term_relationships_removed' => isset( $cleanup['term_relationships_removed'] ) ? absint( $cleanup['term_relationships_removed'] ) : 0,
			'last_batch_postmeta'        => isset( $cleanup['last_batch_postmeta'] ) ? absint( $cleanup['last_batch_postmeta'] ) : 0,
			'updated'                    => phenixsync_format_status_timestamp( $cleanup['updated_at'] ?? 0 ),
		),
	);
}

/**
 * Return the status panel markup used by the page and its AJAX refresh.
 *
 * @return string
 */
function phenixsync_get_sync_status_panel_html() {
	$view    = phenixsync_get_sync_status_view_data();
	$sync    = $view['full_sync'];
	$cleanup = $view['cleanup'];

	ob_start();
	?>
	<h2 style="margin-top: 0;">Sync Status</h2>
	<div class="notice <?php echo esc_attr( $sync['notice_class'] ); ?> inline" style="margin: 0 0 16px; padding: 12px 16px;">
		<p style="margin-top: 0;"><strong><?php echo esc_html( $sync['state_label'] ); ?></strong></p>
		<?php if ( $sync['total'] > 0 ) : ?>
			<progress value="<?php echo esc_attr( $sync['percentage'] ); ?>" max="100" style="width: 100%; max-width: 720px; height: 20px;"><?php echo esc_html( $sync['percentage'] ); ?>%</progress>
			<p><?php echo esc_html( 'Overall estimate: ' . $sync['percentage'] . '%. Current stage: ' . number_format_i18n( $sync['completed'] ) . ' of ' . number_format_i18n( $sync['total'] ) . ' locations (' . $sync['stage_percentage'] . '%).' ); ?></p>
		<?php endif; ?>
		<table class="widefat striped" style="max-width: 900px;">
			<tbody>
				<tr><th scope="row" style="width: 190px;">Current stage</th><td><?php echo esc_html( $sync['stage_label'] ); ?></td></tr>
				<tr><th scope="row">Current location</th><td><?php echo esc_html( $sync['current_s3_index'] ? $sync['current_s3_index'] : '—' ); ?></td></tr>
				<tr><th scope="row">Start time</th><td><?php echo esc_html( $sync['started'] ); ?></td></tr>
				<?php if ( $sync['is_active'] ) : ?>
					<tr><th scope="row">Elapsed time</th><td><?php echo esc_html( $sync['elapsed'] ); ?></td></tr>
					<tr><th scope="row">Estimated time remaining</th><td><?php echo esc_html( $sync['estimated_remaining'] ); ?></td></tr>
					<tr><th scope="row">Estimated completion time</th><td><?php echo esc_html( $sync['estimated_completion'] ); ?></td></tr>
				<?php endif; ?>
				<tr><th scope="row">Last activity</th><td><?php echo esc_html( $sync['updated'] . ( '—' !== $sync['activity'] ? ' (' . $sync['activity'] . ')' : '' ) ); ?></td></tr>
				<?php if ( 'completed' === $sync['state'] || 'failed' === $sync['state'] || 'stopped' === $sync['state'] ) : ?>
					<tr><th scope="row">Finished</th><td><?php echo esc_html( $sync['completed_at'] ); ?></td></tr>
				<?php endif; ?>
				<tr><th scope="row">Retries / errors</th><td><?php echo esc_html( $sync['retry_attempt'] . ' current retries; ' . $sync['error_count'] . ' errors recorded' ); ?></td></tr>
				<tr><th scope="row">Worker memory</th><td><?php echo esc_html( $sync['memory_current'] . ' current; ' . $sync['memory_peak'] . ' peak' ); ?></td></tr>
				<?php if ( $sync['run_id'] ) : ?>
					<tr><th scope="row">Run ID</th><td><code><?php echo esc_html( $sync['run_id'] ); ?></code></td></tr>
				<?php endif; ?>
				<?php if ( $sync['last_error'] ) : ?>
					<tr><th scope="row">Last recorded error</th><td><?php echo esc_html( $sync['last_error'] ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php if ( $sync['is_waiting'] ) : ?>
			<p><em>No worker has reported activity for more than two minutes. The next WP-Cron request may be waiting for site traffic.</em></p>
		<?php endif; ?>
	</div>

	<?php if ( $cleanup['show'] ) : ?>
		<div class="notice <?php echo esc_attr( $cleanup['notice_class'] ); ?> inline" style="margin: 0; padding: 12px 16px;">
			<p style="margin-top: 0;"><strong><?php echo esc_html( $cleanup['state_label'] ); ?></strong></p>
			<p>This database maintenance is separate from location and professional syncing. It removes rows left behind by the former direct-delete process and stops automatically when none remain.</p>
			<ul style="list-style: disc; margin-left: 20px;">
				<li><?php echo esc_html( number_format_i18n( $cleanup['postmeta_rows_removed'] ) . ' orphaned post-meta rows removed since progress tracking began' ); ?></li>
				<li><?php echo esc_html( number_format_i18n( $cleanup['term_relationships_removed'] ) . ' orphaned taxonomy relationships removed since progress tracking began' ); ?></li>
				<li><?php echo esc_html( number_format_i18n( $cleanup['batches'] ) . ' cleanup batches recorded; last batch removed ' . number_format_i18n( $cleanup['last_batch_postmeta'] ) . ' post-meta rows' ); ?></li>
			</ul>
			<p style="margin-bottom: 0;">Last cleanup activity: <?php echo esc_html( $cleanup['updated'] ); ?></p>
		</div>
	<?php endif; ?>
	<?php
	return ob_get_clean();
}

/**
 * Render an automatically refreshed status panel.
 */
function phenixsync_render_sync_status_panel() {
	$panel_id = 'phenixsync-live-status';
	echo '<div id="' . esc_attr( $panel_id ) . '">' . phenixsync_get_sync_status_panel_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped by the renderer.
	?>
	<p class="description">This panel refreshes every 10 seconds while the page is open.</p>
	<script>
	(function() {
		const panel = document.getElementById(<?php echo wp_json_encode( $panel_id ); ?>);
		const startButton = document.getElementById('phenixsync-run-full-sync');
		const stopForm = document.getElementById('phenixsync-stop-full-sync-form');
		const stopDescription = document.getElementById('phenixsync-stop-full-sync-description');
		if (!panel) {
			return;
		}

		let requestInProgress = false;
		const refreshStatus = function() {
			if (requestInProgress || document.hidden) {
				return;
			}

			requestInProgress = true;
			const body = new URLSearchParams({
				action: 'phenixsync_get_sync_status',
				nonce: <?php echo wp_json_encode( wp_create_nonce( 'phenixsync_sync_status' ) ); ?>
			});

			window.fetch(ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
				body: body.toString()
			})
				.then(function(response) { return response.json(); })
				.then(function(response) {
					if (response.success && response.data && response.data.html) {
						panel.innerHTML = response.data.html;
					}
					if (response.success && response.data && typeof response.data.is_active === 'boolean') {
						if (startButton) {
							startButton.disabled = response.data.is_active;
						}
						if (stopForm) {
							stopForm.hidden = !response.data.is_active;
						}
						if (stopDescription) {
							stopDescription.hidden = !response.data.is_active;
						}
					}
				})
				.catch(function() {})
				.finally(function() { requestInProgress = false; });
		};

		window.setInterval(refreshStatus, 10000);
		document.addEventListener('visibilitychange', function() {
			if (!document.hidden) {
				refreshStatus();
			}
		});
	})();
	</script>
	<?php
}

/**
 * Return fresh status markup to authorized administrators.
 */
function phenixsync_ajax_get_sync_status() {
	check_ajax_referer( 'phenixsync_sync_status', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	$view = phenixsync_get_sync_status_view_data();
	wp_send_json_success(
		array(
			'html'      => phenixsync_get_sync_status_panel_html(),
			'is_active' => ! empty( $view['full_sync']['is_active'] ),
		)
	);
}

function phenix_sync_options_page() {
	$sync_view = phenixsync_get_sync_status_view_data();
	$is_active = ! empty( $sync_view['full_sync']['is_active'] );
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

		<hr style="margin: 30px 0;">

		<h2>Manual Full Sync</h2>
		<p>Run the complete locations and professionals sync immediately. Locations are processed first, one at a time. After every location has finished, the professional sync starts automatically and processes one location at a time. This uses the same workflow as the scheduled daily sync, including deletion checks and one-minute retries for failed requests. A second full sync cannot start while one is already active.</p>
		<div style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 12px;">
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="phenixsync_run_locations_sync" />
				<?php wp_nonce_field( 'phenixsync_run_locations_sync', 'phenixsync_run_locations_sync_nonce' ); ?>
				<button type="submit" id="phenixsync-run-full-sync" class="button button-secondary"<?php disabled( $is_active ); ?>>Run Full Sync Now</button>
			</form>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="phenixsync-stop-full-sync-form"<?php echo $is_active ? '' : ' hidden'; ?>>
				<input type="hidden" name="action" value="phenixsync_stop_full_sync" />
				<?php wp_nonce_field( 'phenixsync_stop_full_sync', 'phenixsync_stop_full_sync_nonce' ); ?>
				<button type="submit" class="button button-secondary">Stop Full Sync</button>
			</form>
		</div>
		<p class="description" id="phenixsync-stop-full-sync-description"<?php echo $is_active ? '' : ' hidden'; ?>>Stopping clears queued location and professional work. A request already in progress may finish, but it will not queue another worker.</p>

		<hr style="margin: 30px 0;">

		<?php phenixsync_render_sync_status_panel(); ?>
		
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
				<h3 style="margin-top: 0;">Location Phone Link</h3>
				<p>Displays the phone number as a clickable <code>tel:</code> link. The phone number is automatically formatted as (XXX) XXX-XXXX for display.</p>
				<h4>Examples:</h4>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_phone_link]</code>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_phone_link s3_index="123"]</code>
				<p><strong>Output:</strong> <code>&lt;a href="tel:5551234567"&gt;(555) 123-4567&lt;/a&gt;</code></p>
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
				<h3 style="margin-top: 0;">Location Address Link</h3>
				<p>Displays the full formatted address as a clickable link that opens in Google Maps.</p>
				<h4>Examples:</h4>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_address_link]</code>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_location_address_link s3_index="123"]</code>
				<p><strong>Output:</strong> <code>&lt;a href="https://www.google.com/maps/search/?api=1&amp;query=..." target="_blank"&gt;123 Main St, City, State, 12345&lt;/a&gt;</code></p>
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

			<div style="border: 1px solid #ddd; padding: 20px; border-radius: 5px; background: white;">
				<h3 style="margin-top: 0;">Global Contact Widget</h3>
				<p>Outputs the Find a Suite global contact widget script. If no location is specified, it uses the current location post's S3 index, then the current page's S3 index. If neither is available, it includes up to 20 synced location tokens.</p>
				<h4>Examples:</h4>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_global_contact]</code>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_global_contact s3_index="123"]</code>
				<code style="background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">[phenix_global_contact ltok="token-one,token-two" redirect="https://example.com/thank-you/"]</code>
				<p><strong>Parameters:</strong></p>
				<ul style="margin-bottom: 0;">
					<li><code>s3_index</code> (optional) - The S3 index of the location whose token should be used</li>
					<li><code>ltok</code> or <code>location_token</code> (optional) - One or more comma-separated location tokens to use directly</li>
					<li><code>redirect</code> (optional) - The redirect URL; defaults to the current site home URL</li>
				</ul>
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

function phenixsync_handle_run_locations_sync() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to perform this action.' );
	}

	check_admin_referer( 'phenixsync_run_locations_sync', 'phenixsync_run_locations_sync_nonce' );

	$redirect_url = admin_url( 'options-general.php?page=phenix-sync' );

	if ( ! phenix_sync_is_enabled() ) {
		phenixsync_set_admin_action_notice( 'disabled' );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$run_id = phenixsync_locations_sync_init();

	if ( $run_id ) {
		$status = 'started';
	} elseif ( ! empty( phenixsync_get_full_sync_lock() ) ) {
		$status = 'busy';
	} else {
		$status = 'failed';
	}

	phenixsync_set_admin_action_notice( $status );
	wp_safe_redirect( $redirect_url );
	exit;
}

function phenixsync_handle_stop_full_sync() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to perform this action.' );
	}

	check_admin_referer( 'phenixsync_stop_full_sync', 'phenixsync_stop_full_sync_nonce' );

	$status       = phenixsync_stop_full_sync() ? 'stopped' : 'not-running';
	$redirect_url = admin_url( 'options-general.php?page=phenix-sync' );

	phenixsync_set_admin_action_notice( $status );
	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Save a one-time action result for the current administrator.
 *
 * @param string $status Notice status key.
 */
function phenixsync_set_admin_action_notice( $status ) {
	$user_id = get_current_user_id();

	if ( ! $user_id ) {
		return;
	}

	set_transient(
		'phenixsync_admin_action_notice_' . $user_id,
		sanitize_key( $status ),
		MINUTE_IN_SECONDS
	);
}

function phenixsync_admin_notice_locations_sync_triggered() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! isset( $_GET['page'] ) || 'phenix-sync' !== $_GET['page'] ) {
		return;
	}

	$transient_key = 'phenixsync_admin_action_notice_' . get_current_user_id();
	$status        = get_transient( $transient_key );

	if ( ! $status ) {
		return;
	}

	delete_transient( $transient_key );
	$status = sanitize_key( $status );

	if ( 'disabled' === $status ) {
		echo '<div class="notice notice-error is-dismissible"><p><strong>Action result:</strong> The full sync was not run because syncing is currently disabled. See the status panel for the current saved state.</p></div>';
		return;
	}

	if ( 'started' === $status ) {
		echo '<div class="notice notice-success is-dismissible"><p><strong>Action result:</strong> The full locations and professionals sync was started. See the status panel for current progress.</p></div>';
		return;
	}

	if ( 'busy' === $status ) {
		echo '<div class="notice notice-warning is-dismissible"><p><strong>Action result:</strong> A full sync was already active, so a second run was not started. See the status panel for the active run.</p></div>';
		return;
	}

	if ( 'stopped' === $status ) {
		echo '<div class="notice notice-success is-dismissible"><p><strong>Action result:</strong> The active full sync was stopped. The recurring daily schedule remains enabled, and the status panel contains the saved final state.</p></div>';
		return;
	}

	if ( 'not-running' === $status ) {
		echo '<div class="notice notice-info is-dismissible"><p><strong>Action result:</strong> There was no active full sync to stop. See the status panel for the current saved state.</p></div>';
		return;
	}

	if ( 'failed' === $status ) {
		echo '<div class="notice notice-error is-dismissible"><p><strong>Action result:</strong> The full sync could not be started. See the status panel and PHP error log for details.</p></div>';
	}
}

function phenix_sync_admin_notice_sync_disabled() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( phenix_sync_is_enabled() ) {
		return;
	}

	$settings_url = admin_url( 'options-general.php?page=phenix-sync' );

	echo '<div class="notice notice-error"><p>';
	echo 'Notice syncing from the Phenix API is turned off for this site. Locations and Professionals will not sync at this time. ';
	echo '<a href="' . esc_url( $settings_url ) . '">Please go here to configure.</a>';
	echo '</p></div>';
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

// Helper function to get sync enabled status (default on).
function phenix_sync_is_enabled() {
	$options = get_option('phenix_sync_options');
	if (!is_array($options) || !array_key_exists('phenix_sync_enabled', $options)) {
		return true;
	}
	return (bool) $options['phenix_sync_enabled'];
}

// Helper function to clear all transients and cron events
function phenix_sync_clear_transients_and_cron_events() {
	$active_lock = phenixsync_get_full_sync_lock();
	if ( ! empty( $active_lock['run_id'] ) ) {
		phenixsync_finish_full_sync_status(
			$active_lock['run_id'],
			'stopped',
			'The sync was stopped because its settings changed.'
		);
	}

	// Clear main transients
	delete_transient('phenixsync_locations_data');
	delete_transient('phenixsync_valid_location_ids');
	delete_transient('phenixsync_professionals_queue');
	delete_option( 'phenixsync_full_sync_lock' );

	if ( function_exists( 'phenixsync_clear_legacy_individual_location_transients' ) ) {
		phenixsync_clear_legacy_individual_location_transients();
	}

	if ( function_exists( 'phenixsync_clear_legacy_professionals_response_transients' ) ) {
		phenixsync_clear_legacy_professionals_response_transients();
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
	wp_clear_scheduled_hook('phenixsync_start_professionals_queue');
	wp_clear_scheduled_hook('phenixsync_process_professionals_queue');
	wp_clear_scheduled_hook('phenixsync_retry_locations_sync_init');
	wp_clear_scheduled_hook('phenixsync_retry_single_location_sync');
	wp_clear_scheduled_hook('phenixsync_retry_single_professionals_sync');
	
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
