<?php
/**
 * Render-time handling of robot content: rel of the links the robot created, and the "به نقل از" line.
 *
 * Links imported from a source carry data-pnr-link="1" and the attribution link data-pnr-link="attr". Only
 * those are changed, so links an editor adds later are never touched, and a changed setting applies to
 * every existing post at once.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Seo;

use ParsiNewsRobot\Data\Items;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Sources;

defined( 'ABSPATH' ) || exit;

class Links {

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 20 );
	}

	public static function filter_content( $content ) {
		$post = get_post();
		// The post's meta is already loaded with the query, so lists of posts cost no extra queries here.
		$source_id = $post ? (int) get_post_meta( $post->ID, Items::META, true ) : 0;
		if ( ! $source_id ) {
			return $content;
		}
		$cfg  = self::source_config( $source_id );
		$attr = Settings::attribution( $cfg );
		if ( is_singular() && in_the_loop() && is_main_query() && (int) get_queried_object_id() === (int) $post->ID ) {
			$line = self::attribution_html( $attr, Items::get( $post->ID ), $post->ID );
			if ( '' !== $line ) {
				$content = 'start' === $attr['position'] ? $line . "\n" . $content : $content . "\n" . $line;
			}
		}
		return self::apply_rel( $content, Settings::links_mode( $cfg ), $attr['rel'], (bool) Settings::get( 'links_new_tab' ), $attr['new_tab'] );
	}

	/**
	 * Sets rel/target on the links the robot created. Links from the source text carry data-pnr-link="1",
	 * the attribution link carries data-pnr-link="attr"; each kind follows its own setting.
	 *
	 * @param string $mode      Source-text links: nofollow | follow | strip.
	 * @param string $attr_rel  Attribution link: nofollow | follow | sponsored.
	 */
	public static function apply_rel( $content, $mode, $attr_rel = null, $new_tab = true, $attr_new_tab = null ) {
		if ( false === strpos( $content, 'data-pnr-link' ) ) {
			return $content;
		}
		$attr_rel     = null === $attr_rel ? ( 'strip' === $mode ? 'nofollow' : $mode ) : $attr_rel;
		$attr_new_tab = null === $attr_new_tab ? $new_tab : $attr_new_tab;
		if ( 'strip' === $mode ) {
			$content = preg_replace( '~<a\b[^>]*\bdata-pnr-link="1"[^>]*>(.*?)</a>~is', '$1', $content );
		}
		$tags = new \WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag( 'a' ) ) {
			$kind = $tags->get_attribute( 'data-pnr-link' );
			if ( null === $kind ) {
				continue;
			}
			$is_attr = 'attr' === $kind;
			$rel     = $is_attr ? $attr_rel : $mode;
			$tab     = $is_attr ? $attr_new_tab : $new_tab;
			$values  = array();
			if ( 'nofollow' === $rel ) {
				$values[] = 'nofollow';
			} elseif ( 'sponsored' === $rel ) {
				$values[] = 'sponsored';
				$values[] = 'nofollow';
			}
			if ( $tab ) {
				$values[] = 'noopener';
				$tags->set_attribute( 'target', '_blank' );
			} else {
				$tags->remove_attribute( 'target' );
			}
			if ( $values ) {
				$tags->set_attribute( 'rel', implode( ' ', $values ) );
			} else {
				$tags->remove_attribute( 'rel' );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * The attribution line of a robot post.
	 */
	/**
	 * A source's settings, read once per page view.
	 */
	private static function source_config( $source_id ) {
		static $cache = array();
		if ( ! isset( $cache[ $source_id ] ) ) {
			$cache[ $source_id ] = Sources::exists( $source_id ) ? Sources::config( $source_id ) : Settings::source_defaults();
		}
		return $cache[ $source_id ];
	}

	public static function attribution_html( array $attr, $item, $post_id ) {
		if ( ! $attr['enabled'] || ! $item ) {
			return '';
		}
		$name = $item->source_name ? $item->source_name : (string) wp_parse_url( $item->source_url, PHP_URL_HOST );
		return self::build_attribution( $attr, $name, $item->source_url, get_post_field( 'post_title', $post_id ), get_the_date( '', $post_id ) );
	}

	/**
	 * Builds the line from the template: {source} (name, linked when a link is chosen), {title}, {date}, {url}.
	 */
	public static function build_attribution( array $attr, $name, $article_url, $title = '', $date = '' ) {
		$url = '';
		if ( 'article' === $attr['link'] ) {
			$url = $article_url;
		} elseif ( 'home' === $attr['link'] ) {
			$parts = wp_parse_url( $article_url );
			$url   = ! empty( $parts['host'] ) ? ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . '/' : '';
		}
		$source = $url
			? '<a href="' . esc_url( $url ) . '" data-pnr-link="attr">' . esc_html( $name ) . '</a>'
			: esc_html( $name );

		$template = trim( (string) $attr['template'] );
		if ( '' === $template ) {
			$template = 'به نقل از {source}';
		} elseif ( false === strpos( $template, '{source}' ) ) {
			$template .= ' {source}';
		}
		$html = strtr(
			esc_html( $template ),
			array(
				'{source}' => $source,
				'{title}'  => esc_html( $title ),
				'{date}'   => esc_html( $date ),
				'{url}'    => $url ? '<a href="' . esc_url( $url ) . '" data-pnr-link="attr">' . esc_html( urldecode( $url ) ) . '</a>' : '',
			)
		);
		if ( 'bold' === $attr['style'] ) {
			$html = '<strong>' . $html . '</strong>';
		}
		$class = 'pnr-source' . ( in_array( $attr['style'], array( 'small', 'box' ), true ) ? ' pnr-source-' . $attr['style'] : '' );
		return '<p class="' . esc_attr( $class ) . '">' . $html . '</p>';
	}
}
