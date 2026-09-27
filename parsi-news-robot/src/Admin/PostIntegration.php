<?php
/**
 * Robot posts in the regular post screens: a side box on the edit screen, a list column and a filter view.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Admin;

use ParsiNewsRobot\Data\Items;
use ParsiNewsRobot\Seo\Seo;
use ParsiNewsRobot\Sources;

defined( 'ABSPATH' ) || exit;

class PostIntegration {

	public static function init() {
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save' ), 20 );
		add_filter( 'manage_post_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'views_edit-post', array( __CLASS__, 'views' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list' ) );
	}

	public static function meta_box( $post ) {
		if ( Items::get( $post->ID ) ) {
			add_meta_box( 'pnr-post', 'ربات خبر', array( __CLASS__, 'render' ), 'post', 'side', 'high' );
		}
	}

	public static function render( $post ) {
		$item = Items::get( $post->ID );
		if ( ! $item ) {
			return;
		}
		wp_nonce_field( 'pnr_post_box', 'pnr_post_nonce' );
		$source = Sources::exists( $item->source_id ) ? sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $item->source_id ) ), esc_html( Sources::name( $item->source_id ) ) ) : esc_html( $item->source_name );
		printf( '<p>منبع: %s</p>', $source ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<p><a href="%s" target="_blank" rel="noopener noreferrer">مشاهده خبر اصلی</a></p>', esc_url( $item->source_url ) );
		printf( '<p>دریافت: %s</p>', esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $item->imported_at ) ) );
		printf( '<p>ویدئو: %s — عکس‌های متن: %s</p>', $item->has_video ? 'دارد' : 'ندارد', esc_html( number_format_i18n( (int) $item->image_count ) ) );
		echo '<p><label for="pnr-index">ایندکس در گوگل:</label><br>';
		Form::select(
			'pnr_index_override',
			$item->index_override,
			array(
				''        => 'طبق تنظیمات (' . ( Seo::is_noindex( $post->ID ) ? 'فعلاً ایندکس نمی‌شود' : 'فعلاً ایندکس می‌شود' ) . ')',
				'noindex' => 'ایندکس نشود',
				'index'   => 'ایندکس شود',
			),
			array( 'id' => 'pnr-index' )
		);
		echo '</p><p>';
		printf( '<label><input type="checkbox" name="pnr_keep" value="1"%s> این خبر خودکار حذف نشود</label>', checked( (int) $item->keep_post, 1, false ) );
		echo '</p>';
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['pnr_post_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pnr_post_nonce'] ), 'pnr_post_box' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) || ! Items::get( $post_id ) ) {
			return;
		}
		$override = isset( $_POST['pnr_index_override'] ) ? sanitize_key( $_POST['pnr_index_override'] ) : '';
		Items::update(
			$post_id,
			array(
				'index_override' => in_array( $override, array( 'index', 'noindex' ), true ) ? $override : '',
				'keep_post'      => empty( $_POST['pnr_keep'] ) ? 0 : 1,
			)
		);
		Seo::apply( $post_id, Seo::compute( $post_id ) );
	}

	public static function columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['pnr_source'] = 'ربات خبر';
			}
		}
		return $out;
	}

	public static function column( $column, $post_id ) {
		if ( 'pnr_source' !== $column ) {
			return;
		}
		$item = Items::get( $post_id );
		if ( ! $item ) {
			echo '—';
			return;
		}
		echo esc_html( $item->source_name );
		if ( Seo::is_noindex( $post_id ) ) {
			echo ' <span class="pnr-badge off" title="در گوگل ایندکس نمی‌شود">noindex</span>';
		}
		if ( $item->keep_post ) {
			echo ' <span class="pnr-badge ok">نگه‌دار</span>';
		}
	}

	public static function views( $views ) {
		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Items::table() ); // phpcs:ignore WordPress.DB
		if ( $count ) {
			$current           = ! empty( $_GET['pnr_robot'] ); // phpcs:ignore WordPress.Security.NonceVerification
			$views['pnr_robot'] = sprintf(
				'<a href="%s"%s>خبرهای ربات <span class="count">(%s)</span></a>',
				esc_url( admin_url( 'edit.php?post_type=post&pnr_robot=1' ) ),
				$current ? ' class="current" aria-current="page"' : '',
				esc_html( number_format_i18n( $count ) )
			);
		}
		return $views;
	}

	public static function filter_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || empty( $_GET['pnr_robot'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'     => Items::META,
			'compare' => 'EXISTS',
		);
		$query->set( 'meta_query', $meta );
	}
}
