<?php
/**
 * Wires the plugin together.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot;

use ParsiNewsRobot\Import\Publisher;
use ParsiNewsRobot\Media\Media;
use ParsiNewsRobot\Seo\Links;
use ParsiNewsRobot\Seo\Seo;

defined( 'ABSPATH' ) || exit;

class Plugin {

	public static function boot() {
		load_plugin_textdomain( 'parsi-news-robot', false, dirname( plugin_basename( PNR_FILE ) ) . '/languages' );

		Installer::maybe_upgrade();
		Sources::init();
		Queue::init();
		Publisher::init();
		Media::init();
		Seo::init();
		Links::init();
		Cleanup::init();
		Preset::init();
		add_action( 'save_post', array( Import\Similarity::class, 'on_save_post' ), 20, 2 );

		if ( is_admin() ) {
			Admin\Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pnr', Cli\Command::class );
		}
	}
}
