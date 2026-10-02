<?php
/**
 * Plugin Name:       ربات خبر پارسی
 * Plugin URI:        https://github.com/aminsadidi/blog-scraper
 * Description:       دریافت خودکار خبر از RSS سایت‌های دیگر با متن کامل، تصویر و ویدئو؛ دسته‌بندی بر اساس نقش دسته‌های سایت (دسته اصلی، بخش‌های صفحه اصلی با سهمیه، عکس و ویدئو)؛ پنهان از گوگل و سایت‌مپ (سازگار با رنک مث)؛ حذف خودکار با ریدایرکت.
 * Version:           1.1.2
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       parsi-news-robot
 * Domain Path:       /languages
 *
 * @package ParsiNewsRobot
 */

defined( 'ABSPATH' ) || exit;

define( 'PNR_VERSION', '1.1.2' );
define( 'PNR_DB_VERSION', 3 );
define( 'PNR_FILE', __FILE__ );
define( 'PNR_DIR', plugin_dir_path( __FILE__ ) );
define( 'PNR_URL', plugin_dir_url( __FILE__ ) );

// Action Scheduler must be loaded while plugins are being included; it picks the newest bundled copy on the site.
require_once PNR_DIR . 'lib/action-scheduler/action-scheduler.php';

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'ParsiNewsRobot\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = PNR_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'ParsiNewsRobot\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ParsiNewsRobot\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'ParsiNewsRobot\\Plugin', 'boot' ) );
