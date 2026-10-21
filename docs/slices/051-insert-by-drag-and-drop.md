<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 051: Inserting a file in the content by drag and drop

Status: **first iteration implemented: photos** (see *First iteration* at the end; never tried in a real editor yet). The rest is still a proposal. Related: [022](022-wordpress-native-registration.md) (registering a file as an attachment),
[029](029-user-interface.md) (the panel), [042](042-attachment-methods.md) (what "attached" means), [043](043-gallery-block.md) (the gallery block),
[039](039-trash.md) (the trash), [014](014-simple-and-advanced-media-panel-modes.md) (modes of the panel).

## Goal

While editing a post, drag a file (or several) from the media panel of the sidebar and drop it **where it should appear in the content**: what is inserted depends on the type of the file, by a
table of **handlers** that the administrator edits (a block or a shortcode per type: an image in a gallery, a video in a video block, a GPX track with the shortcode or the block of the track plugin, subtitles added
to a video); a type with no handler is refused. The types that matter first are **images, videos, subtitles and GPX tracks**. Today a file can be attached to the post, or set as the featured image, but
putting it in the text needs the WordPress media library (*Add media* in a block), which does not show the files of the external sources by day, nor their previews.

## What exists

- The panel lists the files of the sources for a day, with previews, filters and actions (attach, detach, featured image, trash, ...). A file that is not in the library is registered
  (an attachment, no file copied) when it is attached or set as the featured image.
- Tiles are buttons with a menu, a selection mode (long press, *Select*) and bulk actions. Nothing is draggable yet.
- Blocks that show media take an attachment ID and a URL: `core/image` (`id`, `url`, `alt`, `sizeSlug`), `core/video` (`id`, `src`), `core/audio`, `core/file` (`id`, `href`, `fileName`),
  `core/gallery` (inner image blocks). The plugin already knows the public URL of a registered file and serves the sizes from its cache (023).
- The gallery block of this plugin (043) shows the attached images by itself: it does not need images dropped one by one.

## Behavior

1. **Dragging**: a tile of the panel can be dragged with the mouse. The drag image is the preview. In selection mode, dragging a selected tile drags **all the selected files**. A file that cannot be
   inserted (in the trash, a type WordPress does not accept, a file that cannot be registered) is not draggable and says why (the sheet already does for imports).
2. **Dropping** on the content: the usual insertion line of the editor shows where the block(s) will go; the drop inserts them there, at the root or inside a container (columns, group...), as for any block.
3. **What is inserted** is decided by the **handler** of the type of the file (see *Handlers*), not by a fixed rule: a block (with its attributes), or a shortcode. The block is a real block, so the author
   uses **its own interface** (toolbar, inspector) to set its parameters afterwards. A file whose type has **no handler is refused**: its tile is not draggable and the sheet says "No block is defined for this type".
   Several files dropped together give what each handler gives, in the order of the panel.
4. **Registering**: a file that has no attachment yet is registered at the drop (the same code as *Import*), so the block has a real attachment ID, a public URL and the sizes.
   While it is being registered the block shows a loading state (a placeholder image block with the spinner), and is replaced, or removed with a notice if registering failed (the reason of the server is shown).
5. **Inserting never attaches** (decided): a block in the text and the link of the file with the post (native parent or a method link, 042) are two things, and a theme that shows the attached files by itself
   (the gallery block of 043, a template) would show a file that is also inserted twice. The drop and the action *Insert in the content* do not call `attach()`; the author attaches with the panel as before, if wanted.
   The attachment record that registering creates (when the file had none) is unattached, like after *Import*.
6. **Alternative without dragging** (required, a drag is not accessible and does not exist on touch screens): an action *Insert in the content* in the menu of the tile, the detail sheet and the bulk actions. It inserts after
   the selected block (or at the end when none is selected), with the same blocks as the drop.
7. **Dropping on a block**: a file can be **added to a block that is already in the content** when the handler has a rule for it: an image dropped on a gallery joins it, subtitles dropped on a video become one of its
   text tracks. The block under the pointer is highlighted when it accepts the file.
8. **Image sizes and alternative text**: the shipped image handler uses the `large` size (the cache serves it) with `sizeSlug: large`, linked to nothing; the full file is the `url` of the attachment. The alternative text is
   the one of the attachment (empty when none); the caption stays empty. All of this is in the handler, so the author changes it with the block.
