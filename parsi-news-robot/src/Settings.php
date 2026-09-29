<?php
/**
 * Global settings, category roles and per-source configuration.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot;

use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'pnr_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			// Category roles: term_id => [role, chance, quota, quota_n, quota_hours].
			'roles'              => array(),
			'random_mode'        => 'independent', // one | independent.
			'random_none'        => 0,             // % of posts that get no homepage section.
			'random_max'         => 1,             // cap for independent mode (0 = no cap).
			'photo_min_images'   => 1,

			// Keyword rules: [keywords, field (title|all), action (skip|category|section), term].
			'rules'              => array(),

			// SEO and links.
			'noindex'            => 1,
			'exclude_site_feed'  => 1,
			'noindex_categories' => array(),
			'links_mode'         => 'nofollow', // nofollow | follow | strip.
			'links_new_tab'      => 1,

			// Attribution.
			'attr_enabled'       => 1,
			'attr_template'      => 'به نقل از {source}',
			'attr_position'      => 'end',     // start | end.
			'attr_link'          => 'article', // article | home | none.
			'attr_rel'           => 'nofollow', // nofollow | follow | sponsored.
			'attr_new_tab'       => 1,
			'attr_style'         => 'plain', // plain | bold | small | box.

			// Media.
			'image_mode'         => 'hybrid', // local | hotlink | hybrid.
			'max_images'         => 15,

			// Publishing.
			'author'             => 0,
			'post_status'        => 'publish',
			'date_mode'          => 'source', // source | import.
			'comment_status'     => 'default', // default | open | closed.
			'drip_minutes'       => 0,
			'dup_titles'         => 1,
			'dup_threshold'      => 75,
			'dup_hours'          => 48,
			'replacements'       => '', // "old => new" per line, applied to every story.
			'content_dup'           => 1,  // Skip stories whose text is mostly the same as a recent one.
			'content_dup_threshold' => 80,
			'content_dup_own'       => 1,  // Also compare with the site's own posts.
			'signatures'         => "انتهای پیام\nکد خبر\nلینک کوتاه\nبیشتر بخوانید\nمطالب مرتبط\nکانال ما\nعضو کانال\nبه کانال تلگرام\nما را در\nکپی برداری\nکپی‌برداری\nبازنشر این مطلب\nمنبع تصویر\nزمان مطالعه\nبه روز شده در\nمنتشر شده در\nاشتراک گذاری\nاشتراک‌گذاری",

			// Auto delete.
			'delete_after_days'  => 0,
			'delete_attachments' => 1,
			'protect_edited'     => 1,
			'redirect_mode'      => 'category', // category | home | gone | none.
			'redirect_keep_days' => 0,

			// Advanced.
			'adaptive'           => 1,
			'user_agent'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
			'timeout'            => 20,
			'iframe_hosts'       => '',
			'manager_roles'      => array(),
			'log_days'           => 30,
		);
	}

	public static function source_defaults() {
		return array(
			'url'                 => '',
			'active'              => 1,
			'interval'            => 15,
			'max_items'           => 5,
			'max_age_hours'       => 24,
			'hours_from'          => '',
			'hours_to'            => '',
			'drip_minutes'        => -1,

			'main_category'       => 0,
			'extra_categories'    => array(),
			'random_scope'        => 'inherit', // inherit | custom | off.
			'random_sections'     => array(),
			'random_mode'         => 'inherit', // inherit | one | independent.
			'random_none'         => -1,
			'random_max'          => -1,
			'use_video'           => 1,
			'use_photo'           => 1,

			'content_mode'        => 'auto', // auto | feed | page.
			'content_selector'    => '',
			'remove_selectors'    => '',
			'include_lead'        => 1,
			'track_updates_hours' => 0,
			'image_mode'          => 'inherit',

			'index_mode'          => 'inherit', // inherit | noindex | index.
			'delete_after'        => -1,

			'source_name'         => '',
			'source_home'         => '',
			'title_remove'        => '',  // Phrases removed from titles (e.g. " - ایسنا").
			'replacements'        => '',  // "old => new" per line, after the global list.

			// Overrides ('inherit' / '' / -1 = use the global setting).
			'attr_mode'           => 'inherit', // inherit | on | off.
			'attr_template'       => '',
			'attr_position'       => 'inherit',
			'attr_link'           => 'inherit',
			'attr_rel'            => 'inherit',
			'attr_style'          => 'inherit',
			'links_mode'          => 'inherit',
			'dup_mode'            => 'inherit', // inherit | off.
			'content_dup_threshold' => -1,
			'comment_status'      => 'inherit',
			'date_mode'           => 'inherit',
			'author'              => 0,
			'post_status'         => 'inherit',
			'include_keywords'    => '',
			'exclude_keywords'    => '',
			'min_words'           => 0,
			'require_image'       => 0,
			'import_tags'         => 0,
		);
	}

	/**
	 * @return mixed All settings, or one value.
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return array_key_exists( $key, self::$cache ) ? self::$cache[ $key ] : null;
	}

	public static function update( array $values ) {
		$merged = array_merge( self::get(), $values );
		update_option( self::OPTION, $merged );
		self::$cache = null;
		return $merged;
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Secret for the cron URL, kept in its own option so it is never part of a settings snapshot.
	 */
	public static function cron_key() {
		$key = get_option( 'pnr_cron_key', '' );
		if ( ! $key ) {
			$key = wp_generate_password( 24, false );
			update_option( 'pnr_cron_key', $key, false );
		}
		return $key;
	}

	/* ---------- Category roles ---------- */

	public static function role_defaults() {
		return array(
			'role'        => '',
			'chance'      => 30,
			'quota'       => 'unlimited', // unlimited | off | limit.
			'quota_n'     => 5,
			'quota_hours' => 24,
		);
	}

	/**
	 * @return array<int, array> term_id => role config (only terms that still exist).
	 */
	public static function roles() {
		$out = array();
		foreach ( (array) self::get( 'roles' ) as $term_id => $cfg ) {
			$term_id = (int) $term_id;
			if ( $term_id && is_array( $cfg ) && ! empty( $cfg['role'] ) && term_exists( $term_id, 'category' ) ) {
				$out[ $term_id ] = array_merge( self::role_defaults(), $cfg );
			}
		}
		return $out;
	}

	/**
	 * @return int[] term ids with the given role.
	 */
	public static function terms_with_role( $role ) {
		$ids = array();
		foreach ( self::roles() as $term_id => $cfg ) {
			if ( $role === $cfg['role'] ) {
				$ids[] = $term_id;
			}
		}
		return $ids;
	}

	/* ---------- Effective values (source overrides global) ---------- */

	public static function image_mode( array $cfg ) {
		$mode = 'inherit' !== $cfg['image_mode'] ? $cfg['image_mode'] : self::get( 'image_mode' );
		return in_array( $mode, array( 'local', 'hotlink', 'hybrid' ), true ) ? $mode : 'hybrid';
	}

	public static function noindex( array $cfg ) {
		if ( 'noindex' === $cfg['index_mode'] ) {
			return true;
		}
		if ( 'index' === $cfg['index_mode'] ) {
			return false;
		}
		return (bool) self::get( 'noindex' );
	}

	public static function delete_days( array $cfg ) {
		return (int) $cfg['delete_after'] >= 0 ? (int) $cfg['delete_after'] : (int) self::get( 'delete_after_days' );
	}

	public static function drip_minutes( array $cfg ) {
		return (int) $cfg['drip_minutes'] >= 0 ? (int) $cfg['drip_minutes'] : (int) self::get( 'drip_minutes' );
	}

	public static function author( array $cfg ) {
		$author = (int) $cfg['author'] ? (int) $cfg['author'] : (int) self::get( 'author' );
		return $author && get_userdata( $author ) ? $author : Util::fallback_author();
	}

	public static function post_status( array $cfg ) {
		$status = 'inherit' !== $cfg['post_status'] ? $cfg['post_status'] : self::get( 'post_status' );
		return in_array( $status, array( 'publish', 'draft', 'pending' ), true ) ? $status : 'publish';
	}

	/**
	 * A source value, or the global one when the source says "inherit" (or leaves it empty / -1).
	 */
	private static function pick( array $cfg, $key, array $allowed = array() ) {
		$value = isset( $cfg[ $key ] ) ? $cfg[ $key ] : 'inherit';
		if ( 'inherit' === $value || '' === $value || -1 === $value || '-1' === $value ) {
			$value = self::get( $key );
		}
		if ( $allowed && ! in_array( $value, $allowed, true ) ) {
			$value = $allowed[0];
		}
		return $value;
	}

	/**
	 * Effective "به نقل از" settings for a source.
	 *
	 * @return array{enabled: bool, template: string, position: string, link: string, rel: string, new_tab: bool, style: string}
	 */
	public static function attribution( array $cfg ) {
		$mode = isset( $cfg['attr_mode'] ) ? $cfg['attr_mode'] : 'inherit';
		return array(
			'enabled'  => 'on' === $mode || ( 'off' !== $mode && (bool) self::get( 'attr_enabled' ) ),
			'template' => (string) self::pick( $cfg, 'attr_template' ),
			'position' => self::pick( $cfg, 'attr_position', array( 'end', 'start' ) ),
			'link'     => self::pick( $cfg, 'attr_link', array( 'article', 'home', 'none' ) ),
			'rel'      => self::pick( $cfg, 'attr_rel', array( 'nofollow', 'follow', 'sponsored' ) ),
			'new_tab'  => (bool) self::get( 'attr_new_tab' ),
			'style'    => self::pick( $cfg, 'attr_style', array( 'plain', 'bold', 'small', 'box' ) ),
		);
	}

	public static function links_mode( array $cfg ) {
		return self::pick( $cfg, 'links_mode', array( 'nofollow', 'follow', 'strip' ) );
	}

	public static function comment_status( array $cfg ) {
		$status = self::pick( $cfg, 'comment_status', array( 'default', 'open', 'closed' ) );
		return 'default' === $status ? get_default_comment_status( 'post' ) : $status;
	}

	public static function date_mode( array $cfg ) {
		return self::pick( $cfg, 'date_mode', array( 'source', 'import' ) );
	}

	/**
	 * @return array{title: bool, content: bool, threshold: int}
	 */
	public static function duplicates( array $cfg ) {
		$off = isset( $cfg['dup_mode'] ) && 'off' === $cfg['dup_mode'];
		return array(
			'title'     => ! $off && (bool) self::get( 'dup_titles' ),
			'content'   => ! $off && (bool) self::get( 'content_dup' ),
			'threshold' => (int) self::pick( $cfg, 'content_dup_threshold' ),
		);
	}

	/**
	 * Find/replace pairs: global list first, then the source's own.
	 *
	 * @return array<string, string>
	 */
	public static function replacements( array $cfg ) {
		$pairs = array();
		foreach ( array( self::get( 'replacements' ), isset( $cfg['replacements'] ) ? $cfg['replacements'] : '' ) as $text ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
				$parts = explode( '=>', $line, 2 );
				$from  = trim( $parts[0] );
				if ( '' !== $from && 2 === count( $parts ) ) {
					$pairs[ $from ] = trim( $parts[1] );
				}
			}
		}
		return $pairs;
	}

	/**
	 * Random placement settings for a source.
	 *
	 * @return array{sections: int[], mode: string, none: int, max: int}
	 */
	public static function random( array $cfg ) {
		$all = self::terms_with_role( 'section' );
		if ( 'off' === $cfg['random_scope'] ) {
			$sections = array();
		} elseif ( 'custom' === $cfg['random_scope'] ) {
			$sections = array_values( array_intersect( $all, array_map( 'intval', (array) $cfg['random_sections'] ) ) );
		} else {
			$sections = $all;
		}
		$mode = 'inherit' !== $cfg['random_mode'] ? $cfg['random_mode'] : self::get( 'random_mode' );
		return array(
			'sections' => $sections,
			'mode'     => 'one' === $mode ? 'one' : 'independent',
			'none'     => (int) $cfg['random_none'] >= 0 ? (int) $cfg['random_none'] : (int) self::get( 'random_none' ),
			'max'      => (int) $cfg['random_max'] >= 0 ? (int) $cfg['random_max'] : (int) self::get( 'random_max' ),
		);
	}

	/**
	 * Hosts whose iframes are kept in imported content.
	 */
	public static function iframe_hosts() {
		$hosts = array(
			'aparat.com',
			'youtube.com',
			'youtube-nocookie.com',
			'youtu.be',
			'vimeo.com',
			'dailymotion.com',
			'telewebion.com',
			'namasha.com',
			'tamasha.com',
			'filimo.com',
			'arvanvod.ir',
			'arvancloud.ir',
			'instagram.com',
			'twitter.com',
			'x.com',
			'facebook.com',
			'soundcloud.com',
			'castbox.fm',
			'google.com',
			'balad.ir',
			'neshan.org',
		);
		return array_values( array_unique( array_merge( $hosts, Util::lines( self::get( 'iframe_hosts' ) ) ) ) );
	}

	public static function video_hosts() {
		return array( 'aparat.com', 'youtube.com', 'youtube-nocookie.com', 'youtu.be', 'vimeo.com', 'dailymotion.com', 'telewebion.com', 'namasha.com', 'tamasha.com', 'filimo.com', 'arvanvod.ir' );
	}
}
