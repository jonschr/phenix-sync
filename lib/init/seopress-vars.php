<?php
/**
 * Register SEOPress dynamic variables for Phenix Sync locations.
 *
 * SEOPress variables (single locations templates only):
 * - %%phenixsync_location_city%% (location city)
 * - %%phenixsync_location_state%% (location state, full name when possible)
 * - %%phenixsync_location_state_abbr%% (location state abbreviation when possible)
 * - %%phenixsync_location_street%% (street address: address1 + address2)
 * - %%phenixsync_location_zip%% (location ZIP/postal code)
 *
 * Usage in SEOPress:
 * - Titles & Metas templates (Title/Meta Description fields)
 * - Social metadata fields (Open Graph/Twitter)
 * - Schema fields + Universal SEO metabox custom fields
 *
 * @package phenixsync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get the current post object for SEOPress variables.
 *
 * @return WP_Post|null
 */
function phenixsync_seopress_get_current_post() {
	$post = get_post();
	if ( $post instanceof WP_Post ) {
		return $post;
	}

	return null;
}

/**
 * Resolve the locations post for SEOPress variables.
 *
 * @return WP_Post|null
 */
function phenixsync_seopress_get_location_post() {
	$post = phenixsync_seopress_get_current_post();
	if ( $post && 'locations' === $post->post_type ) {
		return $post;
	}

	$s3_index = '';
	if ( function_exists( 'phenix_get_page_related_s3_index' ) ) {
		$s3_index = phenix_get_page_related_s3_index();
	}

	if ( ! $s3_index ) {
		return null;
	}

	$location_posts = get_posts( array(
		'post_type' => 'locations',
		'meta_query' => array(
			array(
				'key' => 's3_index',
				'value' => $s3_index,
				'compare' => '=',
			),
		),
		'numberposts' => 1,
	) );

	if ( empty( $location_posts ) || ! ( $location_posts[0] instanceof WP_Post ) ) {
		return null;
	}

	return $location_posts[0];
}

/**
 * Get location meta for a locations post.
 *
 * @param WP_Post $post Post object.
 * @param string  $meta_key Meta key to retrieve.
 * @return string
 */
function phenixsync_seopress_get_location_meta( $post, $meta_key ) {
	$resolved_post = $post;
	if ( ! $resolved_post || 'locations' !== $resolved_post->post_type ) {
		$resolved_post = phenixsync_seopress_get_location_post();
	}

	if ( ! $resolved_post || 'locations' !== $resolved_post->post_type ) {
		return '';
	}

	$allowed_keys = array( 'address1', 'address2', 'city', 'state', 'zip' );
	if ( ! in_array( $meta_key, $allowed_keys, true ) ) {
		return '';
	}

	$value = get_post_meta( $resolved_post->ID, $meta_key, true );
	return $value ? sanitize_text_field( $value ) : '';
}

/**
 * Map US state abbreviations to full names.
 *
 * @return array
 */
function phenixsync_seopress_get_state_map() {
	return array(
		'AL' => 'Alabama',
		'AK' => 'Alaska',
		'AZ' => 'Arizona',
		'AR' => 'Arkansas',
		'CA' => 'California',
		'CO' => 'Colorado',
		'CT' => 'Connecticut',
		'DE' => 'Delaware',
		'FL' => 'Florida',
		'GA' => 'Georgia',
		'HI' => 'Hawaii',
		'ID' => 'Idaho',
		'IL' => 'Illinois',
		'IN' => 'Indiana',
		'IA' => 'Iowa',
		'KS' => 'Kansas',
		'KY' => 'Kentucky',
		'LA' => 'Louisiana',
		'ME' => 'Maine',
		'MD' => 'Maryland',
		'MA' => 'Massachusetts',
		'MI' => 'Michigan',
		'MN' => 'Minnesota',
		'MS' => 'Mississippi',
		'MO' => 'Missouri',
		'MT' => 'Montana',
		'NE' => 'Nebraska',
		'NV' => 'Nevada',
		'NH' => 'New Hampshire',
		'NJ' => 'New Jersey',
		'NM' => 'New Mexico',
		'NY' => 'New York',
		'NC' => 'North Carolina',
		'ND' => 'North Dakota',
		'OH' => 'Ohio',
		'OK' => 'Oklahoma',
		'OR' => 'Oregon',
		'PA' => 'Pennsylvania',
		'RI' => 'Rhode Island',
		'SC' => 'South Carolina',
		'SD' => 'South Dakota',
		'TN' => 'Tennessee',
		'TX' => 'Texas',
		'UT' => 'Utah',
		'VT' => 'Vermont',
		'VA' => 'Virginia',
		'WA' => 'Washington',
		'WV' => 'West Virginia',
		'WI' => 'Wisconsin',
		'WY' => 'Wyoming',
		'DC' => 'District of Columbia',
	);
}