8. **What the panel shows afterwards** is unchanged: inserting does not change the state of the tile, except that a file registered by the drop is now "in the library".

## Handlers: which block or shortcode for which type

The choice is **data**, edited by an administrator (a section of the settings page, next to the file types of [045](045-file-types-editor.md)) and overridable in code with the filter `wp_media_helper_insert_handlers`.
A handler is found by the **extension** first (`gpx`, `vtt`), then by the **category** of the file (image, video, audio, ...). It is one of:

- **A block**: a block name, attributes, and optionally inner blocks, written as a template with placeholders (`{id}` the attachment ID, `{url}` the public URL, `{size_url}` the URL of the size to show, `{width}`, `{height}`,
  `{alt}`, `{title}`, `{name}`, `{mime}`, `{lang}`). Example: `core/video` with `{ "id": "{id}", "src": "{url}" }`.
- **A shortcode**: a tag and attributes with the same placeholders, inserted as a `core/shortcode` block (or a paragraph, to be decided). Example for GPX tracks, to check against the version of the plugin of the site:
  `[sgpx gpx="{url}"]`. A shortcode has no interface of its own: a block is better when the plugin offers one.
- **An addition to a block that is already there** ("drop on a block"): the file is not inserted but added to the block under the pointer. A rule says the **target block**, the **attribute** or the **inner blocks**
  that receive it, and the template of what is added.

Handlers shipped (all editable, none imposed):

| Type | Handler | Why |
|------|---------|-----|
| image | `core/gallery` holding one `core/image` (`{id}`, `{size_url}`, `sizeSlug: large`) | a single image is a gallery of one, and other images can then be **dropped into it** (an image dropped on a gallery, or between its images, joins it) |
| video | `core/video` (`id`, `src`) | |
| audio | `core/audio` | |
| `vtt` (subtitles) | **added to a `core/video`**: `tracks` gets `{ src, kind: "subtitles", srcLang, label }` | `core/video` has a `tracks` attribute: the same drop on a video adds the subtitles to it |
| `gpx` | shortcode `[sgpx gpx="{url}"]` or the block of the track plugin | the site decides |
| anything else | **none**: refused | the types that matter are listed, the rest is not inserted by accident |

Notes on what is possible (a point of view to check in the editor, see the spike):

- **Dropping on a block**: the plugin finds the block under the pointer (`data-block`, the client ID) and asks whether a rule of the handler accepts the file for that block name. If yes, the insertion line is replaced by
  a highlight of the block, and the drop updates the block (`updateBlockAttributes`) or adds inner blocks (`insertBlocks` with the block as root). If not, the file is inserted next to it as usual, or refused when its
  handler **requires a target** (subtitles without a video: "Drop it on a video").
- **A gallery accepts images only** (its inner blocks): another type dropped on it is inserted after it, not in it.
- **Subtitles**: browsers read WebVTT in a `<track>`; `srt` is not accepted by the rule (the message says to convert it). The language is read from the file name (`trip.fr.vtt`, `trip-en.vtt`), else the language of the site, and the label is
  the name of the language.
- **Parameters** of a block are changed by the author with the interface of the block: the plugin sets sensible attributes, it does not offer a second set of options. For a shortcode, the attributes of the template are all there is.
- A handler that names a block that is not registered on the site (a plugin deactivated) is ignored with a notice in the settings; the type is then refused.

## How it works (two approaches, to be tried first)

**A. Let the editor do the insertion.** The drag carries `text/html` (for an image: `<figure class="wp-block-image"><img src="..." alt="" class="wp-image-<id>"></figure>`) and the editor's own drop zone
turns it into blocks, with its insertion line. It costs almost no code and the indicator is free, but only images are reliably parsed with their ID (a pasted video or link does not become a video or a file block with an ID),
the attachment must already exist when the drag starts (so a file not in the library would have to be registered at `dragstart`), and it depends on the version of the editor.

**B. A drop handler of the plugin.** The drag carries a private type (`application/x-wp-media-helper`: the paths and the sources, never URLs) and the plugin listens to `dragover`/`drop` on the editor canvas
(an iframe in current WordPress: its document must be found and followed when it is rebuilt), finds the insertion point, shows its own line, asks the server for what to insert, and calls `insertBlocks()` on the
block editor store at the right root and index. Full control of the block types, of registering at the drop, and of the loading state; more code, and the insertion line is ours.

