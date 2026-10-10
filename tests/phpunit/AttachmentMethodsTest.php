<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

// The functions of WordPress that the native method calls, in its namespace so that no other test sees them.
namespace WP_Media_Helper\Attachment {

	function current_user_can( string $capability, ...$args ): bool {
		return ! in_array( $args[0] ?? 0, $GLOBALS['wpmh_test_forbidden'], true );
	}

	function wp_update_post( array $data, bool $wp_error = false ) {
		$GLOBALS['wpmh_test_posts'][ $data['ID'] ] = $data['post_parent'];

		return $data['ID'];
	}

	function is_wp_error( $value ): bool {
		return $value instanceof \WP_Error;
	}

	function get_post_field( string $field, int $id ) {
		return $GLOBALS['wpmh_test_posts'][ $id ] ?? 0;
	}

	function get_attached_media( string $type, int $postId ): array {
		return [ 31 => 'a', 30 => 'b' ];
	}
}

namespace {

	use PHPUnit\Framework\TestCase;
	use WP_Media_Helper\Attachment\Method;
	use WP_Media_Helper\Attachment\Methods;
	use WP_Media_Helper\Attachment\NativeMethod;

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;

			public function __construct( string $code = '', string $message = '' ) {
				$this->code    = $code;
				$this->message = $message;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			foreach ( $GLOBALS['wpmh_test_filters'][ $hook ] ?? [] as $callback ) {
				$value = $callback( $value, ...$args );
			}

			return $value;
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, $default = false ) {
			return $GLOBALS['wpmh_test_options'][ $name ] ?? $default;
		}
	}

	class AttachmentMethodsTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wpmh_test_posts']     = [ 10 => 0, 11 => 7, 12 => 8 ];
			$GLOBALS['wpmh_test_forbidden'] = [];
			$GLOBALS['wpmh_test_filters']   = [];
			$GLOBALS['wpmh_test_options']   = [];
			$GLOBALS['wpdb']                = new class() {
				public string $posts = 'wp_posts';

				public function prepare( string $query, $ids = [] ): string {
					return implode( ',', $ids );
				}

				public function get_results( string $query, $output = null ): array {
					$rows = [];
					foreach ( array_filter( explode( ',', $query ) ) as $id ) {
						if ( isset( $GLOBALS['wpmh_test_posts'][ (int) $id ] ) ) {
							$rows[] = [ 'ID' => (int) $id, 'post_parent' => $GLOBALS['wpmh_test_posts'][ (int) $id ] ];
						}
					}

					return $rows;
				}
			};
		}

		protected function tearDown(): void {
			unset( $GLOBALS['wpmh_test_posts'], $GLOBALS['wpmh_test_forbidden'], $GLOBALS['wpmh_test_filters'], $GLOBALS['wpmh_test_options'], $GLOBALS['wpdb'] );
		}

		public function test_the_native_method_describes_a_page_with_one_query_and_the_old_rules(): void {
			$described = ( new NativeMethod() )->describe( [ 10, 11, 12, 99 ], 7 );

			$this->assertSame( [ 'attached_here' => false, 'elsewhere' => [], 'protected' => null, 'data' => [] ], $described[10] );
			$this->assertTrue( $described[11]['attached_here'] );
			$this->assertNull( $described[11]['protected'], 'Attached to this post: nothing blocks.' );
			$this->assertSame( [ 8 ], $described[12]['elsewhere'] );
			$this->assertNotNull( $described[12]['protected'], 'Attached to another post: protected.' );
			$this->assertSame( [], $described[99]['elsewhere'], 'An unknown attachment is not attached.' );
			$this->assertFalse( $described[10]['attached_here'] );
		}

		public function test_a_new_post_has_nothing_attached_here(): void {
			$described = ( new NativeMethod() )->describe( [ 11 ], 0 );

			$this->assertFalse( $described[11]['attached_here'] );
			$this->assertSame( [ 7 ], $described[11]['elsewhere'] );
		}

		public function test_attach_sets_the_parent_and_refuses_what_the_user_may_not_change(): void {
			$native = new NativeMethod();
			$this->assertTrue( $native->attach( 10, 5, [] ) );
			$this->assertSame( 5, $GLOBALS['wpmh_test_posts'][10] );

			$GLOBALS['wpmh_test_forbidden'] = [ 12 ];
			$error = $native->attach( 12, 5, [] );
			$this->assertTrue( $error instanceof \WP_Error );
			$this->assertSame( 8, $GLOBALS['wpmh_test_posts'][12] );
		}

		public function test_detach_removes_the_link_with_this_post_only(): void {
			$native = new NativeMethod();

			$this->assertTrue( $native->detach( 11, 8 ), 'Not attached to that post: nothing to do.' );
			$this->assertSame( 7, $GLOBALS['wpmh_test_posts'][11] );

			$this->assertTrue( $native->detach( 11, 7 ) );
			$this->assertSame( 0, $GLOBALS['wpmh_test_posts'][11] );
		}

		public function test_detach_says_not_allowed_without_failing(): void {
			$GLOBALS['wpmh_test_forbidden'] = [ 11 ];
			$error = ( new NativeMethod() )->detach( 11, 7 );

			$this->assertTrue( $error instanceof \WP_Error );
			$this->assertSame( 'not_allowed', $error->get_error_code() );
			$this->assertSame( 7, $GLOBALS['wpmh_test_posts'][11] );
		}

		public function test_removal_is_refused_for_an_attached_media_except_through_the_trash(): void {
			$native = new NativeMethod();

			$this->assertTrue( $native->may_remove( 10 ) );
			$this->assertTrue( $native->may_remove( 11 ) instanceof \WP_Error );
			$this->assertTrue( $native->may_remove( 11, 'trash' ) );
		}

		public function test_posts_of_and_attached_follow_the_parent(): void {
			$native = new NativeMethod();

			$this->assertSame( [ 10 => [], 11 => [ 7 ], 99 => [] ], $native->posts_of( [ 10, 11, 99 ] ) );
			$this->assertSame( [ 31, 30 ], $native->attached( 7, [ 'mime_type' => 'image' ] ) );
			$this->assertSame( [ 'multiple_posts' => false, 'fields' => [] ], $native->capabilities() );
		}

		public function test_the_registry_has_the_native_method_and_ignores_what_is_not_a_method(): void {
			$this->assertSame( [ 'native' ], array_keys( Methods::all() ) );

			$other = new class() implements Method {
				public function id(): string {
					return 'other';
				}
				public function label(): string {
					return 'Other';
				}
				public function capabilities(): array {
					return [ 'multiple_posts' => true, 'fields' => [] ];
				}
				public function describe( array $attachmentIds, int $postId ): array {
					return [];
				}
				public function attach( int $attachmentId, int $postId, array $data ) {
					return true;
				}
				public function detach( int $attachmentId, int $postId ) {
					return true;
				}
				public function update( int $attachmentId, int $postId, array $data ) {
					return true;
				}
				public function attached( int $postId, array $args ): array {
					return [];
				}
				public function posts_of( array $attachmentIds ): array {
					return [];
				}
				public function may_remove( int $attachmentId, string $context = 'remove' ) {
					return true;
				}
			};
			$GLOBALS['wpmh_test_filters']['wp_media_helper_attachment_methods'][] = static function ( array $methods ) use ( $other ): array {
				$methods['other']  = $other;
				$methods['wrong']  = $other;      // Registered under another identifier than its own.
				$methods['native'] = $other;      // The native method cannot be replaced.
				$methods['text']   = 'not a method';

				return $methods;
			};

			$methods = Methods::all();
			$this->assertSame( [ 'native', 'other' ], array_keys( $methods ) );
			$this->assertTrue( $methods['native'] instanceof NativeMethod );
		}

		public function test_the_active_method_is_the_setting_then_the_filter_and_falls_back_to_native(): void {
			$this->assertSame( 'native', Methods::active( 5 )->id() );

			$GLOBALS['wpmh_test_options'][ Methods::OPTION ] = 'missing';
			$this->assertSame( 'native', Methods::active( 5 )->id(), 'A method that is no longer registered gives native.' );

			$seen = [];
			$GLOBALS['wpmh_test_filters']['wp_media_helper_attachment_method'][] = static function ( string $id, int $postId, string $type ) use ( &$seen ): string {
				$seen = [ $id, $postId, $type ];

				return $id;
			};
			Methods::active( 5 );
			$this->assertSame( [ 'missing', 5, '' ], $seen );
		}
	}
}
