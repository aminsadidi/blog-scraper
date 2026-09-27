<?php
/**
 * Removes the plugin's own data when it is deleted from the Plugins screen.
 *
 * Published news posts are kept on purpose: they are regular WordPress posts now. Their robot markers
 * (noindex flags) are removed, so they behave like any other post.
 *
 * @package ParsiNewsRobot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Sources (private post type) and their meta.
$pnr_sources = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'pnr_source'" ); // phpcs:ignore WordPress.DB
foreach ( $pnr_sources as $pnr_id ) {
	wp_delete_post( (int) $pnr_id, true );
}

// Robot markers on posts.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_pnr_source_id', '_pnr_noindex', '_pnr_imported', '_pnr_external_url')" ); // phpcs:ignore WordPress.DB

// Tables.
foreach ( array( 'pnr_items', 'pnr_seen', 'pnr_placements', 'pnr_redirects', 'pnr_log' ) as $pnr_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$pnr_table}" ); // phpcs:ignore WordPress.DB
}

// Options and transients.
foreach ( array( 'pnr_settings', 'pnr_db_version', 'pnr_cron_key', 'pnr_last_tick', 'pnr_last_cleanup', 'pnr_last_external_cron', 'pnr_sync_cursor', 'pnr_activated_at', 'pnr_log' ) as $pnr_option ) {
	delete_option( $pnr_option );
}
delete_transient( 'pnr_schedule_checked' );
delete_transient( 'pnr_welcome' );

// Scheduled jobs.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'parsi-news-robot' );
}

// Capability.
foreach ( wp_roles()->role_objects as $pnr_role ) {
	$pnr_role->remove_cap( 'pnr_manage' );
}
