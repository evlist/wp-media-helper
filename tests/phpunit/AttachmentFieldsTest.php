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
		$this->assertSame( [ [ 'value' => 'a', 'label' => 'a', 'icon' => '', 'icon_svg' => '' ], [ 'value' => 'b', 'label' => 'b', 'icon' => '', 'icon_svg' => '' ] ], $fields[2]['options'] );
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

	private function modes(): array {
		return Fields::normalize( [ [
			'key' => 'modes', 'label' => 'Modes', 'type' => 'multiselect', 'filter' => true, 'filter_default' => [ 'web' ],
			'options' => [
				[ 'value' => 'web', 'label' => 'Web', 'icon' => 'admin-site' ],
				[ 'value' => 'print', 'label' => 'Print', 'icon_svg' => 'M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6z' ],
				[ 'value' => 'evil', 'label' => 'Evil', 'icon' => 'Bad Icon!', 'icon_svg' => '<script>alert(1)</script>' ],
			],
		] ] );
	}

	public function test_a_field_can_be_a_filter_with_a_default_and_pictograms(): void {
		$field = $this->modes()[0];

		$this->assertTrue( $field['filter'] );
		$this->assertSame( [ 'web' ], $field['filter_default'], 'As given: only the web.' );
		$this->assertSame( 'admin-site', $field['options'][0]['icon'] );
		$this->assertSame( 'M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6z', $field['options'][1]['icon_svg'] );
		$this->assertSame( [ '', '' ], [ $field['options'][2]['icon'], $field['options'][2]['icon_svg'] ], 'Anything that is not a Dashicon name or path data is dropped.' );
	}

	public function test_the_default_of_the_filter_is_every_option_unless_said(): void {
		$fields = Fields::normalize( [
			[ 'key' => 'k', 'type' => 'select', 'filter' => true, 'options' => [ [ 'value' => 'a' ], [ 'value' => 'b' ] ] ],
			[ 'key' => 'n', 'type' => 'integer', 'filter' => true ],
			[ 'key' => 'o', 'type' => 'multiselect', 'options' => [ [ 'value' => 'a' ] ] ],
		] );

		$this->assertSame( [ 'a', 'b', '_none' ], $fields[0]['filter_default'], 'Nothing is hidden by default, links with no value included.' );
		$this->assertFalse( $fields[1]['filter'], 'Only a select or a multiselect can be a filter.' );
		$this->assertFalse( $fields[2]['filter'] );
	}

	public function test_the_selection_of_the_filters_comes_from_the_request_then_what_was_stored_then_the_default(): void {
		$fields = $this->modes();
		$none   = static fn (): mixed => null;

		$this->assertSame( [ 'modes' => [ 'web' ] ], \WP_Media_Helper\Admin\EditorMediaController::normalizeLinkFilters( $fields, null, $none ) );
		$this->assertSame( [ 'modes' => [ 'print', '_none' ] ], \WP_Media_Helper\Admin\EditorMediaController::normalizeLinkFilters( $fields, [ 'modes' => [ 'print', '_none', 'zzz' ] ], $none ) );
		$this->assertSame( [ 'modes' => [] ], \WP_Media_Helper\Admin\EditorMediaController::normalizeLinkFilters( $fields, [ 'modes' => [] ], $none ), 'Nothing selected is a choice.' );
		$this->assertSame( [ 'modes' => [ 'print' ] ], \WP_Media_Helper\Admin\EditorMediaController::normalizeLinkFilters( $fields, [], static fn (): array => [ 'print' ] ) );
		$this->assertSame( [], \WP_Media_Helper\Admin\EditorMediaController::normalizeLinkFilters( Fields::normalize( [ [ 'key' => 'p', 'type' => 'integer' ] ] ), null, $none ) );
	}

	public function test_the_filter_only_concerns_the_files_attached_to_this_post(): void {
		$fields = $this->modes();
		$items  = [
			[ 'path' => 'web', 'is_attached_to_current_post' => true, 'attachment_data' => [ 'modes' => [ 'web' ] ] ],
			[ 'path' => 'print', 'is_attached_to_current_post' => true, 'attachment_data' => [ 'modes' => [ 'print' ] ] ],
			[ 'path' => 'both', 'is_attached_to_current_post' => true, 'attachment_data' => [ 'modes' => [ 'web', 'print' ] ] ],
			[ 'path' => 'nomode', 'is_attached_to_current_post' => true, 'attachment_data' => [ 'modes' => [] ] ],
			[ 'path' => 'nodata', 'is_attached_to_current_post' => true ],
			[ 'path' => 'free', 'is_attached_to_current_post' => false ],
			[ 'path' => 'elsewhere', 'is_attached_to_other_post' => true ],
		];
		$paths = static fn ( array $selection ): array => array_column( \WP_Media_Helper\Admin\MediaPanelState::filterByLinkFields( $items, $fields, [ 'modes' => $selection ] ), 'path' );

		$this->assertSame( [ 'web', 'both', 'free', 'elsewhere' ], $paths( [ 'web' ] ) );
		$this->assertSame( [ 'print', 'both', 'free', 'elsewhere' ], $paths( [ 'print' ] ) );
		$this->assertSame( [ 'nomode', 'nodata', 'free', 'elsewhere' ], $paths( [ '_none' ] ), 'The links with no mode have their own value.' );
		$this->assertSame( [ 'free', 'elsewhere' ], $paths( [] ), 'Nothing selected hides the attached files only.' );
		$this->assertSame( array_column( $items, 'path' ), $paths( [ 'web', 'print', '_none' ] ), 'Everything selected filters nothing.' );
	}

	public function test_with_both_mechanisms_the_first_filter_also_selects_the_wordpress_way(): void {
		$fields = $this->modes();
		$none   = static fn (): mixed => null;
		$filters = \WP_Media_Helper\Admin\EditorMediaController::class;

		$this->assertSame( [ 'modes' => [ 'web', '_native' ] ], $filters::normalizeLinkFilters( $fields, null, $none, true ), 'The default of the field, and the WordPress way.' );
		$this->assertSame( [ 'modes' => [ 'web', '_native' ] ], $filters::normalizeLinkFilters( $fields, [ 'modes' => [ '_native', 'web' ] ], $none, true ), 'In the order of the options, then the WordPress way.' );
		$this->assertSame( [ 'modes' => [ 'web', '_none', '_native' ] ], $filters::normalizeLinkFilters( $fields, [ 'modes' => [ 'web', '_none', '_native', 'zzz' ] ], $none, true ) );
		$this->assertSame( [ 'modes' => [ 'web' ] ], $filters::normalizeLinkFilters( $fields, [ 'modes' => [ 'web', '_native' ] ], $none, false ), '`_native` is not a value without the cohabitation.' );
	}

	public function test_the_files_attached_the_wordpress_way_answer_the_native_value_only(): void {
		$fields = $this->modes();
		$items  = [
			[ 'path' => 'native', 'is_attached_to_current_post' => true, 'attached_natively_only' => true ],
			[ 'path' => 'web', 'is_attached_to_current_post' => true, 'attachment_data' => [ 'modes' => [ 'web' ] ] ],
			[ 'path' => 'print', 'is_attached_to_current_post' => true, 'attachment_data' => [ 'modes' => [ 'print' ] ] ],
			[ 'path' => 'free', 'is_attached_to_current_post' => false ],
		];
		$paths = static fn ( array $selection ): array => array_column( \WP_Media_Helper\Admin\MediaPanelState::filterByLinkFields( $items, $fields, [ 'modes' => $selection ], true ), 'path' );

		$this->assertSame( [ 'native', 'free' ], $paths( [ '_native' ] ) );
		$this->assertSame( [ 'web', 'free' ], $paths( [ 'web' ] ) );
		$this->assertSame( [ 'native', 'web', 'free' ], $paths( [ 'web', '_native' ] ) );
		$this->assertSame( array_column( $items, 'path' ), $paths( [ 'web', 'print', '_none', '_native' ] ) );
		$this->assertSame( [ 'free' ], $paths( [] ) );
	}
}
