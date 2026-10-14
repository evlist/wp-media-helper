<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 047: Redating existing attachments (tool and WP-CLI)

Status: **proposal**. Depends on the shared date step of [046](046-upload-date-from-metadata.md) (one function reads the file and
resolves the date, used by the registrar, by the upload filter and here). Related: [026](026-name-date-patterns-and-embedded-dates.md).
Companion: [048](048-redate-attachments-of-other-methods.md).

## Goal

Apply to attachments that **already exist** the date the plugin would give them today (embedded capture date, media date, upload
time), from an administration tool and from a WP-CLI command, with a preview first and a way to go back.

## What exists

- Nothing redates an attachment. Attachments made by other tools (Bulk Media Register, uploads before 046, imports by an earlier
  version) keep the date they had.
- The plugin has no WP-CLI command yet.
- The date model for a file of a source (patterns of the source, 026) needs the source; an arbitrary attachment has none.

## Proposal

1. **One service**, `AttachmentRedater`, with two methods: `plan( $attachmentIds, $options )` returns for each attachment the current
   date, the proposed date, its origin (`exif`, `media`, `filename`, `mtime`, `none`) and a status (`change`, `same`, `no_date`,
   `skipped` with the reason: file missing, unreadable, type not handled), and `apply( $plan )` writes the changes. It reads the file
   with the shared step of 046, never with a second algorithm.
2. **What it changes**: `post_date` and `post_date_gmt` of the attachment only (through `wp_update_post()` limited to these fields,
   so `modified` and the hooks stay consistent). Nothing else: file, metadata, parent, title.
3. **Selection** (the same for the tool and the command): all attachments, a MIME type or category (images), a date range of the
   current date, a parent post, the attachments registered by this plugin (or not), a list of IDs. Default: attachments whose
   proposed date **differs** by more than a tolerance (default 1 minute).
4. **Which dates win** (options, with the defaults of 046):
   - `--source=exif` (embedded capture date, then media date), the default;
   - `--use-filename` also tries the patterns of the global settings (026) when nothing is embedded;
   - `--use-mtime` falls back to the file modification time; off by default, because for an old import it is the import time;
   - never replace a date by "now": an attachment with no usable date is left alone and counted.
5. **Dry run first**: the tool opens on the plan (a table: thumbnail, title, current date, proposed date, origin, status, with
   filters and a sort on the difference) and writes nothing until *Apply* is pressed on the chosen rows. The command is a dry run
   unless `--yes` is given.
6. **Undo**: the previous dates are saved before writing (`_wp_media_helper_previous_date` post meta, one entry, with the
   run identifier) and a *Restore previous dates* action (command: `wp media-helper redate undo [--run=<id>]`) puts them back.
   The saved values are removed by the same action or after 90 days (cron), so they do not accumulate.
7. **Large libraries**: batches of 100 with a time budget in the tool (the browser drives the batches, each a short AJAX call,
   resumable) and a plain loop in the command; progress counter; no step loads all attachments in memory (paged `WP_Query` on IDs).
8. **WP-CLI** (`wp media-helper redate`):
   `wp media-helper redate [<id>…] [--mime=image] [--parent=<post>] [--from=<date>] [--to=<date>] [--plugin-only] [--source=exif] [--use-filename]
   [--use-mtime] [--tolerance=<seconds>] [--limit=<n>] [--format=table|csv|json] [--yes]`, and `redate undo`. Exit code non-zero when
   a file could not be read, with the IDs listed.
9. **Permissions**: the tool needs `manage_options` (it rewrites dates site-wide); the command runs as the shell user.

## Open questions

- **Time zone** of EXIF dates (see 046): the plan shows the date as it would be saved so a shifted batch is visible before it is applied.
- **Posts that display the date** (a gallery sorted by date, 043): redating reorders them, which is the point but is worth a line on
  the page.
- **Updating the index** of the plugin for attachments registered by it: their `post_date` is also in the index or the date model of
  the panel; to be checked, so a redated attachment does not disagree with its tile.
- **Revisions of the date** by another plugin on `wp_update_post`: the hooks run; accepted.

## Non-goals

- Changing a file's own dates (EXIF, modification time).
- Writing dates found by guessing from the post that contains the image.

## Acceptance criteria

1. A dry run lists the proposed changes and writes nothing.
2. *Apply* changes only `post_date` and `post_date_gmt` of the chosen rows, and a log line per attachment says old and new.
3. *Undo* restores the previous dates exactly, even after other redates.
4. An attachment without a usable date, with a missing file or of an unhandled type is listed with the reason and left alone.
5. The command and the tool give the same plan for the same selection, from the same service.
6. A library of 50,000 images can be redated without a timeout or memory error.

## Suggested steps

1. Service `plan` / `apply` with tests for the date origins, the tolerance, the previous-date meta and the undo.
2. WP-CLI command (list, dry run, apply, undo).
3. Administration tool (selection, plan table, batches, undo).
