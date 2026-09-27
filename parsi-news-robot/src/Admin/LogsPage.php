<?php
/**
 * Log viewer.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Admin;

use ParsiNewsRobot\Data\Log;
use ParsiNewsRobot\Sources;

defined( 'ABSPATH' ) || exit;

class LogsPage {

	const PER_PAGE = 100;

	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$source = isset( $_GET['source'] ) ? absint( $_GET['source'] ) : 0;
		$level  = isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$args  = array(
			'source_id' => $source,
			'level'     => $level,
			'limit'     => self::PER_PAGE,
			'offset'    => ( $paged - 1 ) * self::PER_PAGE,
		);
		$total = Log::count( $args );

		Admin::header( 'pnr-logs', 'گزارش‌ها' );
		$sources = array( '0' => 'همه منابع' );
		foreach ( Sources::all_ids() as $id ) {
			$sources[ $id ] = Sources::name( $id );
		}
		?>
		<form method="get" class="pnr-filters">
			<input type="hidden" name="page" value="pnr-logs">
			<?php
			Form::select( 'source', $source, $sources );
			Form::select(
				'level',
				$level,
				array(
					''        => 'همه سطح‌ها',
					'success' => 'منتشر شده',
					'info'    => 'اطلاعات',
					'warning' => 'هشدار',
					'error'   => 'خطا',
				)
			);
			?>
			<button class="button">فیلتر</button>
		</form>
		<?php
		self::table( Log::query( $args ) );

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			);
			echo '</div></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pnr-inline-form">
			<input type="hidden" name="action" value="pnr_clear_log">
			<?php wp_nonce_field( 'pnr_clear_log' ); ?>
			<button class="button pnr-confirm" data-confirm="همه گزارش‌ها پاک شود؟">پاک کردن گزارش‌ها</button>
		</form>
		<?php
		Admin::footer();
	}

	/**
	 * @param object[] $rows
	 */
	public static function table( array $rows ) {
		if ( ! $rows ) {
			echo '<p>هنوز رویدادی ثبت نشده است.</p>';
			return;
		}
		$labels = array(
			'success' => 'منتشر شد',
			'info'    => 'اطلاعات',
			'warning' => 'هشدار',
			'error'   => 'خطا',
		);
		$names  = array();
		echo '<table class="widefat striped pnr-table pnr-log"><thead><tr><th>زمان</th><th>نوع</th><th>منبع</th><th>پیام</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$sid = (int) $row->source_id;
			if ( $sid && ! isset( $names[ $sid ] ) ) {
				$names[ $sid ] = Sources::exists( $sid ) ? Sources::name( $sid ) : 'منبع حذف‌شده';
			}
			printf(
				'<tr><td title="%1$s">%2$s</td><td><span class="pnr-badge %3$s">%4$s</span></td><td>%5$s</td><td>%6$s</td></tr>',
				esc_attr( wp_date( 'Y-m-d H:i:s', (int) $row->created_at ) ),
				esc_html( Admin::when( (int) $row->created_at ) ),
				esc_attr( $row->level ),
				esc_html( isset( $labels[ $row->level ] ) ? $labels[ $row->level ] : $row->level ),
				esc_html( $sid ? $names[ $sid ] : '—' ),
				esc_html( $row->message )
			);
		}
		echo '</tbody></table>';
	}
}