Recommended: **a first step that tries A for images in the version of WordPress in use** (a small spike, thrown away if it is not enough), then **B** as the real implementation, falling back on B for everything
if A is not reliable. The drop handler of the editor must not run twice: when B handles a drag it stops the event.

## Server contract

One endpoint, in the family of the existing ones (`wp_media_helper_insert_data`, same nonce, `edit_post` on the post and `upload_files` for what has to be registered):

- Input: the post ID and a list of `{ source_id, path }` as the panel knows them. **The client never sends a URL or an attachment ID that the server believes**: the server confines the path to the active sources
  (as for import), registers what is missing (the code of *Import*) and reads everything else itself.
- Input also carries, for a drop on a block, the **name** of the target block (and its client ID, only echoed back): the server picks the handler and the rule that accepts the file for that block, or says the file is refused.
- Output, per file in the order sent: `{ attachment_id, mode, mime, name, ... }` with `mode` = `block` (`block`, `attributes`, `innerBlocks`), `shortcode` (`tag`, `attributes`, the text to insert), `append` (the attribute or inner
  blocks of the target and the value to add), or `{ error }` (type without handler, subtitles not in WebVTT, no target, a file WordPress does not accept...). The server expands the templates (URLs, sizes, dimensions, alt, the language
  of a subtitle), so the browser has no rule of its own about types and URLs; a site can still change the result with the filter `wp_media_helper_insert_block( array $result, int $attachmentId, int $postId )`.

## Settings

*WP Media Helper > Insert in the content* (administrators): the table of handlers (type or extension, kind, block or shortcode, template), the rules of drops on blocks, a list of the blocks and shortcodes that exist on the site to choose from,
a check that the template is valid JSON and the block registered, a **reset to the shipped handlers**, and import/export of the table (as for the file types). A type with no row is refused.

## Rules

- Same permission checks as attaching; a trashed file, a file outside the active sources or a type the site does not accept is refused with the usual messages.
- A drop never changes a file or the sources. Dropping twice inserts twice (two blocks, one attachment).
- The attachments registered by a drop are exactly the ones of *Import*: they can be removed from the library (014, 010) and are cleaned like the others (050).
- Nothing in the content is rewritten when a file moves later (049): a block keeps the URL it had; `paths remap` reports the contents that carry an old address.

## Tests

- Unit: the server mapping from a file to the block and its attributes through the handlers (image with and without sizes, a vertical photo, video, subtitles with a language in the name, GPX, a type with no handler, a file WordPress does not
  accept), the expansion of the templates and the refusal of an invalid one, the rules for drops on blocks (accepted, not accepted, required target), the refusals, the order, and that a URL or an ID sent by the browser is ignored.
- A test that a drop and the action *Insert in the content* never send an `attach` (server: the endpoint does not touch the links; browser: the request is the insertion one).
- Browser (the Chromium harness with a mocked server): synthetic `dragstart`/`dragover`/`drop` events with a real `DataTransfer`: the private type is set, a selection drags all its files, a file that cannot be
  inserted is not draggable, the alternative action inserts at the right place, and an error shows its reason. Nothing here proves the behavior of the editor: it needs a run in a real editor.
- Manual, in the editor of the target version of WordPress: drop at the root, in a column, between two blocks, at the end; undo (one step per drop); a drop on the iframe after a re-render; touch device (the action
  instead of the drag).

## Non-goals

- Dropping files from the computer into the panel to upload them.
- Dragging from the panel to the featured image slot (the featured image has its own action).
- Rewriting existing blocks when a file moves, or choosing the size or the link of the image at the drop (the block toolbar does it).
- Reordering the attached files by dragging in the panel (a different feature: the order of a gallery belongs to the gallery block, 043).

## Acceptance criteria

