<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 051: Inserting a file in the content by drag and drop

Status: **proposal** (to be implemented). Related: [022](022-wordpress-native-registration.md) (registering a file as an attachment),
[029](029-user-interface.md) (the panel), [042](042-attachment-methods.md) (what "attached" means), [043](043-gallery-block.md) (the gallery block),
[039](039-trash.md) (the trash), [014](014-simple-and-advanced-media-panel-modes.md) (modes of the panel).

## Goal

While editing a post, drag a file (or several) from the media panel of the sidebar and drop it **where it should appear in the content**: an image becomes an image block, a
video a video block, a sound an audio block, any other allowed file a file block (a download link). Today a file can be attached to the post, or set as the featured image, but
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
3. **What is inserted**, by the category of the file (the same categories as the panel filter, 032): image -> `core/image`; video -> `core/video`; audio -> `core/audio`;
   anything else the site accepts -> `core/file`. Several images dropped together -> **one `core/gallery`** (or one image block each: see the questions); several files of mixed types -> one block each, in the order of the panel.
4. **Registering**: a file that has no attachment yet is registered at the drop (the same code as *Import*), so the block has a real attachment ID, a public URL and the sizes.
   While it is being registered the block shows a loading state (a placeholder image block with the spinner), and is replaced, or removed with a notice if registering failed (the reason of the server is shown).
5. **Attaching** is **not** implied by inserting: a block in the text and the link of the file with the post (native parent or a method link, 042) are two things. See the questions for a setting
   that attaches too, with the default values of the fields of the method (the modes) or by asking.
6. **Alternative without dragging** (required, a drag is not accessible and does not exist on touch screens): an action *Insert in the content* in the menu of the tile, the detail sheet and the bulk actions. It inserts after
   the selected block (or at the end when none is selected), with the same blocks as the drop.
7. **Image sizes**: an image block uses the `large` size (the cache serves it) with `sizeSlug: large`, linked to nothing; the full file is the `url` of the attachment. The alternative text is the one of the
   attachment (empty when none); the caption stays empty.
8. **What the panel shows afterwards** is unchanged: inserting does not change the state of the tile, except that a file registered by the drop is now "in the library".

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
- Output, per file in the order sent: `{ attachment_id, block: 'core/image' | 'core/video' | 'core/audio' | 'core/file' | 'core/gallery', attributes: {...}, mime, name }` or `{ error }`. The server builds the
  attributes (URL, size URL, dimensions, alt, `fileName`, `href`, ...), so the browser has no rule of its own about types and URLs, and a site can change the choice with the filter
  `wp_media_helper_insert_block( array $block, int $attachmentId, int $postId )`.
- The action *Attach when inserting* (if any) is done by the same call through the active method (`attach()`), with the values of the fields asked in the dialog or the defaults.

## Rules

- Same permission checks as attaching; a trashed file, a file outside the active sources or a type the site does not accept is refused with the usual messages.
- A drop never changes a file or the sources. Dropping twice inserts twice (two blocks, one attachment).
- The attachments registered by a drop are exactly the ones of *Import*: they can be removed from the library (014, 010) and are cleaned like the others (050).
- Nothing in the content is rewritten when a file moves later (049): a block keeps the URL it had; `paths remap` reports the contents that carry an old address.

## Tests

- Unit: the server mapping from a file to the block and its attributes for each category (image with and without sizes, a vertical photo, video, audio, other, a file WordPress does not accept), the
  refusals, the order, and that a URL or an ID sent by the browser is ignored.
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

1. Dragging an image tile onto the content inserts an image block with the attachment ID, the public URL and the `large` size, at the position of the insertion line.
2. A file not yet in the library is registered by the drop, and the block shows a loading state until it is.
3. A video, a sound and any other accepted file give a video, audio and file block.
4. Dragging a selection drops all its files in the order of the panel.
5. The action *Insert in the content* does the same without a drag, from the menu, the sheet and the bulk actions.
6. A refused file inserts nothing and says why; nothing is left half done (no block without attachment).
7. Inserting does not attach, unless the chosen setting says so; and the attachment of a file is unchanged by dropping it.
8. One undo step removes what a drop inserted.

## Suggested steps

1. Spike: approach A for images, in the editor of the site (does the ID survive? where does the line appear?). Decide A or B.
2. Server: the endpoint and the mapping to blocks, with tests; registering at the drop.
3. Panel: draggable tiles with the private type, the selection drag, the non-draggable states, and the action *Insert in the content*.
4. Editor: the drop handler (B) or the HTML payload (A), the loading state, the errors.
5. Attach when inserting (setting or dialog), documentation, the harness tests.

## Open questions

- **Several images dropped together**: one gallery block, or one image block each? (A gallery block is better for 2 or more, but changes what the author sees.)
- **Attach when inserting**: never, always, or asked? With a method that has fields (the modes), the values must come from somewhere: the defaults, or the dialog already used for *Attach*.
- **Which `large`**: the size named `large` of the site, or the largest size that fits the content width of the theme? (The block editor knows the content width.)
- **The block for a type that is not an image, a video or a sound** (a GPX track, subtitles): `core/file`, or a block of another plugin of the site (the track plugin) through the filter.
- **Version of the editor**: the lowest version to support; the editor canvas is an iframe from WordPress 6.3 and the drop zone internals changed since, which decides how much of B is possible without private APIs.
