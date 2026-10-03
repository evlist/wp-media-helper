<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 022: WordPress-native registration and recognition

Status: **proposed** (design only, not implemented).

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
- **adoption.** Because Remove is limited to attachments created by this
  plugin, an attachment registered earlier by the replaced tool would become
  unmanageable. Proposed policy: when an existing attachment is recognised and
  its file is below an *enabled* source root, the plugin adds its provenance
  meta (`_wp_media_helper_source_id`, `_wp_media_helper_source_path`) and
  thereafter treats it as its own. Only meta is added; nothing else about the
  attachment changes. The alternative is an explicit "adopt" action.

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

*Remove* deletes only attachments **created by this plugin** (provenance meta
present). For any other attachment the plugin does not offer removal; it only
offers detaching from the current post. Removing an attachment never deletes
the original file: a `wp_delete_file` filter refuses any path inside a source
root, and the attachment's own thumbnails in the cache are deleted with it.
This closes weakness R6 of the [security audit](../IA/security-audit.md).

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

## Acceptance criteria

1. A registered file below uploads has a relative `_wp_attached_file`, a URL
   `guid`, and `width`, `height`, `filesize` and `image_meta` metadata.
2. Registering never creates or modifies a file.
3. Registering a file that already has an attachment, whatever its origin,
   reuses it and creates no duplicate.
4. The panel reports foreign attachments as *In WP media library*.
5. *Remove* is refused for attachments not created by this plugin, and never
   deletes an original file.
6. No attachment field or REST response contains a server path.

## Open questions

- Which behaviors of Bulk Media Register must be reproduced (title, dates,
  parent post, subdirectories, batch size), and which can be dropped?
- Adoption of recognised attachments: automatic when under an enabled source,
  or explicit?

- Should legacy absolute attachments be normalised in bulk (a one-off
  migration) or lazily, when touched? The plugin is at version 0.1.0, so few
  such attachments should exist.
- Should recognition also be offered for attachments whose file is below a
  source root but registered with a different case or Unicode normalisation of
  the path?
