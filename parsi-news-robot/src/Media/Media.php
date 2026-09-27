<?php
/**
 * Image handling: download to the media library, or "external" attachments that point at the
 * source site's URL so themes can still use them as featured images.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Media;

use ParsiNewsRobot\Data\Log;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Support\Dom;
use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Media {

	const EXTERNAL_KEY = '_pnr_external_url';

	public static function init() {
		add_filter( 'image_downsize', array( __CLASS__, 'image_downsize' ), 10, 3 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'attachment_url' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'image_srcset' ), 10, 5 );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'image_attributes' ), 10, 2 );
	}

	public static function external_url( $attachment_id ) {
		return (string) get_post_meta( $attachment_id, self::EXTERNAL_KEY, true );
	}

	/* ---------- Filters that make external attachments behave like normal images ---------- */

	public static function image_downsize( $out, $id, $size ) {
		$url = self::external_url( $id );
		if ( ! $url ) {
			return $out;
		}
		$meta   = wp_get_attachment_metadata( $id );
		$width  = ! empty( $meta['width'] ) ? (int) $meta['width'] : 1200;
		$height = ! empty( $meta['height'] ) ? (int) $meta['height'] : 675;

		$max = null;
		if ( is_array( $size ) && count( $size ) >= 2 ) {
			$max = array( (int) $size[0], (int) $size[1] );
		} elseif ( is_string( $size ) && 'full' !== $size ) {
			$sizes = wp_get_registered_image_subsizes();
			if ( isset( $sizes[ $size ] ) ) {
				$max = array( (int) $sizes[ $size ]['width'], (int) $sizes[ $size ]['height'] );
			}
		}
		if ( $max ) {
			list( $width, $height ) = wp_constrain_dimensions( $width, $height, $max[0], $max[1] );
		}
		return array( $url, $width, $height, false );
	}

	public static function attachment_url( $url, $id ) {
		$external = self::external_url( $id );
		return $external ? $external : $url;
	}

	public static function image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		return self::external_url( $attachment_id ) ? false : $sources;
	}

	public static function image_attributes( $attr, $attachment ) {
		if ( $attachment && self::external_url( $attachment->ID ) ) {
			$attr['referrerpolicy'] = 'no-referrer';
			unset( $attr['srcset'], $attr['sizes'] );
		}
		return $attr;
	}

	/* ---------- Creating attachments ---------- */

	/**
	 * Downloads an image into the media library.
	 *
	 * @param bool $all_sizes false = only the "large" sub-size is generated (content images).
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function sideload( $url, $post_id, $title = '', $referer = '', $all_sizes = true ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$settings = Settings::get();
		$tmp      = wp_tempnam( 'pnr-image' );
		$headers  = array( 'Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8' );
		if ( $referer ) {
			$headers['Referer'] = $referer;
		}
		$response = wp_safe_remote_get(
			Util::encode_url( $url ),
			array(
				'timeout'             => max( 10, (int) $settings['timeout'] ),
				'stream'              => true,
				'filename'            => $tmp,
				'user-agent'          => $settings['user_agent'],
				'headers'             => $headers,
				'limit_response_size' => 15 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'pnr_image_http', 'دانلود تصویر ناموفق بود (کد ' . (int) wp_remote_retrieve_response_code( $response ) . ').' );
		}

		$mime = wp_get_image_mime( $tmp );
		$exts = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);
		if ( ! $mime || ! isset( $exts[ $mime ] ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'pnr_not_image', 'فایل دریافت‌شده تصویر معتبر نیست.' );
		}

		$name = pathinfo( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), PATHINFO_FILENAME );
		$name = preg_replace( '/(\.(jpe?g|png|gif|webp|avif|bmp))+$/i', '', $name );
		$name = sanitize_file_name( $name );
		if ( '' === $name || preg_match( '/[^\x20-\x7e]/', $name ) || strlen( $name ) > 60 ) {
			$name = 'news-' . substr( md5( $url ), 0, 12 );
		}
		$file = array(
			'name'     => $name . '.' . $exts[ $mime ],
			'tmp_name' => $tmp,
		);

		$limit_sizes = function ( $sizes ) {
			return isset( $sizes['large'] ) ? array( 'large' => $sizes['large'] ) : array();
		};
		if ( ! $all_sizes ) {
			add_filter( 'intermediate_image_sizes_advanced', $limit_sizes, 99 );
		}
		$id = media_handle_sideload( $file, $post_id, $title ? $title : null );
		if ( ! $all_sizes ) {
			remove_filter( 'intermediate_image_sizes_advanced', $limit_sizes, 99 );
		}

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		update_post_meta( $id, '_pnr_imported', 1 );
		if ( $title ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $title );
		}
		return (int) $id;
	}

	/**
	 * Registers an attachment that points to the source URL (nothing is downloaded but the size header).
	 *
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function create_external( $url, $post_id, $title = '' ) {
		list( $width, $height ) = self::remote_dimensions( $url );
		$type                   = wp_check_filetype( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $type['type'] ? $type['type'] : 'image/jpeg',
				'post_title'     => $title ? $title : basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
				'post_status'    => 'inherit',
				'post_content'   => '',
				'guid'           => esc_url_raw( $url ),
			),
			false,
			$post_id,
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, self::EXTERNAL_KEY, esc_url_raw( $url ) );
		update_post_meta( $id, '_pnr_imported', 1 );
		if ( $title ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $title );
		}
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => $width,
				'height' => $height,
				'file'   => '',
				'sizes'  => array(),
			)
		);
		return (int) $id;
	}

	/**
	 * Reads image dimensions from the first 64 KB of the file.
	 */
	private static function remote_dimensions( $url ) {
		$response = wp_safe_remote_get(
			Util::encode_url( $url ),
			array(
				'timeout'             => 8,
				'user-agent'          => Settings::get( 'user_agent' ),
				'headers'             => array( 'Range' => 'bytes=0-65535' ),
				'limit_response_size' => 65536,
			)
		);
		if ( ! is_wp_error( $response ) ) {
			$size = @getimagesizefromstring( (string) wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $size && $size[0] > 0 && $size[1] > 0 ) {
				return array( (int) $size[0], (int) $size[1] );
			}
		}
		return array( 1200, 675 );
	}

	/**
	 * Featured image according to the image mode. Falls back to an external attachment if the download fails.
	 *
	 * @return int Attachment ID or 0.
	 */
	public static function set_featured( $url, $post_id, $mode, $title, $referer ) {
		$id = 0;
		if ( 'hotlink' !== $mode ) {
			$id = self::sideload( $url, $post_id, $title, $referer, true );
			if ( is_wp_error( $id ) ) {
				Log::warning( 'دانلود تصویر شاخص ناموفق بود؛ از لینک منبع استفاده شد: ' . $id->get_error_message() );
				$id = 0;
			}
		}
		if ( ! $id ) {
			$id = self::create_external( $url, $post_id, $title );
			$id = is_wp_error( $id ) ? 0 : $id;
		}
		if ( $id ) {
			set_post_thumbnail( $post_id, $id );
		}
		return (int) $id;
	}

	/**
	 * Downloads content images and rewrites their src to the local copy.
	 */
	public static function localize_content_images( $html, $post_id, $max, $title, $referer ) {
		if ( $max <= 0 || false === stripos( $html, '<img' ) ) {
			return $html;
		}
		$doc   = Dom::load( $html );
		$count = 0;
		$done  = array();
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'img' ) ) as $img ) {
			if ( $count >= $max ) {
				break;
			}
			$src = $img->getAttribute( 'src' );
			if ( ! isset( $done[ $src ] ) ) {
				$id            = self::sideload( $src, $post_id, $img->getAttribute( 'alt' ) ? $img->getAttribute( 'alt' ) : $title, $referer, false );
				$done[ $src ] = is_wp_error( $id ) ? 0 : $id;
				$count++;
			}
			$id = $done[ $src ];
			if ( ! $id ) {
				continue;
			}
			$image = wp_get_attachment_image_src( $id, 'large' );
			if ( $image ) {
				$img->setAttribute( 'src', $image[0] );
				$img->setAttribute( 'width', (string) $image[1] );
				$img->setAttribute( 'height', (string) $image[2] );
				$img->removeAttribute( 'referrerpolicy' );
			}
		}
		return trim( Dom::inner_html( Dom::body( $doc ) ) );
	}

	/**
	 * Deletes the images this plugin created for a post.
	 */
	public static function delete_post_media( $post_id ) {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_parent'    => $post_id,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_pnr_imported', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $attachments as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		return count( $attachments );
	}
}
