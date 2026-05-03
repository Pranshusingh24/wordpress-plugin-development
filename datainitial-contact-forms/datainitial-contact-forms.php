<?php
/**
 * Plugin Name:  Datainitial Contact Forms
 * Plugin URI:   https://datanitial.com
 * Description:  Stores contact form submissions from the React frontend and provides an admin UI with CSV export.
 * Version:      1.0.0
 * Author:       Pranshu Singh
 * Author URI:   https://pranshusingh19.github.io/pranshu_portfolio/
 * Text Domain:  datainitial-cf
 */

defined( 'ABSPATH' ) || exit;

define( 'DCF_VERSION',     '1.0.0' );
define( 'DCF_PLUGIN_FILE', __FILE__ );
define( 'DCF_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'DCF_TABLE',       'dcf_submissions' );

/* ═══════════════════════════════════════════════════════════════════════
   1. AUTOLOAD INCLUDES
═══════════════════════════════════════════════════════════════════════ */
require_once DCF_PLUGIN_DIR . 'includes/class-dcf-db.php';
require_once DCF_PLUGIN_DIR . 'includes/class-dcf-rest-api.php';
require_once DCF_PLUGIN_DIR . 'includes/class-dcf-admin.php';

/* ═══════════════════════════════════════════════════════════════════════
   2. ACTIVATION / DEACTIVATION
═══════════════════════════════════════════════════════════════════════ */
register_activation_hook( __FILE__, [ 'DCF_DB', 'create_table' ] );

/* ═══════════════════════════════════════════════════════════════════════
   3. BOOT
═══════════════════════════════════════════════════════════════════════ */
add_action( 'rest_api_init', [ 'DCF_REST_API', 'register_routes' ] );
add_action( 'admin_menu',    [ 'DCF_Admin',    'register_menu'   ] );
add_action( 'admin_enqueue_scripts', [ 'DCF_Admin', 'enqueue_assets' ] );
add_action( 'admin_init',    [ 'DCF_Admin',    'maybe_export_csv' ] );
