<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

/**
 * What the editor inserts for a file dropped from the panel into the content (slice 051), as a description of blocks: the browser
 * turns it into blocks, so it holds no rule of its own about types and addresses. Photos first: a gallery of nested image blocks,
 * the shape of the gallery block of the editor (a single photo is a gallery of one, other photos can then be dropped into it).
 */
final class InsertBlocks {

	public const SIZE = 'large';

	/**
	 * A gallery of image blocks.
	 *
	 * @param array<int, array{id:int, url:string, full_url:string, alt:string}> $images `url` is the address of the size shown, `full_url` that of the file.
	 * @return array{block:string, attributes:array<string, mixed>, innerBlocks:array<int, array<string, mixed>>}
	 */
	public static function gallery( array $images, string $size = self::SIZE, string $linkTo = 'media' ): array {
		$inner = [];
		foreach ( $images as $image ) {
			$inner[] = self::image( $image, $size, $linkTo );
		}

		return [ 'block' => 'core/gallery', 'attributes' => [ 'linkTo' => $linkTo ], 'innerBlocks' => $inner ];
	}

	/**
	 * One image block, as it is inside a gallery (and alone, when the files are dropped into a gallery).
	 *
	 * @param array{id:int, url:string, full_url:string, alt:string} $image
	 * @return array{block:string, attributes:array<string, mixed>, innerBlocks:array<int, array<string, mixed>>}
	 */
	public static function image( array $image, string $size = self::SIZE, string $linkTo = 'media' ): array {
		$attributes = [
			'id'              => (int) $image['id'],
			'url'             => (string) $image['url'],
			'alt'             => (string) $image['alt'],
			'sizeSlug'        => $size,
			'linkDestination' => $linkTo,
		];
		if ( 'media' === $linkTo ) {
			$attributes['href'] = (string) $image['full_url'];
		}

		return [ 'block' => 'core/image', 'attributes' => $attributes, 'innerBlocks' => [] ];
	}

	/**
	 * A video block, with the subtitles that go with it (`tracks`, as the block holds them).
	 *
	 * @param array{id:int, url:string} $video
	 * @param array<int, array{url:string, srclang:string, label:string}> $tracks
	 * @return array{block:string, attributes:array<string, mixed>, innerBlocks:array<int, array<string, mixed>>}
	 */
	public static function video( array $video, array $tracks = [] ): array {
		$attributes = [ 'id' => (int) $video['id'], 'src' => (string) $video['url'], 'controls' => true ];
		if ( [] !== $tracks ) {
			$attributes['tracks'] = array_map( [ self::class, 'track' ], $tracks );
		}

		return [ 'block' => 'core/video', 'attributes' => $attributes, 'innerBlocks' => [] ];
	}

	/**
	 * One track of a video block.
	 *
	 * @param array{url:string, srclang:string, label:string} $track
	 * @return array{src:string, kind:string, label:string, srclang:string}
	 */
	public static function track( array $track ): array {
		return [ 'src' => (string) $track['url'], 'kind' => 'subtitles', 'label' => (string) $track['label'], 'srclang' => (string) $track['srclang'] ];
	}

	/**
	 * Why a file cannot be inserted yet, or null when it can.
	 */
	public static function refusal( string $mediaType, string $name ): ?string {
		if ( in_array( $mediaType, [ 'image', 'video' ], true ) ) {
			return null;
		}
		if ( 'subtitles' === $mediaType ) {
			// The video element only reads WebVTT.
			return 'vtt' === strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ? null : sprintf(
				/* translators: %s: file name. */
				__( '%s cannot be used as subtitles of a video: the browser only reads WebVTT (.vtt) files. Convert it first.', 'wp-media-helper' ),
				$name
			);
		}

		return sprintf(
			/* translators: %s: file name. */
			__( 'No block is defined for %s: only photos, videos and subtitles can be inserted in the content for now.', 'wp-media-helper' ),
			$name
		);
	}
}
