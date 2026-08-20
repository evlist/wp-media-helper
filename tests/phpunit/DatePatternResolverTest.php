<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\MediaSource\DatePatternResolver;

class DatePatternResolverTest extends TestCase {

	public function test_replaces_date_placeholders_in_a_path(): void {
		$resolver = new DatePatternResolver();
		$date     = new DateTimeImmutable( '2026-08-12' );

		$this->assertSame(
			'root/2026/user1/08/12',
			$resolver->resolve( 'root/{date:Y}/user1/{date:m}/{date:d}', $date )
		);
	}

	public function test_supports_a_compound_php_date_format(): void {
		$resolver = new DatePatternResolver();
		$date     = new DateTimeImmutable( '2026-08-12' );

		$this->assertSame( '20260812', $resolver->resolve( '{date:Ymd}', $date ) );
	}

	public function test_preserves_literal_text(): void {
		$resolver = new DatePatternResolver();
		$date     = new DateTimeImmutable( '2026-08-12' );

		$this->assertSame(
			'photos-2026-08-12',
			$resolver->resolve( 'photos-{date:Y}-{date:m}-{date:d}', $date )
		);
	}

	public function test_rejects_unknown_placeholder(): void {
		$resolver = new DatePatternResolver();
		$date     = new DateTimeImmutable( '2026-08-12' );

		$this->expectException( InvalidArgumentException::class );
		$resolver->resolve( 'root/{unknown}', $date );
	}

	public function test_rejects_empty_date_format(): void {
		$resolver = new DatePatternResolver();
		$date     = new DateTimeImmutable( '2026-08-12' );

		$this->expectException( InvalidArgumentException::class );
		$resolver->resolve( 'root/{date:}', $date );
	}

	public function test_uses_source_root_when_path_pattern_is_empty(): void {
		$resolver = new DatePatternResolver();
		$date     = new DateTimeImmutable( '2026-08-12' );

		$this->assertSame(
			'/var/www/media',
			$resolver->resolvePath( '/var/www/media', '', $date )
		);
	}

	public function test_joins_root_and_pattern_when_pattern_is_present(): void {
		$resolver = new DatePatternResolver();
		$date     = new DateTimeImmutable( '2026-08-12' );

		$this->assertSame(
			'/var/www/media/2026/08/12',
			$resolver->resolvePath( '/var/www/media', '{date:Y}/{date:m}/{date:d}', $date )
		);
	}
}
