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
| **Path pattern** | Optional. A **hint** for finding new files quickly: the subdirectory where the files of a date are likely to be, for example `{date:Y}/{date:m}/{date:d}` (placeholders `{date:...}` and `{source}`). The directories of the requested day and of the days around it are checked right away. It does not decide which files belong to a day: files are placed on a day by their date, wherever they are. |
| **Name date patterns** | Optional. One pattern per line, tried in order; the first that matches a file name gives its date. A pattern says how the date is written in the name: `{date:Ymd}`, `IMG_{date:Ymd}_{date:His}`. Letters: `Y` year, `m` month, `d` day, `H` hour, `i` minute, `s` second, `v` milliseconds; `[ ... ]` is an optional part (`{date:Ymd}[_{date:His}]`), `*` any characters, `\` makes the next character literal. A pattern is searched anywhere in the name and a digit next to a date field never continues it. Presets (Android, Pixel, Samsung, screenshots, WhatsApp, separators, any date) can be added from a menu and a test field shows the date a name gives. Common forms such as `20261002_121549` or `2026-10-02` are recognised after your patterns without any. A date alone is placed at 12:00. |
| **Photos** | The capture date of JPEG and TIFF photos (IPTC, else EXIF) is used before the modification time when the name has no date. When the name has a day only (`20261002-trip.jpg`), the capture **time** replaces the 12:00 default if the photo was taken the same day; a photo taken another day keeps 12:00. It is read once, when the file is indexed, and again only if the file changes. |
| **Files without a date in their name** | Whether to use the modification time of the file (on by default). When off, such a file is not placed on any day. |
| **Maximum entries per page** | Number of media items shown per page in the editor panel, and the maximum number of items accepted by a single bulk action. Whole number between 1 and 500, default 100. |
| **State** and order | The sources are an **ordered list**; each file belongs to the first source whose directory contains it, so put narrow sources (`photos`) before broad ones (`uploads`). *Active*: its files are listed and can be imported. *Disabled*: ignored as if it did not exist, its files fall to the sources that follow. *Excluded*: lists nothing, and no other source lists its files either. |

The panel starts at the day a post was published (published, scheduled or private posts) when
no date was chosen for it, and at today for a draft.

See [Dates in WP Media Helper](docs/IA/date-model.md) for how the panel date, the
patterns, the attachment date and the dates of the files relate, and why a date in a
folder name can be misleading.

The settings page shows each source as a card (position, name, state, the buttons to move it, add a
source before or after it, or remove it; drag it by its handle). Its settings open under it, with
the rarely used ones in *Advanced settings*. The root directory is chosen with **Browse…**, which lists
the directories below the uploads directory one level at a time (click one to go into it, *Select* to
choose it, or *Use* for the directory shown); typing the path still works. Private or technical
directories that no source above them lists are shown, marked. An *Index* table below shows the state of each source
with a *Re-scan now* button, and *Reset* is a closed block at the bottom.

Each external directory can define its own path pattern and optional filter
pattern. This makes it possible to target different user trees without scanning
large directory hierarchies.

### Quick start: the uploads directory

When no source covers the WordPress uploads directory, the settings page offers
**Use the uploads directory**: it adds one source on that directory, last in the list,
with default settings. Files dropped there by FTP, Nextcloud, a camera uploader or
another plugin can then be listed and imported; files already in the Media Library
show as imported. Nothing is scanned until you use the button.

The uploads directory may also hold private or technical files, so a built-in list of
directories is **never listed** by a source that contains them: `woocommerce_uploads`,
`wc-logs`, `wpcf7_uploads`, `cache`, `backup*`, `backups`, `ai1wm-backups`, `wpforms`,
`elementor`, `sucuri`, `bulk-media-register-tmp`, plus the thumbnail cache and directories starting with a dot. The
site owner can change the list in code with the `wp_media_helper_excluded_directories`
filter. **It is a default, not a guarantee**: a private directory that is not on it can
still be listed and imported by users who may upload. If you keep private files in uploads,
do not use this button; add a dedicated directory as a source instead. A source whose
root is one of these directories (a deliberate choice) is not affected.

## Allowed base directory

Every external source root must be a
sub-directory of the WordPress uploads directory (for example
`wp-content/uploads/nextcloud`). A source whose root is outside it is
rejected when saving, and a source already stored outside it is disabled and
flagged with a warning on the settings page. A root may be the uploads directory
itself (type `.`). The thumbnail cache (see [Thumbnails](#thumbnails)) is never listed
as media, even inside a source root, and no root can be inside it. Two sources cannot have the same root;
a source whose directory is inside an earlier source's is flagged as shadowed.

In the settings screen the base directory is displayed in front of the *Root
directory* field, and you only type the path below it (for example
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
which publishes thumbnails of private images. The settings page lists the
public URL prefix of each source. If the files are private, block HTTP access to them in
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
  never a file. Thumbnails of that attachment are deleted from the cache.

Only files inside the uploads directory can be registered.

## Thumbnails

Importing creates no file. The sizes of an imported image (`thumbnail`, `medium`,
`large`, and the sizes added by the theme) are created in a **single cache directory
for the whole site**, `wp-content/uploads/thumbnails` by default, so the originals can
stay read-only. The layout is `thumbnails/<folder of the original below uploads>/<name>-<W>x<H>.<ext>`,
the one used by Thumbnails Folder: its existing files are reused, with its URLs.

- A size is created when WordPress asks for it (page rendering, media library) and
  does not exist yet, and in the background after the import by a WP-Cron event. With
  WP-Cron disabled (`DISABLE_WP_CRON`) nothing is scheduled and sizes are created
  when asked for. A request for a thumbnail URL never creates anything.
- Sizes not smaller than the original are not created: WordPress uses the full image.
  The original is never modified, and the orientation of a photo is applied to the
  thumbnail only.
- Only attachments imported by this plugin get new sizes. Attachments from other tools
  keep theirs; those already in the cache are served from there.
- Deleting an attachment deletes its thumbnails, never the original.
- **Previews in the editor panel.** Each listed image, imported or not, shows a preview
  that keeps its proportions, in two sizes (long edge 320 and 640 px, never enlarged),
  stored in the same cache. The panel also shows how many files match the filters.
- **The gallery.** The panel lists files as thumbnails that keep their proportions, over the whole
  width of the panel, 1, 2 or 3 per row (remembered in the browser), with an infinite scroll in lots
  of *maximum entries*. A paperclip on each thumbnail shows the attachment: green, attached to
  this post; red with a lock, attached to another post; crossed out, not attached. Small badges
  show files in the Media Library and files in the trash. A click opens the **detail sheet** of the file
  (large preview, type, dimensions, size, date and where it comes from, source, state and actions,
  with previous and next buttons and the arrow keys). A right click, the ⋮ button shown on hover and
  focus (always shown on a touch screen) or the menu key of the keyboard opens the menu of the file
  (*Details*, its actions, *Select*), which the arrow keys and Escape drive.
- **Featured image.** *Set as featured image* (in the menu and the sheet, for images, when the post
  type has a featured image) imports the file if it is not in the Media Library yet and makes it the
  featured image of the post being edited; the tile shows a star, and *Remove featured image*
  undoes it. There is only one, so there is no bulk action. Like any change in the editor it is
  saved with *Update*. A long press (or Ctrl/Shift+click, or the *Select* button) enters
  selection mode, with a mark on every thumbnail and the bulk actions above. The list is not replaced
  behind your back: when files appear, a notice offers to refresh it. When it is not in the cache yet the browser asks an authenticated endpoint
  (`admin-ajax.php?action=wp_media_helper_thumbnail`) that creates and returns it; only
  the rows on screen are loaded. Only users who may upload see previews, and only for
  images of an active source. An image too small to be reduced is sent as it is
  (up to 1 MB).
- The cache directory is the same for every source and is never listed as media. It
  can be moved, still inside the uploads directory, with the
  `wp_media_helper_thumbnail_cache_dir` filter. Other filters:
  `wp_media_helper_background_thumbnails` (force or prevent the background event) and
  `wp_media_helper_thumbnail_max_pixels` (images larger than 100 megapixels are skipped).

## The trash

*Move to trash* (bulk action and per-item action, in red) takes a file out of the gallery for the whole site, for
example a photo that is not meant to be published. **The file itself is never touched**: it stays on the disk, and may
be in a read-only source. What goes is everything WordPress knows about it: its entries in the media library are
deleted (title, caption, description), so it disappears from every post it was attached to, and if it was the featured
image of the post being edited, the featured image is removed. The file can be restored to the gallery, but not what was lost, so the panel asks for a
confirmation that says what will be lost, including the other posts concerned. An image inserted in a post as a block
keeps showing, because the file is still there. A trashed file is not offered for import or attach, has no preview,
and its thumbnails are deleted from the cache.

Users who may see the trash get a *Show the trash* checkbox that lists the trashed files marked *In the trash*, with
*Restore from trash* to put one back in the gallery. Restoring does not attach it again and does not bring back the
deleted media library entry (keeping those properties is a possible later improvement).

By default whoever can upload files can trash, restore and see the trash. A site can restrict that in code with the
filters `wp_media_helper_can_trash_files` and `wp_media_helper_can_see_trash` (the former names
`wp_media_helper_can_hide_files` and `wp_media_helper_can_see_hidden_files` still work). Trashing also needs the
permission to delete the attachments of the file. A renamed or moved file loses its trash flag.

## Reset

The bottom of the settings page has a **Reset** section to start again from nothing
(tests, a clean configuration). Pick what to remove, type `RESET` and confirm:

| Item | What it removes |
|------|-----------------|
| Settings and sources | The list of sources and the general settings |
| File index | The index tables (including the trash flags of files), the scan state and the scheduled scans (rebuilt when a source is added) |
| Dates saved with posts | The panel date of every post (off by default: editors chose these dates and they cannot be rebuilt) |
| User preferences | The filters and the panel mode of each user |
| Thumbnails of imported media | The cached sizes of the attachments imported by this plugin |
| The whole thumbnail cache | Every file of the cache directory, including previews of files never imported and files written by other tools (off by default) |
| Media imported by this plugin | Removes them from the Media Library, the files stay on disk (off by default) |

Media files and the files of your sources are never deleted; only thumbnails are.
Only administrators (`manage_options`) can reset, with a nonce and the confirmation word.

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

External directories can contain many files, so the plugin keeps a persistent
index of the files it has discovered, in two database tables, instead of scanning
every source when a page is loaded. Finding the files of a day is a query on that
index (see [Dates in WP Media Helper](docs/IA/date-model.md) for how a file is
given its day).

- **Discovery is incremental.** A directory whose modification time has not
  changed is not read again, so a pass over an unchanged tree costs about one
  `stat` per directory. A file added in a sub-directory is found because that
  directory changed.
- **The first scan is split in runs.** It runs in the background within a time
  budget (15 seconds per run), resumes by itself, and the settings page shows its
  progress. Until it has finished once, the panel says the list may be incomplete.
- **Directory times are only a hint.** Some file systems do not update them and an
  edit in place does not change them. A **full pass** re-reads every directory
  weekly and on demand (*Re-scan now* in the settings), and an incremental pass runs
  every 30 minutes.
- **The path pattern is a hint.** When a day is requested, the directories it
  points to for that day and its neighbours are checked at once, briefly, so files
  just added to today's folder appear immediately.
- **Dot files, symbolic links and anything outside the source root are ignored.**
  Files that disappear are marked missing, then forgotten after 30 days.

Background work uses WordPress cron, which runs when the site is visited. On a site
with little traffic, or with `DISABLE_WP_CRON`, call `wp-cron.php` from a system
cron (for example every five minutes), otherwise the first scan and the periodic
passes may not run.

The index holds no server path: files are identified by their path relative to
the uploads directory, so it stays valid if the site moves. It is a cache: it can
be rebuilt with *Re-scan now*. The tables are not removed when the plugin is
deleted.

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
