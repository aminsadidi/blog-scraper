<?php
/**
 * Pulls the full article body, lead, main image and video out of a news page.
 *
 * Order of attempts: the feed's own CSS selector → selectors of common Persian news CMSs
 * and WordPress themes → a readability-style paragraph scoring → JSON-LD articleBody.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Import;

use ParsiNewsRobot\Support\Dom;
use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Extractor {

	/**
	 * Body containers used by common Persian news CMSs and WordPress themes, most specific first.
	 */
	const KNOWN_BODY = array(
		'[itemprop="articleBody"]',
		'.item-text',
		'#echo_detail',
		'.echo_detail',
		'.news-text',
		'.newsText',
		'.news_text',
		'.news-body',
		'.news_body',
		'.newsBody',
		'.NewsText',
		'#newsMainBody',
		'.news-content',
		'.news_content',
		'.content-news',
		'.text-news',
		'.matn',
		'.nt-body',
		'.story',
		'.body',
		'.entry-content',
		'.post-content',
		'.td-post-content',
		'.single-post-content',
		'.single-content',
		'.article-body',
		'.article-content',
		'.article__body',
		'.story-body',
		'.content-body',
		'#content-body',
		'.post-body',
		'.post_content',
		'article',
	);

	/**
	 * Lead / summary containers (often outside the body on Persian news sites).
	 */
	const LEAD = array(
		'.summary',
		'.introtext',
		'.lead',
		'.news-lead',
		'.lead-text',
		'.news_lead',
		'.subtitle',
		'.sub-title',
		'.item-summary',
		'.news-summary',
		'.Lead',
		'p[itemprop="description"]',
		'div[itemprop="description"]',
	);

	/**
	 * Removed from the chosen body: sharing boxes, related news, tags, ads, comments...
	 */
	const JUNK = array(
		'script',
		'style',
		'button',
		'nav',
		'aside',
		'footer',
		'header',
		'h1',
		'.share',
		'.shares',
		'.sharing',
		'.social',
		'.related',
		'.related-news',
		'.related-posts',
		'.tags',
		'.tag-list',
		'.post-tags',
		'.news-tags',
		'.keywords',
		'.comments',
		'#comments',
		'.ads',
		'.advert',
		'.advertisement',
		'.banner',
		'.print',
		'.breadcrumb',
		'.breadcrumbs',
		'.pagination',
		'.author-box',
		'.newsletter',
		'.rating',
		'.item-footer',
		'.item-nav',
		'.short-link',
		'.shortlink',
		'.news-short-link',
		'[class*="share"]',
		'[class*="related"]',
		'[class*="advert"]',
		'[id*="advert"]',
		'[class*="social"]',
		'[class*="newsletter"]',
		'[class*="comment"]',
		'[class*="yektanet"]',
		'[id*="yektanet"]',
		'[id*="pos-article"]',
		'[class*="mediaad"]',
		'[class*="popular"]',
		'[class*="most-read"]',
		'[class*="mostread"]',
		'[data-e2e*="most"]',
		'[aria-labelledby*="most"]',
		'[aria-labelledby*="Most"]',
		'a[href^="#"]',
	);

	/**
	 * @param string $html Full page HTML (UTF-8).
	 * @param string $url  Page URL (used to resolve relative links).
	 * @param array  $cfg  Feed config (content_selector, remove_selectors, include_lead).
	 * @return array{content: string, lead: string, image: string, video: string, description: string, method: string}
	 */
	public static function extract( $html, $url, array $cfg = array() ) {
		$result = array(
			'content'     => '',
			'lead'        => '',
			'image'       => '',
			'video'       => '',
			'description' => '',
			'method'      => '',
			'base'        => $url,
			'title'       => '',
			'date'        => 0,
		);

		$doc = Dom::load( $html, true );
		$xp  = new \DOMXPath( $doc );

		// <base href> changes how every relative URL of the page resolves.
		$base = $xp->query( '//head/base[@href]' )->item( 0 );
		if ( $base ) {
			$resolved = Util::absolute_url( $base->getAttribute( 'href' ), $url );
			if ( $resolved ) {
				$result['base'] = $resolved;
				$url            = $resolved;
			}
		}

		$jsonld                = self::json_ld( $xp );
		$result['image']       = Util::absolute_url( self::meta( $xp, array( 'og:image', 'og:image:url', 'twitter:image', 'twitter:image:src' ) ), $url );
		$result['description'] = self::meta( $xp, array( 'og:description', 'description', 'twitter:description' ) );
		$result['video']       = Util::absolute_url( self::meta( $xp, array( 'og:video:secure_url', 'og:video:url', 'og:video', 'twitter:player' ) ), $url );
		if ( ! $result['image'] && $jsonld['image'] ) {
			$result['image'] = Util::absolute_url( $jsonld['image'], $url );
		}
		if ( ! $result['video'] && $jsonld['video'] ) {
			$result['video'] = Util::absolute_url( $jsonld['video'], $url );
		}

		$result['title'] = self::page_title( $xp );
		$result['date']  = self::published( $xp, $jsonld );

		// Lead first: it often lives in the header, which is removed below.
		if ( ! isset( $cfg['include_lead'] ) || $cfg['include_lead'] ) {
			foreach ( self::LEAD as $selector ) {
				$matches = Dom::select( $xp, $selector );
				if ( count( $matches ) > 2 ) {
					continue; // A class used by a list of teasers, not this story's lead.
				}
				foreach ( $matches as $node ) {
					if ( self::in_teaser_box( $node ) ) {
						continue;
					}
					$len = Dom::text_length( $node );
					if ( $len >= 30 && $len <= 1200 ) {
						$result['lead'] = Util::text( $node->textContent );
						break 2;
					}
				}
			}
		}

		foreach ( Dom::select( $xp, 'script, style, nav, aside, footer, [hidden]' ) as $node ) {
			Dom::remove( $node );
		}
		// Search / comment / newsletter forms go, but a form wrapping the whole page (ASP.NET sites) stays.
		foreach ( array_reverse( iterator_to_array( $xp->query( '//form' ) ) ) as $form ) {
			if ( Dom::text_length( $form ) < 300 ) {
				Dom::remove( $form );
			}
		}

		$nodes = array();
		if ( ! empty( $cfg['content_selector'] ) ) {
			$nodes = self::top_level( Dom::select( $xp, $cfg['content_selector'] ) );
			if ( $nodes ) {
				$result['method'] = 'selector';
			}
		}

		if ( ! $nodes ) {
			$known       = self::known_body( $xp );
			$heuristic   = self::heuristic( $xp );
			$known_len   = $known ? Dom::text_length( $known['node'] ) : 0;
			$heuristic_n = $heuristic ? Dom::text_length( $heuristic ) : 0;

			if ( $known && $known_len >= 0.6 * $heuristic_n ) {
				$nodes            = array( $known['node'] );
				$result['method'] = 'known:' . $known['selector'];
			} elseif ( $heuristic && $heuristic_n >= 150 ) {
				$nodes            = array( $heuristic );
				$result['method'] = 'auto';
			}
		}

		if ( $nodes ) {
			$junk = self::JUNK;
			if ( ! empty( $cfg['remove_selectors'] ) ) {
				$junk = array_merge( $junk, Util::lines( $cfg['remove_selectors'] ) );
			}
			$html_parts = array();
			foreach ( $nodes as $node ) {
				foreach ( Dom::select( $xp, implode( ',', $junk ), $node ) as $bad ) {
					Dom::remove( $bad );
				}
				self::remove_link_lists( $xp, $node );
				$html_parts[] = Dom::inner_html( $node );
			}
			$result['content'] = trim( implode( "\n", $html_parts ) );
		}

		if ( Util::strlen( Util::text( $result['content'] ) ) < 150 && $jsonld['body'] ) {
			$paragraphs        = preg_split( '/\n{1,}/u', trim( $jsonld['body'] ) );
			$result['content'] = '<p>' . implode( '</p><p>', array_map( 'esc_html', array_filter( array_map( 'trim', $paragraphs ) ) ) ) . '</p>';
			$result['method']  = 'jsonld';
		}

		if ( ! $result['lead'] && $jsonld['description'] ) {
			$result['lead'] = Util::text( $jsonld['description'] );
		}

		return $result;
	}

	/**
	 * The article's headline: og:title / h1, without a trailing " - site name".
	 */
	private static function page_title( \DOMXPath $xp ) {
		$h1 = $xp->query( '//h1' )->item( 0 );
		$h1 = $h1 ? Util::text( $h1->textContent ) : '';
		if ( Util::strlen( $h1 ) >= 8 ) {
			return $h1; // The h1 is the headline itself, without the site name.
		}
		$title = Util::text( self::meta( $xp, array( 'og:title', 'twitter:title' ) ) );
		$site  = Util::text( self::meta( $xp, array( 'og:site_name' ) ) );
		if ( $site && Util::strlen( $title ) > Util::strlen( $site ) + 3 ) {
			$title = (string) preg_replace( '/\s*[|\-–—:]\s*' . preg_quote( $site, '/' ) . '\s*$/u', '', $title );
		}
		return trim( $title );
	}

	/**
	 * Publication time from article meta, JSON-LD or <time>.
	 */
	private static function published( \DOMXPath $xp, array $jsonld ) {
		$candidates = array(
			self::meta( $xp, array( 'article:published_time', 'og:published_time', 'pubdate', 'publishdate', 'date', 'dc.date' ) ),
			$jsonld['date'],
		);
		$node = $xp->query( '//*[@itemprop="datePublished"]' )->item( 0 );
		if ( $node ) {
			$candidates[] = $node->getAttribute( 'content' ) ? $node->getAttribute( 'content' ) : $node->getAttribute( 'datetime' );
		}
		$time = $xp->query( '//time[@datetime]' )->item( 0 );
		if ( $time ) {
			$candidates[] = $time->getAttribute( 'datetime' );
		}
		foreach ( $candidates as $value ) {
			$ts = $value ? strtotime( (string) $value ) : false;
			if ( $ts && $ts > 946684800 && $ts <= time() + DAY_IN_SECONDS ) {
				return (int) $ts;
			}
		}
		return 0;
	}

	/**
	 * Whether a node sits in a list item, sidebar, navigation or related/most-read box.
	 */
	private static function in_teaser_box( \DOMNode $node ) {
		for ( $n = $node->parentNode; $n instanceof \DOMElement; $n = $n->parentNode ) {
			if ( in_array( strtolower( $n->nodeName ), array( 'li', 'aside', 'nav', 'footer' ), true ) ) {
				return true;
			}
			if ( preg_match( '/sidebar|side-bar|related|most|popular|teaser|widget|list|latest|slider/i', $n->getAttribute( 'class' ) . ' ' . $n->getAttribute( 'id' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Removes blocks that are mostly links ("most read", "related news" lists) and a heading left alone by them.
	 */
	private static function remove_link_lists( \DOMXPath $xp, \DOMNode $node ) {
		foreach ( array_reverse( iterator_to_array( $xp->query( './/ul | .//ol | .//div | .//section | .//table', $node ) ) ) as $block ) {
			if ( ! $block->parentNode || $xp->query( './/a', $block )->length < 2 ) {
				continue;
			}
			if ( Dom::text_length( $block ) > 0 && Dom::link_density( $xp, $block ) > 0.6 ) {
				$parent = $block->parentNode;
				Dom::remove( $block );
				if ( $parent && $parent !== $node && Dom::text_length( $parent ) < 40 && ! $xp->query( './/img | .//iframe | .//video', $parent )->length ) {
					Dom::remove( $parent );
				}
			}
		}
	}

	/**
	 * First matching known selector with enough text and few links (longest match wins for that selector).
	 */
	private static function known_body( \DOMXPath $xp ) {
		foreach ( self::KNOWN_BODY as $selector ) {
			$best     = null;
			$best_len = 0;
			foreach ( Dom::select( $xp, $selector ) as $node ) {
				if ( in_array( strtolower( $node->nodeName ), array( 'html', 'body' ), true ) ) {
					continue;
				}
				$len = Dom::text_length( $node );
				if ( $len > $best_len && $len >= 250 && Dom::link_density( $xp, $node ) < 0.5 ) {
					$best     = $node;
					$best_len = $len;
				}
			}
			if ( $best ) {
				return array(
					'node'     => $best,
					'selector' => $selector,
				);
			}
		}
		return null;
	}

	/**
	 * Readability-style scoring: paragraphs give points to their ancestors; the best-scoring container wins.
	 */
	private static function heuristic( \DOMXPath $xp ) {
		$candidates = array();
		$paragraphs = $xp->query( '//body//p | //body//pre | //body//div[not(.//div) and not(.//p) and not(.//table) and not(.//ul)]' );

		foreach ( $paragraphs as $p ) {
			$text = trim( (string) preg_replace( '/\s+/u', ' ', $p->textContent ) );
			$len  = Util::strlen( $text );
			if ( $len < 25 ) {
				continue;
			}
			$score = 1 + substr_count( $text, '،' ) + substr_count( $text, ',' ) + min( floor( $len / 100 ), 3 );

			$ancestor = $p->parentNode;
			for ( $level = 0; $level < 3 && $ancestor instanceof \DOMElement; $level++ ) {
				$tag = strtolower( $ancestor->nodeName );
				if ( 'body' === $tag || 'html' === $tag ) {
					break;
				}
				$key = $ancestor->getNodePath();
				if ( ! isset( $candidates[ $key ] ) ) {
					$candidates[ $key ] = array(
						'node'  => $ancestor,
						'score' => self::initial_score( $ancestor ),
					);
				}
				$candidates[ $key ]['score'] += $score / ( 0 === $level ? 1 : ( 1 === $level ? 2 : 3 ) );
				$ancestor                     = $ancestor->parentNode;
			}
		}

		$best       = null;
		$best_score = 0;
		foreach ( $candidates as $candidate ) {
			$score = $candidate['score'] * ( 1 - Dom::link_density( $xp, $candidate['node'] ) );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $candidate['node'];
			}
		}
		return $best;
	}

	private static function initial_score( \DOMElement $node ) {
		switch ( strtolower( $node->nodeName ) ) {
			case 'article':
				$score = 10;
				break;
			case 'div':
			case 'section':
			case 'main':
				$score = 5;
				break;
			case 'pre':
			case 'td':
			case 'blockquote':
				$score = 3;
				break;
			case 'ol':
			case 'ul':
			case 'li':
			case 'form':
			case 'address':
				$score = -3;
				break;
			default:
				$score = 0;
		}
		$attrs = $node->getAttribute( 'class' ) . ' ' . $node->getAttribute( 'id' );
		if ( preg_match( '/comment|footer|foot|sidebar|side-bar|widget|related|share|social|tags?\b|menu|nav|breadcrumb|banner|ads?\b|advert|promo|popup|header|subscribe|newsletter|poll|login|copyright|pager|pagination|popular|most|latest|similar|keyword|toolbar|rating|vote|list/i', $attrs ) ) {
			$score -= 25;
		}
		if ( preg_match( '/article|body|content|entry|main|post|text|story|detail|matn|khabar/i', $attrs ) ) {
			$score += 25;
		}
		return $score;
	}

	/**
	 * Drops nodes nested inside other matched nodes so content is not duplicated.
	 */
	private static function top_level( array $nodes ) {
		$out = array();
		foreach ( $nodes as $node ) {
			$nested = false;
			foreach ( $nodes as $other ) {
				if ( $other !== $node && self::contains( $other, $node ) ) {
					$nested = true;
					break;
				}
			}
			if ( ! $nested ) {
				$out[] = $node;
			}
		}
		return $out;
	}

	private static function contains( \DOMNode $ancestor, \DOMNode $node ) {
		for ( $n = $node->parentNode; $n; $n = $n->parentNode ) {
			if ( $n === $ancestor ) {
				return true;
			}
		}
		return false;
	}

	private static function meta( \DOMXPath $xp, array $names ) {
		foreach ( $names as $name ) {
			$query = sprintf( '//meta[@property="%1$s" or @name="%1$s"]/@content', $name );
			foreach ( $xp->query( $query ) as $attr ) {
				$value = trim( $attr->value );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * Reads Article / NewsArticle / VideoObject data from JSON-LD blocks.
	 */
	private static function json_ld( \DOMXPath $xp ) {
		$out = array(
			'body'        => '',
			'image'       => '',
			'description' => '',
			'video'       => '',
			'date'        => '',
		);
		foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $script ) {
			$data = json_decode( trim( $script->textContent ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$stack = array( $data );
			while ( $stack ) {
				$item = array_pop( $stack );
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( isset( $item['@graph'] ) && is_array( $item['@graph'] ) ) {
					$stack = array_merge( $stack, $item['@graph'] );
				}
				if ( array_keys( $item ) === range( 0, count( $item ) - 1 ) ) {
					$stack = array_merge( $stack, $item );
					continue;
				}
				$types = isset( $item['@type'] ) ? (array) $item['@type'] : array();
				$types = implode( ' ', array_filter( $types, 'is_string' ) );

				if ( preg_match( '/Article|BlogPosting|Reportage/i', $types ) ) {
					if ( ! $out['body'] && ! empty( $item['articleBody'] ) && is_string( $item['articleBody'] ) ) {
						$out['body'] = $item['articleBody'];
					}
					if ( ! $out['date'] && ! empty( $item['datePublished'] ) && is_string( $item['datePublished'] ) ) {
						$out['date'] = $item['datePublished'];
					}
					if ( ! $out['description'] && ! empty( $item['description'] ) && is_string( $item['description'] ) ) {
						$out['description'] = $item['description'];
					}
					if ( ! $out['image'] && ! empty( $item['image'] ) ) {
						$out['image'] = self::ld_url( $item['image'] );
					}
					if ( ! empty( $item['video'] ) && is_array( $item['video'] ) ) {
						$stack[] = $item['video'];
					}
				}
				if ( false !== stripos( $types, 'VideoObject' ) && ! $out['video'] ) {
					$out['video'] = ! empty( $item['embedUrl'] ) ? self::ld_url( $item['embedUrl'] ) : ( ! empty( $item['contentUrl'] ) ? self::ld_url( $item['contentUrl'] ) : '' );
				}
			}
		}
		return $out;
	}

	private static function ld_url( $value ) {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
				return $value['url'];
			}
			$first = reset( $value );
			return false !== $first ? self::ld_url( $first ) : '';
		}
		return '';
	}
}
