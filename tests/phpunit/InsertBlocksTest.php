<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Admin\InsertBlocks;

class InsertBlocksTest extends TestCase {

	private function image( int $id = 58754 ): array {
		return [
			'id'       => $id,
			'url'      => 'https://e/uploads/thumbnails/photos/2026/gisele/10/20261016_161623-1024x473.jpg',
			'full_url' => 'https://e/uploads/photos/2026/gisele/10/20261016_161623.jpg',
			'alt'      => '',
		];
	}

	public function test_a_photo_is_a_gallery_of_one_image_like_the_gallery_block_of_the_editor(): void {
		$block = InsertBlocks::gallery( [ $this->image() ], 'large', 'none' );

		$this->assertSame( 'core/gallery', $block['block'] );
		$this->assertSame( [ 'linkTo' => 'none' ], $block['attributes'] );
		$this->assertSame( 1, count( $block['innerBlocks'] ) );
		$image = $block['innerBlocks'][0];
		$this->assertSame( 'core/image', $image['block'] );
		$this->assertSame( [ 'id' => 58754, 'url' => $this->image()['url'], 'alt' => '', 'sizeSlug' => 'large', 'linkDestination' => 'none' ], $image['attributes'] );
	}

	public function test_the_default_links_the_images_to_their_file(): void {
		$block = InsertBlocks::gallery( [ $this->image( 1 ), $this->image( 2 ) ] );

		$this->assertSame( 'media', $block['attributes']['linkTo'] );
		$this->assertSame( [ 1, 2 ], array_map( static fn ( array $inner ): int => $inner['attributes']['id'], $block['innerBlocks'] ) );
		$this->assertSame( 'https://e/uploads/photos/2026/gisele/10/20261016_161623.jpg', $block['innerBlocks'][0]['attributes']['href'] );
		$this->assertSame( 'large', $block['innerBlocks'][0]['attributes']['sizeSlug'] );
		$this->assertSame( 'media', $block['innerBlocks'][1]['attributes']['linkDestination'] );
	}

	public function test_a_file_that_is_not_a_photo_is_refused_with_the_reason_for_now(): void {
		$this->assertNull( InsertBlocks::refusal( 'image', 'a.jpg' ) );
		$this->assertTrue( str_contains( (string) InsertBlocks::refusal( 'video', 'clip.mp4' ), 'clip.mp4' ) );
		$this->assertNotNull( InsertBlocks::refusal( 'other', 'track.gpx' ) );
	}
}
