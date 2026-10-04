<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 032: File types

Status: **first delivery implemented** (categories, importable check, additional types, ignored extensions); the rest below stays open. Related:
[031](031-video-and-document-previews.md), [026](026-name-date-patterns-and-embedded-dates.md) (embedded
dates by type), [015](015-extensible-media-filter-contract.md) and [018](018-media-type-filter.md) (filters).

## Goal

Decide, in one place, which files the plugin lists, which it can import, how it calls them and what it does
with each type. The rules are scattered today.

## What exists

- **Classification** by extension, hard coded: images (`png jpg jpeg gif webp svg`), videos (`mp4 mov webm avi
  m4v`), everything else `other`. The panel filter offers *Images*, *Videos*, *Other*.
- **Listing:** every file of an active source is indexed and listed, whatever its type (except dot files and
  `Thumbs.db`, `desktop.ini`).
- **Import:** only types WordPress accepts (`wp_check_filetype()` against its allowed MIME list, which a site
  can change with `upload_mimes`). A GPX, a `.srt` or a RAW file is listed but cannot be imported with the
  default list; the message says only that the type is not allowed.
- **Dates and previews** depend on the type (026, 031) but are decided in their own code.

## Questions to settle

1. **Categories.** Replace the three types by a few categories with an icon and a label: image, video, audio,
   document (PDF, office), subtitles, GPS track, archive, other. Where the list lives (a class, with a filter
   for sites), and whether the panel filter follows (one group of checkboxes with more entries).
2. **What is listed.** Listing everything is useful for finding a stray file but noisy: temporary files,
   `.json` sidecars, `.db`, Nextcloud's `.part`. A global list of **ignored extensions and names**, with a
   default (`tmp`, `part`, `db`, `ini`, `json`, ...), plus the possibility to list *only* some categories per source.
3. **What can be imported.** Show clearly in the panel which files can be imported (greyed out otherwise, with
   the reason), instead of failing at import. A setting to **add types** beyond the WordPress list (`gpx`, `srt`,
   `heic`, `dng`) with a safe default: types that can run code (`php`, `phtml`, `phar`, `js`, `html`, `htm`, `svg`
   unless sanitised, `exe`) are **never** importable, whatever the setting, and the added list is code-controlled
   or administrator-only with the same trust model as the allowed base.
4. **Content check.** `wp_check_filetype_and_ext()` for what it can verify (the extension and the content agree),
   at import and before a preview is made.
5. **Photos in other formats.** HEIC and RAW: listed and dated (embedded date) but previewed only when the
   image editor can decode them; WordPress 6.7 and later handle HEIC uploads when Imagick supports it.
6. **Per-type behavior table** (date source, preview, importable, icon) kept in one place, so 026 and 031 do
   not each carry their own switch.

## Non-goals

- Converting files, extracting text, or indexing the content of documents.
- Allowing types that WordPress forbids for security reasons.

## Acceptance criteria

1. A file that cannot be imported is shown as such before the action, with the reason.
2. Adding a type to the importable list works for a harmless type, and is refused for a type that can run code.
3. The ignored list hides its files from every list and from the counts.
4. The panel filter and the icons follow the categories.

## Implemented (first delivery)

- `MediaSource\FileTypes` holds the categories (image, video, audio, document, subtitles, gps, archive, other; filter
  `wp_media_helper_file_categories`), their labels and icons, the list of **dangerous extensions** (never added, never
  imported, even if another plugin adds them through `upload_mimes`) and the parsing of the two settings.
- The panel type filter and the tile icons follow the categories (localized from the server). A saved choice of the former
  three values (image, video, other) still means "everything".
- **Import check before the action:** each listed file carries `can_import` and `import_blocker`; a file that WordPress does
  not accept gets a warning badge, the reason in the detail sheet, and no *Import / Attach / Featured image* action (it can
  still be hidden). The server import refuses it with the same reason.
- **Additional file types** (settings page, administrators only): one `extension mime/type` per line. They are added to
  `upload_mimes` for the whole site (this replaces plugins such as *WP Extra File Types*), at priority 20, then the dangerous
  types are removed from the list. For these types only, `wp_check_filetype_and_ext` takes the extension as written, because
  PHP detects `text/plain` or `application/xml` for GPX and WebVTT and WordPress would refuse them.
- **Ignored extensions** (settings page; default `tmp part db ini lock bak crdownload`): not indexed, so not listed nor
  counted. Changing the list makes the next pass read the sources again.

Still open: listing only some categories per source, `wp_check_filetype_and_ext()` content check before a preview,
HEIC/RAW previews, a single per-type behavior table shared with 026 and 031, the extra types as a code-controlled constant for
sites that want it.
