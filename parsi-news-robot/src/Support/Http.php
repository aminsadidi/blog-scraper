<?php
/**
 * HTTP fetching with a browser-like user agent and charset normalisation.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Support;
use ParsiNewsRobot\Settings;

defined( 'ABSPATH' ) || exit;

class Http {

	/**
	 * @param array $args accept, referer, html (convert body to UTF-8), limit (bytes),
	 *                    etag / last_modified (conditional request).
	 * @return array{body: string, type: string, url: string, not_modified: bool, etag: string, last_modified: string}|\WP_Error
	 */
	public static function get( $url, array $args = array() ) {
		$settings = Settings::get();
		$request  = array(
			'timeout'             => max( 5, (int) $settings['timeout'] ),
			'redirection'         => 5,
			'user-agent'          => $settings['user_agent'] ? $settings['user_agent'] : 'Mozilla/5.0',
			'headers'             => array(
				'Accept'          => isset( $args['accept'] ) ? $args['accept'] : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'fa-IR,fa;q=0.9,en-US;q=0.6,en;q=0.4',
			),
			'limit_response_size' => isset( $args['limit'] ) ? (int) $args['limit'] : 8 * MB_IN_BYTES,
		);
		if ( ! empty( $args['referer'] ) ) {
			$request['headers']['Referer'] = $args['referer'];
		}
		if ( ! empty( $args['etag'] ) ) {
			$request['headers']['If-None-Match'] = $args['etag'];
		}
		if ( ! empty( $args['last_modified'] ) ) {
			$request['headers']['If-Modified-Since'] = $args['last_modified'];
		}

		$url      = Util::encode_url( $url );
		$response = wp_safe_remote_get( $url, $request );

		// Many small news sites have broken certificate chains; the content is public, so retry without verification.
		if ( is_wp_error( $response ) && preg_match( '/ssl|certificate/i', $response->get_error_message() ) ) {
			$request['sslverify'] = false;
			$response             = wp_safe_remote_get( $url, $request );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code          = (int) wp_remote_retrieve_response_code( $response );
		$etag          = (string) wp_remote_retrieve_header( $response, 'etag' );
		$last_modified = (string) wp_remote_retrieve_header( $response, 'last-modified' );
		if ( 304 === $code ) {
			return array(
				'body'          => '',
				'type'          => '',
				'url'           => $url,
				'not_modified'  => true,
				'etag'          => $etag ? $etag : ( isset( $args['etag'] ) ? $args['etag'] : '' ),
				'last_modified' => $last_modified ? $last_modified : ( isset( $args['last_modified'] ) ? $args['last_modified'] : '' ),
			);
		}
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'pnr_http', sprintf( 'سرور منبع پاسخ نامعتبر داد (کد %d).', $code ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$type = wp_remote_retrieve_header( $response, 'content-type' );
		$type = is_array( $type ) ? (string) end( $type ) : (string) $type;

		$final = $url;
		if ( isset( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
			$object = $response['http_response']->get_response_object();
			if ( is_object( $object ) && ! empty( $object->url ) ) {
				$final = $object->url;
			}
		}

		if ( ! empty( $args['html'] ) ) {
			$body = self::html_to_utf8( $body, $type );
		}

		return array(
			'body'          => $body,
			'type'          => $type,
			'url'           => $final,
			'not_modified'  => false,
			'etag'          => $etag,
			'last_modified' => $last_modified,
		);
	}

	/**
	 * Converts an HTML body to UTF-8 using the header or <meta> charset (old Persian sites often use windows-1256).
	 */
	public static function html_to_utf8( $body, $content_type = '' ) {
		$charset = '';
		if ( preg_match( '/charset\s*=\s*["\']?([\w-]+)/i', (string) $content_type, $m ) ) {
			$charset = $m[1];
		} elseif ( preg_match( '/<meta[^>]+charset\s*=\s*["\']?([\w-]+)/i', substr( $body, 0, 4096 ), $m ) ) {
			$charset = $m[1];
		}
		$charset = strtoupper( $charset );

		if ( in_array( $charset, array( '', 'UTF-8', 'UTF8' ), true ) ) {
			if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $body, 'UTF-8' ) ) {
				$charset = 'WINDOWS-1256';
			} else {
				return $body;
			}
		}

		try {
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$converted = @mb_convert_encoding( $body, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} else {
				$converted = @iconv( $charset, 'UTF-8//IGNORE', $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		} catch ( \Throwable $e ) {
			$converted = false;
		}
		return is_string( $converted ) && '' !== $converted ? $converted : $body;
	}
}
