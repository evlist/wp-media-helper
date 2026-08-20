<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# WP Media Helper

A WordPress plugin that streamlines media selection and attachment from the
post editor, with an optional integration for externally-hosted media
directories (e.g. Nextcloud).

## Problem

The native WordPress media workflow has two friction points:

1. **Attaching media to a post** requires leaving the editor and navigating to
   a separate screen. The native media library displays small, square-cropped
   thumbnails that make photo selection tedious.
2. **Registering files from an external directory** (mounted read-only) adds a
   third step on yet another admin page. Plugins such as
   [Bulk Media Register](https://wordpress.org/plugins/bulk-media-register/)
   solve this but require staying on their own page during an AJAX import loop.

## Solution

WP Media Helper adds a **Media** panel to the Gutenberg sidebar — modelled on
the native *Featured Image* panel — that lets users manage attached media
without leaving the post editor.

The gallery uses aspect-ratio-preserving thumbnails (inspired by mobile photo
gallery apps), making it far easier to browse and select photos than the
native square-crop grid.

The panel presents a unified view of **all files matching the filter**,
regardless of their origin: files already in the WordPress media library and
files only present on a configured external filesystem are shown together.
Whether a file needs to be registered first is an invisible implementation
detail.

### Workflow

1. Open or create a post.
2. In the **Media** sidebar panel, review or edit the filter string
   (pre-filled according to the configured filter pattern, editable).
3. Browse the matching files in the gallery. Files already attached to other
   posts are hidden by default; a toggle reveals them with a red warning icon.
4. Select the files to attach.
5. Click **Confirm**: the plugin registers any unregistered files and attaches
   all selected files to the post in a single server-side operation.

## Requirements

- WordPress 6.x or later (block editor).
- PHP 8.1 or later.

External directory integration requires the directory to be accessible
read-only by the web server, but this feature is entirely optional.

## Supported File Types

All MIME types allowed by the WordPress installation are supported (images,
videos, GPX tracks, etc.).

## Configuration

All settings are managed on the plugin's **Settings** page
(*Settings → Media Helper*).

| Setting | Description |
|---------|-------------|
| **External media directories** | Optional. One or more absolute server paths to external media roots. Leave empty to use only the standard WordPress media library. |
| **Path pattern** | Optional. Pattern used to derive the directory for a given date and source. Supports placeholders such as `{date:Y}`, `{date:m}`, `{date:d}` and `{source}`. If left empty, the resolver falls back to the source root directly, which is useful for static media sources that do not depend on the date. |
| **Filter pattern** | Optional. Pattern used to further filter filenames after the target directory has been resolved. Supports placeholders such as `{date:Ymd}`. Defaults to an empty filter. |
| **Thumbnail cache directory** | Optional. Writable path where thumbnails for external media files are stored. Required when external directories are read-only. Thumbnails are generated lazily on first request. |

Each external directory can define its own path pattern and optional filter
pattern. This makes it possible to target different user trees without scanning
large directory hierarchies.

## External Media Indexing

External directories can contain many files, and extracting metadata such as
EXIF or GPX dates can be expensive. The plugin should therefore maintain a
local index of discovered external files instead of scanning every source on
each page load.

When the user requests media for a target date:

1. Display the current indexed results immediately.
2. Check the modification time of each relevant mapped directory.
3. Start a targeted refresh only when a directory changed or its index is too
   old.
4. Update the gallery asynchronously when the refresh completes.

The directory modification time is an invalidation hint, not an authoritative
change notification. The plugin must also support forced refreshes and should
periodically refresh entries even when the directory timestamp appears
unchanged.

While a relevant refresh is running, the gallery remains visible but media
selection and confirmation are disabled. This prevents users from working with
known-stale results. The interface should preserve the current scroll position
and any existing selection when refreshed results arrive.

A low-load scheduled task may refresh configured sources progressively in the
background. It reduces the likelihood of a user-facing refresh, but does not
replace the consistency check performed when the date is requested.

## Replacing Other Plugins

For date-based external directory workflows, this plugin is designed to make
the following plugins unnecessary:

- [Bulk Media Register](https://wordpress.org/plugins/bulk-media-register/) —
  media registration from external directories.
- [Thumbnails Folder](https://wordpress.org/plugins/fr-thumbnails-folder/) —
  thumbnail generation into a separate writable directory when source files are
  on a read-only filesystem.

## Related Plugins and Scope Notes

The following plugins are relevant references for future slices:

1. [Simple Image Sizes](https://wordpress.org/plugins/simple-image-sizes/)
   This is directly related to WP Media Helper. A gallery-oriented UI will need
   multiple thumbnail sizes for performance and visual quality.
2. [WP Extra File Types](https://wordpress.org/plugins/wp-extra-file-types/)
   This is optional, but useful for workflows that rely on non-default media
   extensions such as `.gpx` and `.vtt`.
3. [Lightbox PhotoSwipe](https://wordpress.org/plugins/lightbox-photoswipe/)
   This is optional and can be treated as an integration point for front-end
   media viewing.

Current position:

- Thumbnail size management is in direct scope.
- Additional MIME type support and lightbox integration are remembered as
  optional features and should be evaluated after core workflow delivery.

## License

GPL-3.0-or-later — see [LICENSE](LICENSE).
