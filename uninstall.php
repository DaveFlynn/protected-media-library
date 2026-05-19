<?php
/**
 * Uninstall handler. Removes plugin-managed files and (optionally) attachment
 * posts for protected media. Storage files are intentionally NOT deleted
 * automatically — that requires explicit user opt-in via the admin UI before
 * uninstalling. Document this.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove root .htaccess block.
$ht = ABSPATH . '.htaccess';
if ( file_exists( $ht ) && is_writable( $ht ) ) {
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}
	insert_with_markers( $ht, 'Protected Media Library', [] );
}

// Drop the install-notice option.
delete_option( 'pml_install_notice' );
delete_option( 'pml_delivery_mode' );

// NOTE: protected-uploads/ and the secret file are left in place by design.
// A future admin "danger zone" action can wipe these; doing it silently here
// would risk irreversible data loss on accidental deactivation/reinstall.
