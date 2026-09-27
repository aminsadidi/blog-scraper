<?php
/**
 * Removes the plugin's own data when it is deleted from the Plugins screen.
 *
 * Published news posts are kept on purpose: they are regular WordPress posts now. The plugin's own markers
 * are removed; Rank Math's own "noindex" setting on them is kept, so they stay out of Google.
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

// Scheduled jobs (Action Scheduler is usually not loaded during uninstall, so its tables are cleaned directly).
$pnr_groups = $wpdb->prefix . 'actionscheduler_groups';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pnr_groups ) ) === $pnr_groups ) { // phpcs:ignore WordPress.DB
	$pnr_group_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$pnr_groups} WHERE slug = %s", 'parsi-news-robot' ) ); // phpcs:ignore WordPress.DB
	if ( $pnr_group_id ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE group_id = %d AND status = 'pending'", $pnr_group_id ) ); // phpcs:ignore WordPress.DB
	}
}

// Capability.
foreach ( wp_roles()->role_objects as $pnr_role ) {
	$pnr_role->remove_cap( 'pnr_manage' );
}
