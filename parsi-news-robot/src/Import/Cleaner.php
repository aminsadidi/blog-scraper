<?php
/**
 * Turns scraped HTML into safe, theme-friendly post content and reports its images and videos.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Import;

use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Support\Dom;
use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Cleaner {

	const BLOCK_TAGS = 'p|div|table|ul|ol|figure|iframe|video|audio|blockquote|h2|h3|h4|h5|h6|pre|img';

	/**
	 * @param string $html Raw HTML fragment.
	 * @param string $base URL the fragment came from.
	 * @param array  $opts iframe_hosts, remove (CSS selectors), signatures (phrases, one per line).
	 * @return array{html: string, images: string[], has_video: bool}
	 */
	public static function clean( $html, $base, array $opts = array() ) {
		$opts = array_merge(
			array(
				'signatures'   => Settings::get( 'signatures' ),
				'iframe_hosts' => Settings::iframe_hosts(),
				'remove'       => '',
			),
			$opts
		);

		$doc       = Dom::load( $html );
		$xp        = new \DOMXPath( $doc );
		$body      = Dom::body( $doc );
		$images    = array();
		$has_video = false;
		if ( ! $body ) {
			return array(
				'html'      => '',
				'images'    => array(),
				'has_video' => false,
			);
		}
		$source_host = Util::host( $base );
		$site_host   = Util::host( home_url() );

		// Aparat's script embed → a plain iframe.
		foreach ( iterator_to_array( $xp->query( '//script[@src]' ) ) as $script ) {
			if ( preg_match( '~aparat\.com/embed/([A-Za-z0-9]+)~i', $script->getAttribute( 'src' ), $m ) ) {
				$iframe = $doc->createElement( 'iframe' );
				$iframe->setAttribute( 'src', 'https://www.aparat.com/video/video/embed/videohash/' . $m[1] . '/vt/frame' );
				$script->parentNode->replaceChild( $iframe, $script );
			}
		}

		foreach ( iterator_to_array( $xp->query( '//script | //style | //noscript | //form | //button | //input | //select | //textarea | //svg | //canvas | //link | //meta | //object | //embed | //applet | //comment()' ) ) as $node ) {
			Dom::remove( $node );
		}
		if ( $opts['remove'] ) {
			foreach ( Dom::select( $xp, implode( ',', Util::lines( $opts['remove'] ) ) ) as $node ) {
				Dom::remove( $node );
			}
		}

		// <picture> → its <img>.
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'picture' ) ) as $picture ) {
			$img = $picture->getElementsByTagName( 'img' )->item( 0 );
			if ( ! $img ) {
				$source = $picture->getElementsByTagName( 'source' )->item( 0 );
				if ( $source ) {
					$img = $doc->createElement( 'img' );
					$img->setAttribute( 'srcset', $source->getAttribute( 'srcset' ) );
				}
			}
			if ( $img ) {
				$picture->parentNode->replaceChild( $img, $picture );
			} else {
				Dom::remove( $picture );
			}
		}

		// Images.
		$seen = array();
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'img' ) ) as $img ) {
			$src = Util::absolute_url( self::image_source( $img ), $base );
			$key = $src ? Util::image_key( $src ) : '';
			if ( ! $src || self::is_junk_image( $src, $img ) || isset( $seen[ $key ] ) ) {
				Dom::remove( $img );
				continue;
			}
			$seen[ $key ] = true;
			$alt          = trim( $img->getAttribute( 'alt' ) );
			$width        = (int) $img->getAttribute( 'width' );
			$height       = (int) $img->getAttribute( 'height' );
			self::strip_attributes( $img );
			$img->setAttribute( 'src', $src );
			$img->setAttribute( 'alt', $alt );
			if ( $width > 0 && $height > 0 ) {
				$img->setAttribute( 'width', (string) $width );
				$img->setAttribute( 'height', (string) $height );
			}
			$img->setAttribute( 'loading', 'lazy' );
			$img->setAttribute( 'decoding', 'async' );
			$img->setAttribute( 'referrerpolicy', 'no-referrer' );
			$images[] = $src;
		}

		// Iframes: keep only known embed hosts and the source site's own player.
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'iframe' ) ) as $iframe ) {
			$src     = $iframe->getAttribute( 'src' ) ? $iframe->getAttribute( 'src' ) : $iframe->getAttribute( 'data-src' );
			$src     = Util::absolute_url( $src, $base );
			$host    = Util::host( $src );
			$allowed = $src && Util::host_matches( $host, $source_host );
			foreach ( $opts['iframe_hosts'] as $allowed_host ) {
				$allowed = $allowed || Util::host_matches( $host, $allowed_host );
			}
			if ( ! $allowed ) {
				Dom::remove( $iframe );
				continue;
			}
			$width  = (int) $iframe->getAttribute( 'width' );
			$height = (int) $iframe->getAttribute( 'height' );
			self::strip_attributes( $iframe );
			$iframe->setAttribute( 'src', $src );
			$iframe->setAttribute( 'loading', 'lazy' );
			$iframe->setAttribute( 'allowfullscreen', 'true' );
			$iframe->setAttribute( 'allow', 'autoplay; encrypted-media; fullscreen; picture-in-picture' );
			if ( $width > 0 && $height > 0 ) {
				$iframe->setAttribute( 'width', (string) $width );
				$iframe->setAttribute( 'height', (string) $height );
			}
			if ( self::is_video_url( $src, $source_host ) ) {
				$has_video = true;
				$wrapper   = $doc->createElement( 'div' );
				$wrapper->setAttribute( 'class', 'pnr-video' );
				$iframe->parentNode->replaceChild( $wrapper, $iframe );
				$wrapper->appendChild( $iframe );
			}
		}

		// Native video / audio.
		foreach ( array( 'video', 'audio' ) as $tag ) {
			foreach ( iterator_to_array( $doc->getElementsByTagName( $tag ) ) as $media ) {
				$src    = Util::absolute_url( $media->getAttribute( 'src' ) ? $media->getAttribute( 'src' ) : $media->getAttribute( 'data-src' ), $base );
				$poster = Util::absolute_url( $media->getAttribute( 'poster' ), $base );
				$ok     = (bool) $src;
				foreach ( iterator_to_array( $media->getElementsByTagName( 'source' ) ) as $source ) {
					$s_src = Util::absolute_url( $source->getAttribute( 'src' ) ? $source->getAttribute( 'src' ) : $source->getAttribute( 'data-src' ), $base );
					$type  = $source->getAttribute( 'type' );
					self::strip_attributes( $source );
					if ( $s_src ) {
						$source->setAttribute( 'src', $s_src );
						if ( $type ) {
							$source->setAttribute( 'type', $type );
						}
						$ok = true;
					} else {
						Dom::remove( $source );
					}
				}
				if ( ! $ok ) {
					Dom::remove( $media );
					continue;
				}
				self::strip_attributes( $media );
				if ( $src ) {
					$media->setAttribute( 'src', $src );
				}
				if ( $poster && 'video' === $tag ) {
					$media->setAttribute( 'poster', $poster );
				}
				$media->setAttribute( 'controls', 'controls' );
				$media->setAttribute( 'preload', 'none' );
				if ( 'video' === $tag ) {
					$media->setAttribute( 'playsinline', 'playsinline' );
					$has_video = true;
				}
			}
		}

		// Links: every link that comes from the source is marked, so its rel can be controlled at render time
		// (see Seo\Links) without ever touching links the site's editors add later.
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'a' ) ) as $a ) {
			$href = Util::absolute_url( $a->getAttribute( 'href' ), $base );
			if ( ! $href ) {
				Dom::unwrap( $a );
				continue;
			}
			self::strip_attributes( $a );
			$a->setAttribute( 'href', $href );
			if ( ! Util::host_matches( Util::host( $href ), $site_host ) ) {
				$a->setAttribute( 'data-pnr-link', '1' );
			}
		}

		// Blocks that are nothing but a bare URL (short links, "print" addresses).
		foreach ( iterator_to_array( $xp->query( '//body//*[' . self::tag_test( 'p|div|span|li' ) . ']' ) ) as $el ) {
			if ( self::attached( $el ) && preg_match( '~^\s*https?://\S+\s*$~u', $el->textContent ) && ! $xp->query( './/img | .//iframe | .//video', $el )->length ) {
				Dom::remove( $el );
			}
		}

		// Source signatures ("انتهای پیام", "کد خبر", channel ads...): short blocks containing a listed phrase.
		$phrases = array_filter( array_map( array( Util::class, 'normalize_fa' ), Util::lines( $opts['signatures'] ) ) );
		if ( $phrases ) {
			// Document order: the outermost short block containing the phrase goes, not just the inner span.
			foreach ( iterator_to_array( $xp->query( '//body//*[' . self::tag_test( 'p|div|span|strong|b|em|li|h2|h3|h4|h5|h6|blockquote|figcaption' ) . ']' ) ) as $el ) {
				if ( ! self::attached( $el ) || Util::strlen( $el->textContent ) > 200 || $xp->query( './/img | .//iframe | .//video', $el )->length ) {
					continue;
				}
				$text = Util::normalize_fa( $el->textContent );
				foreach ( $phrases as $phrase ) {
					if ( '' !== $phrase && false !== mb_strpos( $text, $phrase ) ) {
						Dom::remove( $el );
						break;
					}
				}
			}
		}

		// Headings: the post title is the only h1.
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'h1' ) ) as $h1 ) {
			Dom::rename( $h1, 'h2' );
		}

		// Text-only divs → paragraphs, so the theme's typography applies.
		foreach ( iterator_to_array( $xp->query( '//body//div[not(@class="pnr-video")]' ) ) as $div ) {
			if ( ! $xp->query( './/*[' . self::tag_test( self::BLOCK_TAGS ) . ']', $div )->length && '' !== trim( $div->textContent ) ) {
				Dom::rename( $div, 'p' );
			}
		}

		// Drop attributes we do not want (classes, styles, ids, event handlers...).
		foreach ( iterator_to_array( $xp->query( '//body//*' ) ) as $el ) {
			self::filter_attributes( $el );
		}

		// Remove empty wrappers (twice for nesting).
		for ( $pass = 0; $pass < 2; $pass++ ) {
			foreach ( iterator_to_array( $xp->query( '//body//*[' . self::tag_test( 'p|div|span|strong|b|em|i|figure|li|ul|ol|h2|h3|h4|h5|h6|blockquote|section|article|font|center|a' ) . ']' ) ) as $el ) {
				$text = trim( str_replace( "\xC2\xA0", ' ', $el->textContent ) );
				if ( '' === $text && ! $xp->query( './/img | .//iframe | .//video | .//audio', $el )->length ) {
					Dom::remove( $el );
				}
			}
		}

		$out = wp_kses( Dom::inner_html( $body ), self::allowed_html() );
		$out = preg_replace( '~(<br\s*/?>\s*){3,}~i', '<br><br>', $out );
		$out = preg_replace( '~<p>(\s|&nbsp;|<br\s*/?>)*</p>~i', '', $out );

		return array(
			'html'      => trim( (string) $out ),
			'images'    => $images,
			'has_video' => $has_video,
		);
	}

	/**
	 * Removes an image (by URL key) from cleaned HTML, together with a <figure> left empty by it.
	 */
	public static function remove_image( $html, $url ) {
		$key = Util::image_key( $url );
		if ( '' === $key ) {
			return $html;
		}
		$doc     = Dom::load( $html );
		$changed = false;
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'img' ) ) as $img ) {
			if ( Util::image_key( $img->getAttribute( 'src' ) ) !== $key ) {
				continue;
			}
			// A figure whose only image was the featured one: its caption goes with it.
			for ( $figure = $img->parentNode; $figure && 'body' !== $figure->nodeName && 'figure' !== $figure->nodeName; $figure = $figure->parentNode ) {
				continue;
			}
			if ( $figure && 'figure' === $figure->nodeName && 1 === $figure->getElementsByTagName( 'img' )->length && ! $figure->getElementsByTagName( 'iframe' )->length && ! $figure->getElementsByTagName( 'video' )->length ) {
				$parent = $figure->parentNode;
				Dom::remove( $figure );
			} else {
				$parent = $img->parentNode;
				Dom::remove( $img );
			}
			$changed = true;
			while ( $parent && 'body' !== $parent->nodeName && '' === trim( $parent->textContent ) && ! $parent->getElementsByTagName( 'img' )->length && ! $parent->getElementsByTagName( 'iframe' )->length && ! $parent->getElementsByTagName( 'video' )->length ) {
				$next = $parent->parentNode;
				Dom::remove( $parent );
				$parent = $next;
			}
		}
		return $changed ? trim( Dom::inner_html( Dom::body( $doc ) ) ) : $html;
	}

	/**
	 * Image URLs in cleaned HTML, in document order.
	 *
	 * @return string[]
	 */
	public static function image_urls( $html ) {
		if ( false === stripos( (string) $html, '<img' ) ) {
			return array();
		}
		$urls = array();
		foreach ( Dom::load( $html )->getElementsByTagName( 'img' ) as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( $src ) {
				$urls[] = $src;
			}
		}
		return $urls;
	}

	public static function is_video_url( $url, $source_host = '' ) {
		$host = Util::host( $url );
		foreach ( Settings::video_hosts() as $video_host ) {
			if ( Util::host_matches( $host, $video_host ) ) {
				return true;
			}
		}
		if ( preg_match( '~\.(mp4|m3u8|webm|mov)(\?|$)~i', $url ) ) {
			return true;
		}
		// The source site's own player page (e.g. /embed/…, /video/…, /player/…).
		return $source_host && Util::host_matches( $host, $source_host ) && preg_match( '~video|embed|player|media|film~i', (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}

	/**
	 * The allowed tags for imported content.
	 */
	public static function allowed_html() {
		$none = array();
		return array(
			'p'          => array( 'class' => true ),
			'br'         => $none,
			'strong'     => $none,
			'b'          => $none,
			'em'         => $none,
			'i'          => $none,
			'u'          => $none,
			'sub'        => $none,
			'sup'        => $none,
			'span'       => $none,
			'h2'         => $none,
			'h3'         => $none,
			'h4'         => $none,
			'h5'         => $none,
			'h6'         => $none,
			'ul'         => $none,
			'ol'         => $none,
			'li'         => $none,
			'hr'         => $none,
			'pre'        => $none,
			'code'       => $none,
			'q'          => $none,
			'cite'       => $none,
			'figcaption' => $none,
			'figure'     => $none,
			'table'      => $none,
			'thead'      => $none,
			'tbody'      => $none,
			'tfoot'      => $none,
			'tr'         => $none,
			'caption'    => $none,
			'th'         => array(
				'colspan' => true,
				'rowspan' => true,
			),
			'td'         => array(
				'colspan' => true,
				'rowspan' => true,
			),
			'blockquote' => array(
				'class' => true,
				'cite'  => true,
			),
			'div'        => array( 'class' => true ),
			'a'          => array(
				'href'          => true,
				'data-pnr-link' => true,
				'title'  => true,
				'rel'    => true,
				'target' => true,
			),
			'img'        => array(
				'src'            => true,
				'alt'            => true,
				'title'          => true,
				'width'          => true,
				'height'         => true,
				'loading'        => true,
				'decoding'       => true,
				'referrerpolicy' => true,
				'class'          => true,
			),
			'iframe'     => array(
				'src'             => true,
				'width'           => true,
				'height'          => true,
				'allow'           => true,
				'allowfullscreen' => true,
				'loading'         => true,
				'title'           => true,
				'referrerpolicy'  => true,
				'frameborder'     => true,
			),
			'video'      => array(
				'src'         => true,
				'poster'      => true,
				'controls'    => true,
				'preload'     => true,
				'width'       => true,
				'height'      => true,
				'playsinline' => true,
			),
			'audio'      => array(
				'src'      => true,
				'controls' => true,
				'preload'  => true,
			),
			'source'     => array(
				'src'  => true,
				'type' => true,
			),
		);
	}

	private static function image_source( \DOMElement $img ) {
		foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'data-lazy', 'data-url', 'data-full-url', 'data-orig-file' ) as $attr ) {
			$value = trim( $img->getAttribute( $attr ) );
			if ( '' !== $value && 0 !== strpos( $value, 'data:' ) ) {
				return $value;
			}
		}
		foreach ( array( 'data-srcset', 'data-lazy-srcset', 'srcset' ) as $attr ) {
			$value = self::largest_from_srcset( $img->getAttribute( $attr ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		$src = trim( $img->getAttribute( 'src' ) );
		return 0 === strpos( $src, 'data:' ) ? '' : $src;
	}

	private static function largest_from_srcset( $srcset ) {
		$best      = '';
		$best_size = -1;
		foreach ( explode( ',', (string) $srcset ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ) );
			if ( empty( $parts[0] ) || 0 === strpos( $parts[0], 'data:' ) ) {
				continue;
			}
			$size = isset( $parts[1] ) ? (float) $parts[1] : 1;
			if ( $size > $best_size ) {
				$best_size = $size;
				$best      = $parts[0];
			}
		}
		return $best;
	}

	private static function is_junk_image( $src, \DOMElement $img ) {
		$path = strtolower( rawurldecode( (string) wp_parse_url( $src, PHP_URL_PATH ) ) );
		if ( preg_match( '~\.svg$~', $path ) ) {
			return true;
		}
		if ( preg_match( '~(^|[/_.-])(logo|icon|icons|avatar|emoji|spacer|pixel|blank|loading|loader|placeholder|sprite|badge|captcha)([/_.-]|$)~', $path ) ) {
			return true;
		}
		if ( preg_match( '~gravatar\.com|doubleclick|googlesyndication|facebook\.com/tr|yektanet|mediaad~i', $src ) ) {
			return true;
		}
		if ( preg_match( '~^(خبرنامه|newsletter|تبلیغ|آگهی|advertisement|banner|logo|لوگو)$~iu', trim( $img->getAttribute( 'alt' ) ) ) ) {
			return true;
		}
		$width  = (int) $img->getAttribute( 'width' );
		$height = (int) $img->getAttribute( 'height' );
		return ( $width && $width < 80 ) || ( $height && $height < 60 );
	}

	/**
	 * Still inside the document (no removed ancestor)?
	 */
	private static function attached( \DOMNode $node ) {
		for ( $n = $node; $n; $n = $n->parentNode ) {
			if ( 'body' === $n->nodeName ) {
				return true;
			}
		}
		return false;
	}

	private static function strip_attributes( \DOMElement $el ) {
		while ( $el->attributes->length ) {
			$el->removeAttribute( $el->attributes->item( 0 )->nodeName );
		}
	}

	/**
	 * Keeps the attributes listed in allowed_html() and only our own classes.
	 */
	private static function filter_attributes( \DOMElement $el ) {
		static $allowed = null;
		if ( null === $allowed ) {
			$allowed = self::allowed_html();
		}
		$tag   = strtolower( $el->nodeName );
		$keep  = isset( $allowed[ $tag ] ) ? $allowed[ $tag ] : array();
		$names = array();
		foreach ( $el->attributes as $attr ) {
			$names[] = $attr->nodeName;
		}
		foreach ( $names as $name ) {
			$lower = strtolower( $name );
			if ( ! isset( $keep[ $lower ] ) ) {
				$el->removeAttribute( $name );
			} elseif ( 'class' === $lower ) {
				$classes = array_filter(
					preg_split( '/\s+/', $el->getAttribute( $name ) ),
					function ( $c ) {
						return 0 === strpos( $c, 'pnr-' ) || in_array( $c, array( 'twitter-tweet', 'instagram-media' ), true );
					}
				);
				if ( $classes ) {
					$el->setAttribute( $name, implode( ' ', $classes ) );
				} else {
					$el->removeAttribute( $name );
				}
			}
		}
	}

	private static function tag_test( $tags ) {
		return implode(
			' or ',
			array_map(
				function ( $t ) {
					return 'self::' . $t;
				},
				explode( '|', $tags )
			)
		);
	}
}
