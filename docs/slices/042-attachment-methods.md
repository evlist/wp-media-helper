<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 042: Attachment methods (contract for other plugins)

Status: **accepted by Eric on 2026-10-10; steps 1 to 4 are coded** (see the end) (the decisions and the four amendments of the Otherguise session are in; the names below are fixed). Written for the session that develops
[`evlist/wp-otherguise`](https://github.com/evlist/wp-otherguise), whose design note
[`docs/design/media-helper.md`](https://github.com/evlist/wp-otherguise/blob/main/docs/design/media-helper.md) raised the integration.
Everything below is **Decided** (Eric accepted it) unless it says it is a fact about the code of `main`.

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
| `attached( int $postId, array $args ): int[]` | **The reading side**: the attachments of a post, in order. `$args`: `mime_type` (a type or a family such as `image`, as `get_attached_media()` takes it) and `context` (a free string; Otherguise puts the slug of a mode). The result is the list of attachment IDs, in the order the method decides (the native method returns what `get_attached_media()` returns). |
| `posts_of( int[] $attachmentIds ): array<int, int[]>` | The posts each attachment is attached to, for a whole page with one query (used for the column *Attached to* of the media library, see 6). |
| `may_remove( int $attachmentId, string $context = 'remove' ): true\|WP_Error` | Guard for *Remove from library* (`context` = `remove`) and for the trash (`context` = `trash`: the user was asked first and the entries go from every post). The native method refuses `remove` when the item is attached to a post, and accepts `trash`. A multi-post method may accept (the links disappear with the item: Triples already follows `deleted_post`). |

Media Helper still does everything else: registering the file as a native attachment, previews, thumbnails, the trash, the
featured image, filters.

### 2 bis. The reading function (for blocks and themes)

```php
$ids = wp_media_helper_get_attached_media( $post_id, [ 'mime_type' => 'image', 'context' => 'print' ] );
```

A public function of Media Helper that delegates to `attached()` of the active method. With the `native` method it is
`get_attached_media()`. The blocks of a site (`wp-attached-gpx`, `wp-printable-gallery`) call it and work with or without
Otherguise (they test `function_exists()`, and fall back to `get_attached_media()`).

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
      'default' => [ 'web', 'print' ] ],   // every mode that exists, written out (see below)
    [ 'key' => 'position', 'label' => 'Position', 'type' => 'integer', 'default' => null ],
],
```

The default of a multi-valued field is **explicit**: for `modes`, every mode that exists (the method computes it when it
describes its fields). In the Triples model a link with no mode statement belongs to *no* mode, so "web and print" is two
statements. The position per mode is supported by that model (a position on a mode statement pins the photo for that mode
only); the first version of the field `position` is a single integer.

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
- `post_parent`: the method keeps a **primary parent** in it (decided), so that the filter *Unattached* of the media library,
  `get_attached_media()` of third parties and the REST API keep working. The method owns the rule: when the primary parent is
  detached it designates another post if one remains, and clears `post_parent` only when none does. Media Helper itself writes
  `post_parent` only for the `native` method.

### 6. The column *Attached to* of the media library

With a method that has `multiple_posts`, Media Helper replaces the **content** of the column *Attached to* (core knows only
`post_parent`) with the list of all the posts, from `posts_of()`, one query per page of the list. With the `native` method the
column is left as it is. This is its own slice (see the plan).

## What stays outside Media Helper

The rendering of galleries per mode (the shortcode reads the method's data), image sizes per mode, the conversion of existing
`post_parent` attachments into statements, the predicate `media/illustrated-by` and its registration in Triples, and the
screens of Otherguise. In Otherguise this is a `media` module depending on Triples and Modes, registering the method.

## Decisions (Eric, 2026-10-10)

1. The adapter is a **module of Otherguise** (`media`, depends on Triples and Modes), not part of Media Helper.
2. `post_parent` is **kept as a primary parent** by the method; Media Helper shows **several** values in the column *Attached to*.
3. The method is chosen **per site**; the filter allows per post type.
4. **Declarative fields first**, no JavaScript slot yet; the first version of `position` is a single integer.

### Names (fixed)

Interface `WP_Media_Helper\Attachment\Method`; built-in method `native`; registration filter
`wp_media_helper_attachment_methods`; active method setting and filter `wp_media_helper_attachment_method`; reading function
`wp_media_helper_get_attached_media( $post_id, $args )`; constant `WP_MEDIA_HELPER_CONTRACT` (integer, `1`); actions
`wp_media_helper_attached`, `wp_media_helper_detached`, `wp_media_helper_attachment_updated`; filters
`wp_media_helper_panel_config` and `wp_media_helper_panel_item`.

## Plan of slices (Media Helper side)

1. The interface, the `native` method with **exactly today's behavior** and its tests, the setting and the registration
   filter; the controller calls the method instead of `post_parent`. No change for the user.
2. The events, the filters `wp_media_helper_panel_config` and `wp_media_helper_panel_item`, and the multi-post behavior of the panel.
3. The declarative fields: display, edition, the `update` action.
4. The reading function `wp_media_helper_get_attached_media()` and the column *Attached to* (`posts_of()`); these two can come
   before 2 and 3, since the blocks of the site need the first one first.

Never tried on a real WordPress site; the sequence of calls was checked only by reading the code of both projects.

## Step 1: done

`WP_Media_Helper\Attachment\Method`, `NativeMethod` and `Methods` (the registry and the active method, with the setting
`wp_media_helper_attachment_method`, shown in the settings only when another plugin added a method), the constant
`WP_MEDIA_HELPER_CONTRACT`, and the controller of the panel calling the method for `attach`, `detach`, `remove`, the protection
against another post, the state of the files and the trash (`removeRows` with `may_remove()`). **One addition to the
contract above**: `may_remove()` takes the optional argument `$context` (`remove` or `trash`), because the native method must
keep refusing the first and accepting the second (decision of the trash, slice 039). Behavior for the user is unchanged.
Not in step 1 (later steps): the reading function, the column *Attached to*, the events, the filters of the panel, the fields.
Tested with stubs of WordPress (`tests/phpunit/AttachmentMethodsTest.php`); never tried on a real site.

## Step 2: done (the reading side and the column)

- **`wp_media_helper_get_attached_media( $post_id, $args )`** (file `plugin/includes/functions.php`, loaded with the plugin): returns
  the **IDs** (integers, no duplicates, in the order of the method) of the media items attached to a post, through the method
  in use for that post. `$args`: `mime_type`, `context`. With the `native` method it is `get_attached_media()` reduced to the
  IDs; callers that need the posts call `get_post()`. A block or theme tests `function_exists()` and otherwise falls back to
  `get_attached_media()`.
- **Column *Uploaded to*** of the media library list (`Admin\MediaLibraryColumn`): only when the active method has
  `multiple_posts`, the core column `parent` is replaced by a column of ours, in the same place, sortable the same way
  (`orderby=parent`), listing all the posts (`posts_of()`, one query for the page, then one cache priming for the titles), each
  linked to its edit screen when the user may edit it; a title the user may not read is not shown; `(Unattached)` when there
  is none. With the native method the core column stays as it is. Not done: the "Attach" link that core offers on an
  unattached row, and the media grid / attachment details dialog (they keep showing `post_parent`).
- Tested with stubs (`tests/phpunit/AttachmentMethodsTest.php`: the function with the native method and with a method that
  returns duplicates, and the HTML of the column); the hooks of the list table were never run on a real site.

## Steps 3 and 4: done (events, filters of the panel, multi-post behavior, declared fields)

**Events** (after the method succeeded): `wp_media_helper_attached( $attachmentId, $postId, $methodId, $data )`,
`wp_media_helper_detached( $attachmentId, $postId, $methodId )` and `wp_media_helper_attachment_updated( $attachmentId, $postId, $methodId, $data )`.

**Filters of the panel**: `wp_media_helper_panel_config( $config, $postId )` (the data given to the script, which now carries
`attachmentMethod` = `{ id, label, multiple_posts, fields }` for the post being edited) and `wp_media_helper_panel_item( $item,
$context )` for each item of the page (`$context` = `post_id`, `method`). The answer of the list also carries
`attachment_method`, so the panel follows the method of the post.

**Declared fields** (`Attachment\Fields`): a method lists `fields` in `capabilities()`; Media Helper keeps the valid ones (key
`[a-z0-9_-]`, type `multiselect`, `select`, `boolean`, `integer`, `text`, options for the selects, a default of the right
kind) and coerces what the browser sends to the declared types before the method sees it (the method still validates and
stores). `attach` gives the data of the browser, or the defaults of the fields when none was sent. `describe()` returns the
data of the link of each attachment (`data`), shown in the detail sheet.

**New action `update`** (AJAX `bulk_media`, one item, with `data`): changes the data of the link of the files attached here
through `Method::update()`; the answer carries `attachment_data`.

**Panel with a multi-post method** (`multiple_posts`): *attached to other posts* no longer blocks (no red lock, no protected
message; amber paperclip when the file is only attached elsewhere), the sheet shows **Also in** with the other posts the user
may edit (up to 8), **Edit link…** (menu and sheet) opens a dialog with one control per field and saves with `update`, and the
detail sheet shows the values of the fields. *Attach* uses the defaults of the fields; they are changed with *Edit link…*.
Choosing the modes in the same step as attaching, and fields in bulk, are not done.

Tested with stubs and in Chromium with a mock of the server (dialog, save, sheet, menus); never tried on a real site or
with a real second method.

## Step 5: a filter on a field of the link, with pictograms (the modes)

Asked by Eric: choosing the mode when attaching, and filtering the panel by mode. One mechanism for both.

- **A field may be a filter of the panel**: `'filter' => true` on a `select` or `multiselect` field, with `'filter_default'` (the
  values checked at first; every option and `_none` when not said). The panel adds a group of checkboxes named after the field,
  like the other filters (*All*, none allowed, the values in the collapsed summary), plus a value **None** (`_none`): the links
  that have no value in that field ("no mode"), to find and correct them.
- **Pictograms**: each option may carry `icon` (the name of a Dashicon, for example `admin-site` for a globe) or `icon_svg`
  (the data `d` of an SVG path drawn in a 24 x 24 box, for example a printer); only these two forms are accepted, no markup. They
  are drawn in the group and in the collapsed summary.
- **What it filters** (changed after the first tests on the testing site): every file, by the values of its link. A file that is not attached
  to the current post has no link, so no value, and **answers `_none`** like a link with no value: selecting only *Web* shows only what
  is shown in the web mode (the unattached files are hidden, even when the filter *Attachment* includes them); selecting *None or not
  attached* shows the files to attach and the links to correct; all values selected filters nothing; nothing selected hides everything.
  (The first version left the unattached files visible whatever the selection, so that new files could be chosen for a mode; it made the
  filter look broken. Choose *None or not attached* with one mode to attach for that mode, or *All*.)
- **What it attaches**: when exactly one real value is selected in such a filter, *Attach* (item or bulk) uses it as the value
  of the field instead of the default (a notification says "Attached for: Print"); with several values, or *All*, the defaults
  of the fields apply.
- **Persistence**: per user and post, key `link_<field key>` of the filter requests (`filters.link` in the list request,
  `save_filter` with `link_modes`), like the other filters (slice 040).
- Server: `Fields::normalize()` (`filter`, `filter_default`, `icon`, `icon_svg`), `EditorMediaController::normalizeLinkFilters()`,
  `MediaPanelState::filterByLinkFields()`. Tested with stubs and in Chromium with a mocked server; not tried on a real site.

## Step 6: converting what is attached the WordPress way (panel side)

When the active method is not `native`, a file attached to the current post only through `post_parent` (the method's `describe()`
says it is not attached here, `native` says it is) is marked in the panel: item field `attached_natively_only`, a badge at the
bottom right of the tile, the state "Attached the WordPress way only (not converted)", and the action **Convert to the attachment
method** (menu, sheet, and a bulk action in the advanced mode). The action `convert` (AJAX `bulk_media`) calls `Method::attach()`
for those attachments with the data of the browser (the mode selected in a field filter, see step 5) or the defaults of the fields,
fires `wp_media_helper_attached` and answers `converted`. `post_parent` is not touched by Media Helper; the method decides what it
does with it. A method whose reading already includes the native links (the union proposed to Otherguise) never shows the badge.
Not done: a count in the settings of the media attached the WordPress way (it needs the method to know all its links, and a
query on the whole site); the migration of everything at once is the job of the method's plugin. Tested with stubs and in
Chromium with a mocked server.

## Step 7: cohabitation of the two mechanisms (decided by Eric on 2026-10-11)

Remark of Eric: with another method active, a post whose files were all attached through the media library showed none of them
as attached; he wants both mechanisms active, to pass from one to the other, to be asked for the mode when attaching through the
method, a bulk action, and a filter on the mechanism and the mode (*WordPress, web, print*).

- **Setting** `wp_media_helper_attachment_cohabitation` (a checkbox under the choice of the method in the settings, shown when
  a method other than the native one exists; filter of the same name with the ID of the post). `Methods::cohabitation( $postId )` is
  true only with a method other than `native`. Reset deletes it.
- **Definition of the two mechanisms**: a file is attached *the WordPress way* when `post_parent` is the post and the method does
  not know the link (`attached_natively_only`); *through the method* when the method says it is attached here (a method that
  keeps `post_parent` as its primary parent is therefore never "native"). **Media Helper does the cohabitation: a method must
  not add the native links to its own reading** (the union proposed earlier to the Otherguise session), or the panel could not tell
  the two apart.
- **State**: in cohabitation a file attached the WordPress way **counts as attached to the post** (filters, badge, state "Attached
  the WordPress way"; the other posts of the native links are added to *Also in*).
- **Actions** (menu, sheet, bulk, in simple and advanced modes): *Attach (WordPress)* (`attach_native`), *Attach with the
  method…* (`attach_method`, a dialog asks for the values of the fields: the modes), *Switch to the method…* (`to_method`, same
  dialog), *Switch to WordPress* (`to_native`: the method detaches, then the attachment is attached natively; refused when it is
  attached natively to another post), *Detach* (detaches through whichever mechanism holds the file), *Edit link…* (method links
  only). The dialog is prefilled with the selection of the field filter when it holds one value, else the defaults of the fields.
  The former bulk action *Convert* is replaced by *Switch to the method…* in cohabitation. Events: `wp_media_helper_attached` and
  `wp_media_helper_detached` with the identifier of the mechanism (`native` or the method).
- **Filter "Attached through"**: the first filterable field gets the extra value **WordPress** (`_native`, with the name of the
  mechanism, then the values of the field, then *None*); files attached the WordPress way answer `_native` only, the files attached
  through the method answer the values of their link; unattached files are not concerned. Stored with the other link filters
  (`link_<field>`).
- Tested with stubs (selection and filtering) and in Chromium with a mocked server (menus, dialog, bulk actions, filter); not on a
  real site, and never with a real method.

## Step 8: pictograms of the modes on the tiles, and the values asked when attaching (after the tests of Eric on testing)

- **Pictograms on the tiles**: a file attached to the post through a method that has fields flagged `filter` (the modes) shows at the bottom
  right of its tile one small round chip per value of its link (the icon of the option, a letter when it has none), with the name of the field
  and the value as accessible name and tooltip (for example "Modes: Web"). Files attached the WordPress way, and files not attached, have none.
- **Attaching asks for the values**: with a method that declares fields, *Attach* (menu, sheet and bulk action) and *Convert* open the same dialog as
  *Switch to the method…* (one control per field, prefilled with the selection of the field filter when it holds one value, else the defaults)
  and send the chosen values with the request; without fields, nothing is asked. The dialog is titled "Attach … with <method>" or "Switch … to <method>".
- Tested in Chromium with a mocked server (chips, dialog of one file and of a bulk selection, request with the values); not on a real site.
