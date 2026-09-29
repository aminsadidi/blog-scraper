<?php
/**
 * Admin controller: menu, assets, notices and form/ajax handlers.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Admin;

use ParsiNewsRobot\Cleanup;
use ParsiNewsRobot\Data\Log;
use ParsiNewsRobot\Import\FeedReader;
use ParsiNewsRobot\Import\Fetcher;
use ParsiNewsRobot\Installer;
use ParsiNewsRobot\Queue;
use ParsiNewsRobot\Sources;

defined( 'ABSPATH' ) || exit;

class Admin {

	const SLUG = 'pnr-dashboard';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'order_menu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PNR_FILE ), array( __CLASS__, 'plugin_links' ) );

		add_action( 'admin_post_pnr_save_settings', array( SettingsPage::class, 'save' ) );
		add_action( 'admin_post_pnr_save_categories', array( CategoriesPage::class, 'save' ) );
		add_action( 'admin_post_pnr_save_rules', array( RulesPage::class, 'save' ) );
		add_action( 'admin_post_pnr_run_all', array( __CLASS__, 'run_all' ) );
		add_action( 'admin_post_pnr_run_source', array( __CLASS__, 'run_source' ) );
		add_action( 'admin_post_pnr_purge_source', array( __CLASS__, 'purge_source' ) );
		add_action( 'admin_post_pnr_clear_log', array( __CLASS__, 'clear_log' ) );
		add_action( 'admin_post_pnr_new_cron_key', array( __CLASS__, 'new_cron_key' ) );

		add_action( 'wp_ajax_pnr_test_source', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_pnr_discover', array( __CLASS__, 'ajax_discover' ) );

		SourceEditor::init();
		PostIntegration::init();
	}

	public static function menu() {
		$cap = Installer::CAP;
		add_menu_page( 'ربات خبر', 'ربات خبر', $cap, self::SLUG, array( Dashboard::class, 'render' ), 'dashicons-rss', 26 );
		add_submenu_page( self::SLUG, 'پیشخوان ربات خبر', 'پیشخوان', $cap, self::SLUG, array( Dashboard::class, 'render' ) );
		add_submenu_page( self::SLUG, 'افزودن منبع خبر', 'افزودن منبع', $cap, 'post-new.php?post_type=' . Sources::TYPE );
		add_submenu_page( self::SLUG, 'دسته‌ها و بخش‌ها', 'دسته‌ها و بخش‌ها', $cap, 'pnr-categories', array( CategoriesPage::class, 'render' ) );
		add_submenu_page( self::SLUG, 'قوانین کلمه کلیدی', 'قوانین', $cap, 'pnr-rules', array( RulesPage::class, 'render' ) );
		add_submenu_page( self::SLUG, 'تنظیمات ربات خبر', 'تنظیمات', $cap, 'pnr-settings', array( SettingsPage::class, 'render' ) );
		add_submenu_page( self::SLUG, 'گزارش‌ها', 'گزارش‌ها', $cap, 'pnr-logs', array( LogsPage::class, 'render' ) );
	}

	/**
	 * Puts the sources list (added by WordPress for the post type) right after the dashboard.
	 */
	public static function order_menu() {
		global $submenu;
		if ( empty( $submenu[ self::SLUG ] ) ) {
			return;
		}
		$list  = 'edit.php?post_type=' . Sources::TYPE;
		$items = $submenu[ self::SLUG ];
		$found = null;
		foreach ( $items as $i => $item ) {
			if ( $list === $item[2] ) {
				$found = $item;
				unset( $items[ $i ] );
			}
		}
		if ( $found ) {
			$items = array_values( $items );
			array_splice( $items, 1, 0, array( $found ) );
			$submenu[ self::SLUG ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	public static function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return false !== strpos( $screen->id, 'pnr-' ) || false !== strpos( $screen->id, self::SLUG ) || Sources::TYPE === $screen->post_type;
	}

	public static function assets() {
		$screen = get_current_screen();
		if ( ! self::is_plugin_screen() && ! ( $screen && 'post' === $screen->base ) ) {
			return;
		}
		wp_enqueue_style( 'pnr-admin', PNR_URL . 'assets/admin.css', array(), PNR_VERSION );
		if ( self::is_plugin_screen() ) {
			wp_enqueue_script( 'pnr-admin', PNR_URL . 'assets/admin.js', array( 'jquery' ), PNR_VERSION, true );
			wp_localize_script(
				'pnr-admin',
				'pnrAdmin',
				array(
					'ajax'    => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'pnr_ajax' ),
					'testing' => 'در حال دریافت و بررسی خبرها… (ممکن است تا یک دقیقه طول بکشد)',
					'finding' => 'در حال جستجوی فید…',
					'error'   => 'خطا در ارتباط با سرور.',
					'confirm' => 'مطمئن هستید؟',
					'copied'  => 'کپی شد',
				)
			);
		}
	}

	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">پیشخوان</a>' );
		return $links;
	}

	/* ---------- Notices ---------- */

	public static function flash( $message, $type = 'success' ) {
		set_transient( 'pnr_flash_' . get_current_user_id(), array( $message, $type ), 60 );
	}

	public static function notices() {
		if ( ! current_user_can( Installer::CAP ) ) {
			return;
		}
		$flash = get_transient( 'pnr_flash_' . get_current_user_id() );
		if ( $flash ) {
			delete_transient( 'pnr_flash_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $flash[1] ), wp_kses_post( $flash[0] ) );
		}
		if ( get_transient( 'pnr_welcome' ) && ! self::is_plugin_screen() ) {
			delete_transient( 'pnr_welcome' );
			printf(
				'<div class="notice notice-info is-dismissible"><p><strong>ربات خبر فعال شد.</strong> برای شروع، اول به دسته‌های سایت نقش بدهید و بعد منبع خبر اضافه کنید. <a href="%s">رفتن به پیشخوان ربات خبر</a></p></div>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG ) )
			);
		}
	}

	/* ---------- Actions ---------- */

	private static function guard( $action ) {
		if ( ! current_user_can( Installer::CAP ) ) {
			wp_die( 'دسترسی ندارید.', 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $fallback ) {
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ? $ref : $fallback );
		exit;
	}

	public static function run_all() {
		self::guard( 'pnr_run_all' );
		self::extend();
		Queue::tick( true );
		$done = Queue::run_pending( 20 ); // The rest continues in the background.
		self::flash( sprintf( 'همه منابع بررسی شد (%s کار انجام شد). جزئیات در گزارش‌ها.', number_format_i18n( $done ) ) );
		self::back( admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function run_source() {
		$id = isset( $_GET['source'] ) ? absint( $_GET['source'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		self::guard( 'pnr_run_source_' . $id );
		self::extend();
		$result = Fetcher::run( $id );
		$done   = Queue::run_pending( 100 );
		if ( $result['error'] ) {
			self::flash( 'خطا: ' . esc_html( $result['error'] ), 'error' );
		} else {
			self::flash( sprintf( '%1$s خبر جدید در صف قرار گرفت، %2$s رد شد و %3$s کار انجام شد.', number_format_i18n( $result['queued'] ), number_format_i18n( $result['skipped'] ), number_format_i18n( $done ) ) );
		}
		self::back( admin_url( 'edit.php?post_type=' . Sources::TYPE ) );
	}

	public static function purge_source() {
		$id = isset( $_GET['source'] ) ? absint( $_GET['source'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		self::guard( 'pnr_purge_source_' . $id );
		self::extend();
		$count = Cleanup::purge_source( $id );
		self::flash( sprintf( '%s خبر این منبع حذف و ریدایرکت شد.', number_format_i18n( $count ) ) );
		self::back( admin_url( 'edit.php?post_type=' . Sources::TYPE ) );
	}

	public static function clear_log() {
		self::guard( 'pnr_clear_log' );
		Log::clear();
		self::flash( 'گزارش‌ها پاک شد.' );
		self::back( admin_url( 'admin.php?page=pnr-logs' ) );
	}

	public static function new_cron_key() {
		self::guard( 'pnr_new_cron_key' );
		delete_option( 'pnr_cron_key' );
		\ParsiNewsRobot\Settings::cron_key();
		self::flash( 'کلید جدید ساخته شد. اگر کران سرور تنظیم کرده‌اید، آدرس آن را به‌روز کنید.' );
		self::back( admin_url( 'admin.php?page=pnr-settings' ) );
	}

	/* ---------- Ajax ---------- */

	public static function ajax_test() {
		check_ajax_referer( 'pnr_ajax', 'nonce' );
		if ( ! current_user_can( Installer::CAP ) ) {
			wp_send_json_error( 'دسترسی ندارید.' );
		}
		self::extend();
		$data = array();
		parse_str( isset( $_POST['form'] ) ? wp_unslash( $_POST['form'] ) : '', $data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$cfg    = SourceEditor::sanitize( isset( $data['pnr'] ) && is_array( $data['pnr'] ) ? $data['pnr'] : array() );
		$report = Preview::run( $cfg, 3 );
		if ( is_wp_error( $report ) ) {
			wp_send_json_error( esc_html( $report->get_error_message() ) );
		}
		ob_start();
		SourceEditor::render_preview( $report );
		wp_send_json_success( ob_get_clean() );
	}

	public static function ajax_discover() {
		check_ajax_referer( 'pnr_ajax', 'nonce' );
		if ( ! current_user_can( Installer::CAP ) ) {
			wp_send_json_error( 'دسترسی ندارید.' );
		}
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( ! $url ) {
			wp_send_json_error( 'آدرس سایت را وارد کنید.' );
		}
		$found = FeedReader::discover( $url );
		if ( ! $found ) {
			wp_send_json_error( 'فیدی پیدا نشد. آدرس RSS را از خود سایت منبع پیدا کنید (معمولاً در پایین صفحه با نماد RSS).' );
		}
		wp_send_json_success( $found );
	}

	private static function extend() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/* ---------- Shared page chrome ---------- */

	public static function header( $current, $title ) {
		echo '<div class="wrap pnr-wrap"><h1>' . esc_html( $title ) . '</h1>';
		self::tabs( $current );
	}

	public static function tabs( $current ) {
		$tabs = array(
			self::SLUG                               => 'پیشخوان',
			'edit.php?post_type=' . Sources::TYPE    => 'منابع خبر',
			'pnr-categories'                         => 'دسته‌ها و بخش‌ها',
			'pnr-rules'                              => 'قوانین',
			'pnr-settings'                           => 'تنظیمات',
			'pnr-logs'                               => 'گزارش‌ها',
		);
		echo '<nav class="nav-tab-wrapper pnr-tabs">';
		foreach ( $tabs as $slug => $label ) {
			$url = 0 === strpos( $slug, 'edit.php' ) ? admin_url( $slug ) : admin_url( 'admin.php?page=' . $slug );
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( $url ), $slug === $current ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
	}

	public static function footer() {
		echo '</div>';
	}

	/**
	 * "۵ دقیقه پیش" / "۱۰ دقیقه دیگر".
	 */
	public static function when( $timestamp ) {
		if ( ! $timestamp ) {
			return '—';
		}
		$diff = human_time_diff( $timestamp, time() );
		return $timestamp <= time() ? $diff . ' پیش' : $diff . ' دیگر';
	}
}
