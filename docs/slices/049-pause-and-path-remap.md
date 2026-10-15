<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 049: Pause the background work, and remap paths

Status: **proposal**. Related: [036](036-source-lifecycle-and-missing-files.md) (sources that disappear), [037](037-eligibility-reconciliation.md),
[023](023-thumbnails-in-cache.md) (cache layout), [044](044-image-sizes.md) (orphan cleanup).

## Goal

Move the files of a site to other directories (new Docker mounts, renamed folders) **without losing the attachments**: keep the
IDs and change only the paths in the database, with the plugin held still while it happens.

## What exists

- An attachment of a source is found by its `_wp_attached_file` (path relative to uploads), checked against the files of the index
  (`AttachmentRegistry`). The provenance metas `_wp_media_helper_source_id` and `_wp_media_helper_source_path` repeat it, the
  metadata array holds it in `file`, the guid in a URL.
- The index (tables `files`, `directories`) is keyed by source and path, and holds data that exists nowhere else: the trashed
  flag (`hidden`, `hidden_by`, `hidden_at`) and the date set by hand (`date_override`).
- The thumbnail cache is rooted at the path of the original: `uploads/thumbnails/<dir of the original>/<name>-WxH.ext`.
- The index is filled by WP-Cron (`wp_media_helper_index_run`, hourly maintenance) and by the *Re-scan now* buttons. Nothing
  registers a file by itself: a file becomes an attachment only through a panel action.
- There is no way to stop the cron work, apart from excluding sources. A source set to *Excluded* is not listed and no other
  source lists its files (the workaround for today: put the new directory first as an Excluded source until the move).

## Part 1: Pause

1. A **global pause**, stored in an option and shown on the settings page (a notice at the top while it is on), with
   `wp media-helper pause` / `resume` / `status`. A constant `WP_MEDIA_HELPER_PAUSED` forces it for code-controlled sites.
2. While paused:
   - the cron hooks (`wp_media_helper_index_run`, maintenance) do nothing and do not reschedule; the *Re-scan now* buttons are
     disabled with the reason; a pass in progress ends at the next batch;
   - the editor panel keeps **listing** from the index as it is, with a banner, but refuses the actions that write (import,
     attach, detach, trash, restore) with a message; reading, attaching natively through WordPress and the gallery block keep
     working, because they do not depend on the index being current;
   - thumbnails already in the cache are served; new ones are not made by the background job, only on demand for a visitor.
3. Resuming asks for a pass of every source (`requestDuePasses`), so the index catches up.
4. The pause survives restarts, and is **not** cleared by *Reset* (a reset while paused stays paused).

## Part 2: Remap

Command: `wp media-helper paths remap --from=<old prefix> --to=<new prefix> [--source=<new source id>] [--dry-run|--yes]`, repeated
once per mapping, or `--map=<file>` (lines `old new source`).

1. **Preconditions**: the plugin is paused (the command pauses it, and restores the previous state at the end); the new
   directories exist and are readable; the new sources are configured. Checked, with a message that names what is missing.
2. **For each attachment** whose `_wp_attached_file` starts with the old prefix: compute the new path; **check that the file exists at
   the new path** (otherwise the attachment is reported and left alone, never rewritten into a dead path); refuse a collision with
   another attachment already on the new path.
3. **Rewrite** `_wp_attached_file`, `file` in `_wp_attachment_metadata` (through the WordPress functions, never SQL on serialized
   data), `_wp_media_helper_source_path`, `_wp_media_helper_source_id` (the new source) and the guid, in one pass per attachment with
   the old values kept for the undo.
4. **Index**: carry the trashed flag and the date override of each old index row to the row of the new path (the new rows exist after
   a scan of the new sources, so the command scans them first, then copies, then lets the old rows be forgotten with their source).
5. **Thumbnail cache**: move `uploads/thumbnails/<old dir>` to `<new dir>` (a rename inside the cache, confined to it, no links), or
   skip it with `--no-cache` and let the files be made again on demand.
6. **Content URLs**: the command does not edit posts. It **reports** how many posts contain the old URL (`post_content`,
   `meta_value`), and prints the matching `wp search-replace` line (serialization-safe), to be run on purpose.
7. **Report and undo**: a table (attachments moved, missing at the new path, collisions, flags carried, cache moved, posts with old
   URLs) and a run identifier; `wp media-helper paths remap --undo=<run>` restores the old values.
8. **Procedure with no downtime** (in the documentation): back up; mount the new directories **in addition** to the old ones; add the new
   sources before *Uploads*; pause; dry run; apply; resume and scan; check there are no attachments without file and no registered
   file shown as importable; remove the old mounts and sources.

## Open questions

- Several old prefixes can map to one new prefix (the years folded into one folder), so the mapping is a list of prefixes, longest
  first, not a pattern.
- Attachments of **other** plugins pointing into the same directories (Bulk Media Register records) are rewritten too, as they are
  attachments with the same meta; the report says how many were not registered by this plugin.
- The date patterns of the old sources (the year in the folder name) may not apply to the new layout: to check per source.

## Non-goals

- Moving or copying the files themselves (done by the mounts).
- Rewriting post content.

## Acceptance criteria

1. While paused, no cron pass runs and the panel does not write; resuming catches the index up.
2. A dry run changes nothing and lists, per mapping, the attachments, the missing files and the collisions.
3. After a remap the attachments keep their IDs, metadata, parents and methods, and are shown as already registered under the new sources.
4. An attachment whose file is absent at the new path is not modified.
5. Trashed files and dates set by hand survive the remap.
6. Undo restores the paths, the metas and the guid.