/**
 * Get the state full name for SEOPress variables.
 *
 * @param string $state State value.
 * @return string
 */
function phenixsync_seopress_get_state_full_name( $state ) {
	if ( ! $state ) {
		return '';
	}

	$state_map = phenixsync_seopress_get_state_map();
	$state_upper = strtoupper( $state );

	if ( isset( $state_map[ $state_upper ] ) ) {
		return $state_map[ $state_upper ];
	}

	return sanitize_text_field( $state );
}

/**
 * Get the state abbreviation for SEOPress variables.
 *
 * @param string $state State value.
 * @return string
 */
function phenixsync_seopress_get_state_abbr( $state ) {
	if ( ! $state ) {
		return '';
	}

	$state_map = phenixsync_seopress_get_state_map();
	$state_upper = strtoupper( $state );

	if ( isset( $state_map[ $state_upper ] ) ) {
		return $state_upper;
	}

	foreach ( $state_map as $abbr => $name ) {
		if ( 0 === strcasecmp( $name, $state ) ) {
			return $abbr;
		}
	}

	return sanitize_text_field( $state );
}

/**
 * Get the street address for SEOPress variables.
 *
 * @param WP_Post $post Location post.
 * @return string
 */
function phenixsync_seopress_get_street_address( $post ) {
	$address1 = phenixsync_seopress_get_location_meta( $post, 'address1' );
	$address2 = phenixsync_seopress_get_location_meta( $post, 'address2' );

	if ( ! $address1 && ! $address2 ) {
		return '';
	}

	if ( $address1 && $address2 ) {
		return trim( $address1 . ' ' . $address2 );
	}

	return $address1 ? $address1 : $address2;
}

/**
 * Register Phenix Sync variables with SEOPress.
 *
 * @param array $variables Template variables.
 * @return array
 */
function phenixsync_seopress_add_template_variables( $variables ) {
	$variables[] = '%%phenixsync_location_city%%';
	$variables[] = '%%phenixsync_location_state%%';
	$variables[] = '%%phenixsync_location_state_abbr%%';
	$variables[] = '%%phenixsync_location_street%%';
	$variables[] = '%%phenixsync_location_zip%%';

	return $variables;
}
add_filter( 'seopress_titles_template_variables_array', 'phenixsync_seopress_add_template_variables' );

/**
 * Provide Phenix Sync variable replacements to SEOPress.
 *
 * @param array $replacements Template replacement values.
 * @return array
 */
