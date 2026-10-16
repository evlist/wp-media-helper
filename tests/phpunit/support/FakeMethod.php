<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use WP_Media_Helper\Attachment\Method;

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

/**
 * A method that refuses some attachments and links others to posts.
 */
class RecordRemoverFakeMethod implements Method {

	/** @var int[] */
	public array $refuses;
	/** @var array<int, int[]> */
	public array $links;
	public string $id;
	/** @var string[] */
	public array $contexts = [];

	public function __construct( string $id, array $refuses = [], array $links = [] ) {
		$this->id      = $id;
		$this->refuses = $refuses;
		$this->links   = $links;
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return strtoupper( $this->id );
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
		$posts = [];
		foreach ( $attachmentIds as $id ) {
			$posts[ $id ] = $this->links[ $id ] ?? [];
		}

		return $posts;
	}

	public function may_remove( int $attachmentId, string $context = 'remove' ) {
		$this->contexts[] = $context;

		return in_array( $attachmentId, $this->refuses, true ) ? new WP_Error( 'protected', 'linked to a mode' ) : true;
	}
}
