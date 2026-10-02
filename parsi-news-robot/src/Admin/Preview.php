<?php
/**
 * "Test source": runs the whole pipeline on the first entries of a feed without saving anything.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Admin;

use ParsiNewsRobot\Import\Builder;
use ParsiNewsRobot\Import\Dedupe;
use ParsiNewsRobot\Import\FeedReader;
use ParsiNewsRobot\Taxonomy\Categorizer;

defined( 'ABSPATH' ) || exit;

class Preview {

	/**
	 * @return array|\WP_Error
	 */
	/**
	 * @param string $name The source's name as typed in the editor (used for the attribution preview).
	 */
	public static function run( array $cfg, $limit = 3, $name = '' ) {
		if ( empty( $cfg['url'] ) ) {
			return new \WP_Error( 'pnr_no_url', 'آدرس منبع را وارد کنید.' );
		}
		$feed = FeedReader::fetch( $cfg );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		$report = array(
			'feed_title' => $feed['title'],
			'feed_home'  => $feed['home'],
			'count'      => count( $feed['items'] ),
			'type'       => isset( $cfg['source_type'] ) ? $cfg['source_type'] : 'rss',
			'items'      => array(),
		);

		$started = time();
		foreach ( array_slice( $feed['items'], 0, max( 1, min( 5, (int) $limit ) ) ) as $item ) {
			// Stay inside the host's PHP time limit (often 30 s) even when the source is slow.
			if ( $report['items'] && time() - $started > 20 ) {
				break;
			}
			$row   = array(
				'title' => $item['title'],
				'link'  => $item['link'],
				'date'  => $item['date'],
			);
			$built = Builder::build( $item, $cfg );
			if ( is_wp_error( $built ) ) {
				$row['error']    = $built->get_error_message();
				$report['items'][] = $row;
				continue;
			}
			$cats  = Categorizer::assign( $cfg, $built['has_video'], $built['image_count'], $built['rules'] );
			$names = array();
			foreach ( $cats['ids'] as $term_id ) {
				$term    = get_term( $term_id, 'category' );
				$names[] = ( $term && ! is_wp_error( $term ) ? $term->name : '#' . $term_id ) . ' (' . $cats['reasons'][ $term_id ] . ')';
			}
			$row['method']      = $built['method'];
			$row['words']       = $built['words'];
			$row['image_count'] = $built['image_count'];
			$row['has_video']   = $built['has_video'];
			$row['featured']    = $built['featured'];
			$row['categories']  = $names;
			$row['text']        = $built['text'];
			$row['title']       = $built['title']; // After title cleanup / replacements.
			$attr               = \ParsiNewsRobot\Settings::attribution( $cfg );
			$row['attribution'] = $attr['enabled'] ? \ParsiNewsRobot\Seo\Links::apply_rel(
				\ParsiNewsRobot\Seo\Links::build_attribution( $attr, $cfg['source_name'] ? $cfg['source_name'] : ( $name ? $name : ( $feed['title'] ? $feed['title'] : wp_parse_url( $item['link'], PHP_URL_HOST ) ) ), $item['link'], $built['title'], wp_date( get_option( 'date_format' ), $item['date'] ? $item['date'] : time() ) ),
				\ParsiNewsRobot\Settings::links_mode( $cfg ),
				$attr['rel'],
				(bool) \ParsiNewsRobot\Settings::get( 'links_new_tab' ),
				$attr['new_tab']
			) : '';
			$row['attr_position'] = $attr['position'];
			$row['content']     = $built['content'];
			$seen               = \ParsiNewsRobot\Data\Seen::by_hash( Dedupe::item_hash( $item['link'], $item['guid'], $item['title'], count( wp_list_pluck( $feed['items'], 'link' ) ) !== count( array_unique( wp_list_pluck( $feed['items'], 'link' ) ) ) ) );
			$row['already']     = $seen ? $seen->status : '';
			$row['duplicate']   = ! $seen && Dedupe::find_similar( $item['title'], 0, $cfg );
			$copy               = $seen ? null : \ParsiNewsRobot\Import\Similarity::find( $built['title'] . ' ' . $built['text'], 0, $cfg );
			$row['copy']        = $copy ? sprintf( 'متن این خبر %1$d٪ شبیه %2$s «%3$s» است و منتشر نمی‌شود.', round( $copy['score'] * 100 ), $copy['own'] ? 'نوشته خود سایت' : 'خبر منتشرشده', get_post_field( 'post_title', $copy['post_id'] ) ) : '';
			$report['items'][]  = $row;
		}
		return $report;
	}
}
