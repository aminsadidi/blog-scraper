<?php
/**
 * Small helpers shared by the whole plugin.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Support;

defined( 'ABSPATH' ) || exit;

class Util {

	/**
	 * Normalises Persian text for comparisons (Arabic ی/ک, ZWNJ, spacing, case).
	 */
	public static function normalize_fa( $text ) {
		$text = str_replace(
			array( 'ي', 'ى', 'ك', 'ة', "\xE2\x80\x8C", 'ـ', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ),
			array( 'ی', 'ی', 'ک', 'ه', ' ', '', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			(string) $text
		);
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	public static function strlen( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	public static function substr( $text, $start, $length = null ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, $start, $length, 'UTF-8' ) : substr( (string) $text, $start, $length );
	}

	/**
	 * Plain text from HTML: tags stripped, entities decoded, whitespace collapsed.
	 */
	public static function text( $html ) {
		$text = (string) $html;
		// Block boundaries become spaces so words of adjacent paragraphs do not stick together.
		$text = preg_replace( '~</(p|div|li|h[1-6]|figcaption|td|th|blockquote)>|<br\s*/?>~i', '$0 ', $text );
		$text = wp_strip_all_tags( $text );
		// Twice: some feeds double-encode (&amp;#8238;).
		$text = html_entity_decode( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Bidi control characters and zero-width spaces (ZWNJ is kept: it is part of Persian spelling).
		// Decoded entities may form new tags (&lt;script&gt;): strip again, this text is saved unfiltered.
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/[\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Drops 4-byte characters (emoji) when a table still uses the 3-byte "utf8" charset, where they would
	 * make the whole INSERT fail.
	 */
	public static function db_safe( $text, $table, $column ) {
		global $wpdb;
		$charset = $wpdb->get_col_charset( $table, $column );
		if ( is_string( $charset ) && 'utf8mb4' !== $charset ) {
			$text = preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', (string) $text );
		}
		return (string) $text;
	}

	/**
	 * Host name without a leading www.
	 */
	public static function host( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		return $host ? preg_replace( '/^www\./i', '', strtolower( $host ) ) : '';
	}

	/**
	 * True when $host is $domain or one of its subdomains.
	 */
	public static function host_matches( $host, $domain ) {
		$host   = strtolower( (string) $host );
		$domain = preg_replace( '/^www\./i', '', strtolower( trim( (string) $domain ) ) );
		if ( '' === $host || '' === $domain ) {
			return false;
		}
		$host = preg_replace( '/^www\./i', '', $host );
		return $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain;
	}

	/**
	 * Resolves a possibly-relative URL against a base URL. Returns '' for non-http(s) URLs.
	 */
	public static function absolute_url( $url, $base ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, '#' ) ) {
			return '';
		}
		$url = str_replace( ' ', '%20', $url );

		if ( preg_match( '~^[a-z][a-z0-9+.-]*:~i', $url ) ) {
			return preg_match( '~^https?://~i', $url ) ? $url : '';
		}

		$b = wp_parse_url( (string) $base );
		if ( empty( $b['host'] ) ) {
			return '';
		}
		$scheme = isset( $b['scheme'] ) ? $b['scheme'] : 'https';
		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}
		$origin = $scheme . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		$path   = isset( $b['path'] ) ? $b['path'] : '/';

		if ( 0 === strpos( $url, '?' ) ) {
			return $origin . $path . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$full = $url;
		} else {
			$dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
			$full = ( '' === $dir ? '/' : $dir ) . $url;
		}

		// Collapse ./ and ../ segments.
		$query = '';
		if ( false !== ( $q = strpos( $full, '?' ) ) ) {
			$query = substr( $full, $q );
			$full  = substr( $full, 0, $q );
		}
		$out = array();
		foreach ( explode( '/', $full ) as $seg ) {
			if ( '..' === $seg ) {
				array_pop( $out );
			} elseif ( '.' !== $seg ) {
				$out[] = $seg;
			}
		}
		$full = implode( '/', $out );
		if ( 0 !== strpos( $full, '/' ) ) {
			$full = '/' . $full;
		}
		return $origin . $full . $query;
	}

	/**
	 * Percent-encodes non-ASCII characters so the HTTP layer accepts Persian URLs.
	 */
	public static function encode_url( $url ) {
		return preg_replace_callback(
			'/[^\x21-\x7E]/',
			function ( $m ) {
				return rawurlencode( $m[0] );
			},
			(string) $url
		);
	}

	/**
	 * A comparable key for an image URL (ignores query string, WordPress size suffixes and host).
	 */
	public static function image_key( $url ) {
		$path = (string) wp_parse_url( rawurldecode( (string) $url ), PHP_URL_PATH );
		$name = strtolower( basename( $path ) );
		// Drop (possibly doubled) image extensions: photo.jpg.webp → photo.
		$name = preg_replace( '/(\.(jpe?g|png|gif|webp|avif|bmp))+$/', '', $name );
		// WordPress-style size suffixes: photo-300x200, photo-scaled.
		$name = preg_replace( '/-\d{2,4}x\d{2,4}$/', '', $name );
		$name = preg_replace( '/-(scaled|rotated|e\d{10,})$/', '', $name );
		return $name;
	}

	/**
	 * Splits a textarea (new lines or commas) into a list of trimmed, non-empty values.
	 */
	public static function lines( $text ) {
		$parts = preg_split( '/[\r\n,،]+/u', (string) $text );
		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	/**
	 * First administrator, used as a last-resort post author.
	 */
	public static function fallback_author() {
		$users = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
				'orderby' => 'ID',
			)
		);
		return $users ? (int) $users[0] : 1;
	}

	/**
	 * Percent-decoded, lower-cased path without surrounding slashes, used for redirect lookups.
	 */
	public static function normalize_path( $url ) {
		$path  = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( (string) $url, PHP_URL_QUERY );
		if ( '' === trim( $path, '/' ) && preg_match( '/(?:^|&)(p|page_id)=(\d+)/', $query, $m ) ) {
			return '?' . $m[1] . '=' . $m[2]; // Plain permalinks: ?p=123.
		}
		$path = rawurldecode( $path );
		$path = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
		return trim( $path, '/' );
	}
}
