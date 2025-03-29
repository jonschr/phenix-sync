<?php

/**
 * Register the taxonomies
 *
 * @return  void. 
 */
function phenix_register_professionals_tax() {
	register_taxonomy(
		'services',
		'professionals',
		array(
			'label' 			=> __( 'Services' ),
			'rewrite' 		=> array( 'slug' => 'services' ),
			'hierarchical' 	=> true,
			'show_in_rest' 	=> true,
		)
	);
}
add_action( 'init', 'phenix_register_professionals_tax' );