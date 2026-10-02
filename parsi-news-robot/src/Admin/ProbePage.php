<?php
/**
 * "بررسی منبع‌ها": checks many candidate addresses from this server and produces a report to share.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Admin;

use ParsiNewsRobot\Installer;
use ParsiNewsRobot\Probe;

defined( 'ABSPATH' ) || exit;

class ProbePage {

	public static function init() {
		add_action( 'wp_ajax_pnr_probe_url', array( __CLASS__, 'ajax' ) );
	}

	public static function render() {
		Admin::header( 'pnr-probe', 'بررسی منبع‌ها' );
		?>
		<div class="pnr-box pnr-intro">
			<p>این ابزار آدرس‌ها را <strong>از روی سرور خود سایت</strong> بررسی می‌کند (سایت‌های ایرانی معمولاً فقط به سرورهای داخل ایران جواب می‌دهند). برای هر آدرس نشان می‌دهد RSS است یا صفحه، چند خبر دارد، دسته‌های RSS چیست، چند درصد خبرها کلمه موردنظر را دارند، آیا متن کامل استخراج می‌شود و صفحه‌های آرشیو چه فیلترهایی دارند (مثل شماره سرویس‌ها).</p>
			<p>در پایان، «متن گزارش» را کپی کنید و برای پشتیبانی یا دستیارتان بفرستید.</p>
		</div>

		<div class="pnr-box">
			<h2>بررسی آدرس‌ها</h2>
			<p><label>کلمه کلیدی برای سنجش (مثلاً نام شهر): <input type="text" id="pnr-probe-kw" value="مشهد" class="regular-text"></label>
				<button type="button" class="button" id="pnr-probe-suggest">افزودن آدرس‌های پیشنهادی خبرگزاری‌ها</button></p>
			<textarea id="pnr-probe-urls" rows="8" class="large-text" dir="ltr" placeholder="هر خط یک آدرس RSS یا صفحه"></textarea>
			<p><label><input type="checkbox" id="pnr-probe-deep" checked> متن کامل خبر اول هر منبع هم بررسی شود (کندتر)</label></p>
			<p><button type="button" class="button button-primary" id="pnr-probe-run">شروع بررسی</button> <span id="pnr-probe-status"></span></p>
			<div id="pnr-probe-results"></div>
			<h3>متن گزارش</h3>
			<textarea id="pnr-probe-report" rows="12" class="large-text" readonly></textarea>
			<p><button type="button" class="button pnr-copy-report">کپی گزارش</button></p>
			<script type="application/json" id="pnr-probe-suggestions"><?php echo wp_json_encode( Probe::suggestions( 'KEYWORD' ) ); ?></script>
		</div>

		<?php
		Admin::footer();
	}

	public static function ajax() {
		check_ajax_referer( 'pnr_ajax', 'nonce' );
		if ( ! current_user_can( Installer::CAP ) ) {
			wp_send_json_error( 'دسترسی ندارید.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$url = isset( $_POST['url'] ) ? trim( esc_url_raw( wp_unslash( $_POST['url'] ) ) ) : '';
		$kw  = isset( $_POST['kw'] ) ? sanitize_text_field( wp_unslash( $_POST['kw'] ) ) : '';
		if ( ! $url ) {
			wp_send_json_error( 'آدرس نامعتبر است.' );
		}
		$result = Probe::inspect( $url, $kw, ! empty( $_POST['deep'] ) );
		wp_send_json_success(
			array(
				'ok'     => $result['ok'],
				'type'   => $result['type'],
				'count'  => isset( $result['count'] ) ? $result['count'] : 0,
				'share'  => isset( $result['keyword_share'] ) ? $result['keyword_share'] : null,
				'words'  => isset( $result['full_text']['words'] ) ? $result['full_text']['words'] : null,
				'error'  => $result['error'],
				'report' => Probe::report( $result ),
			)
		);
	}
}
