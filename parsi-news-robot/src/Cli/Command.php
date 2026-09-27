<?php
/**
 * WP-CLI commands: wp pnr <command>.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Cli;

use ParsiNewsRobot\Admin\Preview;
use ParsiNewsRobot\Cleanup;
use ParsiNewsRobot\Data\Log;
use ParsiNewsRobot\Import\Fetcher;
use ParsiNewsRobot\Queue;
use ParsiNewsRobot\Seo\Seo;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Parsi News Robot.
 */
class Command {

	/**
	 * Fetches sources and runs the queued imports.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<id>]
	 * : Only this source.
	 *
	 * [--all]
	 * : Ignore each source's schedule and working hours.
	 *
	 * ## EXAMPLES
	 *     wp pnr run --all
	 */
	public function run( $args, $assoc ) {
		if ( ! empty( $assoc['source'] ) ) {
			$result = Fetcher::run( (int) $assoc['source'] );
			\WP_CLI::log( sprintf( 'queued: %d, skipped: %d, updated: %d %s', $result['queued'], $result['skipped'], $result['updated'], $result['error'] ) );
		} else {
			Queue::tick( ! empty( $assoc['all'] ) );
		}
		$done = Queue::run_pending( 600 );
		\WP_CLI::success( sprintf( '%d jobs processed.', $done ) );
	}

	/**
	 * Lists sources with their state.
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array();
		foreach ( Sources::all_ids() as $id ) {
			$cfg    = Sources::config( $id );
			$state  = Sources::state( $id );
			$rows[] = array(
				'id'       => $id,
				'name'     => Sources::name( $id, $cfg ),
				'active'   => $cfg['active'] ? 'yes' : 'no',
				'url'      => $cfg['url'],
				'status'   => $state['last_status'],
				'message'  => $state['last_message'],
				'total'    => $state['total'],
				'next_run' => $state['next_run'] ? wp_date( 'Y-m-d H:i', $state['next_run'] ) : '-',
			);
		}
		\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'id', 'name', 'active', 'url', 'status', 'message', 'total', 'next_run' ) );
	}

	/**
	 * Adds a source.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Feed URL.
	 *
	 * --category=<term_id>
	 * : Main category.
	 *
	 * [--name=<name>]
	 * : Source name.
	 *
	 * [--set=<json>]
	 * : Extra config as JSON.
	 */
	public function add( $args, $assoc ) {
		$id = wp_insert_post(
			array(
				'post_type'   => Sources::TYPE,
				'post_status' => 'publish',
				'post_title'  => isset( $assoc['name'] ) ? $assoc['name'] : $args[0],
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			\WP_CLI::error( $id->get_error_message() );
		}
		$cfg                  = Settings::source_defaults();
		$cfg['url']           = esc_url_raw( $args[0] );
		$cfg['main_category'] = (int) $assoc['category'];
		if ( ! empty( $assoc['set'] ) ) {
			$extra = json_decode( $assoc['set'], true );
			if ( is_array( $extra ) ) {
				$cfg = array_merge( $cfg, $extra );
			}
		}
		Sources::save_config( $id, $cfg );
		\WP_CLI::success( 'Source ' . $id . ' added.' );
	}

	/**
	 * Shows how a feed would be imported (no post is created).
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Feed URL.
	 *
	 * [--items=<n>]
	 * : Number of entries.
	 * ---
	 * default: 2
	 * ---
	 *
	 * [--selector=<css>]
	 * : Content selector.
	 */
	public function test( $args, $assoc ) {
		$cfg                     = Settings::source_defaults();
		$cfg['url']              = $args[0];
		$cfg['content_selector'] = isset( $assoc['selector'] ) ? $assoc['selector'] : '';
		$report                  = Preview::run( $cfg, (int) $assoc['items'] );
		if ( is_wp_error( $report ) ) {
			\WP_CLI::error( $report->get_error_message() );
		}
		\WP_CLI::log( 'Feed: ' . $report['feed_title'] . ' (' . $report['count'] . ' items)' );
		foreach ( $report['items'] as $row ) {
			\WP_CLI::log( str_repeat( '-', 60 ) );
			\WP_CLI::log( 'Title:    ' . $row['title'] );
			if ( ! empty( $row['error'] ) ) {
				\WP_CLI::log( 'Error:    ' . $row['error'] );
				continue;
			}
			\WP_CLI::log( 'Method:   ' . $row['method'] . ' | words: ' . $row['words'] . ' | images: ' . $row['image_count'] . ' | video: ' . ( $row['has_video'] ? 'yes' : 'no' ) );
			\WP_CLI::log( 'Featured: ' . $row['featured'] );
			\WP_CLI::log( 'Cats:     ' . implode( ', ', $row['categories'] ) );
			\WP_CLI::log( 'Text:     ' . mb_substr( $row['text'], 0, 300 ) . '…' );
		}
	}

	/**
	 * Runs the cleanup job (auto delete, pruning).
	 */
	public function cleanup() {
		$deleted = Cleanup::run();
		\WP_CLI::success( sprintf( '%d posts deleted.', $deleted ) );
	}

	/**
	 * Recomputes the noindex flag of every robot post.
	 */
	public function sync() {
		delete_option( 'pnr_sync_cursor' );
		Seo::sync_all();
		\WP_CLI::success( 'Synced.' );
	}

	/**
	 * Prints the latest log entries.
	 *
	 * [--limit=<n>]
	 * : How many.
	 * ---
	 * default: 20
	 * ---
	 */
	public function log( $args, $assoc ) {
		foreach ( array_reverse( Log::query( array( 'limit' => (int) $assoc['limit'] ) ) ) as $row ) {
			\WP_CLI::log( sprintf( '%s [%s] #%d %s', wp_date( 'H:i:s', $row->created_at ), $row->level, $row->source_id, $row->message ) );
		}
	}
}
