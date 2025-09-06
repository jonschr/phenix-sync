jQuery(document).ready(function ($) {
	// Handle professional sync button clicks
	$(document).on('click', '.sync-professional-btn', function (e) {
		e.preventDefault();

		var $button = $(this);
		var postId = $button.data('post-id');
		var locationId = $button.data('location-id');

		// Disable button and show loading state
		$button.prop('disabled', true).text('Syncing...');

		// Make AJAX request
		$.ajax({
			url: phenixsync_ajax.ajax_url,
			type: 'POST',
			data: {
				action: 'phenixsync_sync_professional',
				post_id: postId,
				location_id: locationId,
				nonce: phenixsync_ajax.nonce,
			},
			success: function (response) {
				if (response.success) {
					$button
						.text('Synced!')
						.removeClass('button-secondary')
						.addClass('button-success');
					setTimeout(function () {
						$button
							.prop('disabled', false)
							.text('Sync Now')
							.removeClass('button-success')
							.addClass('button-secondary');
					}, 2000);
				} else {
					$button.prop('disabled', false).text('Sync Failed');
					alert('Sync failed: ' + (response.data || 'Unknown error'));
				}
			},
			error: function () {
				$button.prop('disabled', false).text('Sync Failed');
				alert('AJAX error occurred during sync');
			},
		});
	});

	// Handle location sync button clicks
	$(document).on('click', '.sync-location-btn', function (e) {
		e.preventDefault();

		var $button = $(this);
		var postId = $button.data('post-id');
		var s3Index = $button.data('s3-index');

		// Disable button and show loading state
		$button.prop('disabled', true).text('Syncing...');

		// Make AJAX request
		$.ajax({
			url: phenixsync_ajax.ajax_url,
			type: 'POST',
			data: {
				action: 'phenixsync_sync_location',
				post_id: postId,
				s3_index: s3Index,
				nonce: phenixsync_ajax.nonce,
			},
			success: function (response) {
				if (response.success) {
					$button
						.text('Synced!')
						.removeClass('button-secondary')
						.addClass('button-success');
					setTimeout(function () {
						$button
							.prop('disabled', false)
							.text('Sync Now')
							.removeClass('button-success')
							.addClass('button-secondary');
					}, 2000);
				} else {
					$button.prop('disabled', false).text('Sync Failed');
					alert('Sync failed: ' + (response.data || 'Unknown error'));
				}
			},
			error: function () {
				$button.prop('disabled', false).text('Sync Failed');
				alert('AJAX error occurred during sync');
			},
		});
	});
});