1. Dragging an image tile onto the content inserts a gallery holding one image block with the attachment ID, the public URL and the `large` size, at the position of the insertion line; the author changes its parameters with the interface of the block.
2. An image dropped on that gallery, or between its images, joins it; another type dropped on a gallery is inserted after it.
3. A file not yet in the library is registered by the drop, and the block shows a loading state until it is.
4. A video gives a video block; subtitles (WebVTT) dropped on a video are added to its text tracks with their language; subtitles dropped elsewhere are refused with "Drop it on a video"; `srt` is refused with the reason.
5. A GPX track gives what its handler says (shortcode or block), and changing the handler in the settings changes what the next drop gives.
6. A type with no handler (or a handler whose block is not registered) is not draggable and says why; nothing of it can be inserted by the alternative action either.
7. Dragging a selection drops all its files in the order of the panel, each by its handler.
8. The action *Insert in the content* does the same without a drag, from the menu, the sheet and the bulk actions.
9. A refused file inserts nothing and says why; nothing is left half done (no block without attachment).
10. Inserting never attaches: a file that was not attached to the post is not attached after the drop (and one that was stays attached), through any attachment method; a file registered by the drop is an unattached attachment.
11. One undo step removes what a drop inserted.

## Suggested steps

1. Spike, in the editor of the site: approach A for images (does the ID survive? where does the line appear?), and **the drop on a block** (a gallery, a video: can the block under the pointer be found and updated without private APIs?). Decide A or B.
2. Server: the handlers (shipped table, filter), the expansion of the templates, the rules for drops on blocks, the endpoint, with tests; registering at the drop.
3. Panel: draggable tiles with the private type, the selection drag, the non-draggable states, and the action *Insert in the content*.
4. Editor: the drop handler (B) or the HTML payload (A), the loading state, the errors.
5. The settings page of the handlers (table, check of the blocks, reset, import/export).
6. Documentation, the harness tests (among them: a drop calls no attach).

## Open questions

- **Several images dropped together**: with the gallery handler, one gallery holding all of them (natural); with the `core/image` handler, one block each.
- **A shortcode**: inserted as a `core/shortcode` block, or as text in a paragraph? And which shortcode or block the GPX track plugin of the site expects (to read in its documentation, not assumed here).
- **Subtitles on a video that is not a core block** (a video of another plugin): only `core/video` at first.
- **The size of the image** in the gallery: the size named `large` of the site, or the largest that fits the content width of the theme.
- **Version of the editor**: the lowest version to support; the editor canvas is an iframe from WordPress 6.3, galleries use inner image blocks from 5.9 and the drop zone internals changed since: this decides how much of the drop on a
  block is possible without private APIs, and the spike must answer it.

## First iteration: photos (implemented, to be tried in the editor)

> **Status: the drag-and-drop is parked.** Trying it on a phone showed that it fights with the long press that enters selection mode, and the insertion by the action *Insert in the content* works well. `DRAG_ENABLED` is `false` in `editor-media-panel.js`: tiles
> are not draggable, the code of the drag and of the drop handler stays. The long press (one second, finger and mouse alike) enters selection mode. Drag-and-drop may come back later, for a mouse only.

- **Server**: `Admin\InsertController` (action `wp_media_helper_insert_data`, same nonce as the panel, `edit_post`s and `upload_files`) takes paths and sources, confines them to the active sources, refuses a file in the trash, registers what has no
  attachment (the code of *Import*), reads the addresses (`image_downsize( $id, 'large' )`, which makes the size in the cache when needed) and the alternative text, and answers `{ blocks, results }`. It **never attaches**. `Admin\InsertBlocks`
  builds the description of the blocks: a `core/gallery` (`linkTo: media`) holding `core/image` blocks (`id`, `url` of the size shown, `alt`, `sizeSlug: large`, `linkDestination: media`, `href` the file). All photos of one request go into **one gallery**. A file that
  is not a photo is refused with "No block is defined for ...". The filter `wp_media_helper_insert_block( $block, $images, $postId )` can change the block.
- **Panel**: the action *Insert in the content* (menu, sheet, bulk actions; photos that can be imported or are in the library, for those who may upload) inserts after the selected block, else at the end of the content. Tiles of photos are
  **draggable** (the tile is now a `div role="button"` and not a `<button>`, because Firefox does not start a drag from a button; Enter and Space still activate it). Dragging a selected tile drags all the selected photos. The drag carries only paths and sources
  (`application/x-wp-media-helper`).
- **Editor**: approach **B**, a handler of the plugin on the editor canvas (the iframe is looked at again every 1.5 s, and the main document for a page without iframe): a blue line shows where the drop goes (before or after the block under the pointer, at the side of an image inside a gallery,
  a dashed frame when over the gallery itself), only over the content (not over the sidebar or the toolbar). Photos dropped into a gallery become image blocks of that gallery (no gallery in a gallery); elsewhere one gallery is inserted. The insertion
  is `insertBlocks()` on the block editor store, so it is one undo step.
