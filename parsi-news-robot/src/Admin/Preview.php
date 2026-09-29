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
	public static function run( array $cfg, $limit = 3 ) {
		if ( empty( $cfg['url'] ) ) {
			return new \WP_Error( 'pnr_no_url', 'آدرس RSS را وارد کنید.' );
		}
		$feed = FeedReader::read( $cfg['url'] );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		$report = array(
			'feed_title' => $feed['title'],
			'feed_home'  => $feed['home'],
			'count'      => count( $feed['items'] ),
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
			$row['content']     = $built['content'];
			$seen               = \ParsiNewsRobot\Data\Seen::by_hash( Dedupe::item_hash( $item['link'], $item['guid'], $item['title'], count( wp_list_pluck( $feed['items'], 'link' ) ) !== count( array_unique( wp_list_pluck( $feed['items'], 'link' ) ) ) ) );
			$row['already']     = $seen ? $seen->status : '';
			$row['duplicate']   = ! $seen && Dedupe::find_similar( $item['title'] );
			$report['items'][]  = $row;
		}
		return $report;
	}
}
