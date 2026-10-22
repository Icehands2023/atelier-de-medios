<?php
/**
 * Endpoints AJAX. Todas las respuestas correctas devuelven el árbol actualizado en `tree`.
 */
defined( 'ABSPATH' ) || exit;

class CDM_Ajax {

	public static function init() {
		foreach ( array( 'tree', 'create', 'rename', 'move_folder', 'delete', 'set_cat', 'assign', 'cat_save', 'cat_delete', 'import_tree', 'delete_many' ) as $a ) {
			add_action( 'wp_ajax_cdm_' . $a, array( __CLASS__, $a ) );
		}
	}

	private static function guard( $manage = false ) {
		if ( ! check_ajax_referer( 'cdm_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'La sesión ha caducado. Recarga la página.', 'atelier-de-medios' ) ), 403 );
		}
		if ( ! current_user_can( 'upload_files' ) || ( $manage && ! CDM_Folders::can_manage() ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para esta acción.', 'atelier-de-medios' ) ), 403 );
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
		$id = absint( self::req( 'id', 0 ) );
		if ( self::req( 'purge', 0 ) ) {
			self::purge_files( array( $id ) );
		}
		self::done( CDM_Folders::delete( $id ) );
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
			wp_send_json_error( array( 'message' => __( 'La carpeta de destino no existe.', 'atelier-de-medios' ) ), 400 );
		}
		$paths = array_values( array_unique( array_filter( array_map( 'strval', (array) self::req( 'paths', array() ) ), 'strlen' ) ) );
		if ( count( $paths ) > 3000 ) {
			wp_send_json_error( array( 'message' => __( 'Demasiadas carpetas en una sola importación (máximo 3000).', 'atelier-de-medios' ) ), 400 );
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

	/** Elimina varias carpetas a la vez (las más profundas primero). Los archivos nunca se borran. */
	public static function delete_many() {
		self::guard( true );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) self::req( 'ids', array() ) ) ) ) );
		if ( count( $ids ) > 500 ) {
			wp_send_json_error( array( 'message' => __( 'Demasiadas carpetas a la vez (máximo 500).', 'atelier-de-medios' ) ), 400 );
		}
		if ( self::req( 'purge', 0 ) ) {
			self::purge_files( $ids );
		}
		$depth = array();
		foreach ( $ids as $id ) {
			$anc         = get_ancestors( $id, CDM_Folders::TAX, 'taxonomy' );
			$depth[ $id ] = count( $anc );
		}
		usort( $ids, function ( $a, $b ) use ( $depth ) {
			return $depth[ $b ] - $depth[ $a ];
		} );
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( ! CDM_Folders::exists( $id ) ) {
				continue;
			}
			$r = CDM_Folders::delete( $id );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ), 400 );
			}
			$deleted++;
		}
		self::done( true, array( 'deleted' => $deleted ) );
	}

	/** Borra definitivamente los archivos de las carpetas indicadas y de sus subcarpetas. */
	private static function purge_files( $folder_ids ) {
		$terms = array();
		foreach ( $folder_ids as $fid ) {
			if ( ! CDM_Folders::exists( $fid ) ) {
				continue;
			}
			$terms[] = $fid;
			$terms   = array_merge( $terms, array_map( 'intval', get_term_children( $fid, CDM_Folders::TAX ) ) );
		}
		$terms = array_values( array_unique( $terms ) );
		if ( ! $terms ) {
			return;
		}
		$files = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( array( 'taxonomy' => CDM_Folders::TAX, 'field' => 'term_id', 'terms' => $terms, 'include_children' => false ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- necesario para localizar los archivos de las carpetas.
		) );
		foreach ( $files as $aid ) {
			if ( current_user_can( 'delete_post', $aid ) ) {
				wp_delete_attachment( $aid, true );
			}
		}
	}
}
