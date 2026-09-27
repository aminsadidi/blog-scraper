<?php
/**
 * Category roles: the site's own categories are listed and the admin gives each one a role.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Admin;

use ParsiNewsRobot\Installer;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Sources;
use ParsiNewsRobot\Taxonomy\Categorizer;

defined( 'ABSPATH' ) || exit;

class CategoriesPage {

	public static function render() {
		$roles = Settings::roles();
		$s     = Settings::get();
		$terms = Form::categories();
		$used  = array();
		foreach ( Sources::all_ids() as $id ) {
			$main = (int) Sources::config( $id )['main_category'];
			if ( $main ) {
				$used[ $main ][] = Sources::name( $id );
			}
		}

		Admin::header( 'pnr-categories', 'دسته‌ها و بخش‌ها' );
		?>
		<div class="pnr-box pnr-intro">
			<p>ربات هیچ دسته‌ای از پیش تعریف نمی‌کند؛ همه دسته‌های <strong>سایت خودتان</strong> اینجا آمده‌اند و شما به هر کدام یک نقش می‌دهید:</p>
			<ul>
				<li><strong>موضوعی:</strong> مثل سیاسی، ورزشی، اقتصادی. در تنظیمات هر منبع یکی از این‌ها «دسته اصلی» می‌شود و خبر حتماً در آن منتشر می‌شود.</li>
				<li><strong>بخش صفحه اصلی:</strong> مثل تیتر یک، تیتر دوم، نوار کناری. خبرها به صورت تصادفی و با رعایت «شانس» و «سهمیه» در این‌ها هم قرار می‌گیرند.</li>
				<li><strong>ویدئو / عکس:</strong> خبرهایی که ویدئو دارند، یا غیر از تصویر شاخص عکس دارند، در این دسته‌ها هم قرار می‌گیرند.</li>
				<li><strong>بدون نقش:</strong> ربات به این دسته کاری ندارد (مگر اینکه در یک منبع صریحاً انتخابش کنید).</li>
			</ul>
			<p>سهمیه فقط خبرهای ربات را می‌شمارد؛ خبرهایی که خودتان منتشر می‌کنید هیچ‌وقت شمرده یا محدود نمی‌شوند.</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pnr_save_categories">
			<?php wp_nonce_field( 'pnr_save_categories' ); ?>

			<?php if ( ! $terms ) : ?>
				<p>سایت هنوز دسته‌ای ندارد. <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=category' ) ); ?>">ساخت دسته</a></p>
			<?php else : ?>
			<table class="widefat striped pnr-table pnr-roles">
				<thead><tr><th>دسته</th><th>تعداد نوشته</th><th>نقش</th><th>شانس</th><th>سهمیه ربات</th><th>وضعیت سهمیه</th></tr></thead>
				<tbody>
				<?php
				foreach ( $terms as $term ) :
					$id   = (int) $term->term_id;
					$role = isset( $roles[ $id ] ) ? $roles[ $id ] : Settings::role_defaults();
					$n    = 'roles[' . $id . ']';
					?>
					<tr class="pnr-role-row" data-role="<?php echo esc_attr( $role['role'] ); ?>">
						<td>
							<?php echo esc_html( str_repeat( '— ', $term->depth ) . $term->name ); ?>
							<?php if ( ! empty( $used[ $id ] ) ) : ?>
								<br><small>دسته اصلی: <?php echo esc_html( implode( '، ', $used[ $id ] ) ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $term->count ) ); ?></td>
						<td>
							<?php
							Form::select(
								$n . '[role]',
								$role['role'],
								array(
									''        => 'بدون نقش',
									'topic'   => 'موضوعی (دسته اصلی)',
									'section' => 'بخش صفحه اصلی',
									'video'   => 'ویدئو',
									'photo'   => 'عکس',
								),
								array( 'class' => 'pnr-role-select' )
							);
							?>
						</td>
						<td class="pnr-section-only"><span><?php Form::number( $n . '[chance]', $role['chance'], 0, 100 ); ?>٪</span></td>
						<td class="pnr-section-only">
							<?php
							Form::select(
								$n . '[quota]',
								$role['quota'],
								array(
									'unlimited' => 'نامحدود',
									'limit'     => 'محدود',
									'off'       => 'هیچ (ربات اینجا منتشر نکند)',
								),
								array( 'class' => 'pnr-quota-select' )
							);
							?>
							<span class="pnr-quota-limit">
								حداکثر <?php Form::number( $n . '[quota_n]', $role['quota_n'], 0, 1000 ); ?> خبر در هر
								<?php Form::number( $n . '[quota_hours]', $role['quota_hours'], 1, 720 ); ?> ساعت
							</span>
						</td>
						<td class="pnr-section-only">
							<?php
							if ( 'section' === $role['role'] ) {
								$left = Categorizer::quota_left( $id, $role + array( 'term_id' => $id ) );
								echo esc_html( null === $left ? 'نامحدود' : sprintf( '%s جای خالی', number_format_i18n( $left ) ) );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<h2>انتشار تصادفی در بخش‌های صفحه اصلی</h2>
			<table class="form-table">
				<tr><th>حالت</th><td>
					<?php
					Form::radios(
						'random_mode',
						$s['random_mode'],
						array(
							'one'         => array( 'دقیقاً یکی', 'هر خبر حداکثر در یک بخش قرار می‌گیرد؛ «شانس» هر بخش وزن انتخاب آن است.' ),
							'independent' => array( 'شانس مستقل', 'هر بخش جداگانه با درصد شانس خودش انتخاب می‌شود؛ یک خبر ممکن است در چند بخش باشد.' ),
						)
					);
					?>
				</td></tr>
				<tr><th>بدون بخش</th><td><?php Form::number( 'random_none', $s['random_none'], 0, 100 ); ?>٪ از خبرها در هیچ بخشی قرار نگیرند (قبل از هر دو حالت اعمال می‌شود).</td></tr>
				<tr><th>سقف بخش برای هر خبر</th><td><?php Form::number( 'random_max', $s['random_max'], 0, 20 ); ?> <span class="description">(فقط در حالت «شانس مستقل»؛ ۰ = بدون سقف)</span></td></tr>
				<tr><th>دسته عکس</th><td>خبر وقتی در دسته «عکس» قرار می‌گیرد که حداقل <?php Form::number( 'photo_min_images', $s['photo_min_images'], 1, 50 ); ?> عکس غیر از تصویر شاخص داشته باشد.</td></tr>
			</table>
			<?php submit_button( 'ذخیره نقش‌ها و تنظیمات' ); ?>
		</form>
		<?php
		Admin::footer();
	}

	public static function save() {
		if ( ! current_user_can( Installer::CAP ) ) {
			wp_die( 'دسترسی ندارید.', 403 );
		}
		check_admin_referer( 'pnr_save_categories' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Settings::update(
			array(
				'roles'            => Categorizer::roles_from_input( isset( $post['roles'] ) ? $post['roles'] : array() ),
				'random_mode'      => Form::choice( $post, 'random_mode', array( 'one', 'independent' ), 'independent' ),
				'random_none'      => Form::int( $post, 'random_none', 0, 100, 0 ),
				'random_max'       => Form::int( $post, 'random_max', 0, 20, 1 ),
				'photo_min_images' => Form::int( $post, 'photo_min_images', 1, 50, 1 ),
			)
		);
		Admin::flash( 'نقش دسته‌ها ذخیره شد.' );
		wp_safe_redirect( admin_url( 'admin.php?page=pnr-categories' ) );
		exit;
	}
}
