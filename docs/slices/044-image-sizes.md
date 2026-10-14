<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 044: Image sizes

Status: **proposal**. Related: [023](023-thumbnails-in-cache.md) (thumbnails in the shared cache), [032](032-file-types.md)
(additional types, same "replace a small plugin" approach). Aim: make *Simple Image Sizes* unnecessary.

## Goal

Let an administrator define and edit the image sizes of the site from the plugin, and regenerate the thumbnails of existing
images in the shared cache, so that changing a size is a complete operation.

## What exists

- The plugin reads the sizes WordPress knows (`wp_get_registered_image_subsizes()`: core, theme, other plugins), makes the
  files in `uploads/thumbnails/<dir of the original>/` and serves them (023).
- It never **defines** a size, and it has no way to remake thumbnails in bulk. After a size changes, a new file is made on
  demand at the first use, and the entries of the old size stay in the metadata and the cache.

## Proposal

1. **Settings page "Image sizes"** (administrators only), listing every registered size with its origin (WordPress, theme,
   plugin, this plugin):
   - *WordPress* sizes (`thumbnail`, `medium`, `medium_large`, `large`, plus `1536x1536`, `2048x2048`): width, height and crop
     are the options WordPress already stores; the page edits those options.
   - *Custom* sizes defined here: name, width, height, crop (off, centered, or one of the nine positions). Registered with
     `add_image_size()` early on `after_setup_theme` (priority 99, after the theme), so they are available to every plugin.
     A custom size may **override** a size of the theme by name (the same name registered last wins), shown as such.
   - Sizes of a theme or plugin that cannot be edited here are shown read-only, with a switch to **not make** a size
     (`intermediate_image_sizes_advanced`), useful to stop the 1536 and 2048 sizes.
   - Names are slugs (`[a-z0-9_-]`), unique, and can be used in `[gallery size="…"]`, `wp_get_attachment_image()` and the
     gallery block (043).
2. **Regenerate** a size, several, or all: a background job over the registered attachments (a WP-Cron queue like 023, a
   batch per run, a time budget, a progress counter on the page, cancellable). For each image it makes the missing or changed
   files in the cache, updates `sizes` in the metadata and, optionally, **deletes** the files of sizes that no longer exist.
3. **Detect a stale size.** A size whose width, height or crop changed since the file was made is stale: the file's dimensions
   are compared with the size definition (the metadata already holds them). The page shows how many images are stale per
   size, and the on-demand path of 023 remakes a stale file at the first use instead of serving the old one.
4. **Orphan cleanup**: list the files of `uploads/thumbnails/` that no metadata refers to, and delete them on request (never
   automatically; restricted to the cache directory, no `..`, no links).
5. **WP-CLI**: `wp media-helper sizes list`, `wp media-helper sizes regenerate [<size>…] [--only-missing] [--yes]`, for large
   sites where a browser page is not the right tool.

## Open questions

- **Images not registered by this plugin** (imported by another tool, with their files next to the original): regenerate
  them into the cache only, or also beside the original? Proposal: cache only, as in 023; the files beside the original stay
  untouched.
- **Where custom sizes are stored**: an option holding the list. Do we also accept a constant for code-controlled sites
  (as proposed for the additional types in 032)?
- **Coexistence with *Simple Image Sizes*** while both are active: it also registers sizes and has its own regeneration, so
  the page should warn when it is active, and only one of the two should own the sizes.
- **Retina / `srcset`**: sizes at 2x are another size or a rule of the same size? Out of scope for the first delivery.

## Non-goals

- Cropping by hand, focal points, a visual editor.
- Changing the format of the thumbnails (WebP, AVIF conversion); a later slice.
- Regenerating from the *original* of other sources: only the files WordPress has as attachments.

## Acceptance criteria

1. A size defined on the page is available to `wp_get_attachment_image()`, `[gallery size="…"]` and the REST `media_details`.
2. Changing the width, height or crop of a size marks its files stale, and *Regenerate* remakes them in the cache without
   timing out on a large library (batches, resumable).
3. Disabling a size stops new files being made for it; the optional cleanup removes the old ones.
4. The orphan list never proposes a file outside the cache directory, and deletion needs a confirmation.
5. Nothing changes on a site that does not open the page: the sizes WordPress knows behave as today.

## Suggested steps

1. Read-only page listing the sizes, their origin and the stale counts.
2. Custom sizes and the WordPress options, registration at `after_setup_theme`.
3. Regeneration job with progress, then WP-CLI.
4. Not-making a size and orphan cleanup.
