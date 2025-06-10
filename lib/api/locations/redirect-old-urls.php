<?php
// filepath: /Users/jonschroeder/Local Sites/phenix/app/public/wp-content/plugins/phenix-sync/lib/api/locations/redirect-old-urls.php

add_action('template_redirect', 'phenix_redirect_old_location_urls');

/**
 * Redirects old /locations-detail/?id=XXX URLs to the new location permalinks.
 */
function phenix_redirect_old_location_urls() {
    // Check if the request URI contains '/locations-detail/' and the 'id' GET parameter is set.
    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/locations-detail/') !== false && isset($_GET['id'])) {
        
        $old_id = sanitize_text_field($_GET['id']);

        // Ensure the ID is numeric.
        if (!is_numeric($old_id)) {
            return;
        }

        $args = array(
            'post_type' => 'locations', // Slug of the 'locations' custom post type.
            'meta_query' => array(
                array(
                    'key' => 'phenix_franchise_license_index', // Custom field key.
                    'value' => $old_id,
                    'compare' => '=',
                    'type' => 'NUMERIC' // Assumes the meta value is stored as a number.
                )
            ),
            'posts_per_page' => 1, // We only need one matching post.
            'fields' => 'ids'      // Optimize by only fetching post IDs.
        );

        $location_query = new WP_Query($args);

        if ($location_query->have_posts()) {
            $location_id = $location_query->posts[0];
            $new_url = get_permalink($location_id);

            if ($new_url) {
                // Perform a 301 permanent redirect.
                wp_redirect($new_url, 301);
                exit; // Always call exit after wp_redirect.
            }
        }
        // If no matching new location is found, WordPress will proceed as usual
        // (e.g., potentially showing a 404 if /locations-detail/ is not a valid path).
    }
}
