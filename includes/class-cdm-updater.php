<?php
/**
 * Actualizaciones desde la página de Plugins de WordPress, a partir de las «Releases» de un repositorio público de GitHub.
 * Cada release debe llevar una etiqueta de versión (v1.2.0) y, como archivo adjunto, el ZIP del plugin.
 */
defined( 'ABSPATH' ) || exit;

class CDM_Updater {

	const REPO  = 'Icehands2023/atelier-de-medios';
	const CACHE = 'cdm_update_info';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'plugin_action_links_' . plugin_basename( CDM_FILE ), array( __CLASS__, 'links' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_force_check' ) );
	}

	private static function repo() {
		return apply_filters( 'cdm_update_repo', self::REPO );
	}

	/** @return array|null {version, zip, url, notes} */
	public static function remote( $force = false ) {
		$cached = get_transient( self::CACHE );
		if ( ! $force && false !== $cached ) {
			return $cached ?: null;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'AtelierDeMedios/' . CDM_VERSION ) )
		);
		$info = null;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$j = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $j ) && ! empty( $j['tag_name'] ) ) {
				$zip = '';
				foreach ( (array) ( $j['assets'] ?? array() ) as $a ) {
					if ( isset( $a['browser_download_url'] ) && '.zip' === substr( $a['name'], -4 ) ) {
						$zip = $a['browser_download_url'];
						break;
					}
				}
				if ( ! $zip && ! empty( $j['zipball_url'] ) ) {
					$zip = $j['zipball_url'];
				}
				if ( $zip ) {
					$info = array(
						'version' => ltrim( $j['tag_name'], 'vV' ),
						'zip'     => $zip,
						'url'     => $j['html_url'] ?? '',
						'notes'   => $j['body'] ?? '',
					);
				}
			}
		}
		set_transient( self::CACHE, $info ?: 0, $info ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $info;
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$r = self::remote();
		if ( $r && version_compare( $r['version'], CDM_VERSION, '>' ) ) {
			$transient->response[ plugin_basename( CDM_FILE ) ] = (object) array(
				'slug'        => 'carpetas-medios',
				'plugin'      => plugin_basename( CDM_FILE ),
				'new_version' => $r['version'],
				'url'         => $r['url'],
				'package'     => $r['zip'],
			);
		}
		return $transient;
	}

	public static function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'carpetas-medios' !== $args->slug ) {
			return $res;
		}
		$r = self::remote();
		if ( ! $r ) {
			return $res;
		}
		return (object) array(
			'name'          => 'Atelier de Medios',
			'slug'          => 'carpetas-medios',
			'version'       => $r['version'],
			'author'        => 'Ismael Gonzalo Peña',
			'homepage'      => $r['url'],
			'download_link' => $r['zip'],
			'sections'      => array( 'changelog' => wpautop( esc_html( $r['notes'] ) ) ),
		);
	}

	/** Si el ZIP viene de «zipball», su carpeta raíz tiene otro nombre: se renombra a carpetas-medios. */
	public static function fix_folder( $source, $remote, $upgrader, $extra ) {
		if ( empty( $extra['plugin'] ) || plugin_basename( CDM_FILE ) !== $extra['plugin'] ) {
			return $source;
		}
		global $wp_filesystem;
		$wanted = trailingslashit( dirname( untrailingslashit( $source ) ) ) . 'carpetas-medios/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) || ! $wp_filesystem ) {
			return $source;
		}
		return $wp_filesystem->move( $source, $wanted, true ) ? $wanted : $source;
	}

	public static function links( $links ) {
		$url = wp_nonce_url( admin_url( 'plugins.php?cdm_check=1' ), 'cdm_check' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Buscar actualizaciones', 'carpetas-medios' ) . '</a>' );
		return $links;
	}

	public static function maybe_force_check() {
		if ( empty( $_GET['cdm_check'] ) || ! current_user_can( 'update_plugins' ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'cdm_check' ) ) {
			return;
		}
		self::remote( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'plugins.php?cdm_checked=1' ) );
		exit;
	}
}
