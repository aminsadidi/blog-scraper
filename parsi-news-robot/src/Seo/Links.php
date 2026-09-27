<?php
/**
 * Render-time handling of robot content: rel of the links the robot created, and the "به نقل از" line.
 *
 * Links imported from a source carry data-pnr-link="1". Only those get nofollow/follow/strip, so links an
 * editor adds later are never touched, and changing the setting applies to every existing post at once.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Seo;

use ParsiNewsRobot\Data\Items;
use ParsiNewsRobot\Settings;

defined( 'ABSPATH' ) || exit;

class Links {

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 20 );
	}

	public static function filter_content( $content ) {
		$post = get_post();
		if ( ! $post || ! get_post_meta( $post->ID, Items::META, true ) ) {
			return $content;
		}
		if ( is_singular() && in_the_loop() && is_main_query() && (int) get_queried_object_id() === (int) $post->ID ) {
			$content = self::add_attribution( $content, $post->ID );
		}
		return self::apply_rel( $content, Settings::get( 'links_mode' ) );
	}

	/**
	 * @param string $mode nofollow | follow | strip.
	 */
	public static function apply_rel( $content, $mode ) {
		if ( false === strpos( $content, 'data-pnr-link' ) ) {
			return $content;
		}
		if ( 'strip' === $mode ) {
			return preg_replace( '~<a\b[^>]*\bdata-pnr-link\b[^>]*>(.*?)</a>~is', '$1', $content );
		}
		$tags = new \WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag( 'a' ) ) {
			if ( null === $tags->get_attribute( 'data-pnr-link' ) ) {
				continue;
			}
			$tags->set_attribute( 'rel', 'follow' === $mode ? 'noopener' : 'nofollow noopener' );
			$tags->set_attribute( 'target', '_blank' );
		}
		return $tags->get_updated_html();
	}

	/**
	 * Builds the attribution line from the template ({source}, {title}, {date}).
	 */
	public static function attribution_html( $post_id ) {
		$s = Settings::get();
		if ( ! $s['attr_enabled'] ) {
			return '';
		}
		$item = Items::get( $post_id );
		if ( ! $item ) {
			return '';
		}
		$name = $item->source_name ? $item->source_name : wp_parse_url( $item->source_url, PHP_URL_HOST );
		$url  = '';
		if ( 'article' === $s['attr_link'] ) {
			$url = $item->source_url;
		} elseif ( 'home' === $s['attr_link'] ) {
			$parts = wp_parse_url( $item->source_url );
			$url   = ! empty( $parts['host'] ) ? ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . '/' : '';
		}
		$source = $url
			? '<a href="' . esc_url( $url ) . '" data-pnr-link="1">' . esc_html( $name ) . '</a>'
			: esc_html( $name );

		$template = (string) $s['attr_template'];
		if ( false === strpos( $template, '{source}' ) ) {
			$template .= ' {source}';
		}
		$html = strtr(
			esc_html( $template ),
			array(
				'{source}' => $source,
				'{title}'  => esc_html( get_the_title( $post_id ) ),
				'{date}'   => esc_html( get_the_date( '', $post_id ) ),
			)
		);
		return '<p class="pnr-source">' . $html . '</p>';
	}

	private static function add_attribution( $content, $post_id ) {
		$line = self::attribution_html( $post_id );
		if ( '' === $line ) {
			return $content;
		}
		return 'start' === Settings::get( 'attr_position' ) ? $line . "\n" . $content : $content . "\n" . $line;
	}
}