function phenixsync_seopress_add_template_replacements( $replacements ) {
	$post = phenixsync_seopress_get_location_post();
	$post_type = $post ? $post->post_type : '';

	$location_city = '';
	$location_state = '';
	$location_state_abbr = '';
	$location_street = '';
	$location_zip = '';

	if ( 'locations' === $post_type ) {
		$raw_state = phenixsync_seopress_get_location_meta( $post, 'state' );
		$location_city = esc_attr( phenixsync_seopress_get_location_meta( $post, 'city' ) );
		$location_state = esc_attr( phenixsync_seopress_get_state_full_name( $raw_state ) );
		$location_state_abbr = esc_attr( phenixsync_seopress_get_state_abbr( $raw_state ) );
		$location_street = esc_attr( phenixsync_seopress_get_street_address( $post ) );
		$location_zip = esc_attr( phenixsync_seopress_get_location_meta( $post, 'zip' ) );
	}

	return array_merge(
		$replacements,
		array(
			$location_city,
			$location_state,
			$location_state_abbr,
			$location_street,
			$location_zip,
		)
	);
}
add_filter( 'seopress_titles_template_replace_array', 'phenixsync_seopress_add_template_replacements' );

/**
 * Add Phenix Sync variables to the standard SEOPress variables dropdown.
 *
 * @param array $variables Variable labels keyed by tag.
 * @return array
 */
function phenixsync_seopress_register_dynamic_variables( $variables ) {
	$variables['%%phenixsync_location_city%%'] = __( 'Phenix Location City', 'phenixsync-textdomain' );
	$variables['%%phenixsync_location_state%%'] = __( 'Phenix Location State', 'phenixsync-textdomain' );
	$variables['%%phenixsync_location_state_abbr%%'] = __( 'Phenix Location State Abbr', 'phenixsync-textdomain' );
	$variables['%%phenixsync_location_street%%'] = __( 'Phenix Location Street Address', 'phenixsync-textdomain' );
	$variables['%%phenixsync_location_zip%%'] = __( 'Phenix Location Zip', 'phenixsync-textdomain' );

	return $variables;
}
add_filter( 'seopress_get_dynamic_variables', 'phenixsync_seopress_register_dynamic_variables' );

