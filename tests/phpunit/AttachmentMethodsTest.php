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

namespace WP_Media_Helper\Blocks {

	function get_the_ID() {
		return $GLOBALS['wpmh_test_loop_post'] ?? false;
	}

	function get_block_wrapper_attributes(): string {
		return 'class="wp-block-wp-media-helper-gallery"';
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

	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ): int {
			return abs( (int) $value );
		}
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( string $text ): string {
			return htmlspecialchars( $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( string $url ): string {
			return htmlspecialchars( $url, ENT_QUOTES );
		}
	}
	require_once __DIR__ . '/../../plugin/includes/functions.php';

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

		public function test_the_reading_function_gives_the_ids_of_the_active_method(): void {
			$this->assertSame( [ 31, 30 ], wp_media_helper_get_attached_media( 7, [ 'mime_type' => 'image' ] ) );
			$this->assertSame( [ 31, 30 ], wp_media_helper_get_attached_media( '7' ) );
			$this->assertSame( [ 31, 30 ], wp_media_helper_get_attached_media( 7, 'not an array' ) );
		}

		public function test_the_reading_function_follows_the_method_chosen(): void {
			$mine = new class() implements Method {
				public function id(): string {
					return 'mine';
				}
				public function label(): string {
					return 'Mine';
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
					return [ 5, '6', 5, 0, -1 + 1 ];
				}
				public function posts_of( array $attachmentIds ): array {
					return [];
				}
				public function may_remove( int $attachmentId, string $context = 'remove' ) {
					return true;
				}
			};
			$GLOBALS['wpmh_test_filters']['wp_media_helper_attachment_methods'][] = static function ( array $methods ) use ( $mine ): array {
				$methods['mine'] = $mine;

				return $methods;
			};
			$GLOBALS['wpmh_test_options'][ Methods::OPTION ] = 'mine';

			$this->assertSame( [ 5, 6 ], wp_media_helper_get_attached_media( 7, [ 'context' => 'print' ] ), 'The IDs are integers, without duplicates or zeros.' );
		}

		public function test_the_column_lists_the_posts_with_links_only_where_the_user_may_edit(): void {
			$html = \WP_Media_Helper\Admin\MediaLibraryColumn::format( [
				[ 'title' => 'Trip <1>', 'url' => 'https://e.test/wp-admin/post.php?post=1&action=edit' ],
				[ 'title' => 'Other', 'url' => '' ],
				[ 'title' => '', 'url' => '' ],
			] );

			$this->assertSame( '<a href="https://e.test/wp-admin/post.php?post=1&amp;action=edit">Trip &lt;1&gt;</a><br /><span>Other</span><br /><span>(no title)</span>', $html );
		}

		private function methodWith( array $attached, array $knownHere ): Method {
			return new class( $attached, $knownHere ) implements Method {
				private array $attached;
				private array $knownHere;

				public function __construct( array $attached, array $knownHere ) {
					$this->attached  = $attached;
					$this->knownHere = $knownHere;
				}
				public function id(): string {
					return 'mine';
				}
				public function label(): string {
					return 'Mine';
				}
				public function capabilities(): array {
					return [ 'multiple_posts' => true, 'fields' => [] ];
				}
				public function describe( array $attachmentIds, int $postId ): array {
					$described = [];
					foreach ( $attachmentIds as $id ) {
						$described[ $id ] = [ 'attached_here' => in_array( $id, $this->knownHere, true ), 'elsewhere' => [], 'protected' => null, 'data' => [] ];
					}

					return $described;
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
					return $this->attached;
				}
				public function posts_of( array $attachmentIds ): array {
					return [];
				}
				public function may_remove( int $attachmentId, string $context = 'remove' ) {
					return true;
				}
			};
		}

		private function useMethod( Method $method, bool $cohabitation ): void {
			$GLOBALS['wpmh_test_filters']['wp_media_helper_attachment_methods'][] = static function ( array $methods ) use ( $method ): array {
				$methods['mine'] = $method;

				return $methods;
			};
			$GLOBALS['wpmh_test_options'][ Methods::OPTION ]               = 'mine';
			$GLOBALS['wpmh_test_options'][ Methods::COHABITATION_OPTION ] = $cohabitation;
		}

		public function test_with_both_mechanisms_the_wordpress_links_unknown_to_the_method_are_read_when_no_context_is_asked(): void {
			// The method has 5, and knows 30 (the native stub returns 31 and 30).
			$this->useMethod( $this->methodWith( [ 5, 30 ], [ 30 ] ), true );

			$this->assertSame( [ 5, 30, 31 ], wp_media_helper_get_attached_media( 7, [ 'mime_type' => 'image' ] ) );
			$this->assertSame( [ 5, 30 ], wp_media_helper_get_attached_media( 7, [ 'context' => 'print' ] ), 'A WordPress link says nothing about a context.' );

			$GLOBALS['wpmh_test_filters']['wp_media_helper_native_links_in_context'][] = static fn ( bool $include, string $context ): bool => $include || 'web' === $context;
			$this->assertSame( [ 5, 30, 31 ], wp_media_helper_get_attached_media( 7, [ 'context' => 'web' ] ), 'A plugin may say that they count for the default mode.' );
			$this->assertSame( [ 5, 30 ], wp_media_helper_get_attached_media( 7, [ 'context' => 'print' ] ) );
		}

		public function test_without_the_cohabitation_only_the_method_is_read(): void {
			$this->useMethod( $this->methodWith( [ 5 ], [] ), false );

			$this->assertSame( [ 5 ], wp_media_helper_get_attached_media( 7, [] ) );
		}

		public function test_the_gallery_block_hands_the_attached_images_to_the_wordpress_gallery(): void {
			$seen = null;
			$html = \WP_Media_Helper\Blocks\GalleryBlock::render( [], 7, static function ( array $attributes ) use ( &$seen ): string {
				$seen = $attributes;

				return '<figure>gallery</figure>';
			} );

			$this->assertSame( '<div class="wp-block-wp-media-helper-gallery"><figure>gallery</figure></div>', $html );
			$this->assertSame( [ 'id' => 7, 'ids' => '31,30', 'columns' => 2, 'size' => 'gallery', 'link' => 'file' ], $seen, 'The attributes of today\'s gallery.' );
		}

		public function test_the_gallery_block_takes_the_post_of_the_loop_and_cleans_its_attributes(): void {
			$GLOBALS['wpmh_test_loop_post'] = 12;
			$seen = null;
			\WP_Media_Helper\Blocks\GalleryBlock::render( [ 'columns' => 50, 'size' => '', 'link' => 'evil' ], 0, static function ( array $attributes ) use ( &$seen ): string {
				$seen = $attributes;

				return '';
			} );

			$this->assertSame( 12, $seen['id'] );
			$this->assertSame( [ 9, 'gallery', 'file' ], [ $seen['columns'], $seen['size'], $seen['link'] ] );
			unset( $GLOBALS['wpmh_test_loop_post'] );
			$this->assertSame( '', \WP_Media_Helper\Blocks\GalleryBlock::render( [], 0, static fn (): string => 'never' ), 'No post: nothing.' );
		}

		public function test_the_gallery_block_asks_for_the_context_and_shows_nothing_without_images(): void {
			$this->useMethod( $this->methodWith( [], [] ), false );
			$GLOBALS['wpmh_test_filters']['wp_media_helper_gallery_context'][] = static fn ( string $context ): string => 'print';

			$this->assertSame( '', \WP_Media_Helper\Blocks\GalleryBlock::render( [], 7, static fn (): string => 'never' ), 'No image: no gallery, no wrapper.' );
		}

		public function test_the_gallery_block_asks_the_mode_of_the_page_through_the_filter_of_otherguise(): void {
			$this->useMethod( $this->methodWith( [ 8 ], [] ), false );
			$seen = null;
			// Seen by the filter that can still change it, after the one of Otherguise has answered.
			$GLOBALS['wpmh_test_filters']['wp_media_helper_gallery_context'][] = static function ( string $context ) use ( &$seen ): string {
				$seen = $context;

				return $context;
			};
			$render = static fn () => \WP_Media_Helper\Blocks\GalleryBlock::render( [], 7, static fn (): string => '<i/>' );

			$GLOBALS['wpmh_test_filters']['modes_active_mode'] = [ static fn ( $default ): string => 'print' ];
			$render();
			$this->assertSame( 'print', $seen, 'The slug of the mode, from the filter of Otherguise.' );

			$GLOBALS['wpmh_test_filters']['modes_active_mode'] = [ static fn (): object => new \stdClass() ];
			$render();
			$this->assertSame( '', $seen, 'Anything that is not a string gives no context.' );

			$GLOBALS['wpmh_test_filters']['modes_active_mode'] = [];
			$render();
			$this->assertSame( '', $seen, 'Without Otherguise: no context.' );
		}
	}
}
