<?php
/**
 * Source inspection: what an address really is (RSS or web page), what it contains, and whether full
 * text can be extracted. Used by the "بررسی منبع‌ها" admin tool, which runs on the site's own server
 * (useful when sources only answer from inside the country) and produces a report the owner can share.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot;

use ParsiNewsRobot\Import\Builder;
use ParsiNewsRobot\Import\FeedReader;
use ParsiNewsRobot\Import\ListingReader;
use ParsiNewsRobot\Support\Dom;
use ParsiNewsRobot\Support\Http;
use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Probe {

	/**
	 * Inspects one address.
	 *
	 * @param string $keyword Optional word whose share among the item titles is reported (e.g. a city).
	 * @param bool   $deep    Also run full-text extraction on the first item.
	 * @return array
	 */
	public static function inspect( $url, $keyword = '', $deep = true ) {
		$out  = array(
			'url'   => $url,
			'ok'    => false,
			'type'  => '',
			'error' => '',
		);
		$page = Http::get( $url, array( 'accept' => 'application/rss+xml, application/xml;q=0.9, text/html;q=0.8, */*;q=0.5' ) );
		if ( is_wp_error( $page ) ) {
			$out['error'] = $page->get_error_message();
			return $out;
		}
		$out['final_url'] = $page['url'];
		$body             = $page['body'];
		$head             = substr( ltrim( preg_replace( '/^\xEF\xBB\xBF/', '', $body ) ), 0, 2000 );

		if ( preg_match( '~<(rss|feed|rdf:RDF)[\s>]~i', $head ) ) {
			$out['type'] = 'rss';
			$feed        = FeedReader::parse( $body );
			if ( is_wp_error( $feed ) ) {
				$out['error'] = $feed->get_error_message();
				return $out;
			}
			$items = array();
			foreach ( (array) $feed->get_items( 0, FeedReader::MAX_ITEMS ) as $item ) {
				$items[] = FeedReader::normalize_item( $item, $page['url'] );
			}
			$out['ok']    = true;
			$out['title'] = Util::text( (string) $feed->get_title() );
			$out          = array_merge( $out, self::summarize( $items, $keyword ) );
			$cfg          = Settings::source_defaults();
			$cfg['url']   = $url;
		} else {
			$html = Http::html_to_utf8( $body, $page['type'] );
			if ( Builder::is_challenge_page( $html ) ) {
				$out['type']  = 'blocked';
				$out['error'] = 'دیوار امنیتی (آروان/کلادفلر) جلوی ربات را گرفت.';
				return $out;
			}
			$out['type']    = 'html';
			$parsed         = ListingReader::parse( $html, $page['url'] );
			$out['ok']      = (bool) $parsed['items'];
			$out['title']   = $parsed['site'];
			$out['feeds']   = self::feed_links( $html, $page['url'] );
			$out['filters'] = self::form_filters( $html );
			$out            = array_merge( $out, self::summarize( $parsed['items'], $keyword ) );
			$items          = $parsed['items'];
			$cfg            = Settings::source_defaults();
			$cfg['url']     = $url;
			$cfg['source_type'] = 'page';
		}

		if ( $deep && ! empty( $items ) ) {
			$built = Builder::build( $items[0], $cfg );
			if ( is_wp_error( $built ) ) {
				$out['full_text'] = array( 'error' => $built->get_error_message() );
			} else {
				$out['full_text'] = array(
					'title'  => $built['title'],
					'method' => $built['method'],
					'words'  => $built['words'],
					'images' => $built['image_count'],
					'video'  => $built['has_video'],
					'start'  => Util::substr( $built['text'], 0, 160 ),
				);
			}
		}
		return $out;
	}

	private static function summarize( array $items, $keyword ) {
		$keyword = Util::normalize_fa( $keyword );
		$hits    = 0;
		$cats    = array();
		$sample  = array();
		$dates   = array();
		foreach ( $items as $i => $item ) {
			$text = Util::normalize_fa( $item['title'] . ' ' . Util::text( $item['description'] ) );
			if ( '' !== $keyword && false !== mb_strpos( $text, $keyword ) ) {
				$hits++;
			}
			foreach ( (array) $item['categories'] as $cat ) {
				$cats[ $cat ] = isset( $cats[ $cat ] ) ? $cats[ $cat ] + 1 : 1;
			}
			if ( $item['date'] ) {
				$dates[] = (int) $item['date'];
			}
			if ( $i < 6 ) {
				$sample[] = $item['title'] . ( $item['categories'] ? ' [' . implode( '، ', $item['categories'] ) . ']' : '' );
			}
		}
		arsort( $cats );
		return array(
			'count'         => count( $items ),
			'keyword_share' => '' !== $keyword && $items ? round( 100 * $hits / count( $items ) ) : null,
			'categories'    => array_slice( $cats, 0, 12, true ),
			'newest'        => $dates ? wp_date( 'Y-m-d H:i', max( $dates ) ) : '',
			'oldest'        => $dates ? wp_date( 'Y-m-d H:i', min( $dates ) ) : '',
			'sample'        => $sample,
		);
	}

	/**
	 * <link rel="alternate" type="application/rss+xml"> of a web page.
	 */
	private static function feed_links( $html, $base ) {
		$out = array();
		if ( preg_match_all( '~<link\b[^>]*>~i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '~type=["\']?application/(rss|atom)\+xml~i', $tag ) && preg_match( '~href=["\']([^"\']+)~i', $tag, $h ) ) {
					$out[] = Util::absolute_url( html_entity_decode( $h[1] ), $base );
				}
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * Options of the page's <select> filters (e.g. an archive page's service list → its "tp" ids).
	 *
	 * @return array<string, array<string, string>> name => value => label
	 */
	private static function form_filters( $html ) {
		$doc = Dom::load( $html, true );
		$out = array();
		foreach ( $doc->getElementsByTagName( 'select' ) as $select ) {
			$name = $select->getAttribute( 'name' ) ? $select->getAttribute( 'name' ) : $select->getAttribute( 'id' );
			if ( '' === $name ) {
				continue;
			}
			$options = array();
			foreach ( $select->getElementsByTagName( 'option' ) as $option ) {
				$label = Util::text( $option->textContent );
				if ( '' !== $label ) {
					$options[ $option->getAttribute( 'value' ) ] = $label;
				}
			}
			if ( count( $options ) > 1 && count( $options ) < 200 ) {
				$out[ $name ] = $options;
			}
		}
		return $out;
	}

	/**
	 * Plain-text report line(s) for one result, compact enough to paste into a chat.
	 */
	public static function report( array $r ) {
		$lines   = array();
		$lines[] = '### ' . $r['url'];
		if ( ! $r['ok'] && $r['error'] ) {
			$lines[] = 'خطا: ' . $r['error'];
			return implode( "\n", $lines );
		}
		$lines[] = sprintf(
			'نوع: %s | عنوان: %s | تعداد: %d | جدیدترین: %s | قدیمی‌ترین: %s%s',
			$r['type'],
			isset( $r['title'] ) ? $r['title'] : '',
			isset( $r['count'] ) ? $r['count'] : 0,
			isset( $r['newest'] ) ? $r['newest'] : '-',
			isset( $r['oldest'] ) ? $r['oldest'] : '-',
			isset( $r['keyword_share'] ) && null !== $r['keyword_share'] ? ' | شامل کلمه: ' . $r['keyword_share'] . '٪' : ''
		);
		if ( ! empty( $r['categories'] ) ) {
			$cats = array();
			foreach ( $r['categories'] as $name => $n ) {
				$cats[] = $name . ' (' . $n . ')';
			}
			$lines[] = 'دسته‌های RSS: ' . implode( ' / ', $cats );
		}
		foreach ( isset( $r['sample'] ) ? $r['sample'] : array() as $title ) {
			$lines[] = '- ' . $title;
		}
		if ( ! empty( $r['full_text'] ) ) {
			$f       = $r['full_text'];
			$lines[] = isset( $f['error'] ) ? 'متن کامل: خطا — ' . $f['error'] : sprintf( 'متن کامل: %s کلمه، روش %s، عکس %d، ویدئو %s — «%s…»', $f['words'], $f['method'], $f['images'], $f['video'] ? 'دارد' : 'ندارد', $f['start'] );
		}
		if ( ! empty( $r['feeds'] ) ) {
			$lines[] = 'RSSهای اعلام‌شده در صفحه: ' . implode( ' , ', $r['feeds'] );
		}
		foreach ( isset( $r['filters'] ) ? $r['filters'] : array() as $name => $options ) {
			$pairs = array();
			foreach ( $options as $value => $label ) {
				$pairs[] = $value . '=' . $label;
			}
			$lines[] = 'فیلتر «' . $name . '»: ' . implode( '، ', $pairs );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Candidate addresses for a city keyword on major agencies (to be checked, not guaranteed).
	 *
	 * @return string[]
	 */
	public static function suggestions( $keyword ) {
		$kw = rawurlencode( $keyword );
		return array(
			'https://www.mehrnews.com/rss?kw=' . $kw,
			'https://www.mehrnews.com/rss?tp=9&kw=' . $kw,
			'https://www.mehrnews.com/rss?tp=25&kw=' . $kw,
			'https://www.mehrnews.com/archive',
			'https://www.isna.ir/rss?kw=' . $kw,
			'https://www.isna.ir/archive',
			'https://www.irna.ir/rss?kw=' . $kw,
			'https://www.irna.ir/archive',
			'https://www.khabaronline.ir/rss?kw=' . $kw,
			'https://www.hamshahrionline.ir/rss?kw=' . $kw,
			'https://www.tasnimnews.com/fa/rss',
			'https://www.farsnews.ir/rss',
			'https://www.yjc.ir/fa/rss/allnews',
			'https://www.shahraranews.ir/fa/rss/allnews',
			'https://www.shahraranews.ir/',
			'https://qudsonline.ir/rss',
			'https://www.khorasannews.com/',
			'https://www.varzesh3.com/rss/all',
			'https://www.tabnak.ir/fa/rss/allnews',
		);
	}
}
