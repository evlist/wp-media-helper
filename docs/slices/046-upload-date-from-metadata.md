<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 046: Dates and metadata for files uploaded through the media library

Status: **proposal**. Related: [026](026-name-date-patterns-and-embedded-dates.md) (date model),
[044](044-image-sizes.md) and [045](045-file-types-editor.md) (same "replace a small plugin" approach). Aim: make
*Upload Media Exif Date* unnecessary for files that do not come from a source of this plugin.

## Goal

When a file is uploaded with the standard WordPress forms (media library, the editor's upload, REST `POST /wp/v2/media`), give
the attachment the date and the metadata that the plugin gives to the files it registers itself, by reusing the same code.

## What exists

- A file registered from a source (`AttachmentRegistrar`) gets its date from `AttachmentDate::resolve()` (embedded capture date,
  media date, date in the file name by the patterns of the source, modification time, now) and its metadata from the core
  readers `wp_getimagesize()` / `wp_read_image_metadata()` and `AttachmentMetadata`.
- A file uploaded through WordPress keeps the date of the upload. Its metadata is the one core writes
  (`wp_generate_attachment_metadata()`), which already includes `image_meta.created_timestamp` for a JPEG with EXIF, but the
  **post date is not changed** from it.
- *Upload Media Exif Date* sets `post_date` / `post_date_gmt` of a new attachment from the EXIF date. Its code has not been read
  for this proposal; what is asked here is its **effect**, produced with the plugin's own functions.

## Proposal

1. **A filter on upload** that sets the date of a new attachment before it is saved. The hook is
   `wp_insert_attachment_data` (filter, receives `$data` and `$postarr`) limited to a new attachment (no `ID`), because the
   date must be in the row at insertion and the file is already in uploads at that time (`$postarr['file']` /
   `wp_handle_upload` result). If the file cannot be read at that point, fall back to the action `add_attachment` and a
   `wp_update_post()` of the two date fields only.
2. **The same functions as for registration**: the file is read with `wp_read_image_metadata()` (the EXIF / IPTC dates),
   video and audio with the media reader already used by `AttachmentRegistrar::readMetadata()`, then
   `AttachmentDate::resolve()` with the settings of the plugin. The shared steps (read the file, resolve the date) move into
   one method used by both the registrar and the upload filter, so the two cannot diverge. No second date algorithm.
3. **Which date model?** There is no source here, so the patterns of the file name (026) do not apply by default. Order for an
   upload: embedded capture date, media date, **no file name pattern** unless a setting asks for it, **the upload time**
   (not the modification time, which for an upload is the time of the temporary file). Setting: *"Date uploaded images from
   their metadata"*, on or off, default **off** so that an update changes nothing by surprise.
4. **Metadata**: nothing else is written by the filter. Core already stores the image metadata (EXIF fields, sizes). If the
   other plugin also copies EXIF fields into the title, caption or description, that is **not** reproduced here unless asked
   for (open question).
5. **Scope guards**: only a new attachment created by an upload (a file in uploads, MIME type of a media the plugin can read),
   never one registered by the plugin (it already carries its date and would be resolved twice), never an update of an
   existing attachment, never a `post_date` given explicitly by the caller (REST or code passing a date keeps it).
6. **Existing attachments**: not touched. A bulk *"Set date from metadata"* action on selected attachments is possible later
   and belongs to 026 (*Set date*), not here.

## Open questions

- **Does the plugin copy more than the date?** (title, caption, description, orientation). To be checked on its code or by
  testing on the target site before deciding the scope of point 4.
- **Time zone of EXIF dates**: EXIF has no zone; `wp_read_image_metadata()` reads it as UTC, so the stored local time may be
  shifted. `AttachmentDate::resolve()` already handles this for the registrar: the upload path must pass the same
  `wp_timezone()`; to be confirmed with the photos of the site.
- **Hook order against *Upload Media Exif Date*** if both are active: whichever runs last wins; the page of settings should
  say the plugin can be deactivated.
- **Large files and time**: reading a large video at upload adds work to the request. Proposal: read only images in the first
  delivery.

## Non-goals

- Changing the title, the slug or the file name of an upload.
- Dating attachments that already exist.
- A date model for uploads based on patterns (possible later, with a per-site pattern list).

## Acceptance criteria

1. With the setting on, a JPEG with an EXIF capture date uploaded in the media library has that date as its post date, equal to
   the date the registrar would give the same file.
2. A file without an embedded date gets the upload time, as today.
3. A file registered by the plugin, an update of an existing attachment and an upload with an explicit date are unchanged.
4. With the setting off, nothing changes.
5. The registrar and the upload filter call the same date function; a test fails if one of them stops doing so.

## Suggested steps

1. Extract the "read the file and resolve the date" step from `AttachmentRegistrar` into a shared method, with the existing tests
   unchanged.
2. The filter, the scope guards and the setting; unit tests with the shim for the guards and the date.
3. Try with real uploads (JPEG with and without EXIF, PNG, an explicit date) on the testing site, then decide whether the
   other plugin can be deactivated.
