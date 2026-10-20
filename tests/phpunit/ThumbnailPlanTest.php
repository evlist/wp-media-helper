<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Thumbnails\ManagedFiles;
use WP_Media_Helper\Thumbnails\PostImages;
use WP_Media_Helper\Thumbnails\ThumbnailPlan;

class ThumbnailPlanTest extends TestCase {

	/** What `image_resize_dimensions` gives, simplified: nothing when the size is not smaller; a crop is exactly the size; else the proportions are kept. */
	private function dimensions(): callable {
		return static function ( int $w, int $h, int $dw, int $dh, $crop ) {
			if ( $dw >= $w && $dh >= $h ) {
				return false;
			}
			if ( $crop ) {
				return [ 0, 0, 0, 0, $dw, $dh, $w, $h ];
			}
			$ratio = min( $dw / $w, $dh / $h );

			return [ 0, 0, 0, 0, (int) round( $w * $ratio ), (int) round( $h * $ratio ), $w, $h ];
		};
	}

	private function definitions(): array {
		return [
			'thumb'   => [ 'width' => 480, 'height' => 270, 'crop' => true ],
			'print'   => [ 'width' => 1420, 'height' => 1420, 'crop' => false ],
			'huge'    => [ 'width' => 5000, 'height' => 5000, 'crop' => false ],
		];
	}

	public function test_a_size_that_is_not_there_is_missing_and_one_not_smaller_than_the_original_is_ignored(): void {
		$result = ThumbnailPlan::classify( [ 'width' => 4000, 'height' => 3000, 'sizes' => [] ], $this->definitions(), null, static fn (): bool => true, $this->dimensions() );

		$this->assertSame( [ 'thumb', 'print' ], $result['missing'] );
		$this->assertSame( [], $result['stale'] );
	}

	public function test_a_size_whose_file_is_gone_is_missing(): void {
		$metadata = [ 'width' => 4000, 'height' => 3000, 'sizes' => [ 'thumb' => [ 'width' => 480, 'height' => 270 ], 'print' => [ 'width' => 1420, 'height' => 1065 ] ] ];

		$result = ThumbnailPlan::classify( $metadata, $this->definitions(), null, static fn ( string $name ): bool => 'thumb' === $name, $this->dimensions() );

		$this->assertSame( [ 'print' ], $result['missing'] );
		$this->assertSame( [], $result['stale'] );
	}

	public function test_a_size_with_other_dimensions_than_its_definition_is_stale(): void {
		// 'print' was made for an A4 page (2480) and is now defined for an A5 page (1420); 'thumb' is as defined.
		$metadata = [ 'width' => 4000, 'height' => 3000, 'sizes' => [ 'thumb' => [ 'width' => 480, 'height' => 270 ], 'print' => [ 'width' => 2480, 'height' => 1860 ] ] ];

		$result = ThumbnailPlan::classify( $metadata, $this->definitions(), null, static fn (): bool => true, $this->dimensions() );

		$this->assertSame( [], $result['missing'] );
		$this->assertSame( [ 'print' ], $result['stale'] );
	}

	public function test_only_the_sizes_asked_for_are_looked_at(): void {
		$result = ThumbnailPlan::classify( [ 'width' => 4000, 'height' => 3000, 'sizes' => [] ], $this->definitions(), [ 'print' ], static fn (): bool => true, $this->dimensions() );

		$this->assertSame( [ 'print' ], $result['missing'] );
	}

	public function test_the_fingerprint_changes_with_a_definition_not_with_the_order(): void {
		$a = ThumbnailPlan::fingerprint( $this->definitions() );
		$reordered = array_reverse( $this->definitions(), true );
		$changed = $this->definitions();
		$changed['print']['width'] = 2480;

		$this->assertSame( $a, ThumbnailPlan::fingerprint( $reordered ) );
		$this->assertNotSame( $a, ThumbnailPlan::fingerprint( $changed ) );
	}

	public function test_the_images_of_a_content_are_found_in_classes_blocks_and_galleries(): void {
		$content = '<!-- wp:image {"id":12,"sizeSlug":"large"} --><figure><img class="wp-image-12"/></figure><!-- /wp:image -->'
			. '<!-- wp:gallery {"linkTo":"media"} --><figure><!-- wp:image {"id":30} --><img class="wp-image-30"/><!-- /wp:image --></figure><!-- /wp:gallery -->'
			. '<!-- wp:video {"id":77} --><!-- wp:cover {"id":5} /-->'
			. '[gallery ids="8, 9,10"] <img class="alignnone wp-image-44 size-large">';

		$this->assertSame( [ 5, 8, 9, 10, 12, 30, 44, 77 ], PostImages::ids( $content ) );
		$this->assertSame( [], PostImages::ids( 'nothing here' ) );
	}

	public function test_a_file_is_managed_when_it_is_under_the_directory_of_an_active_source(): void {
		$roots = [ '/var/www/html/wp-content/uploads/photos', '/srv/cumulus/' ];

		$this->assertTrue( ManagedFiles::isUnderRoots( '/var/www/html/wp-content/uploads/photos/2025/eric/07/03/a.jpg', $roots ) );
		$this->assertTrue( ManagedFiles::isUnderRoots( '/srv/cumulus//eric/a.jpg', $roots ) );
		$this->assertFalse( ManagedFiles::isUnderRoots( '/var/www/html/wp-content/uploads/photos-old/a.jpg', $roots ) );
		$this->assertFalse( ManagedFiles::isUnderRoots( '/var/www/html/wp-content/uploads/photos/../secret/a.jpg', $roots ) );
		$this->assertFalse( ManagedFiles::isUnderRoots( '/var/www/html/wp-content/uploads/photos', $roots ) );
		$this->assertFalse( ManagedFiles::isUnderRoots( '/anywhere/a.jpg', [] ) );
		$this->assertFalse( ManagedFiles::isUnderRoots( '', $roots ) );
	}
}
