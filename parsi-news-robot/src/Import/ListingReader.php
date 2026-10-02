<?php
/**
 * News sources without RSS: a listing page (tag, category, section, "latest news"…) is read and its article
 * links become feed items, which then go through the normal pipeline (full text from each article page).
 *
 * Which links are articles is found automatically: links are grouped by the shape of their address
 * (/news/123456/slug → /news/N/S, /fa-ir/slug/a-123 → /fa-ir/S/a-N, /persian/articles/c4gj… → /persian/articles/X)
 * and the group whose links carry real headlines wins. A CSS selector and a URL filter can override this
 * per source.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Import;

use ParsiNewsRobot\Support\Dom;
use ParsiNewsRobot\Support\Http;
use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class ListingReader {

	const MAX_ITEMS = 60;

	/** Anchor texts shorter than this are not headlines (menus, tags, "more"). */
	const MIN_TITLE = 18;

	/**
	 * Same shape as FeedReader::read().
	 *
	 * @return array|\WP_Error
	 */
	public static function read( $url, array $cfg, $etag = '', $last_modified = '' ) {
		$page = Http::get(
			$url,
			array(
				'html'          => true,
				'etag'          => $etag,
				'last_modified' => $last_modified,
			)
		);
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$out = array(
			'not_modified'  => (bool) $page['not_modified'],
			'items'         => array(),
			'title'         => '',
			'home'          => '',
			'etag'          => $page['etag'],
			'last_modified' => $page['last_modified'],
		);
		if ( $out['not_modified'] ) {
			return $out;
		}
		if ( Builder::is_challenge_page( $page['body'] ) ) {
			return new \WP_Error( 'pnr_challenge', 'سایت منبع با دیوار امنیتی (مثل ابر آروان یا کلادفلر) جلوی ربات را گرفته است و صفحه خبرها قابل خواندن نیست.' );
		}

		$parsed       = self::parse( $page['body'], $page['url'], $cfg );
		$out['items'] = $parsed['items'];
		$out['title'] = $parsed['site'];
		$parts        = wp_parse_url( $page['url'] );
		$out['home']  = ! empty( $parts['host'] ) ? ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . '/' : '';

		if ( ! $out['items'] ) {
			return new \WP_Error( 'pnr_listing_empty', 'در این صفحه لینک خبری پیدا نشد. در تنظیمات منبع «انتخابگر لینک خبرها» یا «فقط آدرس‌های شامل» را وارد کنید.' );
		}
		return $out;
	}

	/**
	 * @return array{items: array, site: string}
	 */
	public static function parse( $html, $page_url, array $cfg = array() ) {
		$doc  = Dom::load( $html, true );
		$xp   = new \DOMXPath( $doc );
		$site = self::site_name( $xp );
		$host = Util::host( $page_url );

		$selector = isset( $cfg['list_selector'] ) ? trim( (string) $cfg['list_selector'] ) : '';
		$filter   = isset( $cfg['list_url_filter'] ) ? trim( (string) $cfg['list_url_filter'] ) : '';

		if ( '' === $selector ) {
			// Menus, headers and footers repeat on every page and are never the listing.
			foreach ( Dom::select( $xp, 'nav, header, footer, aside, script, style, noscript, [role="navigation"], [role="complementary"]' ) as $node ) {
				Dom::remove( $node );
			}
		}

		$anchors = array();
		if ( '' !== $selector ) {
			foreach ( Dom::select( $xp, $selector ) as $node ) {
				if ( 'a' === strtolower( $node->nodeName ) ) {
					$anchors[] = $node;
				} else {
					foreach ( $node->getElementsByTagName( 'a' ) as $a ) {
						$anchors[] = $a;
					}
				}
			}
		} else {
			$anchors = iterator_to_array( $doc->getElementsByTagName( 'a' ) );
		}

		// Candidate article links on the same site.
		$links = array();
		$order = 0;
		foreach ( $anchors as $a ) {
			$url = Util::absolute_url( $a->getAttribute( 'href' ), $page_url );
			if ( ! $url || ! Util::host_matches( Util::host( $url ), $host ) ) {
				continue;
			}
			$url  = preg_replace( '/#.*$/', '', $url );
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' === trim( $path, '/' ) || self::is_listing_path( $path ) || Util::normalize_path( $url ) === Util::normalize_path( $page_url ) ) {
				continue;
			}
			if ( '' !== $filter && false === mb_strpos( rawurldecode( $url ), $filter ) && false === strpos( $url, $filter ) ) {
				continue;
			}
			$text = Util::text( $a->textContent );
			if ( '' === $text ) {
				$text = trim( $a->getAttribute( 'title' ) );
			}
			if ( self::looks_like_date( $text ) ) {
				$text = ''; // "۱۰ مهر ۱۴۰۵، ۱۲:۳۰" links to the article too, but is not its title.
			}
			// One article is often linked several times with different addresses (/news/123, /news/123/slug).
			$key = self::article_key( $url );
			if ( ! isset( $links[ $key ] ) ) {
				$links[ $key ] = array(
					'url'     => $url,
					'order'   => $order++,
					'title'   => '',
					'anchors' => array(),
				);
			} elseif ( strlen( $url ) > strlen( $links[ $key ]['url'] ) ) {
				$links[ $key ]['url'] = $url; // The address with the slug is the canonical one.
			}
			$url                        = $key;
			$links[ $url ]['anchors'][] = $a;
			$in_heading = (bool) $xp->query( 'ancestor-or-self::*[self::h1 or self::h2 or self::h3 or self::h4] | .//h1 | .//h2 | .//h3 | .//h4', $a )->length;
			if ( $in_heading && Util::strlen( $text ) >= 8 && empty( $links[ $url ]['heading'] ) ) {
				// A headline beats any teaser text linking to the same article.
				$links[ $url ]['title']   = $text;
				$links[ $url ]['heading'] = true;
			} elseif ( empty( $links[ $url ]['heading'] ) && Util::strlen( $text ) > Util::strlen( $links[ $url ]['title'] ) && Util::strlen( $text ) <= 200 ) {
				$links[ $url ]['title'] = $text;
			}
		}

		// Keep the address shape that carries headlines (unless the admin chose the links with a selector).
		if ( '' === $selector ) {
			$scores = array();
			foreach ( $links as $link ) {
				$key = self::shape( $link['url'] );
				if ( ! isset( $scores[ $key ] ) ) {
					$scores[ $key ] = 0;
				}
				if ( Util::strlen( $link['title'] ) >= self::MIN_TITLE ) {
					$scores[ $key ]++;
				}
			}
			arsort( $scores );
			$best = (int) reset( $scores );
			if ( $best < 3 ) {
				return array(
					'items' => array(),
					'site'  => $site,
				);
			}
			$keep = array();
			foreach ( $scores as $key => $score ) {
				if ( $score >= max( 3, $best * 0.5 ) ) {
					$keep[ $key ] = true;
				}
			}
			$links = array_filter(
				$links,
				function ( $link ) use ( $keep ) {
					return isset( $keep[ ListingReader::shape( $link['url'] ) ] ) && Util::strlen( $link['title'] ) >= ListingReader::MIN_TITLE;
				}
			);
		} else {
			$links = array_filter(
				$links,
				function ( $link ) {
					return Util::strlen( $link['title'] ) >= 8;
				}
			);
		}

		if ( '' === $selector ) {
			$links = self::main_region( $links );
		}

		uasort(
			$links,
			function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);

		$article_urls = array_flip( array_keys( $links ) );
		$items        = array();
		foreach ( array_slice( $links, 0, self::MAX_ITEMS ) as $link ) {
			$box     = self::container( $link['anchors'][0], $article_urls, $page_url );
			$items[] = array(
				'guid'        => $link['url'],
				'link'        => $link['url'],
				'title'       => $link['title'],
				'date'        => $box ? self::date( $box ) : 0,
				'updated'     => 0,
				'content'     => '',
				'description' => $box ? self::summary( $box, $link['title'] ) : '',
				'enclosures'  => $box ? self::image( $box, $page_url ) : array(),
				'categories'  => array(),
			);
		}
		return array(
			'items' => $items,
			'site'  => $site,
		);
	}

	/**
	 * Identity of an article address: everything up to its id segment (/news/6612001/slug → host/news/6612001).
	 */
	public static function article_key( $url ) {
		$host = Util::host( $url );
		$segs = explode( '/', trim( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '/' ) );
		$out  = array();
		foreach ( $segs as $seg ) {
			$out[] = $seg;
			if ( preg_match( '/^\d{4,}$|^[a-z]{1,10}[-_]?\d{4,}$/i', $seg ) ) {
				// Date paths (/1405/07/10/) are not ids; ids are long or come after them.
				if ( ! preg_match( '/^\d{4}$/', $seg ) || count( $out ) === count( $segs ) ) {
					return $host . '/' . implode( '/', $out );
				}
			}
		}
		return $host . '/' . implode( '/', $segs );
	}

	/**
	 * Keeps the links of the page's main list(s): the region holding the most articles, and any region at
	 * least half as large. Teaser boxes of other news ("most read", "related") are smaller.
	 */
	private static function main_region( array $links ) {
		$groups = array();
		foreach ( $links as $key => $link ) {
			$region = self::region_of( $link['anchors'][0], $links );
			$id     = $region ? $region->getNodePath() : '';
			if ( ! isset( $groups[ $id ] ) ) {
				$groups[ $id ] = array();
			}
			$groups[ $id ][] = $key;
		}
		if ( count( $groups ) < 2 ) {
			return $links;
		}
		$largest = max( array_map( 'count', $groups ) );
		$keep    = array();
		foreach ( $groups as $keys ) {
			if ( count( $keys ) >= max( 3, $largest * 0.5 ) ) {
				$keep = array_merge( $keep, $keys );
			}
		}
		return $keep ? array_intersect_key( $links, array_flip( $keep ) ) : $links;
	}

	/**
	 * The nearest list-like ancestor (ul/ol/section/div…) holding at least three of the articles.
	 */
	private static function region_of( \DOMElement $a, array $links ) {
		$keys = array_flip( array_keys( $links ) );
		for ( $node = $a->parentNode, $level = 0; $node instanceof \DOMElement && $level < 10; $node = $node->parentNode, $level++ ) {
			$found = array();
			foreach ( $node->getElementsByTagName( 'a' ) as $link ) {
				$key = self::article_key( preg_replace( '/#.*$/', '', (string) $link->getAttribute( 'href' ) ) );
				$abs = Util::absolute_url( $link->getAttribute( 'href' ), 'https://' . Util::host( $links[ array_key_first( $links ) ]['url'] ) . '/' );
				$key = $abs ? self::article_key( preg_replace( '/#.*$/', '', $abs ) ) : $key;
				if ( isset( $keys[ $key ] ) ) {
					$found[ $key ] = true;
				}
			}
			if ( count( $found ) >= 3 ) {
				return $node;
			}
		}
		return null;
	}

	private static function looks_like_date( $text ) {
		$t = Util::normalize_fa( $text );
		return '' !== $t && ( preg_match( '/^[\d\s\/\-:،,.]+$/u', $t ) || preg_match( '/^\d{1,2}\s+(فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور|مهر|آبان|آذر|دی|بهمن|اسفند)\s+\d{2,4}/u', $t ) || preg_match( '/(ساعت|دقیقه|روز|هفته) پیش$/u', $t ) );
	}

	/**
	 * Address shape: digits → N, "a-123"-style ids → a-N, random ids → X, slugs → S.
	 */
	public static function shape( $url ) {
		$path = trim( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '/' );
		$out  = array();
		foreach ( explode( '/', $path ) as $seg ) {
			$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $seg, 'UTF-8' ) : strtolower( $seg );
			if ( preg_match( '/^\d+$/', $seg ) ) {
				$out[] = 'N';
			} elseif ( preg_match( '/^([a-z]{1,10})[-_]?\d{3,}$/i', $seg, $m ) ) {
				$out[] = strtolower( $m[1] ) . '-N';
			} elseif ( preg_match( '/^(?=.*\d)(?=.*[a-z])[a-z0-9]{8,}$/i', $seg ) ) {
				$out[] = 'X';
			} elseif ( preg_match( '/^\d+[-_].+/u', $seg ) ) {
				$out[] = 'N-S';
			} elseif ( Util::strlen( $seg ) > 24 || substr_count( $seg, '-' ) >= 2 || preg_match( '/[^\x00-\x7F]/', $seg ) ) {
				$out[] = 'S';
			} else {
				$out[] = $lower;
			}
		}
		return '/' . implode( '/', $out );
	}

	/**
	 * Archive / navigation addresses that are never single articles.
	 */
	private static function is_listing_path( $path ) {
		return (bool) preg_match( '~/(tags?|topics?|category|categories|service|services|archive|search|page|author|authors|profile|newsletter|contact|about|login|register|rss|feed)(/|$)|\.(jpe?g|png|gif|webp|pdf|mp4|mp3|zip)$~i', rawurldecode( $path ) );
	}

	/**
	 * The smallest ancestor that still holds only this one article (its "card").
	 */
	private static function container( \DOMElement $a, array $article_urls, $page_url ) {
		$box = $a;
		for ( $node = $a->parentNode, $level = 0; $node instanceof \DOMElement && $level < 6; $node = $node->parentNode, $level++ ) {
			if ( in_array( strtolower( $node->nodeName ), array( 'body', 'html', 'main' ), true ) ) {
				break;
			}
			$urls = array();
			foreach ( $node->getElementsByTagName( 'a' ) as $link ) {
				$url = Util::absolute_url( $link->getAttribute( 'href' ), $page_url );
				if ( $url && isset( $article_urls[ self::article_key( preg_replace( '/#.*$/', '', $url ) ) ] ) ) {
					$urls[ self::article_key( $url ) ] = true;
				}
			}
			if ( count( $urls ) > 1 ) {
				break;
			}
			$box = $node;
		}
		return $box;
	}

	private static function summary( \DOMElement $box, $title ) {
		foreach ( array( 'p', 'div', 'span' ) as $tag ) {
			foreach ( $box->getElementsByTagName( $tag ) as $el ) {
				$text = Util::text( $el->textContent );
				$len  = Util::strlen( $text );
				if ( $len >= 40 && $len <= 600 && false === mb_strpos( $text, $title ) && $el->getElementsByTagName( 'a' )->length <= 1 ) {
					return $text;
				}
			}
		}
		return '';
	}

	private static function image( \DOMElement $box, $page_url ) {
		foreach ( $box->getElementsByTagName( 'img' ) as $img ) {
			foreach ( array( 'data-src', 'data-original', 'data-lazy-src', 'src' ) as $attr ) {
				$src = trim( $img->getAttribute( $attr ) );
				if ( '' !== $src && 0 !== strpos( $src, 'data:' ) ) {
					$src = Util::absolute_url( $src, $page_url );
					if ( $src && ! preg_match( '~\.svg(\?|$)|logo|icon|avatar|placeholder~i', $src ) ) {
						return array(
							array(
								'url'    => $src,
								'type'   => '',
								'medium' => 'image',
								'width'  => 0,
								'height' => 0,
							),
						);
					}
				}
			}
		}
		return array();
	}

	private static function date( \DOMElement $box ) {
		foreach ( $box->getElementsByTagName( 'time' ) as $time ) {
			$ts = strtotime( (string) $time->getAttribute( 'datetime' ) );
			if ( $ts && $ts <= time() + DAY_IN_SECONDS ) {
				return (int) $ts;
			}
		}
		return 0;
	}

	private static function site_name( \DOMXPath $xp ) {
		foreach ( array( '//meta[@property="og:site_name"]/@content', '//meta[@name="application-name"]/@content' ) as $query ) {
			$node = $xp->query( $query )->item( 0 );
			if ( $node && '' !== trim( $node->value ) ) {
				return Util::text( $node->value );
			}
		}
		$title = $xp->query( '//title' )->item( 0 );
		if ( $title ) {
			$parts = preg_split( '/\s+[|\-–—]\s+/u', Util::text( $title->textContent ) );
			return trim( (string) end( $parts ) );
		}
		return '';
	}
}
