<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\MediaSource\ImageDimensions;
use WP_Media_Helper\Thumbnails\PanelThumbnail;

class ImagePreviewTest extends TestCase {

	public function test_a_quarter_turn_orientation_swaps_the_dimensions(): void {
		foreach ( [ 0, 1, 2, 3, 4 ] as $orientation ) {
			$this->assertSame( [ 4000, 3000 ], ImageDimensions::oriented( 4000, 3000, $orientation ) );
		}
		foreach ( [ 5, 6, 7, 8 ] as $orientation ) {
			$this->assertSame( [ 3000, 4000 ], ImageDimensions::oriented( 4000, 3000, $orientation ) );
		}
	}

	public function test_only_raster_formats_are_read(): void {
		$this->assertTrue( ImageDimensions::isReadable( 'a.JPG' ) );
		$this->assertTrue( ImageDimensions::isReadable( 'a.webp' ) );
		$this->assertFalse( ImageDimensions::isReadable( 'a.svg' ) );
		$this->assertFalse( ImageDimensions::isReadable( 'a.mp4' ) );
	}

	public function test_the_reader_gives_the_size_of_a_real_image_and_null_for_anything_else(): void {
		$file = sys_get_temp_dir() . '/wpmh_dim_' . uniqid() . '.png';
		imagepng( imagecreatetruecolor( 40, 30 ), $file );
		$text = $file . '.txt';
		file_put_contents( $text, 'not an image' );

		$read = ImageDimensions::reader();
		$this->assertSame( [ 40, 30 ], $read( $file ) );
		$this->assertNull( $read( $text ) );
		$this->assertNull( $read( $file . '.missing' ) );

		unlink( $file );
		unlink( $text );
	}

	public function test_a_preview_keeps_the_proportions_and_is_never_larger_than_the_image(): void {
		$this->assertSame( [ 320, 213 ], PanelThumbnail::previewDimensions( 3000, 2000, 320 ) );
		$this->assertSame( [ 180, 320 ], PanelThumbnail::previewDimensions( 1080, 1920, 320 ), 'A portrait is limited by its height.' );
		$this->assertSame( [ 640, 360 ], PanelThumbnail::previewDimensions( 1920, 1080, 640 ) );
		$this->assertNull( PanelThumbnail::previewDimensions( 320, 200, 320 ), 'Not larger than the size: the image itself is used.' );
		$this->assertNull( PanelThumbnail::previewDimensions( 100, 80, 320 ) );
		$this->assertNull( PanelThumbnail::previewDimensions( 0, 80, 320 ) );
		$this->assertSame( [ 320, 1 ], PanelThumbnail::previewDimensions( 100000, 300, 320 ), 'A very thin image keeps one pixel.' );
	}

	public function test_the_two_preview_sizes_are_defined(): void {
		$this->assertSame( [ 'small' => 320, 'large' => 640 ], PanelThumbnail::SIZES );
	}
}
