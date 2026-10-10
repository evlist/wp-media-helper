<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 042: Attachment methods (contract for other plugins)

Status: **proposed** (a contract to discuss and implement; nothing is coded). Written for the session that develops
[`evlist/wp-otherguise`](https://github.com/evlist/wp-otherguise), whose design note
[`docs/design/media-helper.md`](https://github.com/evlist/wp-otherguise/blob/main/docs/design/media-helper.md) raised the integration.
Everything below is **Proposed** unless it says it is a fact about the code of `main`.

## Goal

Let a plugin replace *how a media item is attached to a post*, without Media Helper depending on it. Otherguise wants to
attach a photo to **several posts**, **qualified by mode** (web, print, both) with a **position per mode**, through statements
(`post --media/illustrated-by--> attachment`, qualified by `modes/mode` and `triples/position`) instead of `post_parent`.

Direction of dependency (**recommended**): Media Helper stays usable alone and knows nothing about Otherguise; it exposes the
extension points below; Otherguise registers a method. If the plugin is absent, nothing changes.

## Facts about Media Helper today (`main`)

- Attaching is `wp_update_post( post_parent = $postId )`, detaching `post_parent = 0`; the state of a file is derived from
  the `post_parent` of its attachments (`AttachmentRegistry::statesFor()` then `EditorMediaController::resolveAttachmentStates()`):
  *attached to this post*, *attached to another post* (red lock, protected: `protected_other_post`), *not attached*.
- `post_parent` is written or read in: the `attach` action, `detachRows()`, `removeRows()`, `findOtherPostAttachment()`,
  `resolveAttachmentStates()`, `enrichOtherPostInfo()` and the cascade of the trash (slice 039).
- The panel talks to the server with AJAX (`wp_media_helper_media_panel_state`, `wp_media_helper_bulk_media` with the actions
  `import remove attach detach trash restore`), one nonce, capability `edit_posts` (`upload_files` for import and attach).
  Data for the script: `wpMediaHelperEditorPanel`. There is no PHP or JavaScript extension point for any of this.
- Public hooks that exist: the action `wp_media_helper_attachment_registered` and the filters `wp_media_helper_can_trash_files`,
  `wp_media_helper_can_see_trash`, `wp_media_helper_allowed_base`, `wp_media_helper_excluded_directories`,
  `wp_media_helper_file_categories`, `wp_media_helper_thumbnail_cache_dir`, `wp_media_helper_thumbnail_max_pixels`,
  `wp_media_helper_thumbnail_memory_factor`, `wp_media_helper_background_thumbnails`.

## The contract

### 1. Registering a method (PHP)

```php
add_filter( 'wp_media_helper_attachment_methods', function ( array $methods ): array {
    $methods['otherguise'] = new My_Method();   // implements the interface below
    return $methods;
} );
```

Media Helper provides the interface `WP_Media_Helper\Attachment\Method` and the built-in method `native` (today's behavior,
the default). The adapter implements the interface only when `interface_exists()` says it is there, and checks
`defined( 'WP_MEDIA_HELPER_CONTRACT' )` (an integer, `1` for this contract) before registering.

The active method is a site setting (`wp_media_helper_attachment_method`, default `native`), overridable by the filter of the
same name, which receives the post type or the post ID as a second argument so a site can choose per post type.

### 2. The interface `Method`

| Method | Meaning |
|---|---|
| `id(): string`, `label(): string` | Identifier and name shown in the settings. |
| `capabilities(): array` | `multiple_posts` (bool: a media item may belong to several posts, so "attached to another post" is information, not a block) and `fields` (see 4). |
| `describe( int[] $attachmentIds, int $postId ): array` | For each attachment ID, how it stands for this post: `attached_here` (bool), `elsewhere` (list of post IDs, possibly empty), `protected` (null, or a message that blocks attach, detach and removal) and `data` (a JSON-able array: the values of the fields for the link with this post, for example `modes` and `position`). Called with a whole page of attachments at once, so it must not query one by one. |
| `attach( int $attachmentId, int $postId, array $data ): true\|WP_Error` | Creates the link. `$data` comes from the panel; the method **sanitizes and validates it** (Media Helper only checks that it is an array of scalars and lists). Idempotent. |
| `detach( int $attachmentId, int $postId ): true\|WP_Error` | Removes the link with this post only. |
| `update( int $attachmentId, int $postId, array $data ): true\|WP_Error` | Changes the data of an existing link (modes, position) without detaching. |
| `may_remove( int $attachmentId ): true\|WP_Error` | Guard for *Remove from library* and for the trash. The native method refuses when the item is attached to another post. A multi-post method may accept (the links disappear with the item: Triples already follows `deleted_post`). |

Media Helper still does everything else: registering the file as a native attachment, previews, thumbnails, the trash, the
featured image, filters.

### 3. Events (actions)

`wp_media_helper_attached( int $attachmentId, int $postId, string $methodId, array $data )`,
`wp_media_helper_detached( int $attachmentId, int $postId, string $methodId )`,
`wp_media_helper_attachment_updated( ... )`: fired after the method succeeded, whichever method it is, so that a third
party can react (cache, index) without being a method.

### 4. Extra data in the panel, declared and not coded

So that the adapter needs **no JavaScript**, the method describes its fields and Media Helper draws the controls:

```php
'fields' => [
    [ 'key' => 'modes', 'label' => 'Modes', 'type' => 'multiselect',
      'options' => [ [ 'value' => 'web', 'label' => 'Web' ], [ 'value' => 'print', 'label' => 'Print' ] ],
      'default' => [ 'web', 'print' ] ],
    [ 'key' => 'position', 'label' => 'Position', 'type' => 'integer', 'default' => null ],
],
```

Types in the first version: `multiselect`, `select`, `boolean`, `integer`, `text`. The panel shows the values in the detail
sheet, offers them when attaching (with the defaults) and in an *Edit* action (`update`). The values travel in the same
requests as today (`data` per item, JSON), returned by `describe()` in each item as `attachment_data`. A JavaScript extension
(slots for custom controls) is **not** part of this contract and can come later if the declarative fields are not enough.

Two filters let a plugin adjust what is sent: `wp_media_helper_panel_config` (the localized object, which carries the method's
`id`, `multiple_posts` and `fields`) and `wp_media_helper_panel_item` (one item, with its context).

### 5. What changes in the panel for a multi-post method

- "Attached to another post" no longer blocks anything and loses the red lock; it becomes a neutral badge with the posts
  (the filter *Attachment* keeps its three states).
- *Detach* removes the link with the current post only; *Remove from library* and the trash call `may_remove()`.
- `post_parent`: **open** (see below). Media Helper does not write it when the method is not `native`.

## What stays outside Media Helper

The rendering of galleries per mode (the shortcode reads the method's data), image sizes per mode, the conversion of existing
`post_parent` attachments into statements, the predicate `media/illustrated-by` and its registration in Triples, and the
screens of Otherguise. In Otherguise this is a `media` module depending on Triples and Modes, registering the method.

## Open decisions (Eric)

1. **Where the adapter lives**: a module of Otherguise (recommended, consistent with the design note) or a part of Media Helper
   loaded when Otherguise is active.
2. **`post_parent` for a multi-post link**: leave it empty, or keep a "primary parent" so that the column *Attached to* of the
   WordPress media library stays meaningful. The method can do it itself in `attach()` if it wants, so Media Helper can
   stay out of it.
3. Whether the active method is chosen per site or per post type (the filter above allows both).
4. The list of field types, and whether a JavaScript slot is needed from the start.

## Plan of slices (Media Helper side)

1. The interface, the `native` method with **exactly today's behavior** and its tests, the setting and the registration
   filter; the controller calls the method instead of `post_parent`. No change for the user.
2. The events, the filters `wp_media_helper_panel_config` and `wp_media_helper_panel_item`, and the multi-post behavior of the panel.
3. The declarative fields: display, edition, the `update` action.

Never tried on a real WordPress site; the sequence of calls was checked only by reading the code of both projects.
