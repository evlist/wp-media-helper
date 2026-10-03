<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 022: WordPress-native registration and recognition

Status: **implemented**, except what is listed under "Not implemented yet".

## Goal

Make media registered by this plugin indistinguishable, for WordPress and for
other plugins, from media registered by any other method, and make this plugin
recognise media registered by other methods. A user must find the media in the
WordPress Media Library whichever way it was imported, and must never get a
duplicate.

This follows slice 009 ("managed like any other media asset") and the README
("whether a file needs to be registered first is an invisible implementation
detail"), and answers the open question *WordPress uploads as a source* in
[media-source-open-questions.md](../IA/media-source-open-questions.md).

## Background

An attachment registered in place by another tool (here Bulk Media Register)
looks like this on a real site:

- `_wp_attached_file` is **relative to the uploads directory**, for example
  `photos/2026/eric/10/02/20261002_121549.jpg`;
- `_wp_attachment_metadata` holds `width`, `height`, `file`, `filesize`,
  `image_meta` (EXIF) and `sizes`.

Today this plugin stores an **absolute** path in `_wp_attached_file` and in
`guid`, writes no `_wp_attachment_metadata`, and only recognises attachments it
created itself. So the Media Library shows its imports without dimensions or
thumbnails, imports of an already registered file are duplicated, and the
guid discloses a server path.

## Attachment date: possible sources

The attachment date (`post_date` and `post_date_gmt`) decides where an item
sits in the Media Library and in date queries. For photo, track and subtitle
workflows several dates can describe a file, and the right one depends on the
file type. This section only **lists** the possibilities; the priority order and
whether it is configurable are still to be decided.

| # | Source | Typical files | Notes |
|---|--------|---------------|-------|
| 1 | Capture date in the image metadata | JPEG, HEIC, TIFF | Core exposes it as `created_timestamp`, read from the IPTC creation date, else from the EXIF `DateTimeDigitized` field (not `DateTimeOriginal`). EXIF has no time zone: core stores the wall-clock time as if it were UTC (on the target site, a photo taken at 12:15:49 local time has `created_timestamp` 12:15:49 UTC), so the value must be re-read as site-local time, or corrected with an offset tag when one exists |
| 2 | Creation date in the media container | Video (MP4, MOV), some audio | Core reads `created_timestamp` from the QuickTime or ASF metadata for video |
| 3 | Date and time in the **file name** | Photos named `20261002_121549.jpg`, subtitles, exports | Often the only date for formats without metadata, and meaningful for subtitles. Needs a pattern per source or per type, for example `Ymd_His`, with the same placeholder style as the path and filter patterns. The time zone is the site's |
| 4 | Date in the **GPX content** | GPX tracks | Start of the first track segment (`trk/trkseg/trkpt/time`), or the `metadata/time` element; the end time and the duration are also available. Routes and waypoints may have no time. Parsing needs a safe XML reader (no entities, no network, bounded size) |
| 5 | Date of the **directory** | Files stored under a dated path | The source's path pattern already ties a directory to a date (for example `2026/10/02`) |
| 6 | Date **requested in the panel** | Any | The date the editor selected when importing |
| 7 | File **modification time** | Any | Cheap and always available, but changed by copies and synchronisation, so the least reliable |
| 8 | **Registration time** | Any | What core and Bulk Media Register do by default |
| 9 | A **fixed date** | Any | Set by the editor for a batch |
| 10 | Other embedded dates | PDF (`CreationDate`), documents, subtitle headers | Possible later; core does not read them |

Points to settle later: the priority per file type and whether it is a setting
per source; what to do when the selected source of a given file yields nothing
(fall back to the next one, and show which one was used); time-zone handling for
sources 1, 3 and 4; and whether the chosen date should also be stored in the
attachment metadata so it can be recomputed.

## File names that need encoding

Files are never renamed: renaming a source file is unsafe, and impossible on a
read-only mount. `_wp_attached_file` keeps the real relative path. WordPress
builds attachment URLs by appending that path to the uploads URL **without
encoding** (checked in core), so names with spaces, `#`, `?`, `%` or non-ASCII
characters give wrong or broken URLs. Decision: **register such files and
percent-encode their URLs**, encoding each path segment (UTF-8, `/` kept) in:

- the `guid`,
- `wp_get_attachment_url` and, through it, every URL derived from it,
- the thumbnail URLs of slice 023.

The path stored in the database stays raw, so it is encoded exactly once, and a
file literally named `a%20b.jpg` becomes `a%2520b.jpg`. The filter applies to
attachments located in a configured source, so that URLs of unrelated
attachments are not altered.

## What Bulk Media Register does (version 1.41, read from its source)

- It works **inside the uploads directory** only (it lists and registers files
  below `wp_upload_dir()['basedir']`), recursively, with extension, text and
  exclusion filters, in time-limited batches.
- For each file: `wp_insert_attachment()` with a `guid` that is the file URL,
  `post_author` the current user, status `inherit`, `post_title` the file name
  without extension; then `_wp_attached_file` set to the **path relative to
  uploads**.
- **It renames the file on disk** when `sanitize_file_name()` would change its
  name.
- The attachment date follows a setting: the file's modification time, the time
  of registration, or a fixed date.
- It calls `wp_generate_attachment_metadata()`, which writes sub-sizes next to
  the original (or wherever another tool redirects them) and can create a
  `-scaled` or `-rotated` copy and an `original_image` key.
- It skips files that already have an attachment by comparing
  `_wp_attached_file` exactly, and can email a result report with a CSV.
- Its changelog records a fixed path traversal (version 1.32), a reminder that
  this class of weakness applies to this kind of plugin.

This confirms that the uploads-relative representation, exact-path recognition
and uploads-only scope proposed here are the established model.

## Feature parity with Bulk Media Register

| Feature | Decision |
|---------|----------|
| Folder structure kept | Implicit: files are registered in place |
| Title from file name | Yes, as core and that tool do |
| Metadata for images, audio, video | Yes (without writing files, see above) |
| Attachment date | Several possible sources, listed above; priority to be decided |
| Renaming files whose names core would sanitise | **No**: the plugin never modifies a source; URLs are encoded instead (see above) |
| Sub-sizes at registration | Delegated to [slice 023](023-thumbnails-in-cache.md) |
| Extension, text and exclusion filters | Covered by the panel filters (slices 018, 019) |
| Email report and CSV | Out of scope |
| Batched registration with time limit | Covered by bulk actions limited to `max_entries` (slice 020) |

## Replacing Bulk Media Register

The README states that this plugin is designed to make Bulk Media Register
unnecessary for date-based external directory workflows. Coexistence is
therefore a transition, not a target:

- everything that tool registered must keep working **after it is deactivated**:
  its attachments are ordinary WordPress attachments and stay valid, and this
  plugin must keep recognising them (requirement 3);
- the features of that tool that users rely on (for example how the title, the
  date or the parent post of an attachment are set at registration) must be
  inventoried and either supported or explicitly dropped; this is an open
  question below;
- **adoption.** An attachment registered earlier by the replaced tool is already
  fully manageable (attach, detach, remove). Adoption would only add this
  plugin's provenance meta (`_wp_media_helper_source_id`,
  `_wp_media_helper_source_path`), either automatically when the file is below an
  *enabled* source root, or through an explicit "adopt" action. Nothing else about
  the attachment would change.

