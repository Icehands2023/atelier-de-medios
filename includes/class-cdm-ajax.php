<?php
/**
 * Endpoints AJAX. Todas las respuestas correctas devuelven el árbol actualizado en `tree`.
 */
defined( 'ABSPATH' ) || exit;

class CDM_Ajax {

	public static function init() {
		foreach ( array( 'tree', 'create', 'rename', 'move_folder', 'delete', 'set_cat', 'assign', 'cat_save', 'cat_delete' ) as $a ) {
			add_action( 'wp_ajax_cdm_' . $a, array( __CLASS__, $a ) );
		}
	}

	private static function guard( $manage = false ) {
		if ( ! check_ajax_referer( 'cdm_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'La sesión ha caducado. Recarga la página.', 'carpetas-medios' ) ), 403 );
		}
		if ( ! current_user_can( 'upload_files' ) || ( $manage && ! CDM_Folders::can_manage() ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para esta acción.', 'carpetas-medios' ) ), 403 );
		}
	}

	private static function done( $result, $extra = array() ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array_merge( array( 'tree' => CDM_Folders::payload() ), $extra ) );
	}

	private static function req( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- se sanea en cada uso; nonce en guard().
	}

	public static function tree() {
		self::guard();
		self::done( true );
	}

	public static function create() {
		self::guard( true );
		$r = CDM_Folders::create( self::req( 'name' ), absint( self::req( 'parent', 0 ) ), absint( self::req( 'cat', 0 ) ) );
		self::done( $r, is_wp_error( $r ) ? array() : array( 'id' => $r ) );
	}

	public static function rename() {
		self::guard( true );
		self::done( CDM_Folders::rename( absint( self::req( 'id', 0 ) ), self::req( 'name' ) ) );
	}

	public static function move_folder() {
		self::guard( true );
		self::done( CDM_Folders::move( absint( self::req( 'id', 0 ) ), absint( self::req( 'parent', 0 ) ) ) );
	}

	public static function delete() {
		self::guard( true );
		self::done( CDM_Folders::delete( absint( self::req( 'id', 0 ) ) ) );
	}

	public static function set_cat() {
		self::guard( true );
		self::done( CDM_Folders::set_category( absint( self::req( 'id', 0 ) ), absint( self::req( 'cat', 0 ) ) ) );
	}

	public static function assign() {
		self::guard();
		$ids = array_map( 'absint', (array) self::req( 'ids', array() ) );
		$r   = CDM_Folders::assign( $ids, absint( self::req( 'folder', 0 ) ) );
		self::done( $r, is_wp_error( $r ) ? array() : array( 'moved' => $r ) );
	}

	public static function cat_save() {
		self::guard( true );
		$r = CDM_Folders::save_category( absint( self::req( 'id', 0 ) ), self::req( 'name' ), sanitize_text_field( self::req( 'color', '' ) ) );
		self::done( $r, is_wp_error( $r ) ? array() : array( 'id' => $r ) );
	}

	public static function cat_delete() {
		self::guard( true );
		self::done( CDM_Folders::delete_category( absint( self::req( 'id', 0 ) ) ) );
	}
}
