<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Attachment\Fields;

class AttachmentFieldsTest extends TestCase {

	private function declared(): array {
		return Fields::normalize( [
			[ 'key' => 'modes', 'label' => 'Modes', 'type' => 'multiselect', 'options' => [ [ 'value' => 'web', 'label' => 'Web' ], [ 'value' => 'print', 'label' => 'Print' ] ], 'default' => [ 'web', 'print' ] ],
			[ 'key' => 'position', 'label' => 'Position', 'type' => 'integer' ],
			[ 'key' => 'kind', 'type' => 'select', 'options' => [ [ 'value' => 'a' ], [ 'value' => 'b' ] ], 'default' => 'b' ],
			[ 'key' => 'big', 'label' => 'Big', 'type' => 'boolean', 'default' => true ],
			[ 'key' => 'note', 'label' => 'Note', 'type' => 'text' ],
		] );
	}

	public function test_valid_declarations_are_kept_with_their_defaults(): void {
		$fields = $this->declared();

		$this->assertSame( [ 'modes', 'position', 'kind', 'big', 'note' ], array_column( $fields, 'key' ) );
		$this->assertSame( [ 'web', 'print' ], $fields[0]['default'] );
		$this->assertNull( $fields[1]['default'] );
		$this->assertSame( 'b', $fields[2]['default'] );
		$this->assertSame( 'kind', $fields[2]['label'], 'A missing label is the key.' );
		$this->assertSame( [ [ 'value' => 'a', 'label' => 'a' ], [ 'value' => 'b', 'label' => 'b' ] ], $fields[2]['options'] );
		$this->assertTrue( $fields[3]['default'] );
		$this->assertSame( '', $fields[4]['default'] );
	}

	public function test_invalid_declarations_are_dropped(): void {
		$fields = Fields::normalize( [
			'not an array',
			[ 'key' => 'Bad Key!', 'type' => 'text' ],
			[ 'key' => 'x', 'type' => 'unknown' ],
			[ 'key' => 'noopts', 'type' => 'select', 'options' => [] ],
			[ 'key' => 'ok', 'type' => 'text' ],
			[ 'key' => 'ok', 'type' => 'integer' ],
			[ 'type' => 'text' ],
		] );

		$this->assertSame( [ 'ok' ], array_column( $fields, 'key' ) );
		$this->assertSame( 'text', $fields[0]['type'] );
		$this->assertSame( [], Fields::normalize( null ) );
	}

	public function test_the_default_is_coerced_to_the_declared_options(): void {
		$fields = Fields::normalize( [ [ 'key' => 'm', 'type' => 'multiselect', 'options' => [ [ 'value' => 'a' ] ], 'default' => [ 'a', 'zzz' ] ] ] );

		$this->assertSame( [ 'a' ], $fields[0]['default'] );
	}

	public function test_what_the_browser_sends_is_coerced_and_unknown_keys_are_dropped(): void {
		$data = Fields::sanitize( $this->declared(), [
			'modes'    => [ 'print', 'web', 'evil', [ 'x' ] ],
			'position' => ' 12 ',
			'kind'     => 'zzz',
			'big'      => 'on',
			'note'     => "  <b>Hello</b>\nworld  ",
			'other'    => 'dropped',
		] );

		$this->assertSame( [ 'web', 'print' ], $data['modes'], 'In the order of the options, only the declared values.' );
		$this->assertSame( 12, $data['position'] );
		$this->assertSame( '', $data['kind'] );
		$this->assertTrue( $data['big'] );
		$this->assertSame( 'Hello world', $data['note'] );
		$this->assertArrayNotHasKey( 'other', $data );
	}

	public function test_bad_values_become_empty_ones(): void {
		$data = Fields::sanitize( $this->declared(), [ 'modes' => 'web', 'position' => 'abc', 'big' => 'no', 'note' => [ 'x' ] ] );

		$this->assertSame( [], $data['modes'] );
		$this->assertNull( $data['position'] );
		$this->assertFalse( $data['big'] );
		$this->assertSame( '', $data['note'] );
		$this->assertNull( Fields::sanitize( $this->declared(), [ 'position' => '' ] )['position'] );
	}

	public function test_defaults_fill_what_was_not_sent_only_when_asked(): void {
		$this->assertSame( [], Fields::sanitize( $this->declared(), null ) );
		$this->assertSame( [ 'modes' => [ 'web', 'print' ], 'position' => null, 'kind' => 'b', 'big' => true, 'note' => '' ], Fields::sanitize( $this->declared(), null, true ) );
		$this->assertSame( [ 'web' ], Fields::sanitize( $this->declared(), [ 'modes' => [ 'web' ] ], true )['modes'] );
	}

	public function test_the_text_is_cut(): void {
		$data = Fields::sanitize( $this->declared(), [ 'note' => str_repeat( 'é', 400 ) ] );

		$this->assertSame( 255, mb_strlen( $data['note'] ) );
	}
}
