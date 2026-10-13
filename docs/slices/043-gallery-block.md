<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 043: The gallery block

Status: **implemented** (tried only with stubs of WordPress; never on a real site). Replaces the plugin
`wp-printable-gallery` (block `wpprg/wp-printable-gallery`, repository `vdv/wp-printable-gallery` on Gitea) for Eric's blog,
as asked for the rollout 003 of the Otherguise repository. Depends on [042](042-attachment-methods.md).

## What it is

The block **`wp-media-helper/gallery`** ("Attached images gallery") does not draw a gallery. It calls the standard WordPress
gallery (`gallery_shortcode()`) with the images of the post:

- the images come from `wp_media_helper_get_attached_media( $post, [ 'mime_type' => 'image', 'context' => <context> ] )`, so from the
  attachment method in use for the post (with the native method: `get_attached_media( 'image' )`);
- the **context** is the slug of the mode of the page when the plugin Otherguise is active, nothing otherwise. It is asked with the
  filter `modes_active_mode` that Otherguise answers (`apply_filters( 'modes_active_mode', '' )`; the PHP function of the same name
  returns an object, not a slug, and is not used); the filter `wp_media_helper_gallery_context( $context, $postId )` can set it;
- the post is the one of the block context (`postId`), else the one of the loop (`get_the_ID()`);
- the order is the one of the standard gallery given `ids`: `menu_order`, then ID. **Unchanged on purpose**; ordering by date taken is a
  later, visible change (it is part of the sorting of slice 038, which also has to say what a gallery order means for a post);
- the output is the one of the old block: `gallery_shortcode()` with `columns` 2, `size` `gallery`, `link` `file`, wrapped in
  `<div class="wp-block-wp-media-helper-gallery …">` made by `get_block_wrapper_attributes()`. The three values are attributes of
  the block (`columns` 1 to 9, `size`, `link` = `file`, `post` or `none`); the editor has no controls for them yet (set them in the
  markup of the template).
- **One difference from the old block**: with no image, nothing is drawn (the old block, in `?print` with no flagged image, passed
  an empty list and WordPress then showed every attached image).

## Wrapper class and CSS

The theme must replace `.wp-block-wpprg-wp-printable-gallery` by **`.wp-block-wp-media-helper-gallery`** (for example the rule
`margin-top: 0 !important` of `modes/print.css`), and the block name `wpprg/wp-printable-gallery` by `wp-media-helper/gallery` in
the templates.

## Before and after the migration to the modes

`wp_media_helper_get_attached_media()` now takes the cohabitation into account (slice 042, step 7): when both mechanisms are in
use, the media items attached **only the WordPress way** (unknown to the method) are added, **when no context is asked or when the
filter `wp_media_helper_native_links_in_context( $include, $context, $postId )` says so** (default: only with no context, since a
WordPress link says nothing about a mode). An Otherguise adapter that wants such links to count for the default mode (the web)
hooks that filter. A media item that the method knows is read only through the method (its modes), whatever its `post_parent`.

- With the **native method** the block works before any migration: it shows the images attached to the post.
- With the Otherguise method and the cohabitation, the web shows the native links too once the adapter hooks the filter; the
  print mode shows only the links that have the mode `print`, so **printing needs the migration** (or the links to be given the mode
  in the panel).

## How to check it on a site

- The block is registered: `wp eval 'var_dump( WP_Block_Type_Registry::get_instance()->is_registered( "wp-media-helper/gallery" ) );'`
  prints `bool(true)`; it is in the list of blocks of the editor under "Attached images gallery" (category Media).
- On a post with attached images, a template containing `<!-- wp:wp-media-helper/gallery /-->` shows the same images, in the
  same order, as the old block did without `?print`.
- `wp eval 'print_r( wp_media_helper_get_attached_media( POST_ID, [ "mime_type" => "image" ] ) );'` lists the IDs that the gallery gets.
