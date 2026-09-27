<?php
/**
 * Decides the categories of one robot post from the roles the admin gave to the site's own categories:
 *
 *   main category (per source) + fixed extras + rule categories
 *   + video categories (if the story has a video) + photo categories (if it has extra photos)
 *   + homepage sections (random, per mode, respecting each section's quota).
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Taxonomy;

use ParsiNewsRobot\Data\Placements;
use ParsiNewsRobot\Settings;

defined( 'ABSPATH' ) || exit;

class Categorizer {

	const ROLES = array( 'topic', 'section', 'video', 'photo' );

	/**
	 * @param array $rules Result of Rules::evaluate().
	 * @return array{ids: int[], primary: int, sections: int[], reasons: array<int, string>}
	 */
	public static function assign( array $cfg, $has_video, $image_count, array $rules = array() ) {
		$ids      = array();
		$reasons  = array();
		$sections = array();

		$add = function ( $term_id, $reason ) use ( &$ids, &$reasons ) {
			$term_id = (int) $term_id;
			if ( $term_id > 0 && ! in_array( $term_id, $ids, true ) && term_exists( $term_id, 'category' ) ) {
				$ids[]               = $term_id;
				$reasons[ $term_id ] = $reason;
				return true;
			}
			return false;
		};

		$add( $cfg['main_category'], 'اصلی' );
		foreach ( (array) $cfg['extra_categories'] as $term_id ) {
			$add( $term_id, 'ثابت' );
		}
		foreach ( isset( $rules['categories'] ) ? $rules['categories'] : array() as $term_id ) {
			$add( $term_id, 'قانون' );
		}
		if ( $has_video && $cfg['use_video'] ) {
			foreach ( Settings::terms_with_role( 'video' ) as $term_id ) {
				$add( $term_id, 'ویدئو' );
			}
		}
		if ( $cfg['use_photo'] && $image_count >= max( 1, (int) Settings::get( 'photo_min_images' ) ) ) {
			foreach ( Settings::terms_with_role( 'photo' ) as $term_id ) {
				$add( $term_id, 'عکس' );
			}
		}

		$roles  = Settings::roles();
		$random = Settings::random( $cfg );

		// Sections forced by rules (still subject to quota).
		foreach ( isset( $rules['sections'] ) ? $rules['sections'] : array() as $term_id ) {
			if ( isset( $roles[ $term_id ] ) && 'section' === $roles[ $term_id ]['role'] && self::has_quota( $roles[ $term_id ] + array( 'term_id' => $term_id ) ) && $add( $term_id, 'قانون (بخش)' ) ) {
				$sections[] = $term_id;
			}
		}

		// Random sections.
		$eligible = array();
		foreach ( $random['sections'] as $term_id ) {
			if ( ! isset( $roles[ $term_id ] ) || in_array( $term_id, $ids, true ) ) {
				continue;
			}
			$role = $roles[ $term_id ];
			if ( (int) $role['chance'] > 0 && self::has_quota( $role + array( 'term_id' => $term_id ) ) ) {
				$eligible[ $term_id ] = max( 1, min( 100, (int) $role['chance'] ) );
			}
		}

		$skip_random = $random['none'] > 0 && wp_rand( 1, 100 ) <= $random['none'];
		if ( $eligible && ! $skip_random ) {
			if ( 'one' === $random['mode'] ) {
				$picked = self::weighted_pick( $eligible );
				if ( $picked && $add( $picked, 'تصادفی' ) ) {
					$sections[] = $picked;
				}
			} else {
				$keys = array_keys( $eligible );
				shuffle( $keys );
				$count = 0;
				foreach ( $keys as $term_id ) {
					if ( $random['max'] > 0 && $count >= $random['max'] ) {
						break;
					}
					if ( wp_rand( 1, 100 ) <= $eligible[ $term_id ] && $add( $term_id, 'تصادفی' ) ) {
						$sections[] = $term_id;
						$count++;
					}
				}
			}
		}

		if ( ! $ids ) {
			$add( (int) get_option( 'default_category' ), 'پیش‌فرض وردپرس' );
		}

		return array(
			'ids'      => $ids,
			'primary'  => $ids ? $ids[0] : 0,
			'sections' => $sections,
			'reasons'  => $reasons,
		);
	}

	/**
	 * Whether a section can take one more robot post.
	 */
	public static function has_quota( array $role ) {
		if ( 'off' === $role['quota'] ) {
			return false;
		}
		if ( 'limit' !== $role['quota'] ) {
			return true;
		}
		$n = max( 0, (int) $role['quota_n'] );
		if ( 0 === $n ) {
			return false;
		}
		$since = time() - max( 1, (int) $role['quota_hours'] ) * HOUR_IN_SECONDS;
		return Placements::count_since( $role['term_id'], $since ) < $n;
	}

	/**
	 * Remaining places in a section's current quota window (null = unlimited).
	 */
	public static function quota_left( $term_id, array $role ) {
		if ( 'off' === $role['quota'] ) {
			return 0;
		}
		if ( 'limit' !== $role['quota'] ) {
			return null;
		}
		$since = time() - max( 1, (int) $role['quota_hours'] ) * HOUR_IN_SECONDS;
		return max( 0, (int) $role['quota_n'] - Placements::count_since( $term_id, $since ) );
	}

	private static function weighted_pick( array $weights ) {
		$total = array_sum( $weights );
		if ( $total <= 0 ) {
			return 0;
		}
		$roll = wp_rand( 1, $total );
		foreach ( $weights as $term_id => $weight ) {
			$roll -= $weight;
			if ( $roll <= 0 ) {
				return (int) $term_id;
			}
		}
		return (int) array_key_last( $weights );
	}

	/**
	 * Sanitises the roles table posted from the admin.
	 *
	 * @return array<int, array>
	 */
	public static function roles_from_input( $input ) {
		$roles = array();
		foreach ( (array) $input as $term_id => $row ) {
			$term_id = absint( $term_id );
			$role    = isset( $row['role'] ) ? sanitize_key( $row['role'] ) : '';
			if ( ! $term_id || ! in_array( $role, self::ROLES, true ) || ! term_exists( $term_id, 'category' ) ) {
				continue;
			}
			$quota             = isset( $row['quota'] ) ? sanitize_key( $row['quota'] ) : 'unlimited';
			$roles[ $term_id ] = array(
				'role'        => $role,
				'chance'      => isset( $row['chance'] ) ? max( 0, min( 100, absint( $row['chance'] ) ) ) : 30,
				'quota'       => in_array( $quota, array( 'unlimited', 'off', 'limit' ), true ) ? $quota : 'unlimited',
				'quota_n'     => isset( $row['quota_n'] ) ? absint( $row['quota_n'] ) : 5,
				'quota_hours' => isset( $row['quota_hours'] ) ? max( 1, absint( $row['quota_hours'] ) ) : 24,
			);
		}
		return $roles;
	}

	public static function role_label( $role ) {
		$labels = array(
			''        => 'بدون نقش',
			'topic'   => 'موضوعی (دسته اصلی)',
			'section' => 'بخش صفحه اصلی',
			'video'   => 'ویدئو',
			'photo'   => 'عکس',
		);
		return isset( $labels[ $role ] ) ? $labels[ $role ] : $role;
	}
}
