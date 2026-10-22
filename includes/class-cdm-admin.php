<?php
/**
 * Carga de recursos (ventana de medios en admin y constructores) y página de ajustes.
 */
defined( 'ABSPATH' ) || exit;

class CDM_Admin {

	private static $localized = false;

	public static function init() {
		// Se dispara siempre que alguien llama a wp_enqueue_media(): admin, Elementor, Divi Visual Builder, Gutenberg…
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue_media_ui' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_list_ui' ) );

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_cdm_cat_save', array( __CLASS__, 'post_cat_save' ) );
		add_action( 'admin_post_cdm_cat_delete', array( __CLASS__, 'post_cat_delete' ) );
		add_action( 'admin_post_cdm_recount', array( __CLASS__, 'post_recount' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CDM_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/* ------------------------------------------------------------------ Recursos */

	public static function enqueue_media_ui() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		if ( ! is_admin() && ! get_option( 'cdm_builders', 1 ) ) {
			return;
		}
		self::enqueue( 'media' );
	}

	public static function enqueue_list_ui( $hook ) {
		if ( 'media_page_atelier-de-medios' === $hook ) {
			wp_enqueue_style( 'cdm', CDM_URL . 'assets/css/cdm.css', array(), CDM_VERSION );
			return;
		}
		if ( 'upload.php' !== $hook || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		if ( wp_script_is( 'cdm', 'enqueued' ) ) { // modo rejilla: ya cargado por wp_enqueue_media.
			return;
		}
		self::enqueue( 'list' );
	}

	private static function enqueue( $mode ) {
		$deps = 'media' === $mode ? array( 'jquery', 'media-views' ) : array( 'jquery' );
		wp_enqueue_style( 'cdm', CDM_URL . 'assets/css/cdm.css', array(), CDM_VERSION );
		wp_enqueue_script( 'cdm', CDM_URL . 'assets/js/cdm.js', $deps, CDM_VERSION, true );
		if ( self::$localized ) {
			return;
		}
		self::$localized = true;

		$current = 'all';
		if ( isset( $_GET['cdm_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current = sanitize_text_field( wp_unslash( $_GET['cdm_folder'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '0' === $current ) {
				$current = 'none';
			}
		}

		$data = array_merge(
			CDM_Folders::payload(),
			array(
				'mode'      => $mode,
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'cdm_nonce' ),
				'canManage' => CDM_Folders::can_manage(),
				'current'   => $current,
				'settings'  => current_user_can( 'manage_options' ) ? admin_url( 'upload.php?page=atelier-de-medios&tab=ajustes' ) : '',
				'i18n'      => self::i18n(),
			)
		);
		wp_localize_script( 'cdm', 'CDM', $data );
	}

	private static function i18n() {
		return array(
			'folders'        => __( 'Carpetas', 'atelier-de-medios' ),
			'all'            => __( 'Todos los archivos', 'atelier-de-medios' ),
			'none'           => __( 'Sin carpeta', 'atelier-de-medios' ),
			'newFolder'      => __( 'Nueva carpeta', 'atelier-de-medios' ),
			'newSub'         => __( 'Nueva subcarpeta', 'atelier-de-medios' ),
			'rename'         => __( 'Renombrar', 'atelier-de-medios' ),
			'setCat'         => __( 'Cambiar categoría', 'atelier-de-medios' ),
			'moveTo'         => __( 'Mover a…', 'atelier-de-medios' ),
			'del'            => __( 'Eliminar carpeta', 'atelier-de-medios' ),
			'name'           => __( 'Nombre', 'atelier-de-medios' ),
			'parent'         => __( 'Carpeta superior', 'atelier-de-medios' ),
			'root'           => __( '(Raíz)', 'atelier-de-medios' ),
			'category'       => __( 'Categoría', 'atelier-de-medios' ),
			'noCategory'     => __( 'Sin categoría', 'atelier-de-medios' ),
			'newCategory'    => __( 'Nueva categoría…', 'atelier-de-medios' ),
			'manageCats'     => __( 'Gestionar categorías', 'atelier-de-medios' ),
			'catName'        => __( 'Nombre de la categoría', 'atelier-de-medios' ),
			'color'          => __( 'Color', 'atelier-de-medios' ),
			'create'         => __( 'Crear', 'atelier-de-medios' ),
			'save'           => __( 'Guardar', 'atelier-de-medios' ),
			'cancel'         => __( 'Cancelar', 'atelier-de-medios' ),
			'delete'         => __( 'Eliminar', 'atelier-de-medios' ),
			'search'         => __( 'Buscar carpeta…', 'atelier-de-medios' ),
			'allCats'        => __( 'Todas', 'atelier-de-medios' ),
			'filterCat'      => __( 'Filtrar por categoría', 'atelier-de-medios' ),
			'collapse'       => __( 'Ocultar carpetas', 'atelier-de-medios' ),
			'expand'         => __( 'Mostrar carpetas', 'atelier-de-medios' ),
			'expandAll'      => __( 'Expandir todo', 'atelier-de-medios' ),
			'collapseAll'    => __( 'Contraer todo', 'atelier-de-medios' ),
			'noResults'      => __( 'Ninguna carpeta coincide.', 'atelier-de-medios' ),
			'empty'          => __( 'Aún no hay carpetas. Crea la primera con «+».', 'atelier-de-medios' ),
			/* translators: %s: folder name. */
			'confirmDelete'  => __( '¿Eliminar la carpeta «%s»? Los archivos NO se borran: pasarán a «Sin carpeta». Las subcarpetas subirán un nivel.', 'atelier-de-medios' ),
			/* translators: %s: category name. */
			'confirmDelCat'  => __( '¿Eliminar la categoría «%s»? Las carpetas que la usan quedarán sin categoría.', 'atelier-de-medios' ),
			'moved'          => __( 'Movido', 'atelier-de-medios' ),
			'error'          => __( 'No se ha podido completar la acción.', 'atelier-de-medios' ),
			/* translators: %d: number of files. */
			'dragFiles'      => __( '%d archivos', 'atelier-de-medios' ),
			'dragFile'       => __( '1 archivo', 'atelier-de-medios' ),
			'moveHere'       => __( 'Mover a esta carpeta', 'atelier-de-medios' ),
			'uploadTo'       => __( 'Las subidas irán a:', 'atelier-de-medios' ),
			'purgeFiles'     => __( 'Eliminar también los archivos de estas carpetas y sus subcarpetas (definitivo, no se puede deshacer)', 'atelier-de-medios' ),
			'selectMode'     => __( 'Seleccionar varias carpetas', 'atelier-de-medios' ),
			/* translators: %d: number of selected folders. */
			'selCount'       => __( '%d seleccionadas', 'atelier-de-medios' ),
			'selAll'         => __( 'Todas', 'atelier-de-medios' ),
			'selClear'       => __( 'Cancelar', 'atelier-de-medios' ),
			/* translators: %d: number of folders to delete. */
			'confirmDelMany' => __( '¿Eliminar %d carpetas? Los archivos NO se borran: pasarán a «Sin carpeta». Las subcarpetas que no estén seleccionadas subirán un nivel.', 'atelier-de-medios' ),
			/* translators: %d: number of folders deleted. */
			'deletedMany'    => __( '%d carpetas eliminadas', 'atelier-de-medios' ),
			/* translators: %d: number of selected folders. */
			'menuDelMany'    => __( 'Eliminar %d carpetas seleccionadas', 'atelier-de-medios' ),
			'impReading'     => __( 'Leyendo carpetas…', 'atelier-de-medios' ),
			/* translators: 1: files uploaded so far, 2: total files. */
			'impUploading'   => __( 'Subiendo %1$d de %2$d', 'atelier-de-medios' ),
			'impDone'        => __( 'Importación completada', 'atelier-de-medios' ),
			/* translators: 1: number of files uploaded, 2: number of new folders. */
			'impSummary'     => __( '%1$d archivos subidos · %2$d carpetas nuevas', 'atelier-de-medios' ),
			/* translators: %d: number of files that failed. */
			'impFailed'      => __( 'No se han podido subir %d archivos:', 'atelier-de-medios' ),
			/* translators: %d: number of skipped files. */
			'impSkipped'     => __( 'Omitidos (tipo no permitido o demasiado grandes): %d', 'atelier-de-medios' ),
			'impNoManage'    => __( 'No tienes permiso para crear carpetas, así que no se puede importar una estructura.', 'atelier-de-medios' ),
			'impNothing'     => __( 'No hay archivos que importar.', 'atelier-de-medios' ),
			'impNoUploader'  => __( 'El cargador de WordPress no está disponible en esta pantalla.', 'atelier-de-medios' ),
			'impClose'       => __( 'Cerrar', 'atelier-de-medios' ),
		);
	}

	/* ------------------------------------------------------------------ Ajustes */

	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'upload.php?page=atelier-de-medios&tab=ajustes' ) ) . '">' . esc_html__( 'Ajustes', 'atelier-de-medios' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'upload.php?page=atelier-de-medios' ) ) . '"><strong>' . esc_html__( 'Guía de uso', 'atelier-de-medios' ) . '</strong></a>'
		);
		return $links;
	}

	public static function menu() {
		add_submenu_page( 'upload.php', __( 'Atelier de Medios', 'atelier-de-medios' ), __( 'Atelier de Medios', 'atelier-de-medios' ), 'manage_options', 'atelier-de-medios', array( __CLASS__, 'render_page' ) );
	}

	public static function register_settings() {
		register_setting( 'cdm_settings', 'cdm_manage_cap', array(
			'type'              => 'string',
			'default'           => 'edit_others_posts',
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, array( 'manage_options', 'edit_others_posts', 'upload_files' ), true ) ? $v : 'edit_others_posts';
			},
		) );
		foreach ( array( 'cdm_include_children' => 0, 'cdm_builders' => 1, 'cdm_delete_data' => 0 ) as $k => $def ) {
			register_setting( 'cdm_settings', $k, array(
				'type'              => 'integer',
				'default'           => $def,
				'sanitize_callback' => function ( $v ) {
					return empty( $v ) ? 0 : 1;
				},
			) );
		}
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'tab' => 'ajustes' ), $args ), admin_url( 'upload.php?page=atelier-de-medios' ) );
	}

	public static function post_cat_save() {
		check_admin_referer( 'cdm_cat' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atelier-de-medios' ), 403 );
		}
		$r = CDM_Folders::save_category(
			isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
			isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se sanea en save_category().
			isset( $_POST['color'] ) ? sanitize_text_field( wp_unslash( $_POST['color'] ) ) : ''
		);
		wp_safe_redirect( self::page_url( is_wp_error( $r ) ? array( 'cdm_msg' => 'err', 'cdm_err' => rawurlencode( $r->get_error_message() ) ) : array( 'cdm_msg' => 'cat' ) ) );
		exit;
	}

	public static function post_cat_delete() {
		check_admin_referer( 'cdm_cat' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atelier-de-medios' ), 403 );
		}
		CDM_Folders::delete_category( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		wp_safe_redirect( self::page_url( array( 'cdm_msg' => 'catdel' ) ) );
		exit;
	}

	public static function post_recount() {
		check_admin_referer( 'cdm_recount' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atelier-de-medios' ), 403 );
		}
		CDM_Folders::recount();
		wp_safe_redirect( self::page_url( array( 'cdm_msg' => 'recount' ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$cap   = get_option( 'cdm_manage_cap', 'edit_others_posts' );
		$msg   = isset( $_GET['cdm_msg'] ) ? sanitize_key( wp_unslash( $_GET['cdm_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err   = isset( $_GET['cdm_err'] ) ? sanitize_text_field( rawurldecode( sanitize_text_field( wp_unslash( $_GET['cdm_err'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notes = array(
			'cat'     => __( 'Categoría guardada.', 'atelier-de-medios' ),
			'catdel'  => __( 'Categoría eliminada.', 'atelier-de-medios' ),
			'recount' => __( 'Contadores recalculados.', 'atelier-de-medios' ),
		);
		$cats  = CDM_Folders::categories();
		$tot   = CDM_Folders::counts();
		$n_f   = count( CDM_Folders::folders() );
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'guia'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab   = in_array( $tab, array( 'ajustes', 'importar', 'novedades' ), true ) ? $tab : 'guia';
		?>
		<div class="wrap cdm-settings">
			<div class="cdm-hero">
				<img src="<?php echo esc_url( CDM_URL . 'assets/img/icon.svg' ); ?>" width="84" height="84" alt="">
				<div>
					<h1><?php esc_html_e( 'Atelier de Medios', 'atelier-de-medios' ); ?> <small>v<?php echo esc_html( CDM_VERSION ); ?></small></h1>
					<p><?php esc_html_e( 'Tu biblioteca de medios, ordenada a medida: carpetas, categorías y arrastrar y soltar, también dentro de Divi, Elementor y Gutenberg.', 'atelier-de-medios' ); ?></p>
				</div>
			</div>
			<nav class="nav-tab-wrapper cdm-tabs">
				<a class="nav-tab <?php echo 'guia' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'upload.php?page=atelier-de-medios&tab=guia' ) ); ?>"><?php esc_html_e( 'Guía de uso', 'atelier-de-medios' ); ?></a>
				<a class="nav-tab <?php echo 'ajustes' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'upload.php?page=atelier-de-medios&tab=ajustes' ) ); ?>"><?php esc_html_e( 'Ajustes y categorías', 'atelier-de-medios' ); ?></a>
				<a class="nav-tab <?php echo 'importar' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'upload.php?page=atelier-de-medios&tab=importar' ) ); ?>"><?php esc_html_e( 'Importar desde FileBird', 'atelier-de-medios' ); ?></a>
				<a class="nav-tab <?php echo 'novedades' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'upload.php?page=atelier-de-medios&tab=novedades' ) ); ?>"><?php esc_html_e( 'Novedades', 'atelier-de-medios' ); ?></a>
			</nav>

			<?php if ( 'err' === $msg && $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div>
			<?php elseif ( isset( $notes[ $msg ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notes[ $msg ] ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'guia' === $tab ) : ?>
				<?php self::render_guide( $n_f, $tot ); ?>
			<?php elseif ( 'importar' === $tab ) : ?>
				<?php CDM_Import::render_tab(); ?>
			<?php elseif ( 'novedades' === $tab ) : ?>
				<?php self::render_changelog(); ?>
			<?php else : ?>
			<div class="cdm-callout">
				<?php
				printf(
					/* translators: 1: carpetas, 2: archivos totales, 3: archivos sin carpeta */
					esc_html__( '%1$d carpetas · %2$d archivos en la biblioteca · %3$d sin carpeta. Las carpetas son virtuales: no mueven ni renombran los archivos del servidor, por lo que las URL de tus imágenes no cambian nunca.', 'atelier-de-medios' ),
					(int) $n_f,
					(int) $tot['all'],
					(int) $tot['none']
				);
				?>
			</div>

			<h2><?php esc_html_e( 'Ajustes generales', 'atelier-de-medios' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'cdm_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cdm_manage_cap"><?php esc_html_e( 'Quién puede crear, renombrar y borrar carpetas', 'atelier-de-medios' ); ?></label></th>
						<td>
							<select name="cdm_manage_cap" id="cdm_manage_cap">
								<option value="manage_options" <?php selected( $cap, 'manage_options' ); ?>><?php esc_html_e( 'Solo administradores', 'atelier-de-medios' ); ?></option>
								<option value="edit_others_posts" <?php selected( $cap, 'edit_others_posts' ); ?>><?php esc_html_e( 'Editores y administradores', 'atelier-de-medios' ); ?></option>
								<option value="upload_files" <?php selected( $cap, 'upload_files' ); ?>><?php esc_html_e( 'Cualquiera que pueda subir archivos', 'atelier-de-medios' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Todos los que pueden subir archivos pueden mover sus archivos entre carpetas.', 'atelier-de-medios' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Subcarpetas', 'atelier-de-medios' ); ?></th>
						<td><label><input type="checkbox" name="cdm_include_children" value="1" <?php checked( get_option( 'cdm_include_children', 0 ) ); ?>> <?php esc_html_e( 'Al abrir una carpeta, mostrar también los archivos de sus subcarpetas', 'atelier-de-medios' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Constructores visuales', 'atelier-de-medios' ); ?></th>
						<td>
							<label><input type="checkbox" name="cdm_builders" value="1" <?php checked( get_option( 'cdm_builders', 1 ) ); ?>> <?php esc_html_e( 'Mostrar el árbol de carpetas en el selector de medios del front-end (Divi Visual Builder y similares)', 'atelier-de-medios' ); ?></label>
							<p class="description"><?php esc_html_e( 'En el administrador (Elementor, Divi en modo backend, Gutenberg, ACF…) el árbol aparece siempre.', 'atelier-de-medios' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Desinstalación', 'atelier-de-medios' ); ?></th>
						<td><label><input type="checkbox" name="cdm_delete_data" value="1" <?php checked( get_option( 'cdm_delete_data', 0 ) ); ?>> <?php esc_html_e( 'Eliminar carpetas, categorías y ajustes al borrar el plugin (los archivos nunca se borran)', 'atelier-de-medios' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Categorías de carpeta', 'atelier-de-medios' ); ?></h2>
			<p><?php esc_html_e( 'Las categorías agrupan carpetas por color (por ejemplo: Cliente, Proyecto, Marca). En el árbol puedes filtrar las carpetas por categoría.', 'atelier-de-medios' ); ?></p>
			<table class="widefat striped cdm-cat-table">
				<tbody>
				<?php if ( ! $cats ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'Todavía no hay categorías.', 'atelier-de-medios' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $cats as $c ) : ?>
					<tr>
						<td colspan="3">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cdm-cat-row">
								<?php wp_nonce_field( 'cdm_cat' ); ?>
								<input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
								<input type="color" name="color" value="<?php echo esc_attr( $c['color'] ); ?>" aria-label="<?php esc_attr_e( 'Color', 'atelier-de-medios' ); ?>">
								<input type="text" name="name" value="<?php echo esc_attr( $c['name'] ); ?>" class="regular-text" maxlength="60" required>
								<button type="submit" name="action" value="cdm_cat_save" class="button button-primary"><?php esc_html_e( 'Guardar', 'atelier-de-medios' ); ?></button>
								<button type="submit" name="action" value="cdm_cat_delete" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( '¿Eliminar esta categoría? Las carpetas que la usan quedarán sin categoría.', 'atelier-de-medios' ) ); ?>');"><?php esc_html_e( 'Eliminar', 'atelier-de-medios' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<td colspan="3">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cdm-cat-row">
							<?php wp_nonce_field( 'cdm_cat' ); ?>
							<input type="hidden" name="action" value="cdm_cat_save">
							<input type="hidden" name="id" value="0">
							<input type="color" name="color" value="#2563eb" aria-label="<?php esc_attr_e( 'Color', 'atelier-de-medios' ); ?>">
							<input type="text" name="name" class="regular-text" maxlength="60" placeholder="<?php esc_attr_e( 'Nueva categoría', 'atelier-de-medios' ); ?>" required>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Añadir categoría', 'atelier-de-medios' ); ?></button>
						</form>
					</td>
				</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Herramientas', 'atelier-de-medios' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cdm_recount' ); ?>
				<input type="hidden" name="action" value="cdm_recount">
				<button type="submit" class="button"><?php esc_html_e( 'Recalcular contadores de carpetas', 'atelier-de-medios' ); ?></button>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ Novedades */

	/** Historial de versiones, de la más reciente a la más antigua. Tipos: new (Nuevo), imp (Mejora), fix (Corrección). */
	private static function changelog() {
		return array(
			array( '1.6.0', '2026-10-22', array(
				array( 'new', __( 'Al eliminar carpetas puedes marcar la opción de borrar también los archivos que contienen (por defecto no se borran).', 'atelier-de-medios' ) ),
				array( 'new', __( 'Selección múltiple de carpetas: Mayús+clic para un rango, Ctrl/Cmd+clic para carpetas sueltas, o el modo de casillas del encabezado. Elimina todas las seleccionadas de una vez (los archivos no se borran).', 'atelier-de-medios' ) ),
			) ),
			array( '1.5.0', '2026-10-22', array(
				array( 'imp', __( 'Código revisado con Plugin Check, la herramienta oficial de WordPress: textos traducibles, saneado de datos de entrada y consultas a la base de datos documentadas.', 'atelier-de-medios' ) ),
				array( 'imp', __( 'Preparado para el directorio oficial de plugins de WordPress.org (edición sin actualizador propio).', 'atelier-de-medios' ) ),
				array( 'imp', __( 'El identificador interno pasa a «atelier-de-medios»: la carpeta del plugin y su archivo principal cambian de nombre.', 'atelier-de-medios' ) ),
			) ),
			array( '1.4.0', '2026-10-22', array(
				array( 'new', __( 'Nueva pestaña «Novedades» con el historial de cambios de las últimas versiones.', 'atelier-de-medios' ) ),
			) ),
			array( '1.3.0', '2026-10-22', array(
				array( 'new', __( 'Importar carpetas del equipo: arrastra una carpeta de tu ordenador o de una unidad externa sobre el árbol y se crean las carpetas y subcarpetas con sus mismos nombres, subiendo cada imagen a su carpeta.', 'atelier-de-medios' ) ),
				array( 'imp', __( 'Panel de progreso durante la importación, con resumen de archivos subidos, carpetas nuevas, omitidos y fallidos.', 'atelier-de-medios' ) ),
				array( 'imp', __( 'El autor del plugin figura como Ismael GPH.', 'atelier-de-medios' ) ),
			) ),
			array( '1.2.1', '2026-10-21', array(
				array( 'fix', __( 'En Medios (rejilla), el árbol de carpetas se recortaba por abajo al abrir una carpeta con pocos archivos.', 'atelier-de-medios' ) ),
			) ),
			array( '1.2.0', '2026-10-21', array(
				array( 'new', __( 'Actualizaciones desde la página de Plugins de WordPress, a través de las Releases de GitHub.', 'atelier-de-medios' ) ),
				array( 'new', __( 'Enlace «Buscar actualizaciones» en la fila del plugin para comprobar de inmediato si hay una versión nueva.', 'atelier-de-medios' ) ),
			) ),
			array( '1.1.1', '2026-10-21', array(
				array( 'fix', __( 'Las miniaturas se solapaban con la barra de carpetas en la ventana de medios cuando la lista era corta.', 'atelier-de-medios' ) ),
			) ),
			array( '1.1.0', '2026-10-21', array(
				array( 'new', __( 'Importador desde FileBird: trae carpetas, subcarpetas y la ubicación de cada archivo sin modificar FileBird.', 'atelier-de-medios' ) ),
			) ),
			array( '1.0.0', '2026-10-20', array(
				array( 'new', __( 'Primera versión: carpetas y subcarpetas, categorías con color, arrastrar y soltar y árbol integrado en la ventana de medios de Divi, Elementor y Gutenberg.', 'atelier-de-medios' ) ),
			) ),
		);
	}

	private static function render_changelog() {
		$labels = array(
			'new' => __( 'Nuevo', 'atelier-de-medios' ),
			'imp' => __( 'Mejora', 'atelier-de-medios' ),
			'fix' => __( 'Corrección', 'atelier-de-medios' ),
		);
		?>
		<div class="cdm-guide cdm-log">
			<h2><?php esc_html_e( 'Novedades de las últimas versiones', 'atelier-de-medios' ); ?></h2>
			<p class="cdm-wide"><?php esc_html_e( 'Estos son los cambios de las cuatro últimas revisiones: lo nuevo, las mejoras y las correcciones.', 'atelier-de-medios' ); ?></p>
			<?php foreach ( array_slice( self::changelog(), 0, 4 ) as $rel ) : ?>
				<section class="cdm-rel<?php echo CDM_VERSION === $rel[0] ? ' is-current' : ''; ?>">
					<header>
						<h3><?php echo esc_html( 'v' . $rel[0] ); ?></h3>
						<time datetime="<?php echo esc_attr( $rel[1] ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $rel[1] ) ) ); ?></time>
						<?php if ( CDM_VERSION === $rel[0] ) : ?>
							<span class="cdm-cur"><?php esc_html_e( 'Versión instalada', 'atelier-de-medios' ); ?></span>
						<?php endif; ?>
					</header>
					<ul>
						<?php foreach ( $rel[2] as $it ) : ?>
							<li><span class="cdm-tag is-<?php echo esc_attr( $it[0] ); ?>"><?php echo esc_html( $labels[ $it[0] ] ); ?></span><span><?php echo esc_html( $it[1] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ Guía de uso */

	private static function render_guide( $n_folders, $tot ) {
		$steps = array(
			array( __( 'Abre la biblioteca', 'atelier-de-medios' ), __( 'Ve a Medios → Biblioteca (rejilla o lista). A la izquierda aparece el panel «Carpetas».', 'atelier-de-medios' ) ),
			array( __( 'Crea tu primera carpeta', 'atelier-de-medios' ), __( 'Pulsa el botón «+» del panel, ponle nombre y, si quieres, una carpeta superior y una categoría.', 'atelier-de-medios' ) ),
			array( __( 'Llévate los archivos', 'atelier-de-medios' ), __( 'Arrastra uno o varios archivos sobre la carpeta. Con «Selección múltiple» puedes mover muchos de golpe.', 'atelier-de-medios' ) ),
			array( __( 'Úsalas en tus constructores', 'atelier-de-medios' ), __( 'En Divi, Elementor o Gutenberg, al elegir una imagen verás el mismo árbol en la ventana de medios: elige carpeta y listo.', 'atelier-de-medios' ) ),
		);
		$features = array(
			array( __( 'Carpetas y subcarpetas', 'atelier-de-medios' ), __( 'Todas las que quieras, en el nivel que quieras. Son virtuales: tus archivos y sus URL no cambian nunca.', 'atelier-de-medios' ) ),
			array( __( 'Selección múltiple', 'atelier-de-medios' ), __( 'Mayús+clic selecciona un rango, Ctrl/Cmd+clic carpetas sueltas, o activa el modo de casillas con el icono del encabezado. Después pulsa «Eliminar» (o Supr). Esc cancela.', 'atelier-de-medios' ) ),
			array( __( 'Menú contextual', 'atelier-de-medios' ), __( 'Clic derecho (o botón «⋯») sobre una carpeta: nueva subcarpeta, renombrar, cambiar categoría, mover y eliminar. F2 renombra.', 'atelier-de-medios' ) ),
			array( __( 'Categorías con color', 'atelier-de-medios' ), __( 'Agrupa carpetas por Cliente, Proyecto, Marca… Cada categoría tiñe sus carpetas y las pastillas sobre el árbol filtran por ella.', 'atelier-de-medios' ) ),
			array( __( 'Arrastrar y soltar', 'atelier-de-medios' ), __( 'Archivos sobre carpetas y carpetas sobre carpetas. Soltar sobre «Todos los archivos» lleva una carpeta a la raíz; sobre «Sin carpeta» saca archivos de su carpeta.', 'atelier-de-medios' ) ),
			array( __( 'Subidas a la carpeta activa', 'atelier-de-medios' ), __( 'Lo que subas con una carpeta abierta se guarda ahí. El pie del panel te recuerda el destino.', 'atelier-de-medios' ) ),
			array( __( 'Buscador de carpetas', 'atelier-de-medios' ), __( 'Escribe en «Buscar carpeta…» y el árbol se reduce a las coincidencias, con su ruta.', 'atelier-de-medios' ) ),
			array( __( 'Importar carpetas del equipo', 'atelier-de-medios' ), __( 'Arrastra una carpeta de tu ordenador o de una unidad externa sobre el árbol: se crean las carpetas y subcarpetas con sus mismos nombres y se suben las imágenes a cada una. Si la sueltas sobre una carpeta, se crea dentro de ella.', 'atelier-de-medios' ) ),
			array( __( 'Vista de lista', 'atelier-de-medios' ), __( 'Columna «Carpeta» y acción masiva «Mover a carpeta…» para reorganizar muchos archivos a la vez.', 'atelier-de-medios' ) ),
			array( __( 'Detalle del archivo', 'atelier-de-medios' ), __( 'En el panel de detalles de cualquier archivo, el campo «Carpeta» permite cambiarlo sin salir de la ventana.', 'atelier-de-medios' ) ),
			array( __( 'Contadores', 'atelier-de-medios' ), __( 'Cada carpeta, «Todos los archivos» y «Sin carpeta» muestran cuántos archivos contienen.', 'atelier-de-medios' ) ),
		);
		$faq = array(
			array( __( '¿Qué pasa si arrastro una carpeta que ya existe?', 'atelier-de-medios' ), __( 'Se reutilizan las carpetas con el mismo nombre y ubicación, sin duplicarlas. Los archivos se suben de nuevo: WordPress no detecta duplicados, así que evita repetir la misma carpeta.', 'atelier-de-medios' ) ),
			array( __( '¿Puedo traer mis carpetas de FileBird?', 'atelier-de-medios' ), __( 'Sí. Ve a la pestaña «Importar desde FileBird», analiza e importa. Se conserva la jerarquía y la ubicación de cada archivo; FileBird no se modifica.', 'atelier-de-medios' ) ),
			array( __( '¿Si borro una carpeta pierdo imágenes?', 'atelier-de-medios' ), __( 'No. Los archivos pasan a «Sin carpeta» y las subcarpetas suben un nivel.', 'atelier-de-medios' ) ),
			array( __( '¿Cambian las URL de mis imágenes?', 'atelier-de-medios' ), __( 'No. Las carpetas son una capa de organización; los archivos siguen donde estaban en el servidor.', 'atelier-de-medios' ) ),
			array( __( '¿Un archivo puede estar en dos carpetas?', 'atelier-de-medios' ), __( 'No, pertenece a una sola. Para agrupar de otra forma usa categorías de carpeta.', 'atelier-de-medios' ) ),
			array( __( 'No veo el árbol en el constructor del front-end', 'atelier-de-medios' ), __( 'Comprueba en «Ajustes y categorías» que «Constructores visuales» está activado y que abres la pestaña «Biblioteca de medios» de la ventana.', 'atelier-de-medios' ) ),
			array( __( '¿Quién puede crear o borrar carpetas?', 'atelier-de-medios' ), __( 'Lo decides en «Ajustes y categorías»: administradores, editores o cualquiera que pueda subir archivos. Todos pueden mover sus propios archivos.', 'atelier-de-medios' ) ),
		);
		?>
		<div class="cdm-guide">
			<div class="cdm-callout">
				<?php
				printf(
					/* translators: 1: carpetas, 2: archivos totales, 3: archivos sin carpeta */
					esc_html__( 'Ahora mismo: %1$d carpetas · %2$d archivos en la biblioteca · %3$d sin carpeta.', 'atelier-de-medios' ),
					(int) $n_folders,
					(int) $tot['all'],
					(int) $tot['none']
				);
				?>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><?php esc_html_e( 'Ir a la biblioteca', 'atelier-de-medios' ); ?></a>
			</div>

			<h2><?php esc_html_e( 'Empieza en 4 pasos', 'atelier-de-medios' ); ?></h2>
			<ol class="cdm-steps">
				<?php foreach ( $steps as $i => $st ) : ?>
					<li><span class="cdm-step-n"><?php echo (int) ( $i + 1 ); ?></span><div><strong><?php echo esc_html( $st[0] ); ?></strong><p><?php echo esc_html( $st[1] ); ?></p></div></li>
				<?php endforeach; ?>
			</ol>

			<h2><?php esc_html_e( 'Qué puedes hacer', 'atelier-de-medios' ); ?></h2>
			<div class="cdm-cards">
				<?php foreach ( $features as $f ) : ?>
					<div class="cdm-card"><h3><?php echo esc_html( $f[0] ); ?></h3><p><?php echo esc_html( $f[1] ); ?></p></div>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Compatibilidad con constructores', 'atelier-de-medios' ); ?></h2>
			<p class="cdm-wide"><?php esc_html_e( 'El árbol se integra en la ventana de medios nativa de WordPress, la misma que usan Divi (incluido su Visual Builder), Elementor, Gutenberg y la mayoría de plugins. Allí donde se abre «Biblioteca de medios» para elegir una imagen, verás tus carpetas.', 'atelier-de-medios' ); ?></p>

			<h2><?php esc_html_e( 'Preguntas frecuentes', 'atelier-de-medios' ); ?></h2>
			<div class="cdm-faq">
				<?php foreach ( $faq as $q ) : ?>
					<details><summary><?php echo esc_html( $q[0] ); ?></summary><p><?php echo esc_html( $q[1] ); ?></p></details>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
