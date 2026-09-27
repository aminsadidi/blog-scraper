<?php
/**
 * Keyword rules shared by all sources.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Admin;

use ParsiNewsRobot\Installer;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Taxonomy\Rules;

defined( 'ABSPATH' ) || exit;

class RulesPage {

	public static function render() {
		$rules   = Rules::all();
		$rules[] = array(
			'keywords' => '',
			'field'    => 'title',
			'action'   => 'section',
			'term'     => 0,
		);
		Admin::header( 'pnr-rules', 'قوانین کلمه کلیدی' );
		?>
		<div class="pnr-box pnr-intro">
			<p>قوانین روی خبرهای <strong>همه منابع</strong> اعمال می‌شوند. چند نمونه:</p>
			<ul>
				<li>عنوان شامل «فوری» ← بخش «تیتر یک» (با رعایت سهمیه آن بخش).</li>
				<li>عنوان شامل «پرسپولیس» یا «استقلال» ← دسته «فوتبال» هم اضافه شود.</li>
				<li>متن شامل «آگهی» یا «رپرتاژ» ← منتشر نشود.</li>
			</ul>
			<p>چند کلمه را در یک قانون با خط جدید یا کاما جدا کنید؛ کافی است یکی از آن‌ها پیدا شود. «ی/ك» عربی و نیم‌فاصله در مقایسه یکسان در نظر گرفته می‌شوند.</p>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pnr_save_rules">
			<?php wp_nonce_field( 'pnr_save_rules' ); ?>
			<table class="widefat striped pnr-table pnr-rules">
				<thead><tr><th>کلمات</th><th>جستجو در</th><th>کار</th><th>دسته / بخش</th><th></th></tr></thead>
				<tbody id="pnr-rules-body">
				<?php foreach ( array_values( $rules ) as $i => $rule ) : ?>
					<?php self::row( $i, $rule ); ?>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="pnr-add-rule">افزودن قانون</button></p>
			<script type="text/html" id="pnr-rule-template">
				<?php
				self::row(
					'__i__',
					array(
						'keywords' => '',
						'field'    => 'title',
						'action'   => 'section',
						'term'     => 0,
					)
				);
				?>
			</script>
			<?php submit_button( 'ذخیره قوانین' ); ?>
		</form>
		<?php
		Admin::footer();
	}

	private static function row( $i, array $rule ) {
		$n = 'rules[' . $i . ']';
		echo '<tr>';
		echo '<td>';
		Form::textarea( $n . '[keywords]', $rule['keywords'], 2, array( 'class' => 'pnr-rule-words' ) );
		echo '</td><td>';
		Form::select(
			$n . '[field]',
			$rule['field'],
			array(
				'title' => 'فقط عنوان',
				'all'   => 'عنوان و متن',
			)
		);
		echo '</td><td>';
		Form::select(
			$n . '[action]',
			$rule['action'],
			array(
				'section'  => 'قرار دادن در بخش صفحه اصلی',
				'category' => 'افزودن دسته',
				'skip'     => 'منتشر نشود',
			)
		);
		echo '</td><td>';
		Form::category_select( $n . '[term]', $rule['term'], '—' );
		echo '</td><td><button type="button" class="button-link-delete pnr-remove-rule">حذف</button></td>';
		echo '</tr>';
	}

	public static function save() {
		if ( ! current_user_can( Installer::CAP ) ) {
			wp_die( 'دسترسی ندارید.', 403 );
		}
		check_admin_referer( 'pnr_save_rules' );
		$rules = Rules::from_input( isset( $_POST['rules'] ) ? $_POST['rules'] : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Settings::update( array( 'rules' => $rules ) );
		Admin::flash( sprintf( '%s قانون ذخیره شد.', number_format_i18n( count( $rules ) ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=pnr-rules' ) );
		exit;
	}
}
