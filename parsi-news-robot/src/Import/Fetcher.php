<?php
/**
 * One source fetch: read the feed, decide which entries are new, and queue them (with drip slots).
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Import;

use ParsiNewsRobot\Data\Items;
use ParsiNewsRobot\Data\Log;
use ParsiNewsRobot\Data\Seen;
use ParsiNewsRobot\Queue;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Sources;
use ParsiNewsRobot\Support\Util;
use ParsiNewsRobot\Taxonomy\Rules;

defined( 'ABSPATH' ) || exit;

class Fetcher {

	/**
	 * @return array{queued: int, skipped: int, updated: int, error: string}
	 */
	public static function run( $source_id ) {
		$source_id = (int) $source_id;
		$result    = array(
			'queued'  => 0,
			'skipped' => 0,
			'updated' => 0,
			'error'   => '',
		);
		if ( ! Sources::exists( $source_id ) ) {
			return $result;
		}
		$cfg   = Sources::config( $source_id );
		$state = Sources::state( $source_id );
		if ( ! $cfg['url'] ) {
			return $result;
		}

		$now  = time();
		$feed = FeedReader::read( $cfg['url'], $state['etag'], $state['last_modified'] );

		if ( is_wp_error( $feed ) ) {
			$errors  = (int) $state['error_runs'] + 1;
			$backoff = min( 360, max( 1, (int) $cfg['interval'] ) * pow( 2, min( $errors, 6 ) ) );
			Sources::set_state(
				$source_id,
				array(
					'last_run'     => $now,
					'next_run'     => $now + (int) $backoff * MINUTE_IN_SECONDS,
					'last_status'  => 'error',
					'last_message' => $feed->get_error_message(),
					'error_runs'   => $errors,
				)
			);
			Log::error( sprintf( 'خطا در دریافت فید (بار %1$d): %2$s', $errors, $feed->get_error_message() ), $source_id );
			$result['error'] = $feed->get_error_message();
			return $result;
		}

		$state_update = array(
			'last_run'      => $now,
			'etag'          => $feed['etag'],
			'last_modified' => $feed['last_modified'],
			'error_runs'    => 0,
			'last_status'   => 'ok',
		);
		if ( $feed['title'] ) {
			$state_update['feed_title'] = $feed['title'];
		}
		if ( $feed['home'] ) {
			$state_update['feed_home'] = $feed['home'];
		}

		$candidates = array();
		$deferred   = false;
		if ( ! $feed['not_modified'] ) {
			// "0 = no limit" still stops at 45 days: the seen table forgets entries after 60 days, and an older
			// entry still sitting in a feed must not be imported a second time.
			$max_age = $now - ( (int) $cfg['max_age_hours'] > 0 ? (int) $cfg['max_age_hours'] * HOUR_IN_SECONDS : 45 * DAY_IN_SECONDS );
			$link_count = array_count_values( array_filter( wp_list_pluck( $feed['items'], 'link' ) ) );
			foreach ( $feed['items'] as $item ) {
				$shared   = $item['link'] && $link_count[ $item['link'] ] > 1;
				$hash     = Dedupe::item_hash( $item['link'], $item['guid'], $item['title'], $shared );
				$sig      = self::signature( $item );
				$existing = Seen::by_hash( $hash );

				if ( $existing ) {
					if ( self::should_update( $existing, $sig, $cfg ) ) {
						Seen::update(
							$existing->id,
							array(
								'payload'  => $item,
								'feed_sig' => $sig,
							)
						);
						Queue::schedule_update( $existing->id );
						$result['updated']++;
					}
					continue;
				}
				if ( count( $candidates ) >= max( 1, (int) $cfg['max_items'] ) ) {
					$deferred = true;
					continue;
				}

				$base = array(
					'source_id'  => $source_id,
					'item_hash'  => $hash,
					'title_norm' => Dedupe::title_norm( $item['title'] ),
					'title_hash' => Dedupe::title_hash( $item['title'] ),
					'link'       => $item['link'],
					'feed_sig'   => $sig,
				);

				$reason = self::skip_reason( $item, $cfg, $max_age );
				if ( $reason ) {
					Seen::insert( $base + array( 'status' => 'skipped', 'note' => $reason ) );
					$result['skipped']++;
					continue;
				}

				$similar = Dedupe::find_similar( $item['title'], 0, $cfg );
				if ( $similar ) {
					Seen::insert(
						$base + array(
							'status'  => 'duplicate',
							'post_id' => (int) $similar->post_id,
							'note'    => sprintf( 'مشابه خبر قبلی (%d%%)', round( $similar->score * 100 ) ),
						)
					);
					$result['skipped']++;
					continue;
				}

				$candidates[] = array(
					'base' => $base,
					'item' => $item,
				);
			}
		}

		if ( $deferred ) {
			// New entries are still waiting (max items per check); the next check must read the whole feed
			// even if it has not changed, so do not send the cache validators.
			$state_update['etag']          = '';
			$state_update['last_modified'] = '';
		}

		// Oldest first, so with drip publishing the newest story ends up on top.
		$candidates = array_reverse( $candidates );
		$gap        = Settings::drip_minutes( $cfg ) * MINUTE_IN_SECONDS;
		$slot       = $gap ? max( $now, (int) $state['last_slot'] + $gap ) : $now;
		foreach ( $candidates as $candidate ) {
			$seen_id = Seen::insert(
				$candidate['base'] + array(
					'status'       => 'queued',
					'payload'      => $candidate['item'],
					'scheduled_at' => $slot,
				)
			);
			if ( $seen_id ) {
				Queue::schedule_import( $seen_id, $slot );
				$result['queued']++;
				$state_update['last_slot'] = $slot;
				$slot                     += $gap;
			}
		}

		$empty                        = $result['queued'] ? 0 : (int) $state['empty_runs'] + 1;
		$factor                       = Settings::get( 'adaptive' ) ? min( 4, 1 + 0.5 * $empty ) : 1;
		$state_update['empty_runs']   = $empty;
		$state_update['next_run']     = $now + (int) round( max( 1, (int) $cfg['interval'] ) * $factor ) * MINUTE_IN_SECONDS;
		$state_update['last_message'] = $feed['not_modified']
			? 'فید تغییری نکرده بود.'
			: sprintf( '%1$d خبر جدید در صف، %2$d رد شده، %3$d به‌روزرسانی.', $result['queued'], $result['skipped'], $result['updated'] );
		Sources::set_state( $source_id, $state_update );

		if ( $result['queued'] || $result['updated'] ) {
			Log::info( $state_update['last_message'], $source_id );
		}
		return $result;
	}

	/**
	 * A change marker for a feed entry (title, date and text), used for update tracking.
	 */
	public static function signature( array $item ) {
		return md5( $item['title'] . '|' . $item['updated'] . '|' . Util::text( $item['content'] ? $item['content'] : $item['description'] ) );
	}

	private static function should_update( $existing, $sig, array $cfg ) {
		$hours = (int) $cfg['track_updates_hours'];
		if ( $hours <= 0 || 'imported' !== $existing->status || ! $existing->post_id || $existing->feed_sig === $sig ) {
			return false;
		}
		$item = Items::get( $existing->post_id );
		return $item && (int) $item->imported_at >= time() - $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Reasons decidable from the feed entry alone (age, keyword filters, skip rules).
	 */
	private static function skip_reason( array $item, array $cfg, $max_age ) {
		if ( $max_age && $item['date'] && $item['date'] < $max_age ) {
			return 'قدیمی‌تر از حد مجاز';
		}
		$text = Util::normalize_fa( $item['title'] . ' ' . Util::text( $item['description'] ) );

		$include = array_map( array( Util::class, 'normalize_fa' ), Util::lines( $cfg['include_keywords'] ) );
		if ( $include ) {
			$hit = false;
			foreach ( $include as $word ) {
				if ( '' !== $word && false !== mb_strpos( $text, $word ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				return 'کلمه کلیدی لازم را ندارد';
			}
		}
		foreach ( array_map( array( Util::class, 'normalize_fa' ), Util::lines( $cfg['exclude_keywords'] ) ) as $word ) {
			if ( '' !== $word && false !== mb_strpos( $text, $word ) ) {
				return 'کلمه ممنوع: ' . $word;
			}
		}
		$rules = Rules::evaluate( $item['title'], Util::text( $item['description'] ) );
		if ( $rules['skip'] ) {
			return 'قانون حذف: ' . $rules['skip'];
		}
		return '';
	}
}
