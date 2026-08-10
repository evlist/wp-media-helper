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
   (pre-filled according to the configured slug format, editable).
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
| **Filter string format** | Pattern used to pre-fill the filter field in the sidebar panel. Supports placeholders such as `{date:Ymd}`. Defaults to `{date:Ymd}`. |
| **Thumbnail cache directory** | Optional. Writable path where thumbnails for external media files are stored. Required when external directories are read-only. Thumbnails are generated lazily on first request. |

## Replacing Other Plugins

For date-based external directory workflows, this plugin is designed to make
the following plugins unnecessary:

- [Bulk Media Register](https://wordpress.org/plugins/bulk-media-register/) —
  media registration from external directories.
- [Thumbnails Folder](https://wordpress.org/plugins/fr-thumbnails-folder/) —
  thumbnail generation into a separate writable directory when source files are
  on a read-only filesystem.

## License

GPL-3.0-or-later — see [LICENSE](LICENSE).
