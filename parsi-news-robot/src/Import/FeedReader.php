<?php
/**
 * Downloads and parses an RSS/Atom/RDF feed into plain arrays.
 *
 * The feed is fetched with our own HTTP layer (browser user agent, conditional GET, size limit) and parsed
 * by the SimplePie bundled with WordPress. SimplePie's tag stripping is disabled because the Cleaner does a
 * stricter whitelist pass later; SimplePie uses expat, which does not resolve external entities (no XXE).
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Import;

use ParsiNewsRobot\Support\Http;
use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class FeedReader {

	const MAX_ITEMS = 50;

	/**
	 * @return array{not_modified: bool, items: array, title: string, home: string, etag: string, last_modified: string}|\WP_Error
	 */
	public static function read( $url, $etag = '', $last_modified = '' ) {
		$response = Http::get(
			$url,
			array(
				'accept'        => 'application/rss+xml, application/atom+xml, application/rdf+xml, application/xml;q=0.9, text/xml;q=0.9, */*;q=0.5',
				'limit'         => 5 * MB_IN_BYTES,
				'etag'          => $etag,
				'last_modified' => $last_modified,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$out = array(
			'not_modified'  => (bool) $response['not_modified'],
			'items'         => array(),
			'title'         => '',
			'home'          => '',
			'etag'          => $response['etag'],
			'last_modified' => $response['last_modified'],
		);
		if ( $out['not_modified'] ) {
			return $out;
		}

		$feed = self::parse( $response['body'] );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}

		$out['title'] = Util::text( (string) $feed->get_title() );
		$out['home']  = (string) $feed->get_link();

		foreach ( (array) $feed->get_items( 0, self::MAX_ITEMS ) as $item ) {
			$normalized = self::normalize_item( $item, $response['url'] );
			if ( $normalized['title'] && ( $normalized['link'] || $normalized['guid'] ) ) {
				$out['items'][] = $normalized;
			}
		}

		// Newest first.
		usort(
			$out['items'],
			function ( $a, $b ) {
				return $b['date'] <=> $a['date'];
			}
		);
		return $out;
	}

	/**
	 * @return object|\WP_Error SimplePie instance.
	 */
	public static function parse( $xml ) {
		if ( '' === trim( (string) $xml ) ) {
			return new \WP_Error( 'pnr_feed_empty', 'فید خالی است.' );
		}
		if ( ! class_exists( '\SimplePie\SimplePie' ) && ! class_exists( '\SimplePie', false ) ) {
			require_once ABSPATH . WPINC . '/class-simplepie.php';
		}
		$class = class_exists( '\SimplePie\SimplePie' ) ? '\SimplePie\SimplePie' : '\SimplePie';

		$feed = new $class();
		$feed->set_raw_data( $xml );
		$feed->enable_cache( false );
		$feed->enable_order_by_date( true );
		$feed->strip_htmltags( false );
		$feed->strip_attributes( false );
		$feed->init();

		if ( $feed->error() ) {
			$error = $feed->error();
			return new \WP_Error( 'pnr_feed_parse', 'فید قابل خواندن نیست: ' . ( is_array( $error ) ? implode( ' ', $error ) : $error ) );
		}
		return $feed;
	}

	private static function normalize_item( $item, $feed_url ) {
		$link = (string) $item->get_permalink();
		$link = $link ? Util::absolute_url( $link, $feed_url ) : '';

		$enclosures = array();
		foreach ( (array) $item->get_enclosures() as $enclosure ) {
			if ( ! $enclosure ) {
				continue;
			}
			$e_url = (string) $enclosure->get_link();
			if ( $e_url && preg_match( '~^https?://~i', $e_url ) ) {
				$enclosures[] = array(
					'url'    => $e_url,
					'type'   => (string) $enclosure->get_type(),
					'medium' => (string) $enclosure->get_medium(),
					'width'  => (int) $enclosure->get_width(),
					'height' => (int) $enclosure->get_height(),
				);
			}
			$thumb = $enclosure->get_thumbnail();
			if ( $thumb && preg_match( '~^https?://~i', $thumb ) ) {
				$enclosures[] = array(
					'url'    => (string) $thumb,
					'type'   => '',
					'medium' => 'image',
					'width'  => 0,
					'height' => 0,
				);
			}
		}

		$categories = array();
		foreach ( (array) $item->get_categories() as $category ) {
			if ( $category ) {
				$label = Util::text( (string) ( $category->get_label() ? $category->get_label() : $category->get_term() ) );
				if ( '' !== $label && Util::strlen( $label ) < 60 ) {
					$categories[] = $label;
				}
			}
		}

		$content     = (string) $item->get_content( false );
		$description = (string) $item->get_description( true );
		$date        = (int) $item->get_date( 'U' );
		$updated     = (int) $item->get_updated_date( 'U' );

		return array(
			'guid'        => (string) $item->get_id( false ),
			'link'        => $link,
			'title'       => Util::text( (string) $item->get_title() ),
			'date'        => $date,
			'updated'     => $updated,
			'content'     => $content,
			'description' => $description,
			'enclosures'  => $enclosures,
			'categories'  => array_values( array_unique( $categories ) ),
		);
	}

	/**
	 * Finds feed links on a web page (<link rel="alternate">) and tries common feed paths.
	 *
	 * @return array<int, array{url: string, title: string}>
	 */
	public static function discover( $site_url ) {
		$found = array();
		$page  = Http::get( $site_url, array( 'html' => true ) );
		if ( ! is_wp_error( $page ) ) {
			if ( preg_match( '~<(rss|feed|rdf:RDF)[\s>]~i', substr( $page['body'], 0, 2000 ) ) ) {
				return array(
					array(
						'url'   => $page['url'],
						'title' => 'آدرس واردشده خودش فید است',
					),
				);
			}
			if ( preg_match_all( '~<link\b[^>]*>~i', $page['body'], $tags ) ) {
				foreach ( $tags[0] as $tag ) {
					if ( ! preg_match( '~rel=["\']?alternate~i', $tag ) || ! preg_match( '~type=["\']?application/(rss|atom|rdf)\+xml~i', $tag ) ) {
						continue;
					}
					if ( preg_match( '~href=["\']([^"\']+)~i', $tag, $h ) ) {
						$title   = preg_match( '~title=["\']([^"\']*)~i', $tag, $t ) ? html_entity_decode( $t[1], ENT_QUOTES, 'UTF-8' ) : '';
						$abs     = Util::absolute_url( $h[1], $page['url'] );
						$found[] = array(
							'url'   => $abs,
							'title' => $title,
						);
					}
				}
			}
		}

		// Only probe common feed paths when the site answered at all (an unreachable site would time out 16 times).
		if ( ! $found && ! is_wp_error( $page ) ) {
			$parts = wp_parse_url( $site_url );
			if ( ! empty( $parts['host'] ) ) {
				$origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'];
				$base   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
				$paths  = array( '/rss', '/feed', '/rss.xml', '/feed.xml', '/fa/rss', '/fa/rss/allnews', '/rss/all', '/rss/latest' );
				$probes = array();
				foreach ( $paths as $path ) {
					if ( '' !== $base ) {
						$probes[] = $origin . $base . $path;
					}
					$probes[] = $origin . $path;
				}
				foreach ( array_unique( $probes ) as $probe_url ) {
					$probe = Http::get(
						$probe_url,
						array(
							'limit'   => 512 * KB_IN_BYTES,
							'timeout' => 6,
						)
					);
					if ( ! is_wp_error( $probe ) && preg_match( '~<(rss|feed|rdf:RDF)[\s>]~i', substr( $probe['body'], 0, 2000 ) ) ) {
						$found[] = array(
							'url'   => $probe_url,
							'title' => '',
						);
						if ( count( $found ) >= 3 ) {
							break;
						}
					}
				}
			}
		}

		$unique = array();
		foreach ( $found as $row ) {
			$unique[ $row['url'] ] = $row;
		}
		return array_values( $unique );
	}
}