## Interaction with source ownership

[Slice 024](024-source-priority-and-ownership.md) makes the sources an ordered
list in which each file has one owner. Registration records the provenance of the
**owner**, resolved on the server, and recognition never depends on it.

## Scope

Files below the uploads directory (the allowed base, slice 021). Files outside
it would need an absolute representation and an access endpoint; they are out
of scope here.

## Requirements

### 1. Representation

For a file below uploads:

- `_wp_attached_file` is the path relative to the uploads base directory, with
  forward slashes, derived from the canonical path;
- `guid` is the attachment URL, never a file-system path;
- `post_mime_type` comes from `wp_check_filetype()`;
- `post_title` is the file name without extension, like core;
- the provenance meta (`_wp_media_helper_source_id`, `_wp_media_helper_source_path`)
  is still stored, as an addition, and marks the attachment as **created by
  this plugin**.

No server path may appear in any stored or returned attachment field.

### 2. Metadata, without touching the original

On registration, store `_wp_attachment_metadata` with the standard keys
`width`, `height`, `file`, `filesize` and `image_meta`, read with core
functions (`wp_getimagesize()`, `wp_read_image_metadata()`), and an empty
`sizes`. Sub-sizes are produced by [slice 023](023-thumbnails-in-cache.md).

Registration must never create files: no `-scaled` copy (core's big-image
threshold), no `-rotated` copy, no sub-size next to the original. For that the
plugin does not call `wp_generate_attachment_metadata()` on the original.
Audio and video metadata use the core readers.

### 3. Recognition and idempotence

Before creating an attachment, look up an existing one whose
`_wp_attached_file` equals the relative path (an exact, single query). If found,
reuse it, whoever created it. The lookup also accepts the legacy absolute form
written by earlier versions of this plugin, and normalises it to the relative
form when the attachment is next touched.

The panel shows such items as *In WP media library*, and *Attach* and *Detach*
work on them, subject to the capability checks and to the "attached to another
post" protection.

