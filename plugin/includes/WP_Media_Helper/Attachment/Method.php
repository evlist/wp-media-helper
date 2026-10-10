<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Attachment;

/**
 * How a media item is attached to a post (slice 042, contract version 1).
 *
 * The built-in method is `native` (the `post_parent` of the attachment). Another plugin registers
 * its own with the filter `wp_media_helper_attachment_methods`; Media Helper calls the active
 * method for everything that is about the link between a post and a media item, and does the rest
 * itself (registering files, previews, thumbnails, the trash, the featured image, the filters).
 */
interface Method {

	/**
	 * Identifier: the key under which the method is registered.
	 */
	public function id(): string;

	/**
	 * Name shown in the settings.
	 */
	public function label(): string;

	/**
	 * `multiple_posts` (bool): a media item may belong to several posts, so "attached to another post"
	 * is information and not a block. `fields`: the declared extra data of a link (a later slice).
	 *
	 * @return array{multiple_posts:bool, fields:array<int, array<string, mixed>>}
	 */
	public function capabilities(): array;

	/**
	 * How each attachment stands for a post. Called with a whole page at once: no query per attachment.
	 *
	 * - `attached_here`: linked to `$postId`;
	 * - `elsewhere`: the IDs of the other posts it is linked to;
	 * - `protected`: null, or the message of what blocks attach, detach and removal;
	 * - `data`: the values of the fields of the link with this post.
	 *
	 * @param int[] $attachmentIds
	 * @return array<int, array{attached_here:bool, elsewhere:int[], protected:string|null, data:array<string, mixed>}> Keyed by attachment ID.
	 */
	public function describe( array $attachmentIds, int $postId ): array;

	/**
	 * Links the attachment to the post. Idempotent. The method sanitizes and validates `$data`.
	 *
	 * @param array<string, mixed> $data
	 * @return true|\WP_Error
	 */
	public function attach( int $attachmentId, int $postId, array $data );

	/**
	 * Removes the link with this post only. A `WP_Error` with the code `not_allowed` means that the
	 * user may not change this attachment: Media Helper skips it without reporting a failure.
	 *
	 * @return true|\WP_Error
	 */
	public function detach( int $attachmentId, int $postId );

	/**
	 * Changes the data of an existing link without detaching.
	 *
	 * @param array<string, mixed> $data
	 * @return true|\WP_Error
	 */
	public function update( int $attachmentId, int $postId, array $data );

	/**
	 * The attachments of a post, in order. `$args`: `mime_type` (a type or a family such as `image`,
	 * as `get_attached_media()` takes it) and `context` (a free string, for example the slug of a mode).
	 *
	 * @param array<string, mixed> $args
	 * @return int[]
	 */
	public function attached( int $postId, array $args ): array;

	/**
	 * The posts each attachment is attached to, for a whole page with one query. Every ID asked for is a key.
	 *
	 * @param int[] $attachmentIds
	 * @return array<int, int[]>
	 */
	public function posts_of( array $attachmentIds ): array;

	/**
	 * Guard for removing an attachment from the media library. `$context` is `remove` (the action
	 * *Remove from library*) or `trash` (the file is moved to the trash, which removes the entries of
	 * the file from every post: the user was asked first).
	 *
	 * @return true|\WP_Error
	 */
	public function may_remove( int $attachmentId, string $context = 'remove' );
}