- **Tested**: the server mapping (`InsertBlocksTest`) and, in a browser with a mocked editor, the action, the drag payload, the line, the drop before/after a block, the drop into a gallery and the refusal of a drop outside the content. **Not tested**: a real editor
  (iframe canvas, the drop zone of the editor, undo, the serialization of the blocks), which is what the first trial on a site must tell; and Firefox, where the drag was changed to avoid its known problem with buttons.
- **Not done yet**: the other types (videos, subtitles, GPX), the table of handlers and its settings page, a loading placeholder while a file is registered, the dashed frame for a video.

## Second iteration: videos and subtitles (implemented, to be tried in the editor)

- **Videos**: *Insert in the content* on a video inserts a `core/video` block (`id`, `src`, `controls`), one block per video, after the selected block (never inside a gallery: with a video among the blocks everything goes after the gallery). The file is registered in the Media Library
  when needed and never attached.
- **Subtitles** go in the `tracks` of the video block (`kind: subtitles`, `srclang`, `label`). Only **WebVTT** (`.vtt`) can be used: the browser reads nothing else, and `.srt`, `.ass`... are refused with that reason. The type must be allowed by WordPress (*Additional file types* in the settings).
- **Pairing** (`Admin\SubtitleMatcher`, tested): one video gets all the subtitles; otherwise a subtitle goes to the video whose name (without extension) is the subtitle's name without extension and language suffix (`film.fr.vtt`, `film_pt-BR.vtt`, `film.en.forced.vtt` go to `film.mp4`), or the longest video name that starts the
  subtitle's name followed by a separator; a tie goes to the video of the same directory, else to none. The language (`srclang`) and the name shown (`label`, the name of the language, with `forced`, `sdh`... when there is one) come from the same suffix.
- **Validation screen**: when videos and subtitles are inserted together, the panel first asks the server for its proposal (`wp_media_helper_insert_plan`, nothing is registered yet) and shows it in a dialog: for each subtitle, a box to tick it off (a file left out is not sent, not even registered), its file name with the language code and the name shown just under it, then the list of the videos (or none), all editable; a subtitle with no
  guess is flagged. *Insert* sends the validated pairs (`tracks` in `wp_media_helper_insert_data`), *Cancel* does nothing.
- **Subtitles without a video** in the request go to the video block selected in the editor; without one, the panel says to select the video too. A subtitle the dialog did not mention follows the names.
- **Not done yet**: GPX, the table of handlers and its settings page, a poster, the other attributes of the video block (autoplay, loop), a subtitle file shared by several videos, `.srt` to `.vtt` conversion, a loading placeholder.

## Third iteration: GPX tracks, and templates by extension (implemented)

What the theme of the site does decides the first half: the templates carry a block with no attribute (`create-wp-attached-gpx/wp-attached-gpx`) that shows the GPX files **attached** to the post (`get_attached_media( 'application/gpx+xml' )`, or `wp_media_helper_get_attached_media()` with Otherguise), in the
place of the template (the column of the map, in print as on the web). Attaching a GPX file with the action *Attach* is therefore what shows it there. The second half is the same as for photos: *Insert in the content* puts **other** tracks in the text, and never attaches.

- **A template by extension**, written in the settings (*Insertion in the content*, one line per extension: the extension, a space, the block as a JSON object `{ "block", "attributes", "innerBlocks" }`) and changeable with the filter `wp_media_helper_insert_handlers`
  (`Settings\InsertHandlers`, tested). In the strings: `{id}` (a number when it is the whole string), `{url}`, `{name}`, `{title}`, `{mime}`. For a GPX track the site chooses: a `core/shortcode` block (`[sgpx gpx="{url}"]`), or the block of the track plugin with its attribute. The lines are validated when the settings are saved.
