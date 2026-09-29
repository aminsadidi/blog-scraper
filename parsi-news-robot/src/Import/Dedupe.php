<?php
/**
 * Duplicate detection: stable item hashes and "same story, other outlet" title similarity.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Import;

use ParsiNewsRobot\Data\Seen;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Dedupe {

	const STOPWORDS = array( 'و', 'در', 'به', 'از', 'که', 'این', 'آن', 'با', 'را', 'برای', 'تا', 'بر', 'یک', 'هم', 'نیز', 'است', 'شد', 'شده', 'می', 'های', 'ها', 'اینکه', 'یا', 'اما', 'پس', 'چه', 'خود', 'باید', 'کرد', 'کند', 'دارد', 'بود', 'the', 'a', 'an', 'of', 'to', 'in', 'on', 'and', 'for', 'is' );

	/**
	 * Hash that identifies a feed entry across sources (link without tracking parameters, else guid).
	 */
	/**
	 * @param bool $shared_link The feed gives several entries the same link (broken feeds that point every
	 *                          item to the home page): then guid + title identify the entry instead.
	 */
	public static function item_hash( $link, $guid, $title = '', $shared_link = false ) {
		$link = self::canonical_link( $link );
		if ( ! $link ) {
			return md5( 'guid:' . (string) $guid );
		}
		if ( $shared_link ) {
			return md5( 'guid:' . (string) $guid . '|' . self::title_norm( $title ) . '|' . $link );
		}
		return md5( $link );
	}

	public static function canonical_link( $link ) {
		$link = trim( (string) $link );
		if ( '' === $link ) {
			return '';
		}
		$parts = wp_parse_url( $link );
		if ( empty( $parts['host'] ) ) {
			return $link;
		}
		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $args );
			foreach ( array_keys( $args ) as $key ) {
				if ( preg_match( '/^(utm_|fbclid|gclid|ref$|from$|source$)/i', $key ) ) {
					unset( $args[ $key ] );
				}
			}
			ksort( $args );
			$query = $args ? '?' . http_build_query( $args ) : '';
		}
		$host = preg_replace( '/^www\./i', '', strtolower( $parts['host'] ) );
		$path = isset( $parts['path'] ) ? rtrim( rawurldecode( $parts['path'] ), '/' ) : '';
		return $host . $path . $query;
	}

	/**
	 * Normalised title words (Persian letters unified, punctuation and stop words removed).
	 *
	 * @return string[]
	 */
	public static function words( $title ) {
		$text  = Util::normalize_fa( $title );
		$text  = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		$words = preg_split( '/\s+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_diff( $words, self::STOPWORDS ) );
	}

	public static function title_norm( $title ) {
		return implode( ' ', self::words( $title ) );
	}

	public static function title_hash( $title ) {
		$words = array_unique( self::words( $title ) );
		sort( $words );
		return md5( implode( ' ', $words ) );
	}

	/**
	 * Dice coefficient of two word lists (0..1).
	 */
	public static function similarity( array $a, array $b ) {
		$a = array_unique( $a );
		$b = array_unique( $b );
		if ( ! $a || ! $b ) {
			return 0.0;
		}
		return 2 * count( array_intersect( $a, $b ) ) / ( count( $a ) + count( $b ) );
	}

	/**
	 * A recent entry whose title is the same story, or null.
	 *
	 * @return object|null {id, status, post_id, score}
	 */
	public static function find_similar( $title, $exclude_id = 0 ) {
		if ( ! Settings::get( 'dup_titles' ) ) {
			return null;
		}
		$since = time() - max( 1, (int) Settings::get( 'dup_hours' ) ) * HOUR_IN_SECONDS;
		// Short recurring titles ("عکس روز", "کاریکاتور") are different stories every day.
		if ( count( array_unique( self::words( $title ) ) ) < 3 ) {
			return null;
		}
		$exact = Seen::by_title_hash( self::title_hash( $title ), $since, $exclude_id );
		if ( $exact ) {
			$exact->score = 1.0;
			return $exact;
		}

		$words = self::words( $title );
		if ( count( $words ) < 4 ) {
			return null;
		}
		$threshold = max( 50, min( 100, (int) Settings::get( 'dup_threshold' ) ) ) / 100;
		foreach ( Seen::recent_titles( $since ) as $row ) {
			if ( (int) $row->id === (int) $exclude_id ) {
				continue;
			}
			$other = preg_split( '/\s+/u', (string) $row->title_norm, -1, PREG_SPLIT_NO_EMPTY );
			if ( count( $other ) < 4 ) {
				continue;
			}
			$score = self::similarity( $words, $other );
			if ( $score >= $threshold ) {
				$row->score = $score;
				return $row;
			}
		}
		return null;
	}
}
