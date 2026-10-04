<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Admin\MediaPanelState;
use WP_Media_Helper\Admin\EditorMediaController;
use WP_Media_Helper\MediaSource\FileTypes;
use WP_Media_Helper\Settings\GeneralSettings;

class FileTypesTest extends TestCase {

	public function test_categories_of_files(): void {
		$expected = [
			'a.JPG' => 'image', 'b.mp4' => 'video', 'c.mp3' => 'audio', 'd.pdf' => 'document', 'e.vtt' => 'subtitles',
			'f.gpx' => 'gps', 'g.zip' => 'archive', 'h.xyz' => 'other', 'noext' => 'other', 'dir/x.srt' => 'subtitles',
		];
		foreach ( $expected as $name => $category ) {
			$this->assertSame( $category, FileTypes::category( $name ), $name );
			$this->assertSame( $category, MediaPanelState::resolveMediaType( $name ) );
		}
		$this->assertSame( 'other', FileTypes::categories()[ count( FileTypes::categories() ) - 1 ] );
	}

	public function test_dangerous_extensions(): void {
		foreach ( [ 'php', 'PHTML', '.js', 'html', 'svg', 'exe', 'phar', 'htaccess' ] as $extension ) {
			$this->assertTrue( FileTypes::isDangerous( $extension ), $extension );
		}
		foreach ( [ 'gpx', 'vtt', 'heic' ] as $extension ) {
			$this->assertFalse( FileTypes::isDangerous( $extension ), $extension );
		}
	}

	public function test_parsing_additional_types(): void {
		$parsed = FileTypes::parseAdditionalTypes( "# my types\ngpx application/gpx+xml\n.VTT = text/vtt\n\nphp application/x-php\nbad\nsh text/plain\nxyz text/html\nabc application/javascript\n" );
		$this->assertSame( [ 'gpx' => 'application/gpx+xml', 'vtt' => 'text/vtt' ], $parsed['types'] );
		$this->assertCount( 5, $parsed['errors'] );
	}

	public function test_parsing_extensions(): void {
		$this->assertSame( [ 'tmp', 'part' ], FileTypes::parseExtensions( ".TMP, *.part;tmp  ../x/y" ) );
	}

	public function test_dangerous_types_are_removed_from_a_mime_list(): void {
		$mimes = FileTypes::withoutDangerous( [ 'jpg|jpeg' => 'image/jpeg', 'php' => 'text/plain', 'html|htm' => 'text/html', 'gpx' => 'application/gpx+xml' ] );
		$this->assertSame( [ 'jpg|jpeg' => 'image/jpeg', 'gpx' => 'application/gpx+xml' ], $mimes );
	}

	public function test_import_blocker(): void {
		$this->assertNull( FileTypes::importBlocker( 'a.jpg', [ 'ext' => 'jpg', 'type' => 'image/jpeg' ] ) );
		$this->assertNotNull( FileTypes::importBlocker( 'a.gpx', [ 'ext' => false, 'type' => false ] ) );
		$this->assertNotNull( FileTypes::importBlocker( 'a.php', [ 'ext' => 'php', 'type' => 'text/plain' ] ) );
	}

	public function test_mark_importable(): void {
		$checker = static fn ( string $name ): array => str_ends_with( $name, '.jpg' ) ? [ 'ext' => 'jpg', 'type' => 'image/jpeg' ] : [ 'ext' => false, 'type' => false ];
		$items   = MediaPanelState::markImportable( [ [ 'name' => 'a.jpg' ], [ 'name' => 'b.gpx' ] ], $checker );
		$this->assertArrayNotHasKey( 'can_import', $items[0] );
		$this->assertFalse( $items[1]['can_import'] );
		$this->assertNotSame( '', $items[1]['import_blocker'] );
	}

	public function test_the_old_choice_of_everything_still_means_everything(): void {
		$this->assertSame( FileTypes::categories(), EditorMediaController::normalizeMediaTypeFilter( [ 'image', 'video', 'other' ] ) );
		$this->assertSame( [ 'gps' ], EditorMediaController::normalizeMediaTypeFilter( [ 'gps', 'nonsense' ] ) );
		$this->assertSame( FileTypes::categories(), EditorMediaController::normalizeMediaTypeFilter( 'x' ) );
	}

	public function test_settings_store_and_read_the_lists(): void {
		$saved    = null;
		$settings = new GeneralSettings( function () use ( &$saved ) {
			return $saved ?? [];
		}, static function ( array $value ) use ( &$saved ): void {
			$saved = $value;
		} );
		$this->assertSame( FileTypes::DEFAULT_IGNORED, $settings->getIgnoredExtensions() );
		$this->assertSame( [], $settings->getAdditionalTypes() );

		$settings->save( [ 'max_entries' => 50, 'additional_types' => 'gpx application/gpx+xml', 'ignored_extensions' => 'TMP .bak' ] );
		$this->assertSame( [ 'gpx' => 'application/gpx+xml' ], $settings->getAdditionalTypes() );
		$this->assertSame( [ 'tmp', 'bak' ], $settings->getIgnoredExtensions() );

		$this->expectException( InvalidArgumentException::class );
		$settings->save( [ 'max_entries' => 50, 'additional_types' => 'php text/plain' ] );
	}
}
