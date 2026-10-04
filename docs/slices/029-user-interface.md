<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 029: User interface

Status: **proposed** (design only, not implemented). The features it presents come
from slices [020](020-pagination-and-entry-limit.md), [023](023-thumbnails-in-cache.md),
[024](024-source-priority-and-ownership.md), [025](025-database-file-index.md) and
[028](028-default-uploads-source.md).

## Goal

The functions of the plugin were added one slice at a time, with the plainest markup
that worked. This slice improves how they look and how they are used, without changing
what they do. The detailed wishes are to be collected from use; the list below is a
starting point.

## Editor panel

- **Previews.** The 48 px preview next to the name was the minimum. Options: a grid
  view with larger previews, switchable with the list view (remembered per user, like
  the panel mode); a larger preview on hover or focus; a placeholder icon by type
  (video, GPX, subtitles, other) instead of nothing.
- **Layout in the sidebar.** The sidebar is narrow: compact filters (collapsed groups,
  the filter in use summarised), sticky pagination and bulk-action bar, rows that do
  not wrap badly with long names.
- **Status.** Replace the lines of text ("In WP media library", "Attached to current
  post") with badges or icons with text alternatives; keep the "indexing in progress"
  and refresh feedback visible but quiet.
- **Selection.** Select a range with shift-click, keep the selection across pages
  (within the limit of a bulk action), show how many items are selected.
- **Date.** A date picker with previous/next day buttons and a mark for days that
  have files (from the index), since the date is the main entry point.
- **Keyboard and screen readers.** Roles, labels and focus handling checked, in
  particular for the table, the bulk bar and the preview images.

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
