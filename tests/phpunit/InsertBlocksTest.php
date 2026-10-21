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
		$this->assertNotNull( InsertBlocks::refusal( 'other', 'track.gpx' ) );
	}

	public function test_videos_and_webvtt_subtitles_can_be_inserted_other_subtitles_cannot(): void {
		$this->assertNull( InsertBlocks::refusal( 'video', 'clip.mp4' ) );
		$this->assertNull( InsertBlocks::refusal( 'subtitles', 'clip.fr.vtt' ) );
		$this->assertTrue( str_contains( (string) InsertBlocks::refusal( 'subtitles', 'clip.fr.srt' ), 'WebVTT' ) );
	}

	public function test_a_video_block_holds_its_subtitles_as_tracks(): void {
		$block = InsertBlocks::video(
			[ 'id' => 7, 'url' => 'https://e/clip.mp4' ],
			[ [ 'url' => 'https://e/clip.fr.vtt', 'srclang' => 'fr', 'label' => 'Français' ] ]
		);

		$this->assertSame( 'core/video', $block['block'] );
		$this->assertSame( [ 'id' => 7, 'src' => 'https://e/clip.mp4', 'controls' => true, 'tracks' => [ [ 'src' => 'https://e/clip.fr.vtt', 'kind' => 'subtitles', 'label' => 'Français', 'srclang' => 'fr' ] ] ], $block['attributes'] );
		$this->assertArrayNotHasKey( 'tracks', InsertBlocks::video( [ 'id' => 7, 'url' => 'u' ] )['attributes'] );
	}

	public function test_a_gpx_track_is_not_put_in_the_content_the_theme_shows_the_attached_ones(): void {
		$message = (string) InsertBlocks::refusal( 'gps', 'trace.gpx' );

		$this->assertTrue( str_contains( $message, 'trace.gpx' ) );
		$this->assertTrue( str_contains( $message, 'Attach' ) );
		$this->assertNull( InsertBlocks::gpx( [ 'id' => 5, 'url' => 'https://e/trace.gpx', 'name' => 'trace.gpx' ] ) );
		// The other files of the family are not inserted either.
		$this->assertNotNull( InsertBlocks::refusal( 'gps', 'trace.kml' ) );
	}

	public function test_a_site_can_give_a_block_for_a_gpx_track(): void {
		$GLOBALS['wpmh_test_filters']['wp_media_helper_insert_gpx_block'][] = static fn ( $block, array $file ) => [ 'block' => 'core/shortcode', 'attributes' => [ 'text' => '[sgpx gpx="' . $file['url'] . '"]' ] ];
		try {
			$this->assertNull( InsertBlocks::refusal( 'gps', 'trace.gpx' ) );
			$block = InsertBlocks::gpx( [ 'id' => 5, 'url' => 'https://e/trace.gpx', 'name' => 'trace.gpx' ] );
			$this->assertSame( 'core/shortcode', $block['block'] );
			$this->assertSame( [ 'text' => '[sgpx gpx="https://e/trace.gpx"]' ], $block['attributes'] );
			$this->assertSame( [], $block['innerBlocks'] );
		} finally {
			unset( $GLOBALS['wpmh_test_filters']['wp_media_helper_insert_gpx_block'] );
		}
	}
}
