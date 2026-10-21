<?php
/**
 * Importador desde FileBird (versiones actuales con tablas propias y la antigua basada en taxonomía).
 * Sólo LEE los datos de FileBird: no los modifica ni los borra. Funciona aunque FileBird esté desactivado.
 */
defined( 'ABSPATH' ) || exit;

class CDM_Import {

	const CHUNK = 100;

	public static function init() {
		add_action( 'wp_ajax_cdm_import_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_cdm_import_run', array( __CLASS__, 'ajax_run' ) );
	}

	/* ------------------------------------------------------------------ Origen */

	private static function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/** @return array|null ['type' => 'fbv'|'tax', 'folders' => tabla, 'files' => tabla] */
	public static function source() {
		global $wpdb;
		$f = $wpdb->prefix . 'fbv';
		$a = $wpdb->prefix . 'fbv_attachment_folder';
		if ( self::table_exists( $f ) && self::table_exists( $a ) ) {
			return array( 'type' => 'fbv', 'folders' => $f, 'files' => $a );
		}
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'nt_wmc_folder' ) );
		if ( $n > 0 ) {
			return array( 'type' => 'tax', 'folders' => '', 'files' => '' );
		}
		return null;
	}

	/** @return array[] cada una: id, name, parent */
	public static function get_folders( $src ) {
		global $wpdb;
		$out = array();
		if ( 'fbv' === $src['type'] ) {
			$rows = $wpdb->get_results( "SELECT id, name, parent FROM `{$src['folders']}` WHERE id > 0 ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT t.term_id AS id, t.name, tt.parent FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s ORDER BY t.term_id ASC",
					'nt_wmc_folder'
				),
				ARRAY_A
			);
		}
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['id'] ] = array(
				'id'     => (int) $r['id'],
				'name'   => wp_specialchars_decode( (string) $r['name'], ENT_QUOTES ),
				'parent' => (int) $r['parent'],
			);
		}
		return $out;
	}

	private static function files_from_sql( $src ) {
		global $wpdb;
		if ( 'fbv' === $src['type'] ) {
			return array(
				"FROM `{$src['files']}` af INNER JOIN {$wpdb->posts} p ON p.ID = af.attachment_id WHERE p.post_type = 'attachment' AND af.folder_id > 0",
				'af.folder_id AS folder_id, af.attachment_id AS attachment_id',
				'af.attachment_id',
			);
		}
		return array(
			"FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = 'nt_wmc_folder' AND p.post_type = 'attachment'",
			'tt.term_id AS folder_id, tr.object_id AS attachment_id',
			'tr.object_id',
		);
	}

	public static function count_files( $src ) {
		global $wpdb;
		$q = self::files_from_sql( $src );
		return (int) $wpdb->get_var( "SELECT COUNT(*) {$q[0]}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function get_files( $src, $offset, $limit ) {
		global $wpdb;
		$q = self::files_from_sql( $src );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$q[1]} {$q[0]} ORDER BY {$q[2]} ASC LIMIT %d OFFSET %d", (int) $limit, (int) $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------ AJAX */

	private static function guard() {
		if ( ! check_ajax_referer( 'cdm_import', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'La sesión ha caducado. Recarga la página.', 'carpetas-medios' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para esta acción.', 'carpetas-medios' ) ), 403 );
		}
	}

	public static function ajax_scan() {
		self::guard();
		$src = self::source();
		if ( ! $src ) {
			wp_send_json_success( array( 'found' => false ) );
		}
		$folders = self::get_folders( $src );
		wp_send_json_success(
			array(
				'found'   => true,
				'type'    => $src['type'],
				'folders' => count( $folders ),
				'files'   => self::count_files( $src ),
			)
		);
	}

	public static function ajax_run() {
		self::guard();
		$src = self::source();
		if ( ! $src ) {
			wp_send_json_error( array( 'message' => __( 'No se han encontrado datos de FileBird.', 'carpetas-medios' ) ), 404 );
		}
		$step      = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$overwrite = ! empty( $_POST['overwrite'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$key       = 'cdm_import_map_' . get_current_user_id();

		if ( 'folders' === $step ) {
			$res = self::import_folders( $src );
			set_transient( $key, $res['map'], HOUR_IN_SECONDS );
			wp_send_json_success( array( 'created' => $res['created'], 'reused' => $res['reused'], 'failed' => $res['failed'], 'total' => self::count_files( $src ) ) );
		}

		if ( 'files' === $step ) {
			$map = get_transient( $key );
			if ( ! is_array( $map ) ) {
				wp_send_json_error( array( 'message' => __( 'Falta el paso de carpetas. Vuelve a empezar la importación.', 'carpetas-medios' ) ), 400 );
			}
			$rows  = self::get_files( $src, $offset, self::CHUNK );
			$stats = array( 'assigned' => 0, 'skipped' => 0, 'missing' => 0 );
			foreach ( $rows as $r ) {
				$fid = isset( $map[ (int) $r['folder_id'] ] ) ? (int) $map[ (int) $r['folder_id'] ] : 0;
				$aid = (int) $r['attachment_id'];
				if ( ! $fid ) {
					$stats['missing']++;
					continue;
				}
				if ( ! $overwrite && CDM_Folders::folder_of( $aid ) ) {
					$stats['skipped']++;
					continue;
				}
				CDM_Folders::assign( array( $aid ), $fid );
				$stats['assigned']++;
			}
			$done = count( $rows ) < self::CHUNK;
			if ( $done ) {
				CDM_Folders::recount();
				delete_transient( $key );
			}
			wp_send_json_success( array_merge( $stats, array( 'done' => $done, 'next' => $offset + count( $rows ) ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Paso desconocido.', 'carpetas-medios' ) ), 400 );
	}

	/** Crea la estructura (padres primero). Reutiliza carpetas existentes con el mismo nombre y padre. */
	private static function import_folders( $src ) {
		$all   = self::get_folders( $src );
		$index = array(); // [padre][nombre en minúsculas] => id.
		foreach ( CDM_Folders::folders() as $f ) {
			$index[ $f['parent'] ][ mb_strtolower( $f['name'] ) ] = $f['id'];
		}
		$map     = array();
		$created = 0;
		$reused  = 0;
		$failed  = 0;
		$pending = $all;
		$guard   = 0;
		while ( $pending && $guard++ < 100 ) {
			$progress = false;
			foreach ( $pending as $id => $r ) {
				$p = $r['parent'];
				if ( $p > 0 && isset( $all[ $p ] ) && ! isset( $map[ $p ] ) ) {
					continue; // esperar al padre.
				}
				$parent = ( $p > 0 && isset( $map[ $p ] ) ) ? $map[ $p ] : 0;
				$lname  = mb_strtolower( $r['name'] );
				if ( isset( $index[ $parent ][ $lname ] ) ) {
					$map[ $id ] = $index[ $parent ][ $lname ];
					$reused++;
				} else {
					$new = CDM_Folders::create( $r['name'], $parent );
					if ( is_wp_error( $new ) ) {
						$failed++;
						unset( $pending[ $id ] );
						$progress = true;
						continue;
					}
					$map[ $id ]                  = $new;
					$index[ $parent ][ $lname ] = $new;
					$created++;
				}
				unset( $pending[ $id ] );
				$progress = true;
			}
			if ( ! $progress ) {
				// Huérfanas o ciclos: se cuelgan de la raíz.
				foreach ( $pending as $id => $r ) {
					$all[ $id ]['parent'] = 0;
					$pending[ $id ]['parent'] = 0;
				}
			}
		}
		return array( 'map' => $map, 'created' => $created, 'reused' => $reused, 'failed' => $failed );
	}

	/* ------------------------------------------------------------------ Interfaz (pestaña) */

	public static function render_tab() {
		$nonce = wp_create_nonce( 'cdm_import' );
		?>
		<div class="cdm-guide cdm-import" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<h2><?php esc_html_e( 'Importar desde FileBird', 'carpetas-medios' ); ?></h2>
			<p class="cdm-wide"><?php esc_html_e( 'Trae a Atelier de Medios las carpetas, subcarpetas y la ubicación de cada archivo que ya tienes en FileBird. FileBird no se modifica ni se borra, y funciona aunque esté desactivado. Si repites la importación no se duplican carpetas.', 'carpetas-medios' ); ?></p>
			<div class="cdm-callout"><?php esc_html_e( 'Antes de importar, haz una copia de seguridad de la base de datos.', 'carpetas-medios' ); ?></div>

			<p><button type="button" class="button button-primary" id="cdm-scan"><?php esc_html_e( '1. Analizar FileBird', 'carpetas-medios' ); ?></button></p>
			<div id="cdm-scan-result" class="cdm-wide" hidden></div>

			<div id="cdm-run-box" hidden>
				<p><label><input type="checkbox" id="cdm-overwrite"> <?php esc_html_e( 'Sobrescribir archivos que ya estén en una carpeta de Atelier de Medios (por defecto se respetan).', 'carpetas-medios' ); ?></label></p>
				<p><button type="button" class="button button-primary" id="cdm-run"><?php esc_html_e( '2. Importar ahora', 'carpetas-medios' ); ?></button></p>
				<div class="cdm-progress" id="cdm-progress" hidden><div class="cdm-bar"><span id="cdm-bar-fill"></span></div><p id="cdm-progress-txt"></p></div>
			</div>
			<p id="cdm-import-end" class="cdm-wide" hidden></p>
		</div>
		<script>
		(function ($) {
			var $root = $('.cdm-import'), nonce = $root.data('nonce');
			function call(action, data) { return $.post(ajaxurl, $.extend({ action: action, nonce: nonce }, data || {})); }
			function msg(r) { return (r && r.data && r.data.message) || '<?php echo esc_js( __( 'Error inesperado.', 'carpetas-medios' ) ); ?>'; }
			$('#cdm-scan').on('click', function () {
				var $out = $('#cdm-scan-result').prop('hidden', false).text('…');
				call('cdm_import_scan').done(function (r) {
					if (!r.success) { $out.text(msg(r)); return; }
					if (!r.data.found) { $out.text('<?php echo esc_js( __( 'No se han encontrado datos de FileBird en esta base de datos.', 'carpetas-medios' ) ); ?>'); $('#cdm-run-box').prop('hidden', true); return; }
					$out.text(r.data.folders + ' <?php echo esc_js( __( 'carpetas y', 'carpetas-medios' ) ); ?> ' + r.data.files + ' <?php echo esc_js( __( 'archivos organizados encontrados en FileBird.', 'carpetas-medios' ) ); ?>');
					$('#cdm-run-box').prop('hidden', !(r.data.folders > 0));
					$root.data('total', r.data.files);
				}).fail(function (x) { $out.text(msg(x.responseJSON)); });
			});
			$('#cdm-run').on('click', function () {
				var $btn = $(this).prop('disabled', true), overwrite = $('#cdm-overwrite').is(':checked') ? 1 : 0;
				var $p = $('#cdm-progress').prop('hidden', false), $t = $('#cdm-progress-txt'), $f = $('#cdm-bar-fill');
				var acc = { assigned: 0, skipped: 0, missing: 0 }, info = {};
				function fail(x) { $t.text(msg(x && x.responseJSON ? x.responseJSON : x)); $btn.prop('disabled', false); }
				$t.text('<?php echo esc_js( __( 'Creando carpetas…', 'carpetas-medios' ) ); ?>');
				call('cdm_import_run', { step: 'folders' }).done(function (r) {
					if (!r.success) { return fail(r); }
					info = r.data;
					var total = Math.max(info.total, 1);
					(function next(offset) {
						call('cdm_import_run', { step: 'files', offset: offset, overwrite: overwrite }).done(function (s) {
							if (!s.success) { return fail(s); }
							acc.assigned += s.data.assigned; acc.skipped += s.data.skipped; acc.missing += s.data.missing;
							$f.css('width', Math.min(100, Math.round(s.data.next / total * 100)) + '%');
							$t.text(s.data.next + ' / ' + info.total);
							if (!s.data.done) { return next(s.data.next); }
							$f.css('width', '100%');
							$('#cdm-import-end').prop('hidden', false).html('<strong><?php echo esc_js( __( 'Importación completada.', 'carpetas-medios' ) ); ?></strong> ' + info.created + ' <?php echo esc_js( __( 'carpetas creadas', 'carpetas-medios' ) ); ?>, ' + info.reused + ' <?php echo esc_js( __( 'reutilizadas', 'carpetas-medios' ) ); ?>; ' + acc.assigned + ' <?php echo esc_js( __( 'archivos asignados', 'carpetas-medios' ) ); ?>, ' + acc.skipped + ' <?php echo esc_js( __( 'respetados', 'carpetas-medios' ) ); ?>' + (info.failed ? ', ' + info.failed + ' <?php echo esc_js( __( 'carpetas con error', 'carpetas-medios' ) ); ?>' : '') + '. <a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><?php echo esc_js( __( 'Ver la biblioteca', 'carpetas-medios' ) ); ?></a>');
							$btn.prop('disabled', false);
						}).fail(fail);
					})(0);
				}).fail(fail);
			});
		})(jQuery);
		</script>
		<?php
	}
}
