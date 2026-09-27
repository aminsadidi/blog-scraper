<?php
/**
 * Background jobs on Action Scheduler: a one-minute tick picks the sources that are due, each source
 * fetch is its own job, and each news item is its own job (so one slow page never blocks the rest).
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot;

use ParsiNewsRobot\Data\Log;
use ParsiNewsRobot\Import\Fetcher;
use ParsiNewsRobot\Import\Importer;
use ParsiNewsRobot\Seo\Seo;

defined( 'ABSPATH' ) || exit;

class Queue {

	const GROUP   = 'parsi-news-robot';
	const TICK    = 'pnr_tick';
	const FETCH   = 'pnr_fetch_source';
	const IMPORT  = 'pnr_import_item';
	const UPDATE  = 'pnr_update_item';
	const CLEANUP = 'pnr_cleanup';
	const SYNC    = 'pnr_sync_flags';
	const HOOKS   = array( self::TICK, self::FETCH, self::IMPORT, self::UPDATE, self::CLEANUP, self::SYNC );

	public static function init() {
		add_action( self::TICK, array( __CLASS__, 'tick' ) );
		add_action( self::FETCH, array( Fetcher::class, 'run' ) );
		add_action( self::IMPORT, array( Importer::class, 'import' ) );
		add_action( self::UPDATE, array( Importer::class, 'update' ) );
		add_action( self::CLEANUP, array( Cleanup::class, 'run' ) );
		add_action( self::SYNC, array( Seo::class, 'sync_all' ) );

		add_action( 'init', array( __CLASS__, 'ensure_schedules' ), 20 );
		add_action( 'init', array( __CLASS__, 'external_cron' ), 5 );
	}

	public static function available() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Makes sure the recurring jobs exist (checked at most every 10 minutes).
	 */
	public static function ensure_schedules( $force = false ) {
		if ( ! self::available() || ( ! $force && get_transient( 'pnr_schedule_checked' ) ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::TICK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 30, MINUTE_IN_SECONDS, self::TICK, array(), self::GROUP, true );
		}
		if ( ! as_has_scheduled_action( self::CLEANUP, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 300, HOUR_IN_SECONDS, self::CLEANUP, array(), self::GROUP, true );
		}
		set_transient( 'pnr_schedule_checked', 1, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Queues a fetch for every source that is due and inside its working hours.
	 */
	public static function tick( $force = false ) {
		$now = time();
		update_option( 'pnr_last_tick', $now, false );

		foreach ( Sources::active_ids() as $id ) {
			$cfg   = Sources::config( $id );
			$state = Sources::state( $id );
			if ( ! $force && ( (int) $state['next_run'] > $now || ! Sources::in_active_hours( $cfg ) ) ) {
				continue;
			}
			self::fetch_source( $id );
			// Tentative next run; the fetch job replaces it with the real (adaptive) value.
			Sources::set_state( $id, array( 'next_run' => $now + max( 1, (int) $cfg['interval'] ) * MINUTE_IN_SECONDS ) );
		}
	}

	public static function fetch_source( $source_id ) {
		if ( ! self::available() ) {
			Fetcher::run( $source_id );
			return;
		}
		if ( ! as_has_scheduled_action( self::FETCH, array( (int) $source_id ), self::GROUP ) ) {
			as_enqueue_async_action( self::FETCH, array( (int) $source_id ), self::GROUP );
		}
	}

	public static function schedule_import( $seen_id, $timestamp ) {
		if ( ! self::available() ) {
			Importer::import( $seen_id );
			return;
		}
		if ( $timestamp <= time() ) {
			as_enqueue_async_action( self::IMPORT, array( (int) $seen_id ), self::GROUP );
		} else {
			as_schedule_single_action( $timestamp, self::IMPORT, array( (int) $seen_id ), self::GROUP );
		}
	}

	public static function schedule_update( $seen_id ) {
		if ( ! self::available() ) {
			Importer::update( $seen_id );
			return;
		}
		if ( ! as_has_scheduled_action( self::UPDATE, array( (int) $seen_id ), self::GROUP ) ) {
			as_enqueue_async_action( self::UPDATE, array( (int) $seen_id ), self::GROUP );
		}
	}

	public static function schedule_sync() {
		if ( ! self::available() ) {
			Seo::sync_all();
			return;
		}
		if ( ! as_has_scheduled_action( self::SYNC, array(), self::GROUP ) ) {
			as_schedule_single_action( time() + 5, self::SYNC, array(), self::GROUP );
		}
	}

	/**
	 * Runs due jobs right now (used by "run now" buttons, WP-CLI and the cron URL).
	 */
	public static function run_pending( $seconds = 50 ) {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return 0;
		}
		$store   = \ActionScheduler::store();
		$runner  = \ActionScheduler::runner();
		$started = time();
		$done    = 0;
		do {
			$ids = $store->query_actions(
				array(
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'date'     => as_get_datetime_object(),
					'per_page' => 10,
					'orderby'  => 'date',
					'order'    => 'ASC',
				)
			);
			foreach ( $ids as $action_id ) {
				$runner->process_action( $action_id, 'parsi-news-robot' );
				$done++;
				if ( time() - $started > $seconds ) {
					break 2;
				}
			}
		} while ( $ids );
		return $done;
	}

	/**
	 * Secret URL for a real server cron: https://example.com/?pnr_cron=KEY
	 */
	public static function external_cron() {
		if ( empty( $_GET['pnr_cron'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$key = sanitize_text_field( wp_unslash( $_GET['pnr_cron'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! hash_equals( Settings::cron_key(), $key ) ) {
			status_header( 403 );
			exit( 'forbidden' );
		}
		nocache_headers();
		if ( get_transient( 'pnr_cron_lock' ) ) {
			exit( 'busy' );
		}
		set_transient( 'pnr_cron_lock', 1, 45 );
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		self::ensure_schedules( true );
		self::tick();
		$done = self::run_pending( 50 );
		delete_transient( 'pnr_cron_lock' );
		update_option( 'pnr_last_external_cron', time(), false );
		exit( 'ok ' . (int) $done );
	}

	public static function cron_url() {
		return add_query_arg( 'pnr_cron', Settings::cron_key(), home_url( '/' ) );
	}

	public static function log_unavailable() {
		Log::error( 'کتابخانه Action Scheduler بارگذاری نشده است.' );
	}
}
