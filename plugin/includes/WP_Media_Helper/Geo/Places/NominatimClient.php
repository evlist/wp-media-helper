<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo\Places;

/**
 * Asks a Nominatim-compatible service for the place of a position (reverse geocoding). It only builds the request and reads the answer: the pace,
 * the cache and the choice of the service are elsewhere. The transport is injected (WordPress's HTTP API in the plugin).
 */
final class NominatimClient {

	private string $url;
	private string $language;
	private string $userAgent;
	private string $referer;
	private int $precision;
	/** @var callable(string, array<string, string>): array{status:int, body:string, headers:array<string, string>, error:?string} */
	private $transport;

	/**
	 * @param callable(string, array<string, string>): array{status:int, body:string, headers:array<string, string>, error:?string} $transport
	 */
	public function __construct( string $url, string $language, string $userAgent, string $referer, callable $transport, int $precision = 3 ) {
		$this->precision = max( 3, min( 5, $precision ) );
		$this->url       = $url;
		$this->language  = $language;
		$this->userAgent = $userAgent;
		$this->referer   = $referer;
		$this->transport = $transport;
	}

	/**
	 * The address of the request for a position. Only the coordinates (already rounded to the cell by the caller) and the language are sent.
	 */
	public function requestUrl( float $lat, float $lon ): string {
		$query = http_build_query( [
			'format'          => 'geocodejson',
			'lat'             => sprintf( '%.' . $this->precision . 'f', $lat ),
			'lon'             => sprintf( '%.' . $this->precision . 'f', $lon ),
			'zoom'            => 18,
			'addressdetails'  => 1,
			'extratags'       => 1,
			'accept-language' => $this->language,
		], '', '&', PHP_QUERY_RFC3986 );

		return $this->url . ( str_contains( $this->url, '?' ) ? '&' : '?' ) . $query;
	}

	/**
	 * Asks for a place.
	 *
	 * `result`: `ok` (an object was found: `body` is the whole answer), `nothing` (the service answered that there is none), `retry` (the service
	 * refuses or is down: wait before anything else, `retry_after` when it said how long), `error` (this request cannot be answered: try again
	 * later, not now).
	 *
	 * @return array{result:string, http:int, body:?array<string, mixed>, retry_after:?int, message:string}
	 */
	public function reverse( float $lat, float $lon, int $now ): array {
		$response = ( $this->transport )(
			$this->requestUrl( $lat, $lon ),
			[ 'User-Agent' => $this->userAgent, 'Referer' => $this->referer, 'Accept' => 'application/json' ]
		);

		$status = (int) ( $response['status'] ?? 0 );
		if ( null !== ( $response['error'] ?? null ) || 0 === $status ) {
			return [ 'result' => 'retry', 'http' => 0, 'body' => null, 'retry_after' => null, 'message' => (string) ( $response['error'] ?? 'no answer' ) ];
		}
		if ( in_array( $status, [ 429, 503, 509 ], true ) || $status >= 500 ) {
			$header = '';
			foreach ( (array) ( $response['headers'] ?? [] ) as $name => $value ) {
				if ( 'retry-after' === strtolower( (string) $name ) ) {
					$header = (string) ( is_array( $value ) ? reset( $value ) : $value );
				}
			}

			return [ 'result' => 'retry', 'http' => $status, 'body' => null, 'retry_after' => RateLimiter::retryAfter( $header, $now ), 'message' => 'HTTP ' . $status ];
		}
		if ( 200 !== $status ) {
			return [ 'result' => 'error', 'http' => $status, 'body' => null, 'retry_after' => null, 'message' => 'HTTP ' . $status ];
		}

		$body = json_decode( (string) $response['body'], true );
		if ( ! is_array( $body ) ) {
			return [ 'result' => 'error', 'http' => $status, 'body' => null, 'retry_after' => null, 'message' => 'not JSON' ];
		}
		if ( isset( $body['error'] ) ) {
			// "Unable to geocode": nothing there (the sea, a desert).
			return [ 'result' => 'nothing', 'http' => $status, 'body' => $body, 'retry_after' => null, 'message' => (string) ( is_string( $body['error'] ) ? $body['error'] : 'nothing' ) ];
		}
		if ( isset( $body['features'] ) && is_array( $body['features'] ) && [] === $body['features'] ) {
			return [ 'result' => 'nothing', 'http' => $status, 'body' => $body, 'retry_after' => null, 'message' => 'no feature' ];
		}
		if ( ! PlaceAnswer::hasPlace( $body ) ) {
			return [ 'result' => 'error', 'http' => $status, 'body' => $body, 'retry_after' => null, 'message' => 'unexpected answer' ];
		}

		return [ 'result' => 'ok', 'http' => $status, 'body' => $body, 'retry_after' => null, 'message' => '' ];
	}

	/**
	 * The transport of WordPress (`wp_remote_get`).
	 *
	 * @return callable(string, array<string, string>): array{status:int, body:string, headers:array<string, string>, error:?string}
	 */
	public static function wordpressTransport(): callable {
		return static function ( string $url, array $headers ): array {
			$response = wp_remote_get( $url, [ 'timeout' => 15, 'redirection' => 2, 'headers' => $headers ] );
			if ( is_wp_error( $response ) ) {
				return [ 'status' => 0, 'body' => '', 'headers' => [], 'error' => $response->get_error_message() ];
			}
			$headers = wp_remote_retrieve_headers( $response );

			return [
				'status'  => (int) wp_remote_retrieve_response_code( $response ),
				'body'    => (string) wp_remote_retrieve_body( $response ),
				'headers' => is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers,
				'error'   => null,
			];
		};
	}
}
