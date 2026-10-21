<?php
/**
 * Capa de datos: carpetas (taxonomía jerárquica sobre adjuntos) y categorías de carpeta.
 */
defined( 'ABSPATH' ) || exit;

class CDM_Folders {

	const TAX      = 'cdm_folder';
	const OPT_CATS = 'cdm_categories';
	const META_CAT = 'cdm_cat';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
	}

	public static function register() {
		register_taxonomy(
			self::TAX,
			'attachment',
			array(
				'label'                 => __( 'Carpetas de medios', 'carpetas-medios' ),
				'hierarchical'          => true,
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => false,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_in_rest'          => false,
				'show_admin_column'     => false,
				'rewrite'               => false,
				'query_var'             => false,
				'update_count_callback' => '_update_generic_term_count',
				'capabilities'          => array(
					'manage_terms' => 'upload_files',
					'edit_terms'   => 'upload_files',
					'delete_terms' => 'upload_files',
					'assign_terms' => 'upload_files',
				),
			)
		);
	}

	/* ------------------------------------------------------------------ Permisos */

	public static function manage_cap() {
		$cap = get_option( 'cdm_manage_cap', 'edit_others_posts' );
		return in_array( $cap, array( 'manage_options', 'edit_others_posts', 'upload_files' ), true ) ? $cap : 'edit_others_posts';
	}

	public static function can_manage() {
		return current_user_can( self::manage_cap() );
	}

	/* ------------------------------------------------------------------ Lectura */

	public static function folders() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$own = self::own_only() ? self::own_counts() : null;
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = array(
				'id'     => (int) $t->term_id,
				'parent' => (int) $t->parent,
				'name'   => wp_specialchars_decode( $t->name, ENT_QUOTES ),
				'count'  => null === $own ? (int) $t->count : ( isset( $own[ $t->term_id ] ) ? (int) $own[ $t->term_id ] : 0 ),
				'cat'    => (int) get_term_meta( $t->term_id, self::META_CAT, true ),
			);
		}
		return $out;
	}

	/** Los usuarios que sólo ven sus propios archivos (autores…) no deben ver cifras de los ajenos. */
	private static function own_only() {
		return ! current_user_can( 'edit_others_posts' );
	}

	private static function own_counts() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id, COUNT(DISTINCT tr.object_id) AS n FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				 WHERE tt.taxonomy = %s AND p.post_type = 'attachment' AND p.post_status IN ('inherit','private') AND p.post_author = %d
				 GROUP BY tt.term_id",
				self::TAX,
				get_current_user_id()
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->term_id ] = (int) $r->n;
		}
		return $out;
	}

	public static function counts() {
		global $wpdb;
		$author   = self::own_only() ? $wpdb->prepare( ' AND post_author = %d', get_current_user_id() ) : '';
		$author_p = self::own_only() ? $wpdb->prepare( ' AND p.post_author = %d', get_current_user_id() ) : '';
		$all      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status IN ('inherit','private')" . $author ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragmento ya preparado.
		$assigned = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT tr.object_id) FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				 WHERE tt.taxonomy = %s AND p.post_type = 'attachment' AND p.post_status IN ('inherit','private')" . $author_p, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragmento ya preparado.
				self::TAX
			)
		);
		return array(
			'all'  => $all,
			'none' => max( 0, $all - $assigned ),
		);
	}

	public static function payload() {
		return array(
			'folders' => self::folders(),
			'cats'    => self::categories(),
			'counts'  => self::counts(),
		);
	}

	public static function exists( $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		$t = get_term( $id, self::TAX );
		return $t && ! is_wp_error( $t );
	}

	/* ------------------------------------------------------------------ Carpetas */

	private static function friendly( WP_Error $e ) {
		if ( in_array( $e->get_error_code(), array( 'term_exists', 'duplicate_term_slug' ), true ) ) {
			return new WP_Error( 'cdm_exists', __( 'Ya existe una carpeta con ese nombre en esta ubicación.', 'carpetas-medios' ) );
		}
		return $e;
	}

	private static function clean_name( $name ) {
		$name = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $name ) ) );
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 120 );
		}
		return $name;
	}

	public static function create( $name, $parent = 0, $cat = 0 ) {
		$name = self::clean_name( $name );
		if ( '' === $name ) {
			return new WP_Error( 'cdm_empty', __( 'El nombre no puede estar vacío.', 'carpetas-medios' ) );
		}
		$parent = (int) $parent;
		if ( $parent && ! self::exists( $parent ) ) {
			return new WP_Error( 'cdm_parent', __( 'La carpeta superior no existe.', 'carpetas-medios' ) );
		}
		$r = wp_insert_term( $name, self::TAX, array( 'parent' => $parent ) );
		if ( is_wp_error( $r ) ) {
			return self::friendly( $r );
		}
		if ( $cat && self::category_exists( $cat ) ) {
			update_term_meta( $r['term_id'], self::META_CAT, (int) $cat );
		}
		return (int) $r['term_id'];
	}

	public static function rename( $id, $name ) {
		$name = self::clean_name( $name );
		if ( ! self::exists( $id ) ) {
			return new WP_Error( 'cdm_missing', __( 'La carpeta no existe.', 'carpetas-medios' ) );
		}
		if ( '' === $name ) {
			return new WP_Error( 'cdm_empty', __( 'El nombre no puede estar vacío.', 'carpetas-medios' ) );
		}
		$r = wp_update_term( (int) $id, self::TAX, array( 'name' => $name ) );
		return is_wp_error( $r ) ? self::friendly( $r ) : true;
	}

	public static function move( $id, $parent ) {
		$id     = (int) $id;
		$parent = (int) $parent;
		if ( ! self::exists( $id ) || ( $parent && ! self::exists( $parent ) ) ) {
			return new WP_Error( 'cdm_missing', __( 'La carpeta no existe.', 'carpetas-medios' ) );
		}
		if ( $id === $parent ) {
			return new WP_Error( 'cdm_loop', __( 'Una carpeta no puede estar dentro de sí misma.', 'carpetas-medios' ) );
		}
		$children = get_term_children( $id, self::TAX );
		if ( $parent && is_array( $children ) && in_array( $parent, array_map( 'intval', $children ), true ) ) {
			return new WP_Error( 'cdm_loop', __( 'No se puede mover una carpeta dentro de una de sus subcarpetas.', 'carpetas-medios' ) );
		}
		$r = wp_update_term( $id, self::TAX, array( 'parent' => $parent ) );
		return is_wp_error( $r ) ? self::friendly( $r ) : true;
	}

	/**
	 * Elimina la carpeta. Los archivos NO se borran (pasan a «Sin carpeta»)
	 * y las subcarpetas suben un nivel.
	 */
	public static function delete( $id ) {
		if ( ! self::exists( $id ) ) {
			return new WP_Error( 'cdm_missing', __( 'La carpeta no existe.', 'carpetas-medios' ) );
		}
		$r = wp_delete_term( (int) $id, self::TAX );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return (bool) $r;
	}

	public static function set_category( $id, $cat ) {
		if ( ! self::exists( $id ) ) {
			return new WP_Error( 'cdm_missing', __( 'La carpeta no existe.', 'carpetas-medios' ) );
		}
		$cat = (int) $cat;
		if ( $cat && ! self::category_exists( $cat ) ) {
			return new WP_Error( 'cdm_cat_missing', __( 'La categoría no existe.', 'carpetas-medios' ) );
		}
		if ( $cat ) {
			update_term_meta( (int) $id, self::META_CAT, $cat );
		} else {
			delete_term_meta( (int) $id, self::META_CAT );
		}
		return true;
	}

	/** Asigna archivos a una carpeta (0 = sin carpeta). Un archivo pertenece a una sola carpeta. */
	public static function assign( $ids, $folder ) {
		$folder = (int) $folder;
		if ( $folder && ! self::exists( $folder ) ) {
			return new WP_Error( 'cdm_missing', __( 'La carpeta no existe.', 'carpetas-medios' ) );
		}
		$n = 0;
		foreach ( array_unique( array_map( 'intval', (array) $ids ) ) as $id ) {
			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			wp_set_object_terms( $id, $folder ? array( $folder ) : array(), self::TAX );
			$n++;
		}
		return $n;
	}

	public static function folder_of( $attachment_id ) {
		$terms = wp_get_object_terms( (int) $attachment_id, self::TAX );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}
		return $terms[0];
	}

	/** Recalcula los contadores de todas las carpetas. */
	public static function recount() {
		$ids = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false, 'fields' => 'tt_ids' ) );
		if ( ! is_wp_error( $ids ) && $ids ) {
			wp_update_term_count_now( $ids, self::TAX );
		}
	}

	/* ------------------------------------------------------------------ Categorías de carpeta */

	public static function categories() {
		$c = get_option( self::OPT_CATS, array() );
		return is_array( $c ) ? array_values( $c ) : array();
	}

	public static function category_exists( $id ) {
		foreach ( self::categories() as $c ) {
			if ( (int) $c['id'] === (int) $id ) {
				return true;
			}
		}
		return false;
	}

	public static function save_category( $id, $name, $color ) {
		$name = self::clean_name( $name );
		if ( '' === $name ) {
			return new WP_Error( 'cdm_empty', __( 'El nombre no puede estar vacío.', 'carpetas-medios' ) );
		}
		$name = mb_substr( $name, 0, 60 );
		$color = sanitize_hex_color( $color );
		if ( ! $color ) {
			$color = '#2563eb';
		}
		$cats = self::categories();
		$max  = 0;
		foreach ( $cats as $c ) {
			$max = max( $max, (int) $c['id'] );
			if ( (int) $c['id'] !== (int) $id && 0 === strcasecmp( $c['name'], $name ) ) {
				return new WP_Error( 'cdm_exists', __( 'Ya existe una categoría con ese nombre.', 'carpetas-medios' ) );
			}
		}
		$id = (int) $id;
		if ( $id && self::category_exists( $id ) ) {
			foreach ( $cats as $k => $c ) {
				if ( (int) $c['id'] === $id ) {
					$cats[ $k ] = array( 'id' => $id, 'name' => $name, 'color' => $color );
				}
			}
		} else {
			$id     = $max + 1;
			$cats[] = array( 'id' => $id, 'name' => $name, 'color' => $color );
		}
		update_option( self::OPT_CATS, array_values( $cats ), false );
		return $id;
	}

	public static function delete_category( $id ) {
		$id   = (int) $id;
		$cats = array();
		foreach ( self::categories() as $c ) {
			if ( (int) $c['id'] !== $id ) {
				$cats[] = $c;
			}
		}
		update_option( self::OPT_CATS, $cats, false );
		delete_metadata( 'term', 0, self::META_CAT, $id, true );
		return true;
	}
}
