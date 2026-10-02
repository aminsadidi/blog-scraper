<?php
/**
 * Site preset: a "preset.json" shipped next to the plugin file is applied once, right after activation
 * (category roles matched by name, global settings, sources). Used to deliver a plugin that is already
 * configured for one site and starts working as soon as it is activated.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot;

use ParsiNewsRobot\Data\Log;
use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Preset {

	const OPTION = 'pnr_preset_applied';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_apply' ), 99 );
	}

	public static function file() {
		return dirname( PNR_FILE ) . '/preset.json';
	}

	/**
	 * Applies the bundled preset once per preset version.
	 */
	public static function maybe_apply() {
		$file = self::file();
		if ( ! is_readable( $file ) ) {
			return;
		}
		$preset = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( ! is_array( $preset ) ) {
			return;
		}
		$key = md5( wp_json_encode( $preset ) );
		if ( get_option( self::OPTION ) === $key || get_transient( 'pnr_preset_lock' ) ) {
			return;
		}
		set_transient( 'pnr_preset_lock', 1, 5 * MINUTE_IN_SECONDS );
		// Roles and settings only the first time: later preset versions must not undo the owner's changes.
		$report = self::apply( $preset, false === get_option( self::OPTION, false ) );
		update_option( self::OPTION, $key, true );
		update_option( 'pnr_preset_report', $report, false );
		delete_transient( 'pnr_preset_lock' );
		Log::info( 'پیکربندی آماده «' . ( isset( $preset['name'] ) ? $preset['name'] : '' ) . '» اعمال شد: ' . implode( ' | ', $report ) );
	}

	/**
	 * @return string[] Human-readable report lines.
	 */
	public static function apply( array $preset, $full = true ) {
		$report = array();
		if ( $full ) {
			$report = self::apply_settings( $preset );
		}
		return array_merge( $report, self::apply_sources( $preset ) );
	}

	/**
	 * Category roles (matched by category name) and global settings.
	 *
	 * @return string[]
	 */
	private static function apply_settings( array $preset ) {
		$report = array();

		// Category roles.
		$roles   = (array) Settings::get( 'roles' );
		$missing = array();
		$set     = 0;
		foreach ( isset( $preset['roles'] ) ? (array) $preset['roles'] : array() as $row ) {
			$names   = array_merge( array( $row['category'] ), isset( $row['alt'] ) ? (array) $row['alt'] : array() );
			$term_id = self::find_category( $names );
			if ( ! $term_id ) {
				$missing[] = $row['category'];
				continue;
			}
			$roles[ $term_id ] = array_merge(
				Settings::role_defaults(),
				isset( $roles[ $term_id ] ) ? (array) $roles[ $term_id ] : array(),
				array_intersect_key( $row, Settings::role_defaults() )
			);
			$set++;
		}
		$settings          = isset( $preset['settings'] ) ? array_intersect_key( (array) $preset['settings'], Settings::defaults() ) : array();
		$settings['roles'] = $roles;
		Settings::update( $settings );
		$report[] = sprintf( 'نقش %d دسته تنظیم شد', $set );
		if ( $missing ) {
			$report[] = 'این دسته‌ها در سایت پیدا نشدند: ' . implode( '، ', $missing );
		}

		return $report;
	}

	/**
	 * Adds the preset's sources (an address that already exists is left as it is) and moves the ones an
	 * earlier preset version added but this one drops to the trash (their published posts stay).
	 *
	 * @return string[]
	 */
	private static function apply_sources( array $preset ) {
		$report   = array();
		$existing = array();
		foreach ( Sources::all_ids() as $id ) {
			$existing[ Sources::config( $id )['url'] ] = $id;
		}
		$added = 0;
		foreach ( isset( $preset['sources'] ) ? (array) $preset['sources'] : array() as $row ) {
			$cfg = array_intersect_key( (array) $row['config'], Settings::source_defaults() );
			if ( empty( $cfg['url'] ) || isset( $existing[ $cfg['url'] ] ) ) {
				continue;
			}
			foreach ( array( 'main_category' ) as $key ) {
				if ( isset( $cfg[ $key ] ) && ! is_numeric( $cfg[ $key ] ) ) {
					$cfg[ $key ] = self::find_category( array( $cfg[ $key ] ) );
				}
			}
			foreach ( array( 'extra_categories', 'random_sections' ) as $key ) {
				if ( isset( $cfg[ $key ] ) ) {
					$cfg[ $key ] = array_values( array_filter( array_map( array( __CLASS__, 'category_id' ), (array) $cfg[ $key ] ) ) );
				}
			}
			$id = wp_insert_post(
				array(
					'post_type'   => Sources::TYPE,
					'post_status' => 'publish',
					'post_title'  => isset( $row['title'] ) ? $row['title'] : $cfg['url'],
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			Sources::save_config( $id, array_merge( Settings::source_defaults(), $cfg ) );
			// Spread the first reads (half a minute apart) so a shared host never gets them all at once.
			Sources::set_state( $id, array( 'next_run' => time() + $added * 30 ) );
			$existing[ $cfg['url'] ] = $id;
			$added++;
		}
		$report[] = sprintf( '%d منبع اضافه شد', $added );

		$removed = 0;
		foreach ( isset( $preset['remove_sources'] ) ? (array) $preset['remove_sources'] : array() as $url ) {
			if ( isset( $existing[ $url ] ) && wp_trash_post( $existing[ $url ] ) ) {
				$removed++;
			}
		}
		if ( $removed ) {
			$report[] = sprintf( '%d منبع بی‌نتیجه حذف شد', $removed );
		}
		return $report;
	}

	private static function category_id( $value ) {
		return is_numeric( $value ) ? (int) $value : self::find_category( array( $value ) );
	}

	/**
	 * A site category by name, ignoring Arabic/Persian letter forms, half-spaces and spaces.
	 */
	public static function find_category( array $names ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return 0;
		}
		$key = function ( $name ) {
			return str_replace( ' ', '', Util::normalize_fa( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) ) );
		};
		foreach ( $names as $name ) {
			foreach ( $terms as $term ) {
				if ( $key( $term->name ) === $key( $name ) ) {
					return (int) $term->term_id;
				}
			}
		}
		return 0;
	}
}
