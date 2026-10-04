<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 033: Thumbnail sizes and settings

Status: **proposed** (design and open questions only, not scheduled). Builds on
[023](023-thumbnails-in-cache.md) and [029](029-user-interface.md).

## Goal

Make the sizes of what the plugin generates a choice, with a settings screen, so a site can trade disk space
and CPU for quality and speed. Today they are fixed in code, or follow the theme, and the only knobs are filters.

## What exists

- **Sizes of imported images:** the sizes WordPress registers (`thumbnail`, `medium`, `medium_large`, `large`
  and those of the theme and plugins), created in the cache when asked for and by a background event.
- **Previews of the panel:** two fixed sizes, long edge 320 and 640 px, not cropped, never enlarged.
- **Limits:** 100 megapixels and a memory estimate, adjustable by filters only
  (`wp_media_helper_thumbnail_max_pixels`, `wp_media_helper_thumbnail_memory_factor`).
- **Cache location:** `<uploads>/thumbnails`, movable by filter only.
- No setting for the format or the quality of what is written (the image editor's default applies).

## Questions to settle

1. **Panel previews.** Let the administrator choose the two long edges (for example 240/480 for small phones
   and slow sites, 400/800 for large screens) within bounds, and the quality. Changing them leaves the old files
   in the cache: a cleanup of sizes no longer in use, or leave them to a purge.
2. **Sizes of imported images.** Choose which registered sizes are generated for the images this plugin
   imports (all, or a list), since each one costs disk and CPU and a theme often registers many that no page
   uses. The sizes a page asks for are still created on demand, so unchecked sizes are not lost, only not made
   in advance.
3. **Format.** WebP (or AVIF) for the previews of the panel and, optionally, for sub-sizes when the editor
   supports it; the original and its URL are unchanged. Interaction with browsers and with Thumbnails Folder
   files already in the cache.
4. **Limits as settings.** Maximum pixels, memory factor, background budget (seconds) and whether the background
   event runs, visible and editable with sensible bounds (the filters stay for code).
5. **Where the cache is.** A setting for the directory (inside uploads), with the checks of slice 021, and the
   state of the cache: number of files and size, a purge button for the cache and for the previews only.
6. **Retina.** The panel already picks the large size on dense screens for two images per row; a more general
   rule from the density and the pixel ratio.
7. **Reporting.** Show what the image editor is (GD or Imagick), the supported formats, and the largest image
   that fits in memory.

## Non-goals

- Changing the sizes WordPress registers (that is the theme's job).
- An image CDN or resizing service.

## Acceptance criteria

1. Changing the preview sizes changes the files made from then on and nothing else.
2. A size left out of the list is not generated in advance and is still made when a page asks for it.
3. A setting outside its bounds is refused with a message.
4. The state of the cache and the purge buttons match what is on disk.