- Only the extensions that have a template are insertable (besides photos, videos and WebVTT subtitles, which have their own handling): the tile offers *Insert in the content*, the file is registered in the Media Library if needed, never attached, and the block is placed like the others (after the selected block, never inside a gallery).
- **Without a template**, a GPX file is not insertable, and the server (or the panel, when only such files were selected) says: attach it, or give a template for `gpx` in the settings. Any other extension is refused as before.
- **The block of the site** (`wp-attached-gpx`, source `src/wp-attached-gpx/render.php` of the gitea of the site) lists `get_attached_media( 'application/gpx+xml' )` and, for each file, calls `wpgpxmaps_handle_shortcodes()` of **WP GPX Maps** with `gpx` (the address), and `attachments`, `mheight`, `gheight`, `download` from its attributes
  (`showAttachments`, `mapHeight`, `graphHeight`, `showDownload`), after the filter `wpagpx_shortcode_parameters`, inside `div.attached-gpxes` / `div.attached-gpx`. It has no attribute to name a file. So the default template, when WP GPX Maps is active and nothing was saved, is the shortcode `[sgpx gpx="{url}"]` (the same plugin, without the wrappers of the block and its
  filter); a block that accepts an address (a new attribute) would keep the same wrappers and the same parameters, and be written `gpx {"block": "create-wp-attached-gpx/wp-attached-gpx", "attributes": {"<attribute>": "{url}"}}`.
- The type must be allowed by WordPress (*Additional file types*: `gpx application/gpx+xml`) for the file to be registered.
- Still to do: a friendlier editor for the templates than one line of JSON (a table by type with a choice of block), and dropping a file on a block that already exists.

## Ideas for later

- **The gallery block's own picker** (looked at; not implemented). The capture of the *Add to gallery* modal is the WordPress media frame (Backbone, `wp.media.view.MediaFrame.Post`, state `gallery-library`), with two tabs, *Upload files* and *Media Library*.
  - What the library already shows: every file **registered** as an attachment (`ajax_query_attachments_args` can filter or order those, nothing more). The files of the panel that are not registered yet cannot appear there: the library is a list of posts. So a server filter
    cannot do it, and creating the attachments of a whole source to fill the list is what the plugin avoids.
  - Proposed way: **a third tab in the frame**, *Panel* (name to decide), added by patching `wp.media.view.MediaFrame.Select.prototype.browseRouter` (the frame of the gallery block extends it) and handling `content:create:<tab>` with a small Backbone view. The view lists the
    files of the panel through the existing endpoint (`wp_media_helper_media_panel_state`, same filters: source, date, type; photos only here) with the same thumbnails (`wp_media_helper_thumbnail`). Choosing a file calls an action of the plugin that registers it (the code
    of *Import*, as `InsertController` does, never attaching) and answers the attachment id; the view then fetches `wp.media.attachment( id )` and adds it to the frame's selection, so the button *Add to gallery* works as for any other image. Several files can be chosen.
  - **No hook for this**: WordPress has no PHP filter and no JS filter to add a tab to the media frame. Extending the frame's prototype is the usual way plugins do it, but it is the fragile one. Official alternatives, which need no patch of the frame:
    1. **A toolbar button on the gallery block** (done) (JS filter `editor.BlockEdit`, wrapping `core/gallery`): *Add from the panel* opens a modal of the plugin (`wp.components.Modal`) with the photos of the panel, and adds the chosen ones as image blocks of that gallery (the code of the insertion already exists).
    2. **The panel itself** (done): when a gallery block is selected, the action *Insert in the content* adds the photos at the end of that gallery instead of putting a new gallery after it. When an image of a gallery is selected, they were already added after that image.
    3. `editor.MediaUpload` (JS filter) replaces the whole component that blocks use to open the frame: official, but it replaces WordPress's own picker for every block, which is too much.
    Both are done (the button, and the insertion into the selected gallery). The button opens the same panel component in a full-screen modal (`MediaPanel` with a `picker` property: always in selection mode, only photos can be chosen, a button *Add N photos to the gallery* adds them at the end of the gallery, never attaching, then closes the modal). A third tab in the media frame stays a possible spike only if this is not enough.
  - A hook in the editor (`editor.MediaUpload`) is not needed: the frame is created by WordPress itself and the patch applies to every block that opens it (gallery, image, cover, featured image), where the tab could be limited to images.
  - Cost and risk: a Backbone view and CSS to write (the grid cannot reuse the React tiles), a patch on a core prototype that must be checked on each major WordPress version, and the frame's router differs between the *Select* and *Post* frames. A first spike (tab with a plain list of photos of the
    day, one click adds it) would tell how far the reuse goes.
