<?php
/**
 * Dashboard: numbers, health checks, setup steps, sources overview and the latest log.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Admin;

use ParsiNewsRobot\Data\Items;
use ParsiNewsRobot\Data\Log;
use ParsiNewsRobot\Data\Redirects;
use ParsiNewsRobot\Data\Seen;
use ParsiNewsRobot\Queue;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Sources;

defined( 'ABSPATH' ) || exit;

class Dashboard {

	public static function render() {
		$sources = Sources::all_ids();
		$active  = Sources::active_ids();
		$today   = strtotime( 'today', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

		Admin::header( Admin::SLUG, 'ربات خبر' );
		?>
		<div class="pnr-cards">
			<?php
			self::card( 'منابع فعال', number_format_i18n( count( $active ) ) . ' / ' . number_format_i18n( count( $sources ) ) );
			self::card( 'خبرهای امروز', number_format_i18n( Items::count( 0, $today ) ) );
			self::card( '۲۴ ساعت اخیر', number_format_i18n( Items::count( 0, time() - DAY_IN_SECONDS ) ) );
			self::card( 'در صف انتشار', number_format_i18n( Seen::queued_count() ) );
			self::card( 'ریدایرکت‌ها', number_format_i18n( Redirects::count() ) );
			self::card( 'آخرین اجرای زمان‌بند', Admin::when( (int) get_option( 'pnr_last_tick', 0 ) ) );
			?>
		</div>

		<div class="pnr-grid">
			<div class="pnr-box">
				<h2>مراحل راه‌اندازی</h2>
				<?php self::steps(); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pnr_run_all">
					<?php wp_nonce_field( 'pnr_run_all' ); ?>
					<p><button class="button button-primary">همین حالا همه منابع را بررسی کن</button></p>
				</form>
			</div>
			<div class="pnr-box">
				<h2>بررسی سلامت</h2>
				<?php self::health(); ?>
			</div>
		</div>

		<div class="pnr-box">
			<h2>منابع</h2>
			<?php self::sources_table( $sources ); ?>
		</div>

		<div class="pnr-box">
			<h2>آخرین رویدادها <a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=pnr-logs' ) ); ?>">همه گزارش‌ها</a></h2>
			<?php LogsPage::table( Log::query( array( 'limit' => 12 ) ) ); ?>
		</div>
		<?php
		Admin::footer();
	}

	private static function card( $label, $value ) {
		printf( '<div class="pnr-card"><span>%s</span><strong>%s</strong></div>', esc_html( $label ), esc_html( $value ) );
	}

	private static function steps() {
		$roles     = Settings::roles();
		$has_roles = (bool) $roles;
		$sources   = Sources::all_ids();
		$has_main  = false;
		foreach ( $sources as $id ) {
			$has_main = $has_main || (int) Sources::config( $id )['main_category'] > 0;
		}
		$cron_ok = (int) get_option( 'pnr_last_external_cron', 0 ) > time() - HOUR_IN_SECONDS;

		$steps = array(
			array( $has_roles, 'به دسته‌های سایت نقش بدهید (موضوعی، بخش صفحه اصلی، ویدئو، عکس).', admin_url( 'admin.php?page=pnr-categories' ) ),
			array( (bool) $sources, 'یک منبع خبر (آدرس RSS) اضافه کنید.', admin_url( 'post-new.php?post_type=' . Sources::TYPE ) ),
			array( $has_main, 'برای هر منبع «دسته اصلی» را انتخاب کنید و با دکمه «تست منبع» خروجی را ببینید.', admin_url( 'edit.php?post_type=' . Sources::TYPE ) ),
			array( true, 'تنظیمات ایندکس، «به نقل از»، تصاویر و حذف خودکار را مرور کنید.', admin_url( 'admin.php?page=pnr-settings' ) ),
			array( $cron_ok, 'اختیاری ولی توصیه‌شده: کران واقعی سرور را تنظیم کنید (آدرس در تنظیمات ← پیشرفته).', admin_url( 'admin.php?page=pnr-settings#pnr-advanced' ) ),
		);
		echo '<ol class="pnr-steps">';
		foreach ( $steps as $step ) {
			printf( '<li class="%s"><a href="%s">%s</a></li>', $step[0] ? 'done' : 'todo', esc_url( $step[2] ), esc_html( $step[1] ) );
		}
		echo '</ol>';
	}

	/**
	 * @return array[] [status (ok|warn|error), message]
	 */
	public static function checks() {
		$checks = array();

		$checks[] = Queue::available()
			? array( 'ok', 'صف پس‌زمینه (Action Scheduler) فعال است.' )
			: array( 'error', 'کتابخانه Action Scheduler بارگذاری نشد؛ دریافت خودکار کار نمی‌کند.' );

		$tick = (int) get_option( 'pnr_last_tick', 0 );
		if ( ! $tick ) {
			$checks[] = array( 'warn', 'زمان‌بند هنوز اجرا نشده است. چند دقیقه صبر کنید یا دکمه «بررسی همه منابع» را بزنید.' );
		} elseif ( $tick < time() - 10 * MINUTE_IN_SECONDS ) {
			$checks[] = array( 'warn', 'زمان‌بند بیش از ۱۰ دقیقه است اجرا نشده. در سایت‌های کم‌بازدید کران واقعی سرور را تنظیم کنید.' );
		} else {
			$checks[] = array( 'ok', 'زمان‌بند مرتب اجرا می‌شود.' );
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && (int) get_option( 'pnr_last_external_cron', 0 ) < time() - HOUR_IN_SECONDS ) {
			$checks[] = array( 'warn', 'WP-Cron غیرفعال است؛ مطمئن شوید کران سرور تنظیم شده است.' );
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$checks[] = array( 'ok', 'رنک مث شناسایی شد؛ noindex و حذف از سایت‌مپ رنک مث فعال است.' );
		} elseif ( defined( 'WPSEO_VERSION' ) ) {
			$checks[] = array( 'ok', 'Yoast SEO شناسایی شد؛ noindex و حذف از سایت‌مپ Yoast فعال است.' );
		} else {
			$checks[] = array( 'ok', 'افزونه سئو شناسایی نشد؛ noindex با ابزار خود وردپرس اعمال می‌شود.' );
		}
		$checks[] = Settings::get( 'noindex' )
			? array( 'ok', 'خبرهای ربات از دید گوگل پنهان هستند (noindex و بیرون از سایت‌مپ).' )
			: array( 'warn', 'خبرهای ربات قابل ایندکس هستند. اگر نمی‌خواهید، در تنظیمات تغییرش دهید.' );

		$topic = Settings::terms_with_role( 'topic' );
		$sect  = Settings::terms_with_role( 'section' );
		if ( ! Settings::roles() ) {
			$checks[] = array( 'warn', 'هنوز به هیچ دسته‌ای نقش داده نشده است.' );
		} else {
			$checks[] = array( 'ok', sprintf( '%1$s دسته موضوعی، %2$s بخش صفحه اصلی، ویدئو: %3$s، عکس: %4$s', number_format_i18n( count( $topic ) ), number_format_i18n( count( $sect ) ), Settings::terms_with_role( 'video' ) ? 'تعیین شده' : 'تعیین نشده', Settings::terms_with_role( 'photo' ) ? 'تعیین شده' : 'تعیین نشده' ) );
		}

		foreach ( Sources::all_ids() as $id ) {
			$cfg   = Sources::config( $id );
			$state = Sources::state( $id );
			if ( ! $cfg['main_category'] ) {
				$checks[] = array( 'warn', sprintf( 'منبع «%s» دسته اصلی ندارد.', Sources::name( $id, $cfg ) ) );
			}
			if ( (int) $state['error_runs'] >= 3 ) {
				$checks[] = array( 'error', sprintf( 'منبع «%1$s» %2$s بار پشت سر هم خطا داده: %3$s', Sources::name( $id, $cfg ), number_format_i18n( $state['error_runs'] ), $state['last_message'] ) );
			}
		}

		foreach ( array(
			'dom'      => 'DOM',
			'libxml'   => 'libxml',
			'mbstring' => 'mbstring',
		) as $ext => $label ) {
			if ( ! extension_loaded( $ext ) ) {
				$checks[] = array( 'error', sprintf( 'افزونه PHP «%s» روی سرور نصب نیست.', $label ) );
			}
		}
		if ( ! get_option( 'blog_public' ) ) {
			$checks[] = array( 'warn', 'گزینه «از موتورهای جستجو بخواهید سایت را ایندکس نکنند» در تنظیمات خواندن وردپرس روشن است (کل سایت).' );
		}
		return $checks;
	}

	private static function health() {
		$icons = array(
			'ok'    => 'yes-alt',
			'warn'  => 'warning',
			'error' => 'dismiss',
		);
		echo '<ul class="pnr-health">';
		foreach ( self::checks() as $check ) {
			printf( '<li class="%1$s"><span class="dashicons dashicons-%2$s"></span> %3$s</li>', esc_attr( $check[0] ), esc_attr( $icons[ $check[0] ] ), esc_html( $check[1] ) );
		}
		echo '</ul>';
	}

	private static function sources_table( array $ids ) {
		if ( ! $ids ) {
			printf( '<p>هنوز منبعی ندارید. <a class="button" href="%s">افزودن اولین منبع</a></p>', esc_url( admin_url( 'post-new.php?post_type=' . Sources::TYPE ) ) );
			return;
		}
		echo '<table class="widefat striped pnr-table"><thead><tr><th>منبع</th><th>وضعیت</th><th>آخرین بررسی</th><th>بررسی بعدی</th><th>کل خبرها</th><th>پیام</th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$cfg   = Sources::config( $id );
			$state = Sources::state( $id );
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_url( get_edit_post_link( $id ) ),
				esc_html( Sources::name( $id, $cfg ) ),
				self::status_badge( $cfg, $state, $id ), // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( Admin::when( (int) $state['last_run'] ) ),
				esc_html( $cfg['active'] ? Admin::when( (int) $state['next_run'] ) : '—' ),
				esc_html( number_format_i18n( (int) $state['total'] ) ),
				esc_html( $state['last_message'] )
			);
		}
		echo '</tbody></table>';
	}

	public static function status_badge( array $cfg, array $state, $id = 0 ) {
		if ( $id && 'publish' !== get_post_status( $id ) ) {
			return '<span class="pnr-badge off">پیش‌نویس</span>';
		}
		if ( ! $cfg['active'] ) {
			return '<span class="pnr-badge off">خاموش</span>';
		}
		if ( 'error' === $state['last_status'] ) {
			return '<span class="pnr-badge error">خطا</span>';
		}
		if ( ! Sources::in_active_hours( $cfg ) ) {
			return '<span class="pnr-badge off">خارج از ساعت کاری</span>';
		}
		return '<span class="pnr-badge ok">فعال</span>';
	}
}
