<?php
/**
 * Keyword rules: skip a story, add a category, or force a homepage section.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Taxonomy;

use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Rules {

	/**
	 * @return array{skip: string, categories: int[], sections: int[]} skip = the matched keyword, or ''.
	 */
	public static function evaluate( $title, $text = '' ) {
		$out   = array(
			'skip'       => '',
			'categories' => array(),
			'sections'   => array(),
		);
		$title = Util::normalize_fa( $title );
		$all   = $title . ' ' . Util::normalize_fa( $text );

		foreach ( self::all() as $rule ) {
			$haystack = 'title' === $rule['field'] ? $title : $all;
			$match    = '';
			foreach ( Util::lines( $rule['keywords'] ) as $keyword ) {
				$keyword = Util::normalize_fa( $keyword );
				if ( '' !== $keyword && false !== mb_strpos( $haystack, $keyword ) ) {
					$match = $keyword;
					break;
				}
			}
			if ( '' === $match ) {
				continue;
			}
			if ( 'skip' === $rule['action'] ) {
				$out['skip'] = $match;
			} elseif ( 'category' === $rule['action'] && $rule['term'] ) {
				$out['categories'][] = (int) $rule['term'];
			} elseif ( 'section' === $rule['action'] && $rule['term'] ) {
				$out['sections'][] = (int) $rule['term'];
			}
		}
		$out['categories'] = array_values( array_unique( $out['categories'] ) );
		$out['sections']   = array_values( array_unique( $out['sections'] ) );
		return $out;
	}

	/**
	 * @return array[] Sanitised rules.
	 */
	public static function all() {
		$rules = array();
		foreach ( (array) Settings::get( 'rules' ) as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['keywords'] ) ) {
				continue;
			}
			$rules[] = array(
				'keywords' => (string) $rule['keywords'],
				'field'    => isset( $rule['field'] ) && 'all' === $rule['field'] ? 'all' : 'title',
				'action'   => isset( $rule['action'] ) && in_array( $rule['action'], array( 'skip', 'category', 'section' ), true ) ? $rule['action'] : 'skip',
				'term'     => isset( $rule['term'] ) ? (int) $rule['term'] : 0,
			);
		}
		return $rules;
	}

	/**
	 * Sanitises rules posted from the admin.
	 */
	public static function from_input( $input ) {
		$rules = array();
		foreach ( (array) $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$keywords = sanitize_textarea_field( isset( $row['keywords'] ) ? wp_unslash( $row['keywords'] ) : '' );
			if ( '' === trim( $keywords ) ) {
				continue;
			}
			$action  = isset( $row['action'] ) ? sanitize_key( $row['action'] ) : 'skip';
			$rules[] = array(
				'keywords' => $keywords,
				'field'    => isset( $row['field'] ) && 'all' === $row['field'] ? 'all' : 'title',
				'action'   => in_array( $action, array( 'skip', 'category', 'section' ), true ) ? $action : 'skip',
				'term'     => isset( $row['term'] ) ? absint( $row['term'] ) : 0,
			);
		}
		return $rules;
	}
}
