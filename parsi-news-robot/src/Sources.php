<?php
/**
 * News sources: a private post type, one post per RSS feed, with its config and runtime state in meta.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot;

use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Sources {

	const TYPE   = 'pnr_source';
	const CONFIG = '_pnr_config';
	const STATE  = '_pnr_state';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		$cap = Installer::CAP;
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'               => 'منابع خبر',
					'singular_name'      => 'منبع خبر',
					'add_new'            => 'افزودن منبع',
					'add_new_item'       => 'افزودن منبع خبر',
					'edit_item'          => 'ویرایش منبع خبر',
					'new_item'           => 'منبع جدید',
					'view_item'          => 'نمایش منبع',
					'search_items'       => 'جستجوی منابع',
					'not_found'          => 'هنوز منبعی اضافه نشده است.',
					'not_found_in_trash' => 'منبعی در زباله‌دان نیست.',
					'all_items'          => 'منابع خبر',
					'menu_name'          => 'منابع خبر',
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'pnr-dashboard',
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'query_var'           => false,
				'rewrite'             => false,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'delete_posts'           => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'create_posts'           => $cap,
					'delete_others_posts'    => $cap,
					'delete_published_posts' => $cap,
					'edit_published_posts'   => $cap,
					'delete_private_posts'   => $cap,
					'edit_private_posts'     => $cap,
				),
			)
		);
	}

	public static function exists( $id ) {
		$post = get_post( $id );
		return $post && self::TYPE === $post->post_type && 'trash' !== $post->post_status;
	}

	public static function config( $id ) {
		$saved = get_post_meta( $id, self::CONFIG, true );
		return array_merge( Settings::source_defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function save_config( $id, array $config ) {
		update_post_meta( $id, self::CONFIG, $config );
	}

	public static function state_defaults() {
		return array(
			'etag'          => '',
			'last_modified' => '',
			'last_run'      => 0,
			'next_run'      => 0,
			'last_status'   => '',
			'last_message'  => '',
			'empty_runs'    => 0,
			'error_runs'    => 0,
			'total'         => 0,
			'last_new_at'   => 0,
			'last_slot'     => 0,
			'feed_title'    => '',
			'feed_home'     => '',
		);
	}

	public static function state( $id ) {
		$saved = get_post_meta( $id, self::STATE, true );
		return array_merge( self::state_defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function set_state( $id, array $values ) {
		$state = array_merge( self::state( $id ), $values );
		update_post_meta( $id, self::STATE, $state );
		return $state;
	}

	public static function bump_total( $id ) {
		$state = self::state( $id );
		self::set_state(
			$id,
			array(
				'total'       => (int) $state['total'] + 1,
				'last_new_at' => time(),
			)
		);
	}

	/**
	 * @return int[]
	 */
	public static function all_ids() {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::TYPE,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			)
		);
	}

	/**
	 * Published sources that are switched on and have a URL.
	 *
	 * @return int[]
	 */
	public static function active_ids() {
		$ids = array();
		foreach ( self::all_ids() as $id ) {
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$cfg = self::config( $id );
			if ( $cfg['active'] && $cfg['url'] ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Whether "now" (site time) is inside the source's working hours.
	 */
	public static function in_active_hours( array $cfg, $timestamp = null ) {
		$from = self::minutes( $cfg['hours_from'] );
		$to   = self::minutes( $cfg['hours_to'] );
		if ( null === $from || null === $to || $from === $to ) {
			return true;
		}
		$now = (int) wp_date( 'G', $timestamp ) * 60 + (int) wp_date( 'i', $timestamp );
		return $from < $to ? ( $now >= $from && $now < $to ) : ( $now >= $from || $now < $to );
	}

	private static function minutes( $hhmm ) {
		if ( ! is_string( $hhmm ) || ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $hhmm ), $m ) ) {
			return null;
		}
		return min( 23, (int) $m[1] ) * 60 + min( 59, (int) $m[2] );
	}

	public static function name( $id, ?array $cfg = null ) {
		$cfg   = $cfg ? $cfg : self::config( $id );
		$state = self::state( $id );
		if ( $cfg['source_name'] ) {
			return $cfg['source_name'];
		}
		$host  = Util::host( $cfg['url'] );
		$title = trim( (string) get_post_field( 'post_title', $id ) );
		// The title is auto-filled with the host when left empty; a real feed title reads better then.
		if ( '' !== $title && $title !== $host && 'www.' . $host !== $title ) {
			return $title;
		}
		return $state['feed_title'] ? $state['feed_title'] : ( $title ? $title : $host );
	}

	public static function home( $id, ?array $cfg = null ) {
		$cfg = $cfg ? $cfg : self::config( $id );
		if ( $cfg['source_home'] ) {
			return $cfg['source_home'];
		}
		$state = self::state( $id );
		if ( $state['feed_home'] && preg_match( '~^https?://~i', $state['feed_home'] ) ) {
			$parts = wp_parse_url( $state['feed_home'] );
			return $parts['scheme'] . '://' . $parts['host'] . '/';
		}
		$parts = wp_parse_url( $cfg['url'] );
		return ! empty( $parts['host'] ) ? ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . '/' : '';
	}
}
