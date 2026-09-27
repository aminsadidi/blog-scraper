<?php
/**
 * \DOMDocument helpers and a small CSS-selector-to-XPath converter.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Support;

defined( 'ABSPATH' ) || exit;

class Dom {

	/**
	 * Loads HTML as UTF-8. With $full = false the string is treated as a fragment and wrapped in <body>.
	 *
	 * @return \DOMDocument
	 */
	public static function load( $html, $full = false ) {
		$html = (string) $html;
		if ( $full ) {
			$html = preg_replace( '/<meta[^>]+charset\s*=[^>]*>/i', '', $html );
		} else {
			$html = '<!DOCTYPE html><html><head></head><body>' . $html . '</body></html>';
		}

		$doc  = new \DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		foreach ( iterator_to_array( $doc->childNodes ) as $child ) {
			if ( XML_PI_NODE === $child->nodeType ) {
				$doc->removeChild( $child );
			}
		}
		$doc->encoding = 'UTF-8';
		return $doc;
	}

	public static function body( \DOMDocument $doc ) {
		return $doc->getElementsByTagName( 'body' )->item( 0 );
	}

	public static function inner_html( \DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	public static function text_length( \DOMNode $node ) {
		return Util::strlen( trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) ) );
	}

	/**
	 * Share of the node's text that sits inside links (0..1).
	 */
	public static function link_density( \DOMXPath $xp, \DOMNode $node ) {
		$total = self::text_length( $node );
		if ( ! $total ) {
			return 1.0;
		}
		$links = 0;
		foreach ( $xp->query( './/a', $node ) as $a ) {
			$links += self::text_length( $a );
		}
		return min( 1.0, $links / $total );
	}

	public static function remove( \DOMNode $node ) {
		if ( $node->parentNode ) {
			$node->parentNode->removeChild( $node );
		}
	}

	/**
	 * Replaces the node with its children.
	 */
	public static function unwrap( \DOMNode $node ) {
		$parent = $node->parentNode;
		if ( ! $parent ) {
			return;
		}
		while ( $node->firstChild ) {
			$parent->insertBefore( $node->firstChild, $node );
		}
		$parent->removeChild( $node );
	}

	/**
	 * Renames an element (keeps children, drops attributes).
	 */
	public static function rename( \DOMElement $node, $tag ) {
		$new = $node->ownerDocument->createElement( $tag );
		while ( $node->firstChild ) {
			$new->appendChild( $node->firstChild );
		}
		$node->parentNode->replaceChild( $new, $node );
		return $new;
	}

	/**
	 * Runs a CSS selector and returns matching elements as an array.
	 *
	 * @return \DOMElement[]
	 */
	public static function select( \DOMXPath $xp, $selector, $context = null ) {
		$xpath = self::css_to_xpath( $selector, $context ? './/' : '//' );
		if ( '' === $xpath ) {
			return array();
		}
		$prev   = libxml_use_internal_errors( true );
		$result = $context ? @$xp->query( $xpath, $context ) : @$xp->query( $xpath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $result ? iterator_to_array( $result ) : array();
	}

	/**
	 * Converts a (subset of) CSS selector to XPath.
	 * Supports: tag, *, #id, .class, [attr], [attr=v], [attr*=v], [attr^=v], [attr$=v], [attr~=v],
	 * descendant and child (>) combinators, and comma-separated groups.
	 */
	public static function css_to_xpath( $selector, $prefix = '//' ) {
		$out = array();
		foreach ( self::split_groups( (string) $selector ) as $group ) {
			$tokens = self::tokenize( $group );
			if ( ! $tokens ) {
				continue;
			}
			$xpath = $prefix;
			$first = true;
			$axis  = '//';
			foreach ( $tokens as $token ) {
				if ( '>' === $token ) {
					$axis = '/';
					continue;
				}
				if ( ! $first ) {
					$xpath .= $axis;
				}
				$xpath .= self::compound( $token );
				$first  = false;
				$axis   = '//';
			}
			if ( ! $first ) {
				$out[] = $xpath;
			}
		}
		return implode( ' | ', $out );
	}

	private static function split_groups( $selector ) {
		$groups = array();
		$buf    = '';
		$depth  = 0;
		$quote  = '';
		$len    = strlen( $selector );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $selector[ $i ];
			if ( $quote ) {
				if ( $c === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '[' === $c ) {
				$depth++;
			} elseif ( ']' === $c ) {
				$depth--;
			} elseif ( ( ',' === $c || "\n" === $c ) && 0 === $depth ) {
				$groups[] = trim( $buf );
				$buf      = '';
				continue;
			}
			$buf .= $c;
		}
		$groups[] = trim( $buf );
		return array_values( array_filter( $groups, 'strlen' ) );
	}

	private static function tokenize( $group ) {
		$tokens = array();
		$buf    = '';
		$depth  = 0;
		$quote  = '';
		$len    = strlen( $group );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $group[ $i ];
			if ( $quote ) {
				if ( $c === $quote ) {
					$quote = '';
				}
				$buf .= $c;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '[' === $c ) {
				$depth++;
			} elseif ( ']' === $c ) {
				$depth--;
			} elseif ( 0 === $depth && ( ctype_space( $c ) || '>' === $c || '+' === $c || '~' === $c ) ) {
				// "~=" inside [] is handled by depth; a bare ~ or + is treated as a descendant combinator.
				if ( '' !== $buf ) {
					$tokens[] = $buf;
					$buf      = '';
				}
				if ( '>' === $c ) {
					$tokens[] = '>';
				}
				continue;
			}
			$buf .= $c;
		}
		if ( '' !== $buf ) {
			$tokens[] = $buf;
		}
		return $tokens;
	}

	private static function compound( $s ) {
		preg_match( '/^(\*|[a-zA-Z][a-zA-Z0-9-]*)?/', $s, $m );
		$tag   = ! empty( $m[1] ) ? strtolower( $m[1] ) : '*';
		$rest  = substr( $s, strlen( $m[0] ) );
		$preds = array();

		while ( '' !== $rest && false !== $rest ) {
			if ( preg_match( '/^#([\w-]+)/u', $rest, $m ) ) {
				$preds[] = '@id=' . self::literal( $m[1] );
			} elseif ( preg_match( '/^\.([\w-]+)/u', $rest, $m ) ) {
				$preds[] = 'contains(concat(" ",normalize-space(@class)," "),' . self::literal( ' ' . $m[1] . ' ' ) . ')';
			} elseif ( preg_match( '/^\[\s*([\w:-]+)\s*(?:([*^$~|]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]*)))?\s*\]/u', $rest, $m ) ) {
				$attr = '@' . $m[1];
				$op   = isset( $m[2] ) ? $m[2] : '';
				$val  = '';
				foreach ( array( 3, 4, 5 ) as $i ) {
					if ( isset( $m[ $i ] ) && '' !== $m[ $i ] ) {
						$val = $m[ $i ];
						break;
					}
				}
				$lit = self::literal( $val );
				switch ( $op ) {
					case '=':
						$preds[] = $attr . '=' . $lit;
						break;
					case '*=':
						$preds[] = 'contains(' . $attr . ',' . $lit . ')';
						break;
					case '^=':
						$preds[] = 'starts-with(' . $attr . ',' . $lit . ')';
						break;
					case '$=':
						$preds[] = 'substring(' . $attr . ',string-length(' . $attr . ')-string-length(' . $lit . ')+1)=' . $lit;
						break;
					case '~=':
						$preds[] = 'contains(concat(" ",normalize-space(' . $attr . ')," "),' . self::literal( ' ' . $val . ' ' ) . ')';
						break;
					case '|=':
						$preds[] = '(' . $attr . '=' . $lit . ' or starts-with(' . $attr . ',' . self::literal( $val . '-' ) . '))';
						break;
					default:
						$preds[] = $attr;
				}
			} else {
				// Unsupported syntax: match nothing rather than everything.
				$preds[] = 'false()';
				break;
			}
			$rest = substr( $rest, strlen( $m[0] ) );
		}

		return $tag . ( $preds ? '[' . implode( ' and ', $preds ) . ']' : '' );
	}

	private static function literal( $value ) {
		if ( false === strpos( $value, '"' ) ) {
			return '"' . $value . '"';
		}
		if ( false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}
		return 'concat("' . str_replace( '"', '",\'"\',"', $value ) . '")';
	}
}
