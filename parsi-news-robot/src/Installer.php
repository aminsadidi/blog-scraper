<?php
/**
 * Activation, database schema (versioned) and deactivation.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot;

defined( 'ABSPATH' ) || exit;

class Installer {

	const CAP = 'pnr_manage';

	public static function activate() {
		self::install_schema();
		self::grant_capability();
		Settings::cron_key();
		if ( false === get_option( 'pnr_activated_at', false ) ) {
			add_option( 'pnr_activated_at', time(), '', false );
		}
		set_transient( 'pnr_welcome', 1, HOUR_IN_SECONDS );
	}

	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( Queue::HOOKS as $hook ) {
				as_unschedule_all_actions( $hook, array(), Queue::GROUP );
			}
		}
		delete_transient( 'pnr_schedule_checked' );
	}

	/**
	 * Runs on every load: upgrades the schema when the plugin was updated in place.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'pnr_db_version', 0 ) < PNR_DB_VERSION ) {
			self::install_schema();
			self::grant_capability();
		}
	}

	public static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}pnr_items (
				post_id bigint(20) unsigned NOT NULL,
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				seen_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source_url text NOT NULL,
				source_name varchar(191) NOT NULL DEFAULT '',
				feed_sig char(32) NOT NULL DEFAULT '',
				content_hash char(32) NOT NULL DEFAULT '',
				main_term bigint(20) unsigned NOT NULL DEFAULT 0,
				has_video tinyint(1) NOT NULL DEFAULT 0,
				image_count smallint(5) unsigned NOT NULL DEFAULT 0,
				keep_post tinyint(1) NOT NULL DEFAULT 0,
				index_override varchar(10) NOT NULL DEFAULT '',
				imported_at int(10) unsigned NOT NULL DEFAULT 0,
				updated_at int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (post_id),
				KEY source_imported (source_id,imported_at),
				KEY imported_at (imported_at)
			) $c;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}pnr_seen (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				item_hash char(32) NOT NULL,
				title_norm varchar(255) NOT NULL DEFAULT '',
				title_hash char(32) NOT NULL DEFAULT '',
				link text NOT NULL,
				feed_sig char(32) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'queued',
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				payload longtext NULL,
				note varchar(255) NOT NULL DEFAULT '',
				scheduled_at int(10) unsigned NOT NULL DEFAULT 0,
				created_at int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY item_hash (item_hash),
				KEY title_hash (title_hash),
				KEY created_at (created_at),
				KEY source_status (source_id,status)
			) $c;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}pnr_placements (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				term_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				placed_at int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY term_time (term_id,placed_at),
				KEY post_id (post_id)
			) $c;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}pnr_redirects (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				path_hash char(32) NOT NULL,
				path text NOT NULL,
				target_type varchar(20) NOT NULL DEFAULT 'category',
				target_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at int(10) unsigned NOT NULL DEFAULT 0,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				last_hit int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY path_hash (path_hash),
				KEY created_at (created_at)
			) $c;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}pnr_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at int(10) unsigned NOT NULL DEFAULT 0,
				level varchar(10) NOT NULL DEFAULT 'info',
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				message text NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY source_id (source_id)
			) $c;"
		);

		// Read on every request by maybe_upgrade(), so it must be autoloaded (no extra query per page view).
		update_option( 'pnr_db_version', PNR_DB_VERSION, true );
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( 'pnr_db_version', true ); // Older installs created it without autoload.
		}
	}

	/**
	 * Administrators always manage the robot; extra roles can be granted in the settings.
	 */
	public static function grant_capability() {
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}
	}

	public static function sync_role_capabilities( array $roles ) {
		foreach ( wp_roles()->role_objects as $name => $role ) {
			if ( 'administrator' === $name ) {
				continue;
			}
			if ( in_array( $name, $roles, true ) ) {
				$role->add_cap( self::CAP );
			} elseif ( $role->has_cap( self::CAP ) ) {
				$role->remove_cap( self::CAP );
			}
		}
	}

	public static function tables() {
		global $wpdb;
		return array(
			$wpdb->prefix . 'pnr_items',
			$wpdb->prefix . 'pnr_seen',
			$wpdb->prefix . 'pnr_placements',
			$wpdb->prefix . 'pnr_redirects',
			$wpdb->prefix . 'pnr_log',
		);
	}
}
