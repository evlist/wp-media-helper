<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Settings\ExternalSourceSettings;

class ExternalSourceSettingsTest extends TestCase {

	public function test_loads_empty_array_when_option_is_missing(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => false,
			static function ( array $value ): void {}
		);

		$this->assertSame( [], $settings->getAll() );
	}

	public function test_saves_normalized_sources(): void {
		$saved = null;
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ) use ( &$saved ): void {
				$saved = $value;
			}
		);

		$settings->saveAll( [
			[
				'name' => '  Nextcloud Main  ',
				'enabled' => '1',
				'root' => '/var/www/media',
				'path_pattern' => '{date:Y}/{date:m}/{date:d}',
				'filter_pattern' => '  {date:Ymd}  ',
				'thumbnail_cache' => '/tmp/wp-media-helper-cache',
			],
		] );

		$this->assertIsArray( $saved );
		$this->assertSame( 'nextcloud-main', $saved[0]['id'] );
		$this->assertSame( 'Nextcloud Main', $saved[0]['name'] );
		$this->assertTrue( $saved[0]['enabled'] );
		$this->assertSame( '/var/www/media', $saved[0]['root'] );
		$this->assertSame( '{date:Y}/{date:m}/{date:d}', $saved[0]['path_pattern'] );
		$this->assertSame( '{date:Ymd}', $saved[0]['filter_pattern'] );
		$this->assertSame( '/tmp/wp-media-helper-cache', $saved[0]['thumbnail_cache'] );
	}

	public function test_rejects_invalid_source_shape(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$this->expectException( InvalidArgumentException::class );
		$settings->saveAll( [
			[
				'name' => '',
				'root' => '',
				'path_pattern' => '',
			],
		] );
	}
}