### 3b. Matching rule

Matching is by exact file identity (relative path), not by file name. The
earlier fuzzy matching (`_N` suffix, base name) existed to follow files that
WordPress renames on upload; it does not apply to in-place registration and is
a source of false positives.

### 4. Removal

*Remove* deletes the attachments of the file **whichever tool created them**,
provided the user may delete them and they are not attached to a post. It only
removes the WordPress record: the original file is never deleted, so the
operation is low-risk, and a `wp_delete_file` filter refuses every file deletion
for the duration of the call. This closes weakness R6 of the
[security audit](../IA/security-audit.md).

Known consequences for attachments created by other tools: files they generated
(sub-sizes, for example the thumbnails of Thumbnails Folder) stay on disk, since
the filter refuses their deletion too, and posts that referred to the attachment
by its ID (featured image, galleries) lose that reference, as with any removal
of a media from the library. The URLs already written in post content keep
working because the files remain. Cleaning up generated thumbnails belongs to
[slice 023](023-thumbnails-in-cache.md).

## Security

- Paths are canonicalised and confined to the source root as today; the
  relative path is computed from the canonical result, never from client input.
- Capability checks are unchanged (`upload_files`, `edit_post`, `delete_post`).
- Weakness R3 disappears for new registrations, and R5 is reduced because the
  lookup is exact instead of scanning every attachment.

## Non-goals

- Files outside uploads.
- Generating sub-sizes (slice 023).
- Changing attachments created by other tools beyond attaching and detaching.

## Implementation notes

- `UploadsPath` computes the uploads-relative path (the `_wp_attached_file`
  value), encodes URLs, and lists the values under which an attachment may be
  recorded (relative, and the absolute forms of older versions).
- `AttachmentRegistry` finds attachments with a few exact queries on
  `_wp_attached_file` (the database comparison ignores case, so the result is
  verified in PHP), and flags those created by this plugin.
- `AttachmentRegistrar` registers a file with `wp_insert_attachment()`, reading
  metadata with `wp_getimagesize()`, `wp_read_image_metadata()` and the core
  audio and video readers. It never calls `wp_generate_attachment_metadata()`.
- `AttachmentDate` chooses the date. **Default order, provisional**: image
  capture date (re-read as site time), video or audio creation date, a date in the
  file name (`20261002_121549`, `2026-10-02`, ...), file modification time, then
  now. GPX content, directory date and the other sources of the list above are
  not used yet.
- `AttachmentUrls` encodes the URL of attachments located in a configured
  source (`wp_get_attachment_url`), and the `guid` is written encoded.
- Panel state (imported, attached here, attached elsewhere) is computed from the
  attachments found for the listed files, by exact path. The earlier fuzzy
  matching on file names has been removed.
- Removal applies to the attachments of the file whichever tool created them.
  During the call a `wp_delete_file` filter refuses every file deletion, and
  `_wp_attached_file` is removed first, so no original file is ever deleted.
- Attachments created by earlier versions of this plugin are rewritten in the
  native form when they are next recognised on import or attach.

### Not implemented yet

- Files outside the uploads directory are **refused** (message: only files inside
  the WordPress uploads directory can be registered). They would need an absolute
  representation and an access endpoint. This only matters when the allowed base
  directory has been moved out of uploads by the site owner.
- Adoption of attachments created by other tools (adding this plugin's
  provenance meta to them). Recognised attachments can already be attached,
  detached and removed, so adoption is now only about provenance. The automatic
  adoption proposed above is not done, because on a source covering WordPress's own
  upload folders it would also capture native uploads; it stays to be decided.
- Recording the date source, GPX dates, a configurable date order.
- Sub-sizes (slice 023).

## Acceptance criteria

1. A registered file below uploads has a relative `_wp_attached_file`, a URL
   `guid`, and `width`, `height`, `filesize` and `image_meta` metadata.
2. Registering never creates or modifies a file.
3. Registering a file that already has an attachment, whatever its origin,
   reuses it and creates no duplicate.
4. The panel reports foreign attachments as *In WP media library*.
5. *Remove* deletes the attachments of a file whichever tool created them, and
   never deletes a file.
6. No attachment field or REST response contains a server path.

## Open questions

- Attachment date: which priority order per file type, whether it is a setting,
  and how time zones are handled (see the list above).
- Should the encoding filter also apply to attachments outside the configured
  sources that contain characters needing encoding?
- Adoption of recognised attachments: automatic when under an enabled source,
  or explicit?

- Legacy absolute attachments are normalised lazily, when next recognised
  (decided and implemented; the plugin is at version 0.1.0).
- Should recognition also be offered for attachments whose file is below a
  source root but registered with a different case or Unicode normalisation of
  the path?
