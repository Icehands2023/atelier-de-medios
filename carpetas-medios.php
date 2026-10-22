<?php
/**
 * Plugin Name:       Atelier de Medios — Carpetas para tu biblioteca
 * Description:       Organiza la biblioteca de medios en carpetas y subcarpetas, con categorías de carpeta, arrastrar y soltar y árbol integrado en la ventana de medios de Divi, Elementor, Gutenberg y cualquier constructor que use el selector nativo de WordPress.
 * Version:           1.3.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Ismael GPH
 * License:           GPL-2.0-or-later
 * Text Domain:       carpetas-medios
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'CDM_VERSION', '1.3.0' );
define( 'CDM_FILE', __FILE__ );
define( 'CDM_DIR', plugin_dir_path( __FILE__ ) );
define( 'CDM_URL', plugin_dir_url( __FILE__ ) );

require_once CDM_DIR . 'includes/class-cdm-folders.php';
require_once CDM_DIR . 'includes/class-cdm-media.php';
require_once CDM_DIR . 'includes/class-cdm-ajax.php';
require_once CDM_DIR . 'includes/class-cdm-import.php';
require_once CDM_DIR . 'includes/class-cdm-updater.php';
require_once CDM_DIR . 'includes/class-cdm-admin.php';

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'carpetas-medios', false, dirname( plugin_basename( CDM_FILE ) ) . '/languages' );
	CDM_Folders::init();
	CDM_Media::init();
	CDM_Ajax::init();
	CDM_Import::init();
	CDM_Updater::init();
	CDM_Admin::init();
} );

register_activation_hook( __FILE__, function () {
	CDM_Folders::register();
	add_option( 'cdm_manage_cap', 'edit_others_posts' );
	add_option( 'cdm_include_children', 0 );
	add_option( 'cdm_builders', 1 );
	add_option( 'cdm_delete_data', 0 );
} );
