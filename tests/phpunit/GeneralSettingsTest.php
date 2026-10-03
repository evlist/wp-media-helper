<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Settings\GeneralSettings;

class GeneralSettingsTest extends TestCase {

	private function settings( mixed $stored, ?array &$saved = null ): GeneralSettings {
		return new GeneralSettings(
			static fn(): mixed => $stored,
			static function ( array $value ) use ( &$saved ): void {
				$saved = $value;
			}
		);
	}

	public function test_defaults_when_nothing_is_stored(): void {
		$this->assertSame( GeneralSettings::DEFAULT_MAX_ENTRIES, $this->settings( [] )->getMaxEntries() );
		$this->assertSame( GeneralSettings::DEFAULT_MAX_ENTRIES, $this->settings( false )->getMaxEntries() );
	}

	public function test_returns_the_stored_value(): void {
		$this->assertSame( 25, $this->settings( [ 'max_entries' => 25 ] )->getMaxEntries() );
		$this->assertSame( 25, $this->settings( [ 'max_entries' => '25' ] )->getMaxEntries() );
	}

	public function test_falls_back_to_the_default_when_the_stored_value_is_invalid(): void {
		foreach ( [ 0, -3, 100000, 'abc', 2.5, [] ] as $invalid ) {
			$this->assertSame( GeneralSettings::DEFAULT_MAX_ENTRIES, $this->settings( [ 'max_entries' => $invalid ] )->getMaxEntries() );
		}
	}

	public function test_validation_rejects_non_integers_and_out_of_range_values(): void {
		$this->assertNull( GeneralSettings::validateMaxEntries( '1' ) );
		$this->assertNull( GeneralSettings::validateMaxEntries( 500 ) );
		foreach ( [ '0', '501', '-1', '', 'ten', '1.5', null ] as $invalid ) {
			$this->assertNotNull( GeneralSettings::validateMaxEntries( $invalid ) );
		}
	}

	public function test_save_persists_an_integer(): void {
		$saved = null;
		$this->settings( [], $saved )->save( [ 'max_entries' => '40' ] );

		$this->assertSame( [ 'max_entries' => 40 ], $saved );
	}

	public function test_save_rejects_invalid_values_without_persisting(): void {
		$saved = null;

		try {
			$this->settings( [], $saved )->save( [ 'max_entries' => '9999' ] );
			$this->fail( 'Expected an InvalidArgumentException.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertNull( $saved );
		}
	}
}
