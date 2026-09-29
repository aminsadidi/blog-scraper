<?php
/**
 * Edit screen and list table of news sources.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Admin;

use ParsiNewsRobot\Installer;
use ParsiNewsRobot\Queue;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Sources;

defined( 'ABSPATH' ) || exit;

class SourceEditor {

	public static function init() {
		add_action( 'add_meta_boxes_' . Sources::TYPE, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . Sources::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_filter( 'manage_' . Sources::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Sources::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'post_updated_messages', array( __CLASS__, 'messages' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'before_delete' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'list_header' ) );
	}

	public static function title_placeholder( $text, $post ) {
		return Sources::TYPE === $post->post_type ? 'نام منبع (مثلاً: ورزش سه – فوتبال)' : $text;
	}

	public static function list_header() {
		$screen = get_current_screen();
		if ( $screen && 'edit-' . Sources::TYPE === $screen->id ) {
			echo '<div class="wrap pnr-wrap pnr-tabs-only">';
			Admin::tabs( 'edit.php?post_type=' . Sources::TYPE );
			echo '</div>';
		}
	}

	public static function meta_boxes() {
		remove_meta_box( 'slugdiv', Sources::TYPE, 'normal' );
		add_meta_box( 'pnr-source', 'تنظیمات منبع', array( __CLASS__, 'render' ), Sources::TYPE, 'normal', 'high' );
		add_meta_box( 'pnr-source-state', 'وضعیت منبع', array( __CLASS__, 'render_state' ), Sources::TYPE, 'side', 'default' );
	}

	/* ---------- Edit screen ---------- */

	public static function render( $post ) {
		$c       = Sources::config( $post->ID );
		$topics  = Settings::terms_with_role( 'topic' );
		if ( $topics && (int) $c['main_category'] && ! in_array( (int) $c['main_category'], $topics, true ) ) {
			// The current main category lost its "topic" role later: keep it selectable so saving does not drop it.
			$topics[] = (int) $c['main_category'];
		}
		$section = Settings::terms_with_role( 'section' );
		$n       = function ( $key ) {
			return 'pnr[' . $key . ']';
		};
		$inherit = function ( $value ) {
			return (int) $value < 0 ? '' : $value;
		};
		wp_nonce_field( 'pnr_save_source', 'pnr_source_nonce' );
		?>
		<div class="pnr-source-editor">
			<nav class="pnr-subtabs">
				<a href="#pnr-tab-general" class="active">عمومی</a>
				<a href="#pnr-tab-cats">دسته‌بندی</a>
				<a href="#pnr-tab-content">محتوا و تصاویر</a>
				<a href="#pnr-tab-publish">انتشار و سئو</a>
				<a href="#pnr-tab-filters">فیلترها</a>
				<a href="#pnr-tab-test">تست منبع</a>
			</nav>

			<div class="pnr-tab active" id="pnr-tab-general">
				<table class="form-table">
					<tr><th>آدرس RSS</th><td>
						<div class="pnr-inline">
							<?php Form::url( $n( 'url' ), $c['url'], array( 'id' => 'pnr-url', 'placeholder' => 'https://example.com/rss' ) ); ?>
							<button type="button" class="button" id="pnr-discover">پیدا کردن خودکار RSS</button>
						</div>
						<p class="description">اگر آدرس RSS را نمی‌دانید، آدرس صفحه اصلی یا صفحه یک بخش از سایت منبع را وارد کنید و «پیدا کردن خودکار» را بزنید.</p>
						<div id="pnr-discover-result"></div>
					</td></tr>
					<tr><th>فعال</th><td><?php Form::checkbox( $n( 'active' ), $c['active'], 'این منبع خودکار بررسی شود' ); ?></td></tr>
					<tr><th>فاصله بررسی</th><td><?php Form::number( $n( 'interval' ), $c['interval'], 1, 1440 ); ?> دقیقه
						<p class="description">اگر منبع چند بار پشت سر هم خبر جدید نداشته باشد، فاصله به‌طور خودکار تا ۴ برابر بیشتر می‌شود (قابل خاموش کردن در تنظیمات).</p></td></tr>
					<tr><th>حداکثر خبر در هر بررسی</th><td><?php Form::number( $n( 'max_items' ), $c['max_items'], 1, 50 ); ?></td></tr>
					<tr><th>خبرهای قدیمی‌تر از</th><td><?php Form::number( $n( 'max_age_hours' ), $c['max_age_hours'], 0, 720 ); ?> ساعت وارد نشوند <span class="description">(۰ = بدون محدودیت تا سقف ۴۵ روز)</span></td></tr>
					<tr><th>ساعت کاری</th><td>از <input type="time" name="<?php echo esc_attr( $n( 'hours_from' ) ); ?>" value="<?php echo esc_attr( $c['hours_from'] ); ?>"> تا <input type="time" name="<?php echo esc_attr( $n( 'hours_to' ) ); ?>" value="<?php echo esc_attr( $c['hours_to'] ); ?>">
						<p class="description">خالی = همیشه. مثال: ۰۸:۰۰ تا ۰۱:۰۰ (بازه شبانه هم پشتیبانی می‌شود). ساعت بر اساس منطقه زمانی سایت است.</p></td></tr>
					<tr><th>انتشار تدریجی</th><td><?php Form::number( $n( 'drip_minutes' ), $inherit( $c['drip_minutes'] ), 0, 240, array( 'placeholder' => 'پیش‌فرض' ) ); ?> دقیقه فاصله بین انتشار خبرهای این منبع
						<p class="description">خالی = طبق تنظیمات کلی (<?php echo esc_html( number_format_i18n( (int) Settings::get( 'drip_minutes' ) ) ); ?> دقیقه). ۰ = همه با هم.</p></td></tr>
				</table>
			</div>

			<div class="pnr-tab" id="pnr-tab-cats">
				<table class="form-table">
					<tr><th>دسته اصلی <span class="required">*</span></th><td>
						<?php Form::category_select( $n( 'main_category' ), $c['main_category'], '— انتخاب کنید —', $topics ); ?>
						<p class="description">
							<?php
							if ( $topics ) {
								echo 'فقط دسته‌هایی که نقش «موضوعی» دارند نمایش داده می‌شوند. ';
							} else {
								echo 'هنوز دسته‌ای نقش «موضوعی» نگرفته، پس همه دسته‌ها نمایش داده می‌شوند. ';
							}
							printf( '<a href="%s">مدیریت نقش دسته‌ها</a>', esc_url( admin_url( 'admin.php?page=pnr-categories' ) ) );
							?>
						</p>
					</td></tr>
					<tr><th>دسته‌های ثابت اضافه</th><td><?php Form::category_checklist( $n( 'extra_categories' ), (array) $c['extra_categories'] ); ?>
						<p class="description">هر خبر این منبع همیشه در این دسته‌ها هم قرار می‌گیرد (اختیاری).</p></td></tr>
					<tr><th>بخش‌های صفحه اصلی</th><td>
						<?php
						Form::select(
							$n( 'random_scope' ),
							$c['random_scope'],
							array(
								'inherit' => 'همه بخش‌ها طبق تنظیمات کلی',
								'custom'  => 'فقط بخش‌هایی که انتخاب می‌کنم',
								'off'     => 'خبرهای این منبع در هیچ بخشی قرار نگیرند',
							),
							array( 'id' => 'pnr-random-scope' )
						);
						?>
						<div id="pnr-random-custom" class="pnr-sub">
							<?php Form::category_checklist( $n( 'random_sections' ), (array) $c['random_sections'], $section ? $section : array( -1 ), 'هنوز هیچ دسته‌ای نقش «بخش صفحه اصلی» ندارد.' ); ?>
						</div>
						<p class="description">شانس و سهمیه هر بخش در صفحه «دسته‌ها و بخش‌ها» تعیین می‌شود.</p>
					</td></tr>
					<tr><th>حالت تصادفی</th><td>
						<?php
						Form::select(
							$n( 'random_mode' ),
							$c['random_mode'],
							array(
								'inherit'     => 'طبق تنظیمات کلی',
								'one'         => 'دقیقاً یکی از بخش‌ها',
								'independent' => 'هر بخش با شانس مستقل خودش',
							)
						);
						?>
						<p>درصد خبرهایی که در هیچ بخشی قرار نگیرند: <?php Form::number( $n( 'random_none' ), $inherit( $c['random_none'] ), 0, 100, array( 'placeholder' => 'پیش‌فرض' ) ); ?>٪
						&nbsp; حداکثر بخش برای هر خبر: <?php Form::number( $n( 'random_max' ), $inherit( $c['random_max'] ), 0, 20, array( 'placeholder' => 'پیش‌فرض' ) ); ?></p>
						<p class="description">خالی = طبق تنظیمات کلی. سقف ۰ یعنی بدون سقف.</p>
					</td></tr>
					<tr><th>عکس و ویدئو</th><td>
						<?php Form::checkbox( $n( 'use_video' ), $c['use_video'], 'خبرهای ویدئودار در دسته‌های «ویدئو» هم قرار بگیرند' ); ?><br>
						<?php Form::checkbox( $n( 'use_photo' ), $c['use_photo'], 'خبرهایی که غیر از تصویر شاخص عکس دارند در دسته‌های «عکس» هم قرار بگیرند' ); ?>
					</td></tr>
				</table>
			</div>

			<div class="pnr-tab" id="pnr-tab-content">
				<table class="form-table">
					<tr><th>روش گرفتن متن</th><td>
						<?php
						Form::radios(
							$n( 'content_mode' ),
							$c['content_mode'],
							array(
								'auto' => array( 'خودکار (پیشنهادی)', 'اگر RSS متن کامل داشت از همان، وگرنه صفحه خبر باز و متن کامل استخراج می‌شود.' ),
								'feed' => array( 'فقط از RSS', 'سریع‌ترین؛ برای منابعی که متن کامل را در RSS می‌گذارند.' ),
								'page' => array( 'همیشه از صفحه خبر', 'برای منابعی که RSS آن‌ها فقط خلاصه دارد.' ),
							)
						);
						?>
					</td></tr>
					<tr><th>انتخابگر متن خبر</th><td><?php Form::text( $n( 'content_selector' ), $c['content_selector'], array( 'dir' => 'ltr', 'placeholder' => '.item-text' ) ); ?>
						<p class="description">اختیاری. اگر تشخیص خودکار درست کار نکرد، انتخابگر CSS بخش متن خبر را وارد کنید (مثلاً <code>.item-text</code> یا <code>#newsMainBody</code>). چند انتخابگر را با کاما جدا کنید.</p></td></tr>
					<tr><th>بخش‌های حذفی</th><td><?php Form::textarea( $n( 'remove_selectors' ), $c['remove_selectors'], 2, array( 'dir' => 'ltr', 'placeholder' => '.related-news' ) ); ?>
						<p class="description">اختیاری. انتخابگر بخش‌هایی از متن که باید حذف شوند (هر خط یکی).</p></td></tr>
					<tr><th>لید خبر</th><td><?php Form::checkbox( $n( 'include_lead' ), $c['include_lead'], 'لید (خلاصه ابتدای خبر) اگر جدا از متن بود، به اول متن اضافه شود' ); ?></td></tr>
					<tr><th>به‌روزرسانی خبر</th><td><?php Form::number( $n( 'track_updates_hours' ), $c['track_updates_hours'], 0, 168 ); ?> ساعت
						<p class="description">اگر منبع خبری را تا این مدت بعد از انتشار تغییر داد (مثل نتیجه زنده بازی)، خبر سایت شما هم به‌روز شود. ۰ = خاموش. خبرهایی که دستی ویرایش کرده‌اید دست نمی‌خورند.</p></td></tr>
					<tr><th>تصاویر</th><td>
						<?php
						Form::select(
							$n( 'image_mode' ),
							$c['image_mode'],
							array(
								'inherit' => 'طبق تنظیمات کلی',
								'hybrid'  => 'ترکیبی: تصویر شاخص روی هاست من، عکس‌های متن از منبع',
								'local'   => 'همه تصاویر روی هاست من',
								'hotlink' => 'همه تصاویر از هاست منبع',
							)
						);
						?>
					</td></tr>
					<tr><th>نام منبع</th><td><?php Form::text( $n( 'source_name' ), $c['source_name'], array( 'placeholder' => 'خودکار از RSS' ) ); ?>
						<p class="description">برای «به نقل از». خالی = نام همین منبع یا عنوان RSS.</p></td></tr>
					<tr><th>صفحه اصلی منبع</th><td><?php Form::url( $n( 'source_home' ), $c['source_home'], array( 'placeholder' => 'خودکار' ) ); ?></td></tr>
					<tr><th>برچسب‌ها</th><td><?php Form::checkbox( $n( 'import_tags' ), $c['import_tags'], 'دسته‌بندی‌های RSS منبع به‌عنوان برچسب اضافه شوند' ); ?></td></tr>
				</table>
			</div>

			<div class="pnr-tab" id="pnr-tab-publish">
				<table class="form-table">
					<tr><th>ایندکس در گوگل</th><td>
						<?php
						Form::select(
							$n( 'index_mode' ),
							$c['index_mode'],
							array(
								'inherit' => 'طبق تنظیمات کلی (' . ( Settings::get( 'noindex' ) ? 'ایندکس نشود' : 'ایندکس شود' ) . ')',
								'noindex' => 'ایندکس نشود و در سایت‌مپ نباشد',
								'index'   => 'ایندکس شود',
							)
						);
						?>
					</td></tr>
					<tr><th>حذف خودکار بعد از</th><td><?php Form::number( $n( 'delete_after' ), $inherit( $c['delete_after'] ), 0, 3650, array( 'placeholder' => 'پیش‌فرض' ) ); ?> روز
						<p class="description">خالی = طبق تنظیمات کلی (<?php echo esc_html( (int) Settings::get( 'delete_after_days' ) ? number_format_i18n( (int) Settings::get( 'delete_after_days' ) ) . ' روز' : 'حذف نمی‌شوند' ); ?>). ۰ = هرگز. آدرس خبرهای حذف‌شده به دسته اصلی ریدایرکت می‌شود.</p></td></tr>
					<tr><th>نویسنده</th><td>
						<?php
						wp_dropdown_users(
							array(
								'name'              => $n( 'author' ),
								'selected'          => (int) $c['author'],
								'show_option_none'  => 'طبق تنظیمات کلی',
								'option_none_value' => 0,
								'capability'        => array( 'edit_posts' ),
							)
						);
						?>
					</td></tr>
					<tr><th>وضعیت انتشار</th><td>
						<?php
						Form::select(
							$n( 'post_status' ),
							$c['post_status'],
							array(
								'inherit' => 'طبق تنظیمات کلی',
								'publish' => 'انتشار مستقیم',
								'pending' => 'در انتظار بررسی',
								'draft'   => 'پیش‌نویس',
							)
						);
						?>
					</td></tr>
				</table>
			</div>

			<div class="pnr-tab" id="pnr-tab-filters">
				<table class="form-table">
					<tr><th>فقط خبرهای شامل</th><td><?php Form::textarea( $n( 'include_keywords' ), $c['include_keywords'], 3 ); ?>
						<p class="description">اختیاری. هر خط یک کلمه؛ فقط خبرهایی که در عنوان یا خلاصه یکی از این کلمات را دارند وارد می‌شوند.</p></td></tr>
					<tr><th>خبرهای شامل این کلمات وارد نشوند</th><td><?php Form::textarea( $n( 'exclude_keywords' ), $c['exclude_keywords'], 3 ); ?></td></tr>
					<tr><th>حداقل طول متن</th><td><?php Form::number( $n( 'min_words' ), $c['min_words'], 0, 5000 ); ?> کلمه <span class="description">(۰ = بدون محدودیت)</span></td></tr>
					<tr><th>تصویر</th><td><?php Form::checkbox( $n( 'require_image' ), $c['require_image'], 'خبرهای بدون تصویر وارد نشوند' ); ?></td></tr>
				</table>
				<p class="description">قوانین کلمه کلیدی مشترک بین همه منابع (مثلاً «هر خبری که «فوری» دارد به تیتر یک برود») در صفحه <a href="<?php echo esc_url( admin_url( 'admin.php?page=pnr-rules' ) ); ?>">قوانین</a> تعریف می‌شوند.</p>
			</div>

			<div class="pnr-tab" id="pnr-tab-test">
				<p>با این دکمه، چند خبر اول RSS با همین تنظیمات (حتی ذخیره‌نشده) دریافت و پردازش می‌شود و نتیجه را می‌بینید. <strong>هیچ خبری منتشر نمی‌شود.</strong></p>
				<p><button type="button" class="button button-primary" id="pnr-test">تست منبع</button></p>
				<div id="pnr-test-result"></div>
			</div>
		</div>
		<?php
	}

	public static function render_state( $post ) {
		$c     = Sources::config( $post->ID );
		$state = Sources::state( $post->ID );
		if ( ! $state['last_run'] ) {
			echo '<p>این منبع هنوز بررسی نشده است.</p>';
		} else {
			printf( '<p>وضعیت: %s</p>', Dashboard::status_badge( $c, $state, $post->ID ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			printf( '<p>آخرین بررسی: %s<br>بررسی بعدی: %s</p>', esc_html( Admin::when( (int) $state['last_run'] ) ), esc_html( Admin::when( (int) $state['next_run'] ) ) );
			printf( '<p>%s</p>', esc_html( $state['last_message'] ) );
			printf( '<p>کل خبرهای منتشرشده: <strong>%s</strong></p>', esc_html( number_format_i18n( (int) $state['total'] ) ) );
		}
		if ( 'publish' === $post->post_status ) {
			printf(
				'<p><a class="button" href="%s">همین حالا بررسی کن</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pnr_run_source&source=' . $post->ID ), 'pnr_run_source_' . $post->ID ) )
			);
			printf(
				'<p><a class="button-link-delete pnr-confirm" data-confirm="%s" href="%s">حذف همه خبرهای این منبع</a></p>',
				esc_attr( 'همه خبرهایی که از این منبع منتشر شده حذف و ریدایرکت می‌شوند. ادامه می‌دهید؟' ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pnr_purge_source&source=' . $post->ID ), 'pnr_purge_source_' . $post->ID ) )
			);
		}
	}

	/**
	 * Renders the "test source" report.
	 */
	public static function render_preview( array $report ) {
		printf(
			'<div class="pnr-preview-head"><strong>%s</strong> — %s خبر در RSS</div>',
			esc_html( $report['feed_title'] ? $report['feed_title'] : 'فید' ),
			esc_html( number_format_i18n( $report['count'] ) )
		);
		$methods = array(
			'feed'     => 'متن از خود RSS',
			'selector' => 'متن از صفحه خبر با انتخابگر شما',
			'auto'     => 'متن از صفحه خبر با تشخیص خودکار',
			'jsonld'   => 'متن از داده‌های ساختاریافته صفحه',
		);
		foreach ( $report['items'] as $row ) {
			echo '<div class="pnr-preview-item">';
			printf( '<h3><a href="%s" target="_blank" rel="noopener">%s</a></h3>', esc_url( $row['link'] ), esc_html( $row['title'] ) );
			if ( ! empty( $row['error'] ) ) {
				printf( '<p class="pnr-error">%s</p></div>', esc_html( $row['error'] ) );
				continue;
			}
			$method = isset( $methods[ $row['method'] ] ) ? $methods[ $row['method'] ] : ( 0 === strpos( $row['method'], 'known:' ) ? 'متن از صفحه خبر (الگوی ' . substr( $row['method'], 6 ) . ')' : $row['method'] );
			echo '<ul class="pnr-facts">';
			printf( '<li>روش: %s</li>', esc_html( $method ) );
			printf( '<li>طول متن: %s کلمه</li>', esc_html( number_format_i18n( $row['words'] ) ) );
			printf( '<li>عکس‌های متن: %s</li>', esc_html( number_format_i18n( $row['image_count'] ) ) );
			printf( '<li>ویدئو: %s</li>', $row['has_video'] ? 'دارد' : 'ندارد' );
			printf( '<li>دسته‌ها: %s</li>', esc_html( implode( '، ', $row['categories'] ) ) );
			if ( 'imported' === $row['already'] ) {
				echo '<li class="pnr-warn">این خبر قبلاً وارد و منتشر شده است.</li>';
			} elseif ( $row['already'] ) {
				echo '<li class="pnr-warn">این خبر قبلاً بررسی شده و دوباره وارد نمی‌شود.</li>';
			}
			if ( $row['duplicate'] ) {
				echo '<li class="pnr-warn">این خبر مشابه خبری است که قبلاً وارد شده و منتشر نمی‌شود.</li>';
			}
			echo '</ul>';
			echo '<div class="pnr-preview-body">';
			if ( $row['featured'] ) {
				printf( '<img class="pnr-preview-featured" src="%s" alt="" referrerpolicy="no-referrer">', esc_url( $row['featured'] ) );
			}
			echo wp_kses( $row['content'], \ParsiNewsRobot\Import\Cleaner::allowed_html() );
			echo '</div></div>';
		}
		echo '<p class="description">اگر متن ناقص است یا بخش‌های اضافه دارد، در تب «محتوا و تصاویر» انتخابگر متن یا بخش‌های حذفی را تنظیم کنید و دوباره تست کنید. تغییرات را فراموش نکنید ذخیره کنید.</p>';
	}

	/* ---------- Saving ---------- */

	/**
	 * Sanitises posted source settings (used on save and by the test button).
	 */
	public static function sanitize( array $in ) {
		$d   = Settings::source_defaults();
		$out = array(
			'url'                 => isset( $in['url'] ) ? esc_url_raw( trim( $in['url'] ) ) : '',
			'active'              => Form::bool( $in, 'active' ),
			'interval'            => Form::int( $in, 'interval', 1, 1440, $d['interval'] ),
			'max_items'           => Form::int( $in, 'max_items', 1, 50, $d['max_items'] ),
			'max_age_hours'       => Form::int( $in, 'max_age_hours', 0, 720, $d['max_age_hours'] ),
			'hours_from'          => isset( $in['hours_from'] ) && preg_match( '/^\d{1,2}:\d{2}$/', $in['hours_from'] ) ? $in['hours_from'] : '',
			'hours_to'            => isset( $in['hours_to'] ) && preg_match( '/^\d{1,2}:\d{2}$/', $in['hours_to'] ) ? $in['hours_to'] : '',
			'drip_minutes'        => Form::int( $in, 'drip_minutes', 0, 240, -1 ),

			'main_category'       => Form::int( $in, 'main_category', 0 ),
			'extra_categories'    => Form::ids( $in, 'extra_categories' ),
			'random_scope'        => Form::choice( $in, 'random_scope', array( 'inherit', 'custom', 'off' ), 'inherit' ),
			'random_sections'     => Form::ids( $in, 'random_sections' ),
			'random_mode'         => Form::choice( $in, 'random_mode', array( 'inherit', 'one', 'independent' ), 'inherit' ),
			'random_none'         => Form::int( $in, 'random_none', 0, 100, -1 ),
			'random_max'          => Form::int( $in, 'random_max', 0, 20, -1 ),
			'use_video'           => Form::bool( $in, 'use_video' ),
			'use_photo'           => Form::bool( $in, 'use_photo' ),

			'content_mode'        => Form::choice( $in, 'content_mode', array( 'auto', 'feed', 'page' ), 'auto' ),
			'content_selector'    => isset( $in['content_selector'] ) ? sanitize_text_field( $in['content_selector'] ) : '',
			'remove_selectors'    => Form::textarea_value( $in, 'remove_selectors' ),
			'include_lead'        => Form::bool( $in, 'include_lead' ),
			'track_updates_hours' => Form::int( $in, 'track_updates_hours', 0, 168, 0 ),
			'image_mode'          => Form::choice( $in, 'image_mode', array( 'inherit', 'hybrid', 'local', 'hotlink' ), 'inherit' ),

			'index_mode'          => Form::choice( $in, 'index_mode', array( 'inherit', 'noindex', 'index' ), 'inherit' ),
			'delete_after'        => Form::int( $in, 'delete_after', 0, 3650, -1 ),

			'source_name'         => Form::text_value( $in, 'source_name' ),
			'source_home'         => isset( $in['source_home'] ) ? esc_url_raw( trim( $in['source_home'] ) ) : '',
			'author'              => Form::int( $in, 'author', 0 ),
			'post_status'         => Form::choice( $in, 'post_status', array( 'inherit', 'publish', 'pending', 'draft' ), 'inherit' ),
			'include_keywords'    => Form::textarea_value( $in, 'include_keywords' ),
			'exclude_keywords'    => Form::textarea_value( $in, 'exclude_keywords' ),
			'min_words'           => Form::int( $in, 'min_words', 0, 5000, 0 ),
			'require_image'       => Form::bool( $in, 'require_image' ),
			'import_tags'         => Form::bool( $in, 'import_tags' ),
		);
		return array_merge( $d, $out );
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['pnr_source_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pnr_source_nonce'] ), 'pnr_save_source' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( Installer::CAP ) ) {
			return;
		}
		$in  = isset( $_POST['pnr'] ) && is_array( $_POST['pnr'] ) ? wp_unslash( $_POST['pnr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$old = Sources::config( $post_id );
		$new = self::sanitize( $in );
		Sources::save_config( $post_id, $new );

		// Check soon, and read the whole feed again so changed filters apply to entries already in it.
		Sources::set_state(
			$post_id,
			array(
				'next_run'      => 0,
				'error_runs'    => 0,
				'etag'          => '',
				'last_modified' => '',
			)
		);
		if ( $old['index_mode'] !== $new['index_mode'] ) {
			Queue::schedule_sync();
		}

		if ( ! $new['url'] ) {
			Admin::flash( 'آدرس RSS وارد نشده است؛ این منبع بررسی نمی‌شود.', 'warning' );
		} elseif ( ! $new['main_category'] ) {
			Admin::flash( 'دسته اصلی انتخاب نشده است؛ خبرهای این منبع در دسته پیش‌فرض وردپرس قرار می‌گیرند.', 'warning' );
		}
		if ( '' === trim( $post->post_title ) && $new['url'] ) {
			remove_action( 'save_post_' . Sources::TYPE, array( __CLASS__, 'save' ), 10 );
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => wp_parse_url( $new['url'], PHP_URL_HOST ),
				)
			);
			add_action( 'save_post_' . Sources::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		}
	}

	public static function before_delete( $post_id ) {
		if ( Sources::TYPE === get_post_type( $post_id ) ) {
			\ParsiNewsRobot\Data\Seen::delete_source( $post_id );
		}
	}

	/* ---------- List table ---------- */

	public static function columns( $columns ) {
		return array(
			'cb'           => $columns['cb'],
			'title'        => 'منبع',
			'pnr_feed'     => 'RSS',
			'pnr_category' => 'دسته اصلی',
			'pnr_status'   => 'وضعیت',
			'pnr_last'     => 'آخرین بررسی',
			'pnr_total'    => 'خبرها',
		);
	}

	public static function column( $column, $post_id ) {
		$c     = Sources::config( $post_id );
		$state = Sources::state( $post_id );
		switch ( $column ) {
			case 'pnr_feed':
				printf( '<span dir="ltr">%s</span>', esc_html( wp_parse_url( $c['url'], PHP_URL_HOST ) ) );
				break;
			case 'pnr_category':
				$term = $c['main_category'] ? get_term( $c['main_category'], 'category' ) : null;
				echo $term && ! is_wp_error( $term ) ? esc_html( $term->name ) : '<span class="pnr-warn">انتخاب نشده</span>';
				break;
			case 'pnr_status':
				echo Dashboard::status_badge( $c, $state, $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'pnr_last':
				echo esc_html( Admin::when( (int) $state['last_run'] ) );
				if ( $state['last_message'] ) {
					echo '<br><small>' . esc_html( $state['last_message'] ) . '</small>';
				}
				break;
			case 'pnr_total':
				echo esc_html( number_format_i18n( (int) $state['total'] ) );
				break;
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( Sources::TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'], $actions['view'] );
		if ( 'publish' === $post->post_status ) {
			$actions['pnr_run'] = sprintf(
				'<a href="%s">همین حالا بررسی کن</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pnr_run_source&source=' . $post->ID ), 'pnr_run_source_' . $post->ID ) )
			);
		}
		return $actions;
	}

	public static function messages( $messages ) {
		$saved                      = 'منبع ذخیره شد.';
		$messages[ Sources::TYPE ] = array_fill( 0, 11, $saved );
		$messages[ Sources::TYPE ][0] = '';
		return $messages;
	}
}
