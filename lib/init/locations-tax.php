<?php

/**
 * Register the taxonomies
 *
 * @return  void. 
 */
function phenix_register_states_tax() {
	register_taxonomy(
		'states',
		'locations',
		array(
			'label' 			=> __( 'States' ),
			'rewrite' 		=> array( 'slug' => 'states' ),
			'hierarchical' 	=> true,
			'show_in_rest' 	=> true,
		)
	);
}
add_action( 'init', 'phenix_register_states_tax' );