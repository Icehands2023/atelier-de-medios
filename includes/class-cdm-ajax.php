<?php
/**
 * Endpoints AJAX. Todas las respuestas correctas devuelven el árbol actualizado en `tree`.
 */
defined( 'ABSPATH' ) || exit;

class CDM_Ajax {

	public static function init() {
		foreach ( array( 'tree', 'create', 'rename', 'move_folder', 'delete', 'set_cat', 'assign', 'cat_save', 'cat_delete', 'import_tree' ) as $a ) {
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

	/**
	 * Crea (o reutiliza) la estructura de carpetas de una carpeta arrastrada desde el equipo.
	 * `paths`: rutas relativas tipo «Cliente/Fotos/2026». Devuelve `map`: ruta => ID de carpeta.
	 */
	public static function import_tree() {
		self::guard( true );
		$parent = absint( self::req( 'parent', 0 ) );
		if ( $parent && ! CDM_Folders::exists( $parent ) ) {
			wp_send_json_error( array( 'message' => __( 'La carpeta de destino no existe.', 'carpetas-medios' ) ), 400 );
		}
		$paths = array_values( array_unique( array_filter( array_map( 'strval', (array) self::req( 'paths', array() ) ), 'strlen' ) ) );
		if ( count( $paths ) > 3000 ) {
			wp_send_json_error( array( 'message' => __( 'Demasiadas carpetas en una sola importación (máximo 3000).', 'carpetas-medios' ) ), 400 );
		}
		usort( $paths, function ( $a, $b ) {
			return substr_count( $a, '/' ) - substr_count( $b, '/' );
		} );

		$index = array(); // [padre][nombre en minúsculas] => id.
		foreach ( CDM_Folders::folders() as $f ) {
			$index[ $f['parent'] ][ mb_strtolower( $f['name'] ) ] = $f['id'];
		}
		$map     = array( '' => $parent );
		$created = 0;
		foreach ( $paths as $path ) {
			$cur = '';
			foreach ( explode( '/', $path ) as $seg ) {
				$key = '' === $cur ? $seg : $cur . '/' . $seg;
				if ( isset( $map[ $key ] ) ) {
					$cur = $key;
					continue;
				}
				$pid   = $map[ $cur ];
				$name  = CDM_Folders::clean_name( $seg );
				$lname = mb_strtolower( $name );
				if ( isset( $index[ $pid ][ $lname ] ) ) {
					$id = $index[ $pid ][ $lname ];
				} else {
					$id = CDM_Folders::create( $name, $pid );
					if ( is_wp_error( $id ) ) {
						wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
					}
					$index[ $pid ][ $lname ] = $id;
					$created++;
				}
				$map[ $key ] = $id;
				$cur         = $key;
			}
		}
		unset( $map[''] );
		self::done( true, array( 'map' => $map, 'created' => $created ) );
	}
}
