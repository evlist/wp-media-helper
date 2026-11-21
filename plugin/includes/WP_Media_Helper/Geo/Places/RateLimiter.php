<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo\Places;

/**
 * Paces the requests to the geocoding service (slice 052): a minimum time between two requests, and a back-off after a refusal (HTTP 429, 503,
 * a service that does not answer), honouring `Retry-After`. The state is kept (an option), so that two runs of the job, or the job and a command,
 * share the same pace.
 */
final class RateLimiter {

	private const MAX_BACKOFF = 21600; // Six hours.

	/** @var callable(): array<string, mixed> */
	private $load;
	/** @var callable(array<string, mixed>): void */
	private $save;
	/** @var callable(): float */
	private $clock;

	/**
	 * @param callable(): array<string, mixed>     $load
	 * @param callable(array<string, mixed>): void $save
	 * @param callable(): float|null               $clock Seconds, with fractions.
	 */
	public function __construct( callable $load, callable $save, ?callable $clock = null ) {
		$this->load  = $load;
		$this->save  = $save;
		$this->clock = $clock ?? static fn (): float => microtime( true );
	}

	/**
	 * Seconds to wait before the next request may be sent (0 when it may be sent now).
	 */
	public function waitSeconds(): float {
		$state = ( $this->load )();

		return max( 0.0, (float) ( $state['next_at'] ?? 0.0 ) - ( $this->clock )() );
	}

	/**
	 * Seconds left of a back-off (0 when there is none): nothing is sent during it.
	 */
	public function blockedFor(): int {
		$state = ( $this->load )();

		return max( 0, (int) ceil( (float) ( $state['blocked_until'] ?? 0.0 ) - ( $this->clock )() ) );
	}

	/**
	 * A request was just sent: the next one waits for `$interval` seconds.
	 */
	public function recordRequest( float $interval ): void {
		$state            = ( $this->load )();
		$state['next_at'] = ( $this->clock )() + max( 0.0, $interval );
		( $this->save )( $state );
	}

	public function success(): void {
		$state = ( $this->load )();
		if ( ! empty( $state['failures'] ) ) {
			$state['failures'] = 0;
			( $this->save )( $state );
		}
	}

	/**
	 * The service refused or did not answer: nothing is sent until the back-off ends. It grows with the failures in a row (2, 4, 8 minutes...,
	 * six hours at most) and is never shorter than the `Retry-After` the service gave.
	 *
	 * @return int The seconds of the back-off.
	 */
	public function backoff( ?int $retryAfter = null ): int {
		$state             = ( $this->load )();
		$failures          = (int) ( $state['failures'] ?? 0 ) + 1;
		$delay             = (int) min( self::MAX_BACKOFF, max( (int) $retryAfter, 60 * ( 2 ** $failures ) ) );
		$state['failures'] = $failures;
		$state['blocked_until'] = ( $this->clock )() + $delay;
		( $this->save )( $state );

		return $delay;
	}

	/**
	 * Reads a `Retry-After` header: a number of seconds or a date.
	 */
	public static function retryAfter( string $header, int $now ): ?int {
		$header = trim( $header );
		if ( '' === $header ) {
			return null;
		}
		if ( 1 === preg_match( '/^\d+$/', $header ) ) {
			return (int) $header;
		}
		$time = strtotime( $header );

		return false === $time ? null : max( 0, $time - $now );
	}
}
