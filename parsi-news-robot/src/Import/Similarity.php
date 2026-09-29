<?php
/**
 * Body-text similarity between news stories.
 *
 * Agencies often republish each other's stories ("به نقل از …") with a new title, an added first line and
 * small edits. Titles then differ, but most of the text is the same. Each text is reduced to a compact
 * fingerprint: the set of its two-word phrases (shingles), hashed, of which only the K smallest hashes are
 * kept (a "bottom-k sketch"). From two sketches we estimate how much of the shorter text is contained in the
 * longer one; a copy with an extra intro still scores high, two different stories on the same topic do not.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Import;

use ParsiNewsRobot\Data\Items;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Similarity {

	/** Hashes kept per text. */
	const K = 128;

	/**
	 * Words per phrase. Two-word phrases tolerate light editing: in tests on real articles a copy with 10% of
	 * its words changed scored ~84%, while different stories stayed below ~35% (99th percentile).
	 */
	const SHINGLE = 2;

	/** Format version of stored sketches; sketches of another version are ignored. */
	const VERSION = 'b2';

	/** Texts shorter than this are not compared (the title check covers them). */
	const MIN_WORDS = 40;

	/** Post meta holding the sketch of the site's own posts. */
	const META = '_pnr_sketch';

	/**
	 * Words of a text, normalised (Persian letters unified, punctuation dropped). Stop words are kept:
	 * word order inside phrases is what makes a copy recognisable.
	 *
	 * @return string[]
	 */
	public static function words( $text ) {
		$text = Util::normalize_fa( Util::text( $text ) );
		$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		return preg_split( '/\s+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * @return array{n: int, h: int[]}|null n = number of distinct phrases, h = the K smallest phrase hashes.
	 */
	public static function sketch( $text ) {
		$words = self::words( $text );
		$count = count( $words );
		if ( $count < self::MIN_WORDS ) {
			return null;
		}
		$hashes = array();
		for ( $i = 0; $i <= $count - self::SHINGLE; $i++ ) {
			$hashes[ crc32( implode( ' ', array_slice( $words, $i, self::SHINGLE ) ) ) ] = true;
		}
		$hashes = array_keys( $hashes );
		sort( $hashes, SORT_NUMERIC );
		return array(
			'n' => count( $hashes ),
			'h' => array_slice( $hashes, 0, self::K ),
		);
	}

	/**
	 * Compact storage form: "version|n:base64(uint32 list)".
	 */
	public static function encode( $sketch ) {
		if ( ! $sketch ) {
			return '';
		}
		return self::VERSION . '|' . $sketch['n'] . ':' . base64_encode( pack( 'N*', ...$sketch['h'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	public static function decode( $stored ) {
		$stored = (string) $stored;
		$prefix = self::VERSION . '|';
		if ( 0 !== strpos( $stored, $prefix ) ) {
			return null;
		}
		$stored = substr( $stored, strlen( $prefix ) );
		$pos    = strpos( $stored, ':' );
		if ( false === $pos ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, $pos + 1 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $raw || '' === $raw || 0 !== strlen( $raw ) % 4 ) {
			return null;
		}
		return array(
			'n' => (int) substr( $stored, 0, $pos ),
			'h' => array_values( unpack( 'N*', $raw ) ),
		);
	}

	/**
	 * Share (0..1) of the shorter text found in the longer one.
	 */
	public static function score( array $a, array $b ) {
		if ( ! $a['n'] || ! $b['n'] ) {
			return 0.0;
		}
		$in_a = array_flip( $a['h'] );
		$in_b = array_flip( $b['h'] );

		// The K smallest hashes of the union; each one is in A (or B) exactly when it is in A's (B's) sketch.
		$union = array_keys( $in_a + $in_b );
		sort( $union, SORT_NUMERIC );
		$union = array_slice( $union, 0, self::K );
		$both  = 0;
		foreach ( $union as $h ) {
			if ( isset( $in_a[ $h ], $in_b[ $h ] ) ) {
				$both++;
			}
		}
		$jaccard = $both / count( $union );
		if ( $jaccard <= 0 ) {
			return 0.0;
		}
		// |A∩B| from Jaccard and the set sizes, then relative to the shorter text.
		$intersection = $jaccard * ( $a['n'] + $b['n'] ) / ( 1 + $jaccard );
		return min( 1.0, $intersection / min( $a['n'], $b['n'] ) );
	}

	/**
	 * The most similar recent story above the threshold, or null.
	 *
	 * @param int $exclude_post Post to ignore (the one being updated).
	 * @return array{post_id: int, score: float, own: bool}|null own = a post written on the site itself.
	 */
	public static function find( $text, $exclude_post = 0 ) {
		$s = Settings::get();
		if ( empty( $s['content_dup'] ) ) {
			return null;
		}
		$sketch = self::sketch( $text );
		if ( ! $sketch ) {
			return null;
		}
		$threshold = max( 50, min( 100, (int) $s['content_dup_threshold'] ) ) / 100;
		$since     = time() - max( 1, (int) $s['dup_hours'] ) * HOUR_IN_SECONDS;
		$best      = null;

		foreach ( self::candidates( $since, ! empty( $s['content_dup_own'] ) ) as $candidate ) {
			if ( (int) $candidate['post_id'] === (int) $exclude_post ) {
				continue;
			}
			$other = self::decode( $candidate['sketch'] );
			if ( ! $other ) {
				continue;
			}
			$score = self::score( $sketch, $other );
			if ( $score >= $threshold && ( ! $best || $score > $best['score'] ) ) {
				$best = array(
					'post_id' => (int) $candidate['post_id'],
					'score'   => $score,
					'own'     => $candidate['own'],
				);
			}
		}
		return $best;
	}

	/**
	 * Robot posts of the window, plus (optionally) the site's own published posts.
	 *
	 * @return array[] {post_id, sketch, own}
	 */
	private static function candidates( $since, $own ) {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, sketch FROM ' . Items::table() . " WHERE imported_at >= %d AND sketch <> '' ORDER BY imported_at DESC LIMIT 5000", (int) $since ) ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $row ) {
			$out[] = array(
				'post_id' => (int) $row->post_id,
				'sketch'  => $row->sketch,
				'own'     => false,
			);
		}
		if ( $own ) {
			$posts = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT p.ID, m.meta_value FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
					WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.post_date_gmt >= %s LIMIT 2000",
					self::META,
					gmdate( 'Y-m-d H:i:s', $since )
				)
			);
			foreach ( $posts as $row ) {
				$out[] = array(
					'post_id' => (int) $row->ID,
					'sketch'  => $row->meta_value,
					'own'     => true,
				);
			}
		}
		return $out;
	}

	/**
	 * Keeps the sketch of the site's own posts up to date (robot posts keep theirs in the items table).
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status || Items::get( $post_id ) ) {
			return;
		}
		$encoded = self::encode( self::sketch( $post->post_title . ' ' . $post->post_content ) );
		if ( $encoded ) {
			update_post_meta( $post_id, self::META, $encoded );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/**
	 * Fills sketches for posts that existed before this feature (recent window only).
	 */
	public static function backfill() {
		global $wpdb;
		$since = time() - 2 * max( 1, (int) Settings::get( 'dup_hours' ) ) * HOUR_IN_SECONDS;
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM ' . Items::table() . " WHERE imported_at >= %d AND sketch NOT LIKE %s LIMIT 1000", $since, $wpdb->esc_like( self::VERSION . '|' ) . '%' ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post ) {
				Items::update( (int) $id, array( 'sketch' => self::encode( self::sketch( $post->post_title . ' ' . $post->post_content ) ) ) );
			}
		}
		$own = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'date_query'     => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $since ), 'column' => 'post_date_gmt' ) ),
				'posts_per_page' => 500,
				'meta_query'     => array( array( 'key' => Items::META, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $own as $post ) {
			self::on_save_post( $post->ID, $post );
		}
	}
}
