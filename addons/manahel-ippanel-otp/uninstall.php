<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'mio_settings' );
delete_option( 'mio_version' );
// User mobile/national-ID metadata and the login page are intentionally preserved.
