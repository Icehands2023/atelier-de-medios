<?php
/**
 * Integración con la biblioteca de medios: filtros de consulta, asignación al subir,
 * columna y acciones masivas en la vista de lista, campo en el detalle del adjunto.
 */
defined( 'ABSPATH' ) || exit;

class CDM_Media {

	public static function init() {
		// Consulta de la ventana de medios (Divi, Elementor, Gutenberg, rejilla de Medios…).
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'filter_ajax_query' ) );
		// Vista de lista de Medios.
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list_query' ) );
		// Archivo nuevo → carpeta activa.
		add_action( 'add_attachment', array( __CLASS__, 'assign_on_upload' ) );

		// Vista de lista: columna, acciones masivas, aviso.
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'bulk_target_select' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );

		// Campo «Carpeta» en el detalle del adjunto. OJO: la clave NO puede llamarse igual que la taxonomía,
		// o WordPress la trataría como una lista de términos y crearía carpetas con el ID como nombre.
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'attachment_field_save' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ Consultas */

	/**
	 * @param mixed $value 'all' | '' | 'none' | 0 | ID de carpeta.
	 * @return array|null Cláusula de tax_query o null si no hay que filtrar.
	 */
	public static function tax_clause( $value ) {
		if ( null === $value || '' === $value || 'all' === $value ) {
			return null;
		}
		if ( 'none' === $value || '0' === (string) $value ) {
			return array(
				'taxonomy' => CDM_Folders::TAX,
				'operator' => 'NOT EXISTS',
			);
		}
		$id = absint( $value );
		if ( ! $id ) {
			return null;
		}
		return array(
			'taxonomy'         => CDM_Folders::TAX,
			'field'            => 'term_id',
			'terms'            => array( $id ),
			'include_children' => (bool) get_option( 'cdm_include_children', 0 ),
		);
	}

	public static function filter_ajax_query( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- la propia acción de WP ya verifica permisos.
		if ( ! isset( $_REQUEST['query']['cdm_folder'] ) || ! is_scalar( $_REQUEST['query']['cdm_folder'] ) ) {
			return $query;
		}
		$clause = self::tax_clause( sanitize_text_field( wp_unslash( $_REQUEST['query']['cdm_folder'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $clause ) {
			$query['tax_query'] = array_merge( isset( $query['tax_query'] ) ? (array) $query['tax_query'] : array(), array( $clause ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtrado nativo por la taxonomía de carpetas.
		}
		return $query;
	}

	public static function filter_list_query( $q ) {
		global $pagenow;
		if ( ! is_admin() || 'upload.php' !== $pagenow || ! $q->is_main_query() || ! isset( $_GET['cdm_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$clause = self::tax_clause( sanitize_text_field( wp_unslash( $_GET['cdm_folder'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $clause ) {
			$tax = (array) $q->get( 'tax_query' );
			$tax[] = $clause;
			$q->set( 'tax_query', $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtrado nativo por la taxonomía de carpetas.
		}
	}

	/* ------------------------------------------------------------------ Subida */

	public static function assign_on_upload( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST['cdm_folder'] ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$folder = absint( wp_unslash( $_REQUEST['cdm_folder'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( $folder && CDM_Folders::exists( $folder ) ) {
			wp_set_object_terms( $post_id, array( $folder ), CDM_Folders::TAX );
		}
	}

	/* ------------------------------------------------------------------ Vista de lista */

	public static function add_column( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['cdm_folder'] = __( 'Carpeta', 'atelier-de-medios' );
			}
		}
		if ( ! isset( $out['cdm_folder'] ) ) {
			$out['cdm_folder'] = __( 'Carpeta', 'atelier-de-medios' );
		}
		return $out;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'cdm_folder' !== $column ) {
			return;
		}
		$t = CDM_Folders::folder_of( $post_id );
		if ( ! $t ) {
			echo '<span class="cdm-col-none">—</span>';
			return;
		}
		$color = '#8b9ab5';
		$cat   = (int) get_term_meta( $t->term_id, CDM_Folders::META_CAT, true );
		foreach ( CDM_Folders::categories() as $c ) {
			if ( (int) $c['id'] === $cat ) {
				$color = $c['color'];
			}
		}
		$url = add_query_arg( array( 'mode' => 'list', 'cdm_folder' => (int) $t->term_id ), admin_url( 'upload.php' ) );
		printf(
			'<a class="cdm-col-folder" href="%s"><span class="cdm-dot" style="background:%s"></span>%s</a>',
			esc_url( $url ),
			esc_attr( $color ),
			esc_html( wp_specialchars_decode( $t->name, ENT_QUOTES ) )
		);
	}

	public static function bulk_actions( $actions ) {
		if ( current_user_can( 'upload_files' ) ) {
			$actions['cdm_move'] = __( 'Mover a carpeta…', 'atelier-de-medios' );
		}
		return $actions;
	}

	/** Lista plana indentada de carpetas para <select>. */
	public static function folder_options( $selected = null, $with_none = true ) {
		$folders = CDM_Folders::folders();
		$by      = array();
		foreach ( $folders as $f ) {
			$by[ $f['parent'] ][] = $f;
		}
		$html = '';
		if ( $with_none ) {
			$html .= '<option value="0">' . esc_html__( 'Sin carpeta', 'atelier-de-medios' ) . '</option>';
		}
		$walk = function ( $parent, $depth ) use ( &$walk, $by, $selected, &$html ) {
			if ( empty( $by[ $parent ] ) ) {
				return;
			}
			$list = $by[ $parent ];
			usort( $list, function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] );
			} );
			foreach ( $list as $f ) {
				$html .= sprintf(
					'<option value="%d"%s>%s%s</option>',
					$f['id'],
					selected( (int) $selected, $f['id'], false ),
					str_repeat( '&nbsp;&nbsp;&nbsp;', $depth ),
					esc_html( $f['name'] )
				);
				$walk( $f['id'], $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $html;
	}

	public static function bulk_target_select( $post_type, $which ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id || 'bar' !== $which || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		echo '<span class="cdm-bulk-target" style="display:none"><label class="screen-reader-text" for="cdm_bulk_target">' . esc_html__( 'Carpeta de destino', 'atelier-de-medios' ) . '</label>';
		echo '<select name="cdm_bulk_target" id="cdm_bulk_target"><option value="">' . esc_html__( 'Elige carpeta de destino…', 'atelier-de-medios' ) . '</option>' . self::folder_options() . '</select></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado.
	}

	public static function handle_bulk( $location, $action, $ids ) {
		if ( 'cdm_move' !== $action ) {
			return $location;
		}
		$target = isset( $_REQUEST['cdm_bulk_target'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cdm_bulk_target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WP verifica 'bulk-media'.
		if ( '' === $target || ! current_user_can( 'upload_files' ) ) {
			return add_query_arg( 'cdm_notice', 'notarget', $location );
		}
		$n = CDM_Folders::assign( (array) $ids, (int) $target );
		if ( is_wp_error( $n ) ) {
			return add_query_arg( 'cdm_notice', 'error', $location );
		}
		return add_query_arg( array( 'cdm_notice' => 'moved', 'cdm_n' => (int) $n ), $location );
	}

	public static function bulk_notice() {
		global $pagenow;
		if ( 'upload.php' !== $pagenow || empty( $_GET['cdm_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET['cdm_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'moved' === $code ) {
			$n = isset( $_GET['cdm_n'] ) ? absint( $_GET['cdm_n'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			/* translators: %d: número de archivos. */
			$msg = sprintf( _n( '%d archivo movido a la carpeta.', '%d archivos movidos a la carpeta.', $n, 'atelier-de-medios' ), $n );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Elige una carpeta de destino antes de mover los archivos.', 'atelier-de-medios' ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------ Detalle del adjunto */

	public static function attachment_field( $fields, $post ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $fields;
		}
		$t = CDM_Folders::folder_of( $post->ID );
		$fields['cdm_folder_id'] = array(
			'label' => __( 'Carpeta', 'atelier-de-medios' ),
			'input' => 'html',
			'html'  => sprintf(
				'<select class="cdm-field-select" name="attachments[%1$d][cdm_folder_id]" id="attachments-%1$d-cdm_folder_id" data-id="%1$d">%2$s</select>',
				(int) $post->ID,
				self::folder_options( $t ? $t->term_id : 0 )
			),
		);
		return $fields;
	}

	public static function attachment_field_save( $post, $attachment ) {
		if ( isset( $attachment['cdm_folder_id'] ) && current_user_can( 'upload_files' ) ) {
			CDM_Folders::assign( array( $post['ID'] ), (int) $attachment['cdm_folder_id'] );
		}
		return $post;
	}
}
