<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 029: User interface

Status: **in progress** (the gallery design below is decided; delivered in steps). The features it presents come
from slices [020](020-pagination-and-entry-limit.md), [023](023-thumbnails-in-cache.md),
[024](024-source-priority-and-ownership.md), [025](025-database-file-index.md) and
[028](028-default-uploads-source.md).

## Goal

The functions of the plugin were added one slice at a time, with the plainest markup
that worked. This slice improves how they look and how they are used, without changing
what they do. The detailed wishes are to be collected from use; the list below is a
starting point.

## Editor panel: a gallery

Decided with the author, after the Samsung Gallery: the table is replaced by a **gallery of
thumbnails** over the full width of the panel.

- **Real geometry.** Thumbnails keep the proportions of the image (a square crop hides what
  identifies a photo) and are laid out in justified rows. The index stores the dimensions of each
  image, as displayed (EXIF orientation applied).
- **Density.** The user chooses how many images per row: **1 (large), 2 or 3 (small)**, remembered
  per user. The server gives two preview sizes (long edge 320 and 640 px, not cropped, not
  enlarged, in the same cache and layout as the other sizes) and the browser takes the one that fits.
- **State on the thumbnail.** The attachment state is a badge: a green paperclip (attached to this
  post), a red paperclip with a lock (attached to another post), a crossed paperclip (not attached).
  A shape and a text alternative go with every colour. Two smaller corner badges show *in the
  Media Library* and *hidden*.
- **Files without a preview** (video, GPX, subtitles, other) get a tile with an icon for the type
  and the name; video posters need ffmpeg and are left for later.
- **Selection.** A long press on a thumbnail enters selection mode, with a checkbox on every
  thumbnail and the bulk actions in a bar; Ctrl/Shift-click and a *Select* button give the same to
  a mouse user. The selection survives scrolling and loading more.
- **Actions and details.** A click on a thumbnail opens a **detail sheet** (large preview, name, date
  and its source, size, dimensions, state, actions). The context menu (right click) offers the same
  actions, and so do a *more* button shown on hover and focus and the menu key of the keyboard,
  because a right click does not exist on a touch screen and is not discoverable.
- **Infinite scroll** replaces the pagination: the next lot (the *maximum entries* setting) is
  loaded when the end is near, and the automatic refresh checks for news without resetting the
  scroll ("New files, refresh").
- **Counts.** The number of files matching the filters is always shown, and the number selected.
- The table is not kept.

### Delivery

1. **Foundations** (done): file count in the panel; dimensions of images in the index (`width`,
   `height`, as displayed with the EXIF orientation applied, read from the header at indexing; the
   index version is 2 and a full pass reads the existing rows); two non-cropped preview sizes
   (long edge 320 and 640 px) in the cache, with their URLs in the listing (`thumbnail_url`,
   `thumbnail_large_url`) and the dimensions on each item (`width`, `height`).
2. **The gallery** (done): rows of 1, 2 or 3 thumbnails keeping their proportions (the height of
   a row makes the widths add up to the panel width, a short last row is not stretched), density
   remembered in the browser, attachment paperclip and corner badges, selection mode (long press,
   Ctrl/Shift+click, *Select* button), infinite scroll, a notice instead of a silent replacement
   when the list changes. Tested in Chromium with a mock of `wp` and a fake server (250 files).
   For now a click opens a menu with the information and the actions (the detail sheet and the
   right-click menu are step 3).
3. **Detail sheet and context menu**, with the keyboard.

## Settings page

- **Sources.** Collapsible cards that show name, state, root and the index status on one
  line; drag and drop reordering in addition to the arrow buttons (slice 024); the
  rarely used fields (path pattern, name pattern, modification time) in an "Advanced"
  part.
- **Messages.** Validation errors next to the field and summarised at the top; the
  warnings (shadowed sources, public exposure, sources outside the allowed base) grouped
  and dismissible.
- **First use.** An empty state that explains the two ways to start: add a source, or
  use the uploads directory (slice 028).
- **Reset.** The Reset section stays at the bottom, visually separated.
- **Index.** A small table (source, files, directories, last scan, scan in progress)
  with a "Scan now" button per source, instead of a sentence.

## Cross-cutting

- Admin colours and spacing follow the WordPress admin styles, including dark and
  high-contrast schemes; no inline `<style>` block (move to a stylesheet).
- Strings stay translatable; no text built from parts that cannot be reordered.
- Responsive: the settings page works on a narrow screen; the panel works at the
  sidebar width WordPress gives it.
- Keep the build simple: the panel stays plain JavaScript on `wp.element`/`wp.components`
  unless a build step is decided separately.

## Non-goals

- New functions: this slice only presents existing ones. New filters, actions and
  settings belong to their own slices.
- A redesign of the Media Library screens of WordPress.

## Acceptance criteria

1. Every function available before the slice is still reachable and behaves the same.
2. The panel is usable at the default sidebar width, with the keyboard, and with a
   screen reader on the table and the bulk actions.
3. The settings page has no inline style block, and its warnings are grouped.
4. The grid view (if kept) loads previews of the visible items only.

## Open questions

- Which of the ideas above matter most, and what is missing: to be collected while
  using the plugin.
- Whether a build step (bundler, `@wordpress/scripts`) is acceptable, which would make
  the panel easier to write with JSX and tested components.
- Grid view as an option or as the default.
