<?php
/**
 * Plugin Name: Frymo Translatepress Integration
 * Plugin URI: https://github.com/frymo-de/frymo-translatepress-integration
 * Version: 0.3.3
 * Description: Adds seamless multilingual support to Frymo by integrating with TranslatePress.
 * Text Domain: frymo-tpi
 * Author: Stark Systems UG
 * Author URI: https://frymo.de
 * Domain Path: /languages
 */


add_action( 'template_redirect', 'ftpi_redirect_based_on_locale' );
function ftpi_redirect_based_on_locale() {
	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}

	$locale = get_locale();

	if ( ! is_singular( FRYMO_POST_TYPE ) ) {
		return;
	}

	$current_post = get_queried_object();
	// error_log( "current_post ID\n" . print_r( $current_post->ID, true ) );

	$replacement_post = frymo_tpi_get_translated_post( $current_post, $locale );
	// error_log( "replacement_post ID\n" . print_r( $replacement_post->ID, true ) . "\n" );

	if ( $replacement_post->ID !== $current_post->ID ) {
		$permalink = get_the_permalink( $replacement_post );

		wp_redirect( $permalink, 301 );
		exit;
	}
}








function frymo_tpi_get_translated_post( $current_post, $locale ) {
	$current_post_id = $current_post->ID;

	$post_ids_with_same_external_id = frymo_tpi_get_matching_post_ids_by_external_object_id( $current_post_id );
	// error_log( "post_ids_with_same_external_id\n" . print_r( $post_ids_with_same_external_id, true ) . "\n" );

	$translation_post_id = frymo_tpi_get_translated_object_id( $post_ids_with_same_external_id, $locale );

	if ( is_int( $translation_post_id ) ) {
		$translation_post = get_post( $translation_post_id );

		if (
			$translation_post instanceof WP_Post &&
			'publish' === $translation_post->post_status &&
			FRYMO_POST_TYPE === $translation_post->post_type
		) {
			return $translation_post;
		}
	}

	return $current_post;
}


/**
 * Get post IDs of the same post type with matching 'frymo_objektnr_extern' meta value.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int $post_id The ID of the current post.
 * @return int[] Array of matching post IDs.
 */
function frymo_tpi_get_matching_post_ids_by_external_object_id( $post_id ) {
	global $wpdb;

	$post_id = absint( $post_id );

	if ( 0 === $post_id ) {
		return array();
	}

	// Get the meta value from the current post.
	$external_id = get_post_meta( $post_id, 'frymo_objektnr_extern', true );

	if ( empty( $external_id ) ) {
		return array();
	}

	// Prepare SQL query to fetch matching post IDs.
	$query = $wpdb->prepare(
		"
		SELECT p.ID
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
		WHERE p.post_type = %s
			AND p.ID != %d
			AND p.post_status = 'publish'
			AND pm.meta_key = 'frymo_objektnr_extern'
			AND pm.meta_value = %s
		",
		FRYMO_POST_TYPE, // Custom post type to match
		$post_id,        // Exclude the current post by ID
		$external_id     // External ID to match from post meta
	);

	$results = $wpdb->get_col( $query );

	// Ensure the result is an array of integers.
	return array_map( 'absint', $results );
}

function frymo_tpi_get_translated_object_id( $post_ids, $translation_locale ) {
	$translation_post_id = false;

	$translation_locale = strtolower( str_replace( '_', '-', $translation_locale ) );
	// error_log( "translation_locale\n" . print_r( $translation_locale, true ) );

	foreach ( $post_ids as $post_id ) {
		$terms = wp_get_object_terms( $post_id, 'immobilie_language' );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			continue;
		}

		$post_locale = strtolower( str_replace( '_', '-', $terms[0]->name ) );

		// error_log( "post_locale\n" . print_r( $post_locale, true ) . "\n" );


		if ( $translation_locale === $post_locale ) {
			$translation_post_id = $post_id;
			break;
		}
	}

	// error_log( "translation_post_id\n" . print_r( $translation_post_id, true ) . "\n" );

	return $translation_post_id;
}





// JustIMMO: Include only posts with the same immobilie_language term as the <sprache> value.
add_filter( 'frymo/xml_process/search_existing_object_args', 'frymo_tpi_search_existing_object_args', 10, 3 );

/**
 * Filters WP_Query arguments to include only posts with a matching immobilie_language term.
 *
 * This ensures that only posts assigned the same language (from <sprache> in the XML)
 * are considered as matching during import or processing.
 *
 * @since 1.0.0
 *
 * @param array                $args       WP_Query arguments.
 * @param \SimpleXMLElement    $immobilie  The <immobilie> node from the OpenImmo XML feed.
 * @param array                $options    Optional additional data passed to the filter.
 *
 * @return array Modified WP_Query arguments with a tax_query constraint.
 */
function frymo_tpi_search_existing_object_args( $args, $immobilie, $options ) {

	/**
	 * Check if Frymo_Process_Xml_File class exists and if the method add_language_to_existing_object_query_args exists.
	 *
	 * @return array Modified WP_Query arguments with a tax_query constraint.
	 */
	if ( ! class_exists( 'Frymo_Process_Xml_File' )
		|| ! method_exists( 'Frymo_Process_Xml_File', 'add_language_to_existing_object_query_args' ) ) {
		return $args;
	}

	// Extract language from the XML node.
	$object_lang = (string) $immobilie->verwaltung_techn->sprache;

	if ( empty( $object_lang ) ) {
		return $args; // No language set; return unmodified args.
	}

	// Lookup the term by name in the immobilie_language taxonomy.
	$term = get_term_by( 'name', $object_lang, 'immobilie_language' );

	if ( ! $term || is_wp_error( $term ) ) {
		return $args; // Term doesn't exist or error occurred.
	}

	// Initialize or extend the tax_query argument.
	if ( empty( $args['tax_query'] ) || ! is_array( $args['tax_query'] ) ) {
		$args['tax_query'] = [];
	}

	// Add tax_query condition to include only posts with the matching language term.
	$args['tax_query'][] = [
		'taxonomy' => 'immobilie_language',
		'field'    => 'term_id',
		'terms'    => [ $term->term_id ],
		'operator' => 'IN',
	];

	// Ensure relation is set if multiple tax_query conditions exist.
	if ( count( $args['tax_query'] ) > 1 ) {
		$args['tax_query']['relation'] = 'AND';
	}

	return $args;
}





require_once plugin_dir_path(__FILE__) . 'inc/vendor/autoload.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/Frymo-de/frymo-translatepress-integration/',
    __FILE__,
    'frymo-translatepress-integration'
);

$updateChecker->setBranch( 'main' );
$updateChecker->getVcsApi()->enableReleaseAssets();