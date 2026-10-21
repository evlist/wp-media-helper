<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Settings\InsertHandlers;

class InsertHandlersTest extends TestCase {

	public function test_one_line_by_extension_is_read(): void {
		$result = InsertHandlers::parse( "gpx {\"block\": \"core/shortcode\", \"attributes\": {\"text\": \"[sgpx gpx=\\\"{url}\\\"]\"}}\n\n.KML {\"block\":\"my/map\"}" );

		$this->assertSame( [], $result['errors'] );
		$this->assertSame( [ 'gpx', 'kml' ], array_keys( $result['handlers'] ) );
		$this->assertSame( 'core/shortcode', $result['handlers']['gpx']['block'] );
		$this->assertSame( 'my/map', $result['handlers']['kml']['block'] );
	}

	public function test_a_wrong_line_is_an_error_with_its_number(): void {
		$result = InsertHandlers::parse( "gpx {\"block\":\"core/shortcode\"}\nkml not json\npdf {\"block\":\"nope\"}\nzip {\"block\":\"a/b\",\"attributes\":3}" );

		$this->assertSame( [ 'gpx' ], array_keys( $result['handlers'] ) );
		$this->assertCount( 3, $result['errors'] );
		$this->assertTrue( str_contains( $result['errors'][0], 'line 2' ) );
		$this->assertTrue( str_contains( $result['errors'][1], 'line 3' ) );
		$this->assertTrue( str_contains( $result['errors'][2], 'line 4' ) );
	}

	public function test_the_placeholders_are_filled_everywhere_and_id_is_a_number_when_alone(): void {
		$template = [ 'block' => 'my/track', 'attributes' => [ 'id' => '{id}', 'url' => '{url}', 'text' => '[t id={id} f="{name}"] {title} {mime}', 'flag' => true ], 'innerBlocks' => [ [ 'block' => 'core/paragraph', 'attributes' => [ 'content' => '{name}' ] ] ] ];

		$block = InsertHandlers::block( $template, [ 'id' => 42, 'url' => 'https://e/t.gpx', 'name' => 't.gpx', 'title' => 'Track', 'mime' => 'application/gpx+xml' ] );

		$this->assertSame( 'my/track', $block['block'] );
		$this->assertSame( [ 'id' => 42, 'url' => 'https://e/t.gpx', 'text' => '[t id=42 f="t.gpx"] Track application/gpx+xml', 'flag' => true ], $block['attributes'] );
		$this->assertSame( 'core/paragraph', $block['innerBlocks'][0]['block'] );
		$this->assertSame( [ 'content' => 't.gpx' ], $block['innerBlocks'][0]['attributes'] );
	}

	public function test_the_saved_text_wins_and_nothing_is_assumed_without_the_gpx_plugin(): void {
		$this->assertSame( 'gpx {"block":"a/b"}', InsertHandlers::textOf( [ 'insert_handlers' => 'gpx {"block":"a/b"}' ] ) );
		$this->assertSame( '', InsertHandlers::textOf( [ 'insert_handlers' => '' ] ), 'Saved empty: no template.' );
		$this->assertSame( '', InsertHandlers::textOf( [] ), 'Without the plugin of the GPX shortcode, no default.' );
	}
}
