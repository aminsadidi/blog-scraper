<?php
/**
 * Global settings.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Admin;

use ParsiNewsRobot\Installer;
use ParsiNewsRobot\Queue;
use ParsiNewsRobot\Seo\Seo;
use ParsiNewsRobot\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	public static function render() {
		$s = Settings::get();
		Admin::header( 'pnr-settings', 'تنظیمات ربات خبر' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pnr-settings">
			<input type="hidden" name="action" value="pnr_save_settings">
			<?php wp_nonce_field( 'pnr_save_settings' ); ?>

			<div class="pnr-box">
				<h2>سئو و ایندکس</h2>
				<table class="form-table">
					<tr><th>ایندکس خبرهای ربات</th><td>
						<?php Form::checkbox( 's[noindex]', $s['noindex'], 'خبرهای ربات ایندکس نشوند و در سایت‌مپ نباشند (پیشنهادی)' ); ?>
						<p class="description">روی خبرها تگ <code>noindex, follow</code> و هدر <code>X-Robots-Tag</code> گذاشته می‌شود، از سایت‌مپ رنک مث / Yoast / وردپرس حذف می‌شوند و به IndexNow رنک مث فرستاده نمی‌شوند. فایل robots.txt دستکاری نمی‌شود (مسدود کردن در آن باعث می‌شود گوگل تگ noindex را نبیند). هر منبع و هر خبر می‌تواند استثنا داشته باشد.</p>
					</td></tr>
					<tr><th>فید سایت</th><td><?php Form::checkbox( 's[exclude_site_feed]', $s['exclude_site_feed'], 'خبرهای ربات در فید RSS سایت شما نیایند (تا دیگران آن‌ها را از شما کپی نکنند)' ); ?></td></tr>
					<tr><th>صفحه دسته‌های noindex</th><td>
						<?php Form::category_checklist( 's[noindex_categories]', (array) $s['noindex_categories'] ); ?>
						<p class="description">اختیاری. صفحه آرشیو این دسته‌ها هم ایندکس نشود (مثلاً دسته‌ای که فقط خبرهای ربات دارد).</p>
					</td></tr>
					<tr><th>لینک‌هایی که ربات ساخته</th><td>
						<?php
						Form::radios(
							's[links_mode]',
							$s['links_mode'],
							array(
								'nofollow' => array( 'nofollow', 'پیشنهادی.' ),
								'follow'   => array( 'follow', 'لینک‌ها عادی باشند.' ),
								'strip'    => array( 'حذف لینک', 'متن لینک بماند ولی لینک نباشد.' ),
							)
						);
						?>
						<p class="description">فقط لینک‌هایی که از متن منبع آمده‌اند و لینک «به نقل از» تغییر می‌کنند. لینک‌هایی که خودتان در خبرها اضافه کنید هرگز دستکاری نمی‌شوند. تغییر این گزینه فوراً روی همه خبرهای قبلی هم اعمال می‌شود.</p>
					</td></tr>
				</table>
			</div>

			<div class="pnr-box">
				<h2>به نقل از</h2>
				<table class="form-table">
					<tr><th>نمایش</th><td><?php Form::checkbox( 's[attr_enabled]', $s['attr_enabled'], 'زیر یا بالای هر خبر ربات، منبع آن نوشته شود' ); ?></td></tr>
					<tr><th>متن</th><td><?php Form::text( 's[attr_template]', $s['attr_template'] ); ?>
						<p class="description">برچسب‌ها: <code>{source}</code> نام منبع، <code>{title}</code> عنوان خبر، <code>{date}</code> تاریخ.</p></td></tr>
					<tr><th>جای قرارگیری</th><td>
						<?php
						Form::select(
							's[attr_position]',
							$s['attr_position'],
							array(
								'end'   => 'آخر متن',
								'start' => 'اول متن',
							)
						);
						?>
					</td></tr>
					<tr><th>لینک نام منبع به</th><td>
						<?php
						Form::select(
							's[attr_link]',
							$s['attr_link'],
							array(
								'article' => 'صفحه همان خبر در سایت منبع',
								'home'    => 'صفحه اصلی سایت منبع',
								'none'    => 'بدون لینک',
							)
						);
						?>
					</td></tr>
				</table>
			</div>

			<div class="pnr-box">
				<h2>تصاویر</h2>
				<table class="form-table">
					<tr><th>محل تصاویر</th><td>
						<?php
						Form::radios(
							's[image_mode]',
							$s['image_mode'],
							array(
								'hybrid'  => array( 'ترکیبی (پیشنهادی)', 'تصویر شاخص روی هاست شما (سایزهای کوچک برای صفحه اصلی ساخته می‌شود)، عکس‌های داخل متن از هاست منبع.' ),
								'local'   => array( 'همه روی هاست شما', 'پایدارترین و سریع‌ترین برای بازدیدکننده؛ فضای هاست مصرف می‌کند (با حذف خودکار، تصاویر هم پاک می‌شوند).' ),
								'hotlink' => array( 'همه از هاست منبع', 'فضای هاست مصرف نمی‌شود، ولی سایز کوچک ساخته نمی‌شود (صفحه اصلی سنگین‌تر) و اگر منبع عکس را پاک کند یا جلوی هات‌لینک را بگیرد، عکس خراب می‌شود.' ),
							)
						);
						?>
					</td></tr>
					<tr><th>حداکثر عکس دانلودی در هر خبر</th><td><?php Form::number( 's[max_images]', $s['max_images'], 0, 100 ); ?> <span class="description">(در حالت «همه روی هاست شما»)</span></td></tr>
				</table>
			</div>

			<div class="pnr-box">
				<h2>انتشار</h2>
				<table class="form-table">
					<tr><th>نویسنده پیش‌فرض</th><td>
						<?php
						wp_dropdown_users(
							array(
								'name'              => 's[author]',
								'selected'          => (int) $s['author'],
								'show_option_none'  => 'اولین مدیر سایت',
								'option_none_value' => 0,
								'capability'        => array( 'edit_posts' ),
							)
						);
						?>
					</td></tr>
					<tr><th>وضعیت انتشار</th><td>
						<?php
						Form::select(
							's[post_status]',
							$s['post_status'],
							array(
								'publish' => 'انتشار مستقیم',
								'pending' => 'در انتظار بررسی',
								'draft'   => 'پیش‌نویس',
							)
						);
						?>
					</td></tr>
					<tr><th>تاریخ خبر</th><td>
						<?php
						Form::select(
							's[date_mode]',
							$s['date_mode'],
							array(
								'source' => 'تاریخ انتشار در منبع',
								'import' => 'زمان دریافت',
							)
						);
						?>
					</td></tr>
					<tr><th>دیدگاه‌ها</th><td>
						<?php
						Form::select(
							's[comment_status]',
							$s['comment_status'],
							array(
								'default' => 'طبق تنظیمات وردپرس',
								'open'    => 'باز',
								'closed'  => 'بسته',
							)
						);
						?>
					</td></tr>
					<tr><th>انتشار تدریجی</th><td><?php Form::number( 's[drip_minutes]', $s['drip_minutes'], 0, 240 ); ?> دقیقه فاصله بین خبرهای هر منبع <span class="description">(۰ = همه با هم؛ هر منبع می‌تواند مقدار خودش را داشته باشد)</span></td></tr>
					<tr><th>خبر تکراری از چند منبع</th><td>
						<?php Form::checkbox( 's[dup_titles]', $s['dup_titles'], 'اگر خبری با عنوان مشابه در این بازه منتشر شده، دوباره منتشر نشود' ); ?>
						<p>شباهت عنوان حداقل <?php Form::number( 's[dup_threshold]', $s['dup_threshold'], 50, 100 ); ?>٪ در <?php Form::number( 's[dup_hours]', $s['dup_hours'], 1, 720 ); ?> ساعت اخیر</p>
					</td></tr>
				</table>
			</div>

			<div class="pnr-box">
				<h2>پاک‌سازی متن</h2>
				<table class="form-table">
					<tr><th>امضا و عبارت‌های حذفی</th><td>
						<?php Form::textarea( 's[signatures]', $s['signatures'], 8 ); ?>
						<p class="description">هر خط یک عبارت. پاراگراف‌های کوتاهی (کمتر از ۲۰۰ حرف) که یکی از این عبارت‌ها را دارند حذف می‌شوند؛ مثل «انتهای پیام»، «کد خبر» یا تبلیغ کانال منبع.</p></td></tr>
					<tr><th>دامنه‌های مجاز برای امبد</th><td>
						<?php Form::textarea( 's[iframe_hosts]', $s['iframe_hosts'], 3, array( 'dir' => 'ltr' ) ); ?>
						<p class="description">علاوه بر آپارات، یوتیوب، تلوبیون، نماشا، اینستاگرام، توییتر و… که از قبل مجازند. iframe های دیگر به دلایل امنیتی حذف می‌شوند.</p></td></tr>
				</table>
			</div>

			<div class="pnr-box">
				<h2>حذف خودکار</h2>
				<table class="form-table">
					<tr><th>حذف خبرها بعد از</th><td><?php Form::number( 's[delete_after_days]', $s['delete_after_days'], 0, 3650 ); ?> روز <span class="description">(۰ = هرگز؛ هر منبع می‌تواند مقدار خودش را داشته باشد)</span></td></tr>
					<tr><th>تصاویر</th><td><?php Form::checkbox( 's[delete_attachments]', $s['delete_attachments'], 'تصاویری که ربات دانلود کرده هم حذف شوند' ); ?></td></tr>
					<tr><th>خبرهای ویرایش‌شده</th><td><?php Form::checkbox( 's[protect_edited]', $s['protect_edited'], 'خبرهایی که دستی ویرایش کرده‌ام حذف نشوند' ); ?></td></tr>
					<tr><th>آدرس خبر حذف‌شده</th><td>
						<?php
						Form::select(
							's[redirect_mode]',
							$s['redirect_mode'],
							array(
								'category' => 'ریدایرکت ۳۰۱ به دسته اصلی خبر',
								'home'     => 'ریدایرکت ۳۰۱ به صفحه اصلی',
								'gone'     => 'کد ۴۱۰ (برای همیشه حذف شده)',
								'none'     => 'هیچ (خطای ۴۰۴)',
							)
						);
						?>
					</td></tr>
					<tr><th>نگهداری ریدایرکت‌ها</th><td><?php Form::number( 's[redirect_keep_days]', $s['redirect_keep_days'], 0, 3650 ); ?> روز <span class="description">(۰ = برای همیشه)</span></td></tr>
				</table>
			</div>

			<div class="pnr-box" id="pnr-advanced">
				<h2>پیشرفته</h2>
				<table class="form-table">
					<tr><th>فاصله هوشمند</th><td><?php Form::checkbox( 's[adaptive]', $s['adaptive'], 'منابعی که خبر جدید ندارند کمتر بررسی شوند (تا ۴ برابر)' ); ?></td></tr>
					<tr><th>User-Agent</th><td><?php Form::text( 's[user_agent]', $s['user_agent'], array( 'class' => 'large-text', 'dir' => 'ltr' ) ); ?></td></tr>
					<tr><th>مهلت درخواست</th><td><?php Form::number( 's[timeout]', $s['timeout'], 5, 120 ); ?> ثانیه</td></tr>
					<tr><th>نگهداری گزارش‌ها</th><td><?php Form::number( 's[log_days]', $s['log_days'], 1, 365 ); ?> روز</td></tr>
					<tr><th>دسترسی</th><td>
						<p>علاوه بر مدیر کل، این نقش‌ها هم بتوانند ربات را مدیریت کنند:</p>
						<?php
						foreach ( wp_roles()->get_names() as $role => $label ) {
							if ( 'administrator' === $role ) {
								continue;
							}
							printf(
								'<label class="pnr-inline-check"><input type="checkbox" name="s[manager_roles][]" value="%s"%s> %s</label> ',
								esc_attr( $role ),
								checked( in_array( $role, (array) $s['manager_roles'], true ), true, false ),
								esc_html( translate_user_role( $label ) )
							);
						}
						?>
					</td></tr>
					<tr><th>کران واقعی سرور</th><td>
						<p>در سایت‌های کم‌بازدید، زمان‌بند وردپرس فقط با بازدید اجرا می‌شود. برای دقت بیشتر این دستور را در کران‌جاب هاست (هر ۵ دقیقه) بگذارید:</p>
						<code class="pnr-code" dir="ltr">*/5 * * * * wget -q -O /dev/null "<?php echo esc_html( Queue::cron_url() ); ?>"</code>
						<p><button type="button" class="button pnr-copy" data-copy="<?php echo esc_attr( '*/5 * * * * wget -q -O /dev/null "' . Queue::cron_url() . '"' ); ?>">کپی</button>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pnr_new_cron_key' ), 'pnr_new_cron_key' ) ); ?>">ساخت کلید جدید</a></p>
						<p class="description">آخرین اجرا از این آدرس: <?php echo esc_html( Admin::when( (int) get_option( 'pnr_last_external_cron', 0 ) ) ); ?></p>
					</td></tr>
				</table>
			</div>

			<?php submit_button( 'ذخیره تنظیمات' ); ?>
		</form>
		<?php
		Admin::footer();
	}

	public static function save() {
		if ( ! current_user_can( Installer::CAP ) ) {
			wp_die( 'دسترسی ندارید.', 403 );
		}
		check_admin_referer( 'pnr_save_settings' );
		$in  = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$d   = Settings::defaults();
		$old = Settings::get();

		$roles = array();
		if ( current_user_can( 'manage_options' ) ) {
			foreach ( isset( $in['manager_roles'] ) ? (array) $in['manager_roles'] : array() as $role ) {
				$role = sanitize_key( $role );
				if ( 'administrator' !== $role && wp_roles()->is_role( $role ) ) {
					$roles[] = $role;
				}
			}
		} else {
			$roles = (array) $old['manager_roles'];
		}

		$new = array(
			'noindex'               => Form::bool( $in, 'noindex' ),
			'exclude_site_feed'     => Form::bool( $in, 'exclude_site_feed' ),
			'noindex_categories'    => Form::ids( $in, 'noindex_categories' ),
			'links_mode'            => Form::choice( $in, 'links_mode', array( 'nofollow', 'follow', 'strip' ), 'nofollow' ),
			'attr_enabled'          => Form::bool( $in, 'attr_enabled' ),
			'attr_template'         => Form::text_value( $in, 'attr_template' ) ? Form::text_value( $in, 'attr_template' ) : $d['attr_template'],
			'attr_position'         => Form::choice( $in, 'attr_position', array( 'start', 'end' ), 'end' ),
			'attr_link'             => Form::choice( $in, 'attr_link', array( 'article', 'home', 'none' ), 'article' ),
			'image_mode'            => Form::choice( $in, 'image_mode', array( 'hybrid', 'local', 'hotlink' ), 'hybrid' ),
			'max_images'            => Form::int( $in, 'max_images', 0, 100, $d['max_images'] ),
			'author'                => Form::int( $in, 'author', 0 ),
			'post_status'           => Form::choice( $in, 'post_status', array( 'publish', 'pending', 'draft' ), 'publish' ),
			'date_mode'             => Form::choice( $in, 'date_mode', array( 'source', 'import' ), 'source' ),
			'comment_status'        => Form::choice( $in, 'comment_status', array( 'default', 'open', 'closed' ), 'default' ),
			'drip_minutes'          => Form::int( $in, 'drip_minutes', 0, 240, 0 ),
			'dup_titles'            => Form::bool( $in, 'dup_titles' ),
			'dup_threshold'         => Form::int( $in, 'dup_threshold', 50, 100, $d['dup_threshold'] ),
			'dup_hours'             => Form::int( $in, 'dup_hours', 1, 720, $d['dup_hours'] ),
			'signatures'            => Form::textarea_value( $in, 'signatures' ),
			'iframe_hosts'          => Form::textarea_value( $in, 'iframe_hosts' ),
			'delete_after_days'     => Form::int( $in, 'delete_after_days', 0, 3650, 0 ),
			'delete_attachments'    => Form::bool( $in, 'delete_attachments' ),
			'protect_edited'        => Form::bool( $in, 'protect_edited' ),
			'redirect_mode'         => Form::choice( $in, 'redirect_mode', array( 'category', 'home', 'gone', 'none' ), 'category' ),
			'redirect_keep_days'    => Form::int( $in, 'redirect_keep_days', 0, 3650, 0 ),
			'adaptive'              => Form::bool( $in, 'adaptive' ),
			'user_agent'            => Form::text_value( $in, 'user_agent' ) ? Form::text_value( $in, 'user_agent' ) : $d['user_agent'],
			'timeout'               => Form::int( $in, 'timeout', 5, 120, $d['timeout'] ),
			'log_days'              => Form::int( $in, 'log_days', 1, 365, $d['log_days'] ),
			'manager_roles'         => $roles,
		);
		Settings::update( $new );
		Installer::sync_role_capabilities( $roles );

		if ( (int) $old['noindex'] !== (int) $new['noindex'] ) {
			Queue::schedule_sync();
		}
		if ( $old['noindex_categories'] !== $new['noindex_categories'] ) {
			Seo::flush_sitemaps();
		}
		Admin::flash( 'تنظیمات ذخیره شد.' );
		wp_safe_redirect( admin_url( 'admin.php?page=pnr-settings' ) );
		exit;
	}
}
