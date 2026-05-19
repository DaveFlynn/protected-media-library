<?php
/**
 * Plugin Name:       Protected Media Library
 * Description:       A parallel, physically-separated media library. Protected files live outside wp-content/uploads/ and are served through an authenticated fast-path handler.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Dave
 * License:           GPL v2 or later
 * Text Domain:       protected-media-library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PML_VERSION', '0.1.1' );
define( 'PML_FILE', __FILE__ );
define( 'PML_DIR', plugin_dir_path( __FILE__ ) );
define( 'PML_URL', plugin_dir_url( __FILE__ ) );
define( 'PML_SLUG', 'protected-media-library' );

// Filesystem roots. The installer picks the best path (preferring outside the
// document root) and persists it in the `pml_storage_dir` option. We resolve
// here so the rest of the plugin can use simple constants.
$pml_storage = get_option( 'pml_storage_dir' );
if ( ! is_string( $pml_storage ) || $pml_storage === '' ) {
	$pml_storage = WP_CONTENT_DIR . '/protected-uploads';
}
define( 'PML_STORAGE_DIR', $pml_storage );
define( 'PML_SECRET_FILE', PML_STORAGE_DIR . '/.pml-secret.php' );

// URL prefix served by the handler / fallback.
define( 'PML_URL_PREFIX', 'protected-media' );

// Cookie config.
define( 'PML_COOKIE_NAME', 'wp_protected_media' );
define( 'PML_COOKIE_TTL', HOUR_IN_SECONDS );

require_once PML_DIR . 'includes/class-access.php';
require_once PML_DIR . 'includes/class-cookie.php';
require_once PML_DIR . 'includes/class-storage.php';
require_once PML_DIR . 'includes/class-rewrites.php';
require_once PML_DIR . 'includes/class-library.php';
require_once PML_DIR . 'includes/class-admin.php';
require_once PML_DIR . 'includes/class-handler-fallback.php';
require_once PML_DIR . 'includes/class-blocks.php';
require_once PML_DIR . 'includes/class-shortcodes.php';
require_once PML_DIR . 'includes/class-classic-editor.php';
require_once PML_DIR . 'includes/class-add-page.php';
require_once PML_DIR . 'includes/class-cache.php';
require_once PML_DIR . 'includes/class-installer.php';

register_activation_hook( __FILE__, [ 'PML_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PML_Installer', 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
	PML_Storage::init();
	PML_Rewrites::init();
	PML_Library::init();
	PML_Admin::init();
	PML_Cookie::init();
	PML_Handler_Fallback::init();
	PML_Blocks::init();
	PML_Shortcodes::init();
	PML_Classic_Editor::init();
	PML_Add_Page::init();
	PML_Cache::init();
} );
