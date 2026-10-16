<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

// The functions of WordPress that the guarded removal calls, in its namespace so that no other test sees them.
namespace WP_Media_Helper\MediaSource {

	function is_wp_error( $value ): bool {
		return $value instanceof \WP_Error;
	}

	function do_action( string $hook, ...$args ): void {
		$GLOBALS['wpmh_test_actions'][] = [ $hook, $args ];
	}
}

namespace {

	use PHPUnit\Framework\TestCase;
	use WP_Media_Helper\MediaSource\RecordRemover;

	class RecordRemoverGuardTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wpmh_test_actions'] = [];
		}

		public function test_every_method_is_asked_with_the_trash_context_and_a_refusal_keeps_the_record(): void {
			$native = new RecordRemoverFakeMethod( 'native' );
			$other  = new RecordRemoverFakeMethod( 'otherguise', [ 11 ] );
			$passed = [];

			$result = RecordRemover::removeGuarded( [ 10, 11, 12 ], [ 'native' => $native, 'otherguise' => $other ], 'trash', function ( array $ids ) use ( &$passed ): array {
				$passed = $ids;

				return $ids;
			} );

			$this->assertSame( [ 10, 12 ], $passed );
			$this->assertSame( [ 10, 12 ], $result['removed'] );
			$this->assertSame( [ 11 => 'otherguise: linked to a mode' ], $result['refused'] );
			$this->assertSame( [ 'trash', 'trash', 'trash' ], array_slice( $other->contexts, 0, 3 ) );
		}

		public function test_the_methods_are_told_what_was_removed(): void {
			RecordRemover::removeGuarded( [ 10 ], [ 'native' => new RecordRemoverFakeMethod( 'native' ) ], 'trash', fn( array $ids ): array => $ids );

			$this->assertSame( [ [ 'wp_media_helper_attachments_removed', [ [ 10 ], 'orphans' ] ] ], $GLOBALS['wpmh_test_actions'] );
		}

		public function test_nothing_is_announced_when_nothing_was_removed(): void {
			RecordRemover::removeGuarded( [ 11 ], [ 'o' => new RecordRemoverFakeMethod( 'o', [ 11 ] ) ], 'trash', fn( array $ids ): array => $ids );

			$this->assertSame( [], $GLOBALS['wpmh_test_actions'] );
		}
	}
}
