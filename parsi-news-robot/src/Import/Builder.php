<?php
/**
 * Turns one feed entry into ready-to-publish content (used by imports, updates and the "test source" preview).
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Import;

use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Support\Http;
use ParsiNewsRobot\Support\Util;
use ParsiNewsRobot\Taxonomy\Rules;

defined( 'ABSPATH' ) || exit;

class Builder {

	/** Feed text shorter than this (characters) means "summary only": the page is fetched in auto mode. */
	const FULL_TEXT_MIN = 600;

	/**
	 * @return array|\WP_Error Error code "pnr_skip" means "do not import" (not a failure).
	 */
	public static function build( array $item, array $cfg ) {
		$title = trim( (string) $item['title'] );
		$link  = (string) $item['link'];
		if ( '' === $title ) {
			return new \WP_Error( 'pnr_skip', 'خبر عنوان ندارد.' );
		}

		$feed_html = '' !== trim( (string) $item['content'] ) ? $item['content'] : $item['description'];
		$feed_len  = Util::strlen( Util::text( $feed_html ) );
		$mode      = in_array( $cfg['content_mode'], array( 'auto', 'feed', 'page' ), true ) ? $cfg['content_mode'] : 'auto';
		$need_page = $link && ( 'page' === $mode || ( 'auto' === $mode && $feed_len < self::FULL_TEXT_MIN ) );

		$extracted = null;
		$page_url  = $link;
		$page_err  = '';
		if ( $need_page || ( $link && 'feed' === $mode && ! self::feed_image( $item ) ) ) {
			$page = Http::get(
				$link,
				array(
					'html'    => true,
					'referer' => Util::host( $link ) ? 'https://' . wp_parse_url( $link, PHP_URL_HOST ) . '/' : '',
				)
			);
			if ( is_wp_error( $page ) ) {
				$page_err = $page->get_error_message();
				if ( 'page' === $mode ) {
					return new \WP_Error( 'pnr_page', 'صفحه خبر باز نشد: ' . $page_err );
				}
			} elseif ( self::is_challenge_page( $page['body'] ) ) {
				$page_err = 'سایت منبع با دیوار امنیتی (مثل ابر آروان یا کلادفلر) جلوی ربات را گرفته است؛ متن کامل قابل دریافت نیست. حالت «فقط از RSS» را امتحان کنید.';
				if ( 'page' === $mode ) {
					return new \WP_Error( 'pnr_page', $page_err );
				}
			} else {
				$page_url  = $page['url'];
				$extracted = Extractor::extract( $page['body'], $page_url, $cfg );
			}
		}

		$use_page = $need_page && $extracted && Util::strlen( Util::text( $extracted['content'] ) ) >= 150;
		if ( $use_page ) {
			$html   = $extracted['content'];
			$base   = $extracted['base'];
			$method = $extracted['method'];
		} else {
			$html   = $feed_html;
			$base   = $link ? $link : $cfg['url'];
			$method = 'feed';
			if ( 'page' === $mode ) {
				return new \WP_Error( 'pnr_page', 'متن کامل در صفحه خبر پیدا نشد. انتخابگر متن را در تنظیمات منبع وارد کنید.' );
			}
		}

		$clean = Cleaner::clean(
			$html,
			$base,
			array( 'remove' => $cfg['remove_selectors'] )
		);
		$html  = $clean['html'];

		// Featured image: the page's og:image (usually full size) → feed enclosure → first image of the text.
		$featured = '';
		if ( $extracted && $extracted['image'] && ! preg_match( '~(^|[/_.-])(logo|default|placeholder|share)([/_.-]|$)~i', (string) wp_parse_url( $extracted['image'], PHP_URL_PATH ) ) ) {
			$featured = $extracted['image'];
		}
		if ( ! $featured ) {
			$featured = self::feed_image( $item );
		}
		if ( ! $featured && $clean['images'] ) {
			$featured = $clean['images'][0];
		}
		if ( $featured ) {
			$html = Cleaner::remove_image( $html, $featured );
		}

		// Videos: embeds in the text, a video enclosure, or the page's og:video.
		$has_video = $clean['has_video'];
		if ( ! $has_video ) {
			$video_html = self::extra_video( $item, $extracted, $base );
			if ( $video_html ) {
				$html      = $video_html . "\n" . $html;
				$has_video = true;
			}
		}

		// Lead.
		$lead = '';
		if ( $cfg['include_lead'] ) {
			if ( $extracted && $extracted['lead'] ) {
				$lead = $extracted['lead'];
			} elseif ( $use_page ) {
				$lead = Util::text( $item['description'] );
			}
		}
		$text = Util::text( $html );
		if ( $lead && Util::strlen( $lead ) >= 30 && Util::strlen( $lead ) <= 1200 && false === mb_strpos( Util::normalize_fa( $text ), Util::normalize_fa( Util::substr( $lead, 0, 80 ) ) ) ) {
			$html = '<p class="pnr-lead"><strong>' . esc_html( $lead ) . '</strong></p>' . "\n" . $html;
			$text = $lead . ' ' . $text;
		}

		$images = Cleaner::image_urls( $html );
		$words  = count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );

		if ( (int) $cfg['min_words'] > 0 && $words < (int) $cfg['min_words'] ) {
			return new \WP_Error( 'pnr_skip', sprintf( 'متن کوتاه‌تر از حد مجاز (%d کلمه).', $words ) );
		}
		if ( $cfg['require_image'] && ! $featured ) {
			return new \WP_Error( 'pnr_skip', 'خبر تصویر ندارد.' );
		}
		if ( '' === trim( $text ) && ! $has_video ) {
			return new \WP_Error( 'pnr_empty', 'متنی برای خبر پیدا نشد' . ( $page_err ? ': ' . $page_err : '.' ) );
		}

		$rules = Rules::evaluate( $title, $text );
		if ( $rules['skip'] ) {
			return new \WP_Error( 'pnr_skip', 'قانون حذف: ' . $rules['skip'] );
		}

		$excerpt = wp_trim_words( $lead ? $lead : $text, $lead ? 60 : 40, '…' );

		return array(
			'title'        => $title,
			'link'         => $link,
			'page_url'     => $page_url,
			'content'      => $html,
			'excerpt'      => $excerpt,
			'text'         => $text,
			'words'        => $words,
			'featured'     => $featured,
			'images'       => $images,
			'image_count'  => count( $images ),
			'has_video'    => $has_video,
			'method'       => $method,
			'date'         => (int) $item['date'],
			'tags'         => isset( $item['categories'] ) ? (array) $item['categories'] : array(),
			'rules'        => $rules,
			'content_hash' => md5( $title . '|' . $text ),
			'feed_sig'     => Fetcher::signature( $item ),
		);
	}

	/**
	 * A bot-check / DDoS-protection page instead of the article (ArvanCloud, Cloudflare, DDoS-Guard…).
	 */
	public static function is_challenge_page( $html ) {
		if ( strlen( (string) $html ) > 60000 ) {
			return false; // Real article pages are larger than challenge pages.
		}
		return (bool) preg_match( '~challenge-platform|cf-browser-verification|cf_chl_|Just a moment\.\.\.|Checking your browser|ddos-guard|__arvan|arvancloud.*challenge|captcha-delivery|<title>\s*(Attention Required|DDoS)~i', (string) $html );
	}

	/**
	 * First image from the feed entry's enclosures / media tags.
	 */
	public static function feed_image( array $item ) {
		foreach ( isset( $item['enclosures'] ) ? (array) $item['enclosures'] : array() as $enc ) {
			$is_image = 'image' === $enc['medium'] || 0 === strpos( (string) $enc['type'], 'image/' ) || preg_match( '~\.(jpe?g|png|webp|gif|avif)(\?|$)~i', $enc['url'] );
			if ( $is_image && ! preg_match( '~\.svg(\?|$)~i', $enc['url'] ) ) {
				return $enc['url'];
			}
		}
		return '';
	}

	/**
	 * A video player for a video enclosure or og:video, when the text has none.
	 */
	private static function extra_video( array $item, $extracted, $base ) {
		foreach ( isset( $item['enclosures'] ) ? (array) $item['enclosures'] : array() as $enc ) {
			if ( 'video' === $enc['medium'] || 0 === strpos( (string) $enc['type'], 'video/' ) || preg_match( '~\.(mp4|webm)(\?|$)~i', $enc['url'] ) ) {
				return '<video src="' . esc_url( $enc['url'] ) . '" controls="controls" preload="none" playsinline="playsinline"></video>';
			}
		}
		if ( $extracted && $extracted['video'] ) {
			$url  = $extracted['video'];
			$host = Util::host( $url );
			if ( preg_match( '~\.(mp4|webm)(\?|$)~i', $url ) ) {
				return '<video src="' . esc_url( $url ) . '" controls="controls" preload="none" playsinline="playsinline"></video>';
			}
			foreach ( Settings::video_hosts() as $video_host ) {
				if ( Util::host_matches( $host, $video_host ) ) {
					$cleaned = Cleaner::clean( '<iframe src="' . esc_url( $url ) . '"></iframe>', $base );
					return $cleaned['has_video'] ? $cleaned['html'] : '';
				}
			}
		}
		return '';
	}
}
