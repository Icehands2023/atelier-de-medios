<?php
/**
 * Desinstalación: sólo borra datos si el usuario lo ha activado en los ajustes.
 * Los archivos de la biblioteca de medios NUNCA se tocan.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( get_option( 'cdm_delete_data' ) ) {
	if ( ! taxonomy_exists( 'cdm_folder' ) ) {
		register_taxonomy( 'cdm_folder', 'attachment', array( 'hierarchical' => true, 'public' => false, 'rewrite' => false ) );
	}
	$terms = get_terms( array( 'taxonomy' => 'cdm_folder', 'hide_empty' => false, 'fields' => 'ids' ) );
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term_id ) {
			wp_delete_term( (int) $term_id, 'cdm_folder' );
		}
	}
	delete_metadata( 'term', 0, 'cdm_cat', '', true );
	foreach ( array( 'cdm_categories', 'cdm_manage_cap', 'cdm_include_children', 'cdm_builders', 'cdm_delete_data' ) as $opt ) {
		delete_option( $opt );
	}
}

delete_transient( 'cdm_update_info' );
