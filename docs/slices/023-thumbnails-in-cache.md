<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 023: Thumbnails in a separate cache

Status: **proposed** (design only, not implemented). Depends on
[slice 022](022-wordpress-native-registration.md).

## Goal

Generate and serve the image sub-sizes of registered media from a separate
cache directory, so that originals can stay on a read-only or shared file
system, and so that this plugin can replace tools such as Thumbnails Folder
without changing existing thumbnail URLs.

## Observed layout (real site, used as the compatibility target)

For an original `uploads/photos/2026/eric/10/02/20261002_121549.jpg` the
attachment metadata contains `width`, `height`, `file` (relative path),
`filesize`, `image_meta` and six `sizes`: `thumbnail` (150x150), `medium`
(300x169), `medium_large` (512x288), `large` (1024x577) and two sizes added by
a theme or plugin (`gpxmaps` 400x225, `gallery` 480x270). The thumbnails are
files and public URLs below:

`uploads/thumbnails/photos/2026/eric/10/02/20261002_121549-1024x577.jpg`

That is: `<cache>/<directory of the relative path>/<name>-<W>x<H>.<ext>`. Each
size entry has the core keys (`file` is the bare file name) **plus a non-core
`path` key holding an absolute server path**, which is evidently added by the
tool that moved the thumbnails. Core resolves a size URL from the directory of
the original, so that tool must also rewrite URLs.

Consequences for the design:

- the cache layout mirrors the **uploads-relative** path of the original, not
  the path relative to the source root;
- sizes are those registered at generation time (custom names, a changed
  `medium_large`), so the plugin must use WordPress's current size list, never
  a hard-coded one;
- the absolute `path` key leaks the server layout through any API that returns
  attachment metadata, and must not be written by this plugin.

## Requirements

### 1. Layout and reuse

- Thumbnails are written to `<cache>/<dirname(relative path)>/<name>-<W>x<H>.<ext>`,
  where `W` and `H` are the dimensions WordPress computes for that size. Existing
  files at that location are **reused, never regenerated or renamed**.
- Size entries written to `_wp_attachment_metadata` use the core keys only
  (`file`, `width`, `height`, `mime-type`, `filesize`) and no absolute path. An
  existing `path` key written by another tool is tolerated and read as a hint,
  after checking that it lies inside the cache.

### 2. URLs

The cache must be inside the uploads directory so its files have a public URL.
Where an attachment's original is in a source with a cache, the URLs returned
by WordPress for its sizes (`image_downsize`, `wp_get_attachment_image_src`,
`wp_calculate_image_srcset`, `wp_prepare_attachment_for_js` and the REST media
response) point to the cache. URLs already under the cache are left alone, so
that this plugin and another thumbnail tool can coexist during a migration.

### 3. Generation on first request

As the README says, thumbnails are generated lazily. A request for a
thumbnail that does not exist yet reaches WordPress as an ordinary not-found
request; a handler recognises the cache URL pattern, generates the file with
the WordPress image editor, stores it and answers with it. Later requests are
served directly by the web server.

The handler is strict because it can be triggered by anyone:

- the request is parsed with a fixed pattern and is **only a key**: the
  attachment is looked up from the relative path (slice 022), and the output
  file name and dimensions are computed from the attachment record and a
  registered size, never from the request string;
- only dimensions that match a **currently registered size** for that original
  are generated, so a visitor cannot make the server create arbitrary sizes;
- no path component may contain `..`, null bytes or a stream wrapper, and the
  target must stay below the cache after canonicalisation;
- generation is limited in image size and memory, takes a lock per target to
  avoid concurrent duplicates, and unknown or unregistered originals answer 404;
- the original is never modified: no `-scaled` or `-rotated` copy; EXIF
  orientation is applied to the thumbnail only.

### 4. Lifecycle

- Removing an attachment created by this plugin deletes its cache files and
  never the original (slice 022).
- When registered sizes change, missing sizes are generated on demand; existing
  files are not touched. A maintenance action to purge the cache can be added
  later.
- A source's cache must be separate from its root (slice 021), and the settings
  page already shows its public URL prefix.

## Security

Thumbnails are public derivatives by design, so only images the site accepts to
publish may be given a public cache. Cache locations, hashing of generated
names (when names are not mirrored), and writes follow the rules listed in
[slice 021](021-allowed-base-directory.md). The generation handler raises the
resource-exhaustion concern (R5 of the [audit](../IA/security-audit.md)), hence
the registered-size allow-list, the limits and the locks above.

## Non-goals

- Private (non-public) files: they need the access endpoint planned separately.
- Video posters and non-image previews.
- A thumbnail CDN or external storage.

## Acceptance criteria

1. Existing thumbnail files at the mirrored location are reused and their URLs
   keep working.
2. A missing registered size is generated on first request and served directly
   afterwards.
3. A request for an unregistered size, an unknown file or a path outside the
   cache creates nothing and answers 404.
4. No absolute path is written to attachment metadata or returned by the REST
   API.
5. The original file is never created, modified or deleted.
6. A source on a read-only mount works when it has a writable cache.

## Open questions

- Per-source cache directories (as configured today) or a single global cache
  mirrored on uploads-relative paths (as on the observed site)?
- Generate sizes at registration as well as on demand?
- How to migrate from Thumbnails Folder: read its `path` hints, or ignore them
  and rely on the mirrored layout?
- Which formats (WebP, AVIF) and which image editor (GD or Imagick) are
  supported?
