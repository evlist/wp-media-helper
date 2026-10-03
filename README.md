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
| **External media directories** | Optional. One or more absolute server paths to external media roots, each located inside the WordPress uploads directory (see [Allowed base directory](#allowed-base-directory)). Leave empty to use only the standard WordPress media library. |
| **Path pattern** | Optional. Pattern used to derive the directory for a given date and source. Supports placeholders such as `{date:Y}`, `{date:m}`, `{date:d}` and `{source}`. If left empty, the resolver falls back to the source root directly, which is useful for static media sources that do not depend on the date. |
| **Filter pattern** | Optional. Pattern used to further filter filenames after the target directory has been resolved. Supports placeholders such as `{date:Ymd}`. Defaults to an empty filter. |
| **Maximum entries per page** | Number of media items shown per page in the editor panel, and the maximum number of items accepted by a single bulk action. Whole number between 1 and 500, default 100. |
| **Thumbnail cache directory** | Optional. Writable directory where thumbnails for external media files are stored, located inside the same allowed base directory as the roots and separate from the source root. Required when external directories are read-only. Thumbnails are generated lazily on first request. |

Each external directory can define its own path pattern and optional filter
pattern. This makes it possible to target different user trees without scanning
large directory hierarchies.

## Allowed base directory

Every external source root, and every thumbnail cache directory, must be a
sub-directory of the WordPress uploads directory (for example
`wp-content/uploads/nextcloud`). A source whose root or cache is outside it is
rejected when saving, and a source already stored outside it is disabled and
flagged with a warning on the settings page. A thumbnail cache may not exist
yet (it is created on first use) but its parent must, and it must be separate
from the source root: it can be neither inside it, equal to it, nor contain it,
otherwise thumbnails would be listed as media.

In the settings screen the base directory is displayed in front of the *Root
directory* and *Thumbnail cache directory* fields, and you only type the path below it (for example
`nextcloud/photos`). The plugin still stores the absolute path. A value starting
with `/` is treated as an absolute path and must itself be inside the base.
When the restriction is lifted, the field asks for an absolute path again.

With Docker, mount the directories you want to expose inside the uploads
directory, read-only:

```yaml
services:
  wordpress:
    volumes:
      - /srv/nextcloud/data/alice/files/Photos:/var/www/html/wp-content/uploads/nextcloud/photos:ro
```

The site owner can move the base directory, or lift the restriction, from
`wp-config.php` or a plugin; it cannot be changed from the admin screen:

```php
// A different base directory.
define( 'WP_MEDIA_HELPER_ALLOWED_BASE', '/srv/media' );

// Or through a filter. Return false to remove the restriction.
add_filter( 'wp_media_helper_allowed_base', static fn () => '/srv/media' );
```

### Public access to files under uploads

Web servers normally serve the uploads directory, so **anything mounted there
can be downloaded by anyone who knows or guesses its URL**, even though the
plugin itself never exposes these URLs. The same holds for the thumbnail cache,
which would publish thumbnails of private images. The settings page lists the
public URL prefix of each source and cache. If the files are private, block HTTP access to them in
the web-server configuration:

```apache
# Apache, in the virtual host
<Location "/wp-content/uploads/nextcloud">
    Require all denied
</Location>
```

```nginx
# nginx
location ^~ /wp-content/uploads/nextcloud/ { deny all; }
```

Adjust the URL prefix to your mount point. Also make sure scripts cannot run from
these directories: a `.php` file added to a source by anyone who can write to it
would otherwise be executed by a server that runs PHP in uploads.

```apache
# Apache: no script execution under the source directory
<Directory "/var/www/html/wp-content/uploads/nextcloud">
    php_flag engine off
    Options -ExecCGI
    RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8
</Directory>
```

```nginx
# nginx: refuse PHP below the source directory
# (place it before the generic "location ~ \.php$" block)
location ~* ^/wp-content/uploads/nextcloud/.*\.php$ { deny all; }
```

The plugin does not write these
rules itself: read-only mounts cannot hold an `.htaccess` file, nginx ignores
it, and a future feature may need to serve some of these files. The plugin's
own index (`wp-media-helper-index`) is protected by an `.htaccess` file and an
`index.php`, which nginx ignores: on nginx, deny
`/wp-content/uploads/wp-media-helper-index/` explicitly (see weakness R2 in the
[security audit](docs/IA/security-audit.md)).

## Compatibility with the WordPress Media Library

Importing a file registers it the way WordPress does, so it shows up in the
Media Library like any other media and works with other plugins:

- the file stays where it is; nothing is copied, moved or renamed, and no file is
  created at registration;
- the attachment records the path relative to the uploads directory, a public URL
  (file names with spaces, `#`, `%` or accents are percent-encoded in URLs), the
  dimensions, size and EXIF data of images, and the audio and video metadata;
- the attachment date is the capture date of the image (or the creation date of a
  video), else a date written in the file name such as `20261002_121549`, else
  the modification time of the file. This order is provisional;
- a file that already has an attachment, whichever tool created it (for example
  Bulk Media Register), is recognised and reused: no duplicate, and it is shown as
  already in the library;
- *Remove* deletes the attachments of the file whichever tool created them, and
  never a file. Thumbnails generated by another plugin for that attachment stay on
  disk.

Only files inside the uploads directory can be registered. Thumbnails are not
generated by this plugin yet.

## Pagination

The editor panel never loads every matching file at once. Results are sorted,
filtered, then split into pages of **Maximum entries per page** items, with
*Previous* / *Next* controls and a "Page X of Y" indicator. Changing the date
or a filter returns to the first page, and a page number beyond the last page
is clamped to the last one. Selections apply to the visible page only, so a
bulk action can never exceed the configured maximum; the server rejects larger
requests.

## Security

- Every AJAX endpoint checks a capability and a nonce. Importing or attaching
  media requires `upload_files`; removing or detaching an attachment requires
  the matching `delete_post` / `edit_post` capability on that attachment.
- Source roots must be inside the allowed base directory (the uploads
  directory by default), which only the site owner can change.
- File paths sent by the browser are never trusted: they are resolved with
  `realpath()` and accepted only when they point to a regular file below the
  root of an enabled source. `..` segments and symbolic links leaving the root
  are rejected, and the scanner ignores symbolic links.
- Only file types allowed by WordPress (`wp_check_filetype()`) can be
  registered.
- The external media index is stored in a private
  `wp-media-helper-index` folder of the uploads directory (mode `0700`, with
  `index.php` and `.htaccess` guards), not in the system temporary directory.
  On servers that ignore `.htaccess` (for example nginx), deny HTTP access to
  that folder in the server configuration.
- Dates sent by the browser must be valid `Y-m-d` calendar dates, and absolute
  server directories are not sent back to the browser.

See [Constraints and working rules](docs/IA/constraints.md) for the trust
model and the [security audit](docs/IA/security-audit.md) for the known
residual weaknesses.

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
