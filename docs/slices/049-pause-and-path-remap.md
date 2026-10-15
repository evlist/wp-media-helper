<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 049: Pause the background work, and remap paths

Status: **part 1 (pause) implemented**, part 2 (remap) to do. Related: [036](036-source-lifecycle-and-missing-files.md) (sources that disappear), [037](037-eligibility-reconciliation.md),
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
2. While paused, **only the indexing stops**: the cron hooks (`wp_media_helper_index_run`, maintenance) do nothing and do not
   reschedule, the *Re-scan now* buttons are disabled with the reason, a pass in progress ends at the next batch, and a banner says
   so. The panel, the imports, the trash, the gallery block and the thumbnails keep working on the index as it is (they update
   their own rows). The command of part 2 additionally holds a short lock that makes the panel refuse writes while it runs.
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
4. **Index, rewritten in place** (not rescanned): the rows of `files` and `directories` of the old sources get their new
   `source_id`, `path` and `path_hash` (same key function as the scanner, `KeyMapper`), so their ids, trashed flag
   (`hidden*`), `date_override`, computed dates and `first_seen` stay as they are, and the index agrees with the posts table as soon
   as the command ends, **before any scan**. When several old sources fold into one new source, the tree is joined: a row for the
   new root and for each intermediate directory (`2024`, `2025`, …) is created with no stored `mtime`, so the first scan reads their
   listing and finds what is new (other folders of the new mount), while the rewritten directories keep their `mtime` and are
   not read again. Rows without a mapping are left alone. A consistency check at the end (every rewritten attachment has an index
   row under its new path, and the reverse for registered files) is part of the report.
5. **Thumbnail cache**: move `uploads/thumbnails/<old dir>` to `<new dir>` (a rename inside the cache, confined to it, no links), or
   skip it with `--no-cache` and let the files be made again on demand.
6. **Content URLs**: the command does not edit posts. It **reports** how many posts contain the old URL (`post_content`,
   `meta_value`), and prints the matching `wp search-replace` line (serialization-safe), to be run on purpose.
7. **Report and undo**: a table (attachments moved, missing at the new path, collisions, flags carried, cache moved, posts with old
   URLs) and a run identifier; `wp media-helper paths remap --undo=<run>` restores the old values.
8. **Procedure with no downtime** (in the documentation): back up and count the old URLs in the contents; pause the indexing; add the
   new sources before *Uploads*; mount the new directories **in addition** to the old ones; dry run; apply; set the old sources to
   *Excluded*; **test on the stable index** (nothing is scanned meanwhile); resume the indexing; remove the old mounts, then the old
   sources.

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

1. While paused, no cron pass runs and no re-scan can be asked; the panel works on the index as it is; resuming catches the index up.
2. A dry run changes nothing and lists, per mapping, the attachments, the missing files and the collisions.
3. After a remap, **before any scan**, the attachments keep their IDs, metadata, parents and methods, and the panel shows them as already registered under the new sources.
4. An attachment whose file is absent at the new path is not modified.
5. Trashed files and dates set by hand survive the remap.
6. Undo restores the paths, the metas and the guid.
