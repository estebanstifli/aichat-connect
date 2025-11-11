<?php
/**
 * Uninstall cleanup for Andromeda Connect
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove plugin options (do not drop tables to avoid data loss)
$option_keys = [
    'aichat_global_bot_slug',
    'aichat_connect_access_token',
    'aichat_connect_default_phone_id',
];

foreach ( $option_keys as $k ) {
    delete_option( $k );
    // In case multisite, clean network option as well
    delete_site_option( $k );
}