if ( interface_exists( 'SEOPress\\Models\\GetTagValue' ) ) {
	/**
	 * Base tag class for Phenix Sync SEOPress variables.
	 */
	abstract class Phenixsync_SEOPress_Tag_Base implements SEOPress\Models\GetTagValue {
		/**
		 * Get the post from context.
		 *
		 * @param array|null $args Context args.
		 * @return WP_Post|null
		 */
		protected function get_post_from_context( $args ) {
			$context = isset( $args[0] ) ? $args[0] : null;
			if ( ! $context || ! isset( $context['post'] ) ) {
				return null;
			}

			return $context['post'] instanceof WP_Post ? $context['post'] : null;
		}
	}

	class Phenixsync_SEOPress_Tag_Location_City extends Phenixsync_SEOPress_Tag_Base {
		const NAME = 'phenixsync_location_city';

		public static function getDescription() {
			return __( 'Phenix Location City', 'phenixsync-textdomain' );
		}

		public function getValue( $args = null ) {
			$post = $this->get_post_from_context( $args );
			if ( ! $post || 'locations' !== $post->post_type ) {
				return '';
			}

			return esc_attr( phenixsync_seopress_get_location_meta( $post, 'city' ) );
		}
	}

	class Phenixsync_SEOPress_Tag_Location_State extends Phenixsync_SEOPress_Tag_Base {
		const NAME = 'phenixsync_location_state';

		public static function getDescription() {
			return __( 'Phenix Location State', 'phenixsync-textdomain' );
		}

		public function getValue( $args = null ) {
			$post = $this->get_post_from_context( $args );
			if ( ! $post || 'locations' !== $post->post_type ) {
				return '';
			}

			$raw_state = phenixsync_seopress_get_location_meta( $post, 'state' );
			return esc_attr( phenixsync_seopress_get_state_full_name( $raw_state ) );
		}
	}

	class Phenixsync_SEOPress_Tag_Location_State_Abbr extends Phenixsync_SEOPress_Tag_Base {
		const NAME = 'phenixsync_location_state_abbr';

		public static function getDescription() {
			return __( 'Phenix Location State Abbr', 'phenixsync-textdomain' );
		}

		public function getValue( $args = null ) {
			$post = $this->get_post_from_context( $args );
			if ( ! $post || 'locations' !== $post->post_type ) {
				return '';
			}

			$raw_state = phenixsync_seopress_get_location_meta( $post, 'state' );
			return esc_attr( phenixsync_seopress_get_state_abbr( $raw_state ) );
		}
	}

	class Phenixsync_SEOPress_Tag_Location_Street extends Phenixsync_SEOPress_Tag_Base {
		const NAME = 'phenixsync_location_street';

		public static function getDescription() {
			return __( 'Phenix Location Street Address', 'phenixsync-textdomain' );
		}

		public function getValue( $args = null ) {
			$post = $this->get_post_from_context( $args );
			if ( ! $post || 'locations' !== $post->post_type ) {
				return '';
			}

			return esc_attr( phenixsync_seopress_get_street_address( $post ) );
		}
	}

	class Phenixsync_SEOPress_Tag_Location_Zip extends Phenixsync_SEOPress_Tag_Base {
		const NAME = 'phenixsync_location_zip';

		public static function getDescription() {
			return __( 'Phenix Location Zip', 'phenixsync-textdomain' );
		}

		public function getValue( $args = null ) {
			$post = $this->get_post_from_context( $args );
			if ( ! $post || 'locations' !== $post->post_type ) {
				return '';
			}

			return esc_attr( phenixsync_seopress_get_location_meta( $post, 'zip' ) );
		}
	}

	/**
	 * Register Phenix Sync variables for the Universal SEO metabox and schemas.
	 *
	 * @param array $tags Tags available.
	 * @return array
	 */
	function phenixsync_seopress_register_universal_tags( $tags ) {
		$phenixsync_tags = array(
			'phenixsync_location_city' => array(
				'class' => Phenixsync_SEOPress_Tag_Location_City::class,
				'name' => __( 'Phenix Location City', 'phenixsync-textdomain' ),
				'schema' => false,
				'alias' => array(),
				'custom' => null,
				'input' => '%%phenixsync_location_city%%',
				'description' => __( 'Phenix Location City', 'phenixsync-textdomain' ),
			),
			'phenixsync_location_state' => array(
				'class' => Phenixsync_SEOPress_Tag_Location_State::class,
				'name' => __( 'Phenix Location State', 'phenixsync-textdomain' ),
				'schema' => false,
				'alias' => array(),
				'custom' => null,
				'input' => '%%phenixsync_location_state%%',
				'description' => __( 'Phenix Location State', 'phenixsync-textdomain' ),
			),
			'phenixsync_location_state_abbr' => array(
				'class' => Phenixsync_SEOPress_Tag_Location_State_Abbr::class,
				'name' => __( 'Phenix Location State Abbr', 'phenixsync-textdomain' ),
				'schema' => false,
				'alias' => array(),
				'custom' => null,
				'input' => '%%phenixsync_location_state_abbr%%',
				'description' => __( 'Phenix Location State Abbr', 'phenixsync-textdomain' ),
			),
			'phenixsync_location_street' => array(
				'class' => Phenixsync_SEOPress_Tag_Location_Street::class,
				'name' => __( 'Phenix Location Street Address', 'phenixsync-textdomain' ),
				'schema' => false,
				'alias' => array(),
				'custom' => null,
				'input' => '%%phenixsync_location_street%%',
				'description' => __( 'Phenix Location Street Address', 'phenixsync-textdomain' ),
			),
			'phenixsync_location_zip' => array(
				'class' => Phenixsync_SEOPress_Tag_Location_Zip::class,
				'name' => __( 'Phenix Location Zip', 'phenixsync-textdomain' ),
				'schema' => false,
				'alias' => array(),
				'custom' => null,
				'input' => '%%phenixsync_location_zip%%',
				'description' => __( 'Phenix Location Zip', 'phenixsync-textdomain' ),
			),
		);

		return array_merge( $tags, $phenixsync_tags );
	}
	add_filter( 'seopress_tags_available', 'phenixsync_seopress_register_universal_tags' );
}
