<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 049: Pause the background work, and remap paths

Status: **implemented** (parts 1 and 2; tried only with stubs of WordPress and an in-memory database, never on a real site). Related: [036](036-source-lifecycle-and-missing-files.md) (sources that disappear), [037](037-eligibility-reconciliation.md),
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

## Implemented

- **Pause**: `Index\Pause` (option `wp_media_helper_index_paused`, constant `WP_MEDIA_HELPER_PAUSED`), honored by the cron hooks, `IndexManager`
  (background run, due passes, requested passes, quick scans for the panel) and the settings page (banner, *Paused* state, disabled
  buttons). `DayIndex::forDay()` answers with the reason `index-paused` and does not ask the panel to wait. `wp media-helper
  pause | resume | status`. A request made during the pause is kept and served after it. The pause is not cleared by a reset.
- **Remap**: `wp media-helper paths remap` (`Cli\PathsCommand`), on `Maintenance\PathMap` (prefix mapping, longest first, whole
  directories only, no `..`, no absolute path) and `Maintenance\PathRemapper`:
  - attachments found by `_wp_attached_file`: rewrites it, `file` in the metadata, the provenance metas (`source_path`, and `source_id`
    for those registered by this plugin) and the guid (only when it is the address of the old path). An attachment whose file is not at
    the new path is left alone and reported; so is one in collision with another attachment already on the new path;
  - the index rows rewritten in place (new `source_id`, `path`, `path_hash`; ids, trash, dates by hand and stored `mtime` kept); the
    top directory of an old source is hung under a chain of joining directories created with no `mtime` when several old sources
    become one; a row already at the new path is reported and left alone;
  - the thumbnails of the old directories moved inside the cache (`--no-cache` to skip);
  - the contents with an old address are counted and the `wp search-replace` lines printed, nothing edited.
- **Differences from the proposal above**: the command **requires** the indexing to be paused (it does not pause by itself) before `--yes`;
  there is **no lock on the panel writes** while it runs (run it when nobody edits); the **undo** is the same command with the inverse
  mapping recorded for each run (`--undo=<run>`, the ten last runs are kept in the option `wp_media_helper_remap_runs`), so the old mount
  must still be there and the old source id is the one found in the index under the old prefix (or `--from-source`).
- The first scan after the pause is a full pass of the new source (it has no scan state): it reads every directory again and
  recomputes the dates of the index with the patterns of the **new** source.

## Commands for scripts (added for the rollout)

All are rerunnable and say so when there is nothing to do; `--dry-run` changes nothing.

- `wp media-helper status [--format=json]`: `paused` or `running` (json: `{"state":"paused","by_constant":false}`).
- `wp media-helper source list [--format=table|json|csv|yaml]`: rank, id, name, root (relative to uploads), state, path pattern, name patterns, mtime fallback, and the number of attachments registered through each source.
- `wp media-helper source add --root=<root> [--name] [--before=<id|root|name>] [--path-pattern] [--name-patterns='a|b'] [--no-mtime-fallback] [--dry-run] [--porcelain]`: same checks as the settings page (root inside the allowed base, patterns, unique names, overlaps). A source that already has this root is not added again. `--porcelain` prints only the id (added or already there).
- `wp media-helper source exclude <id|root|name>` / `source include <...>` / `source remove <...>`: `remove` refuses while attachments are registered through the source.
- `wp media-helper paths remap ... --format=json`: one object with `applied`, `undo`, `run`, `attachments`, `index`, `cache`, `urls`, `problems`; the exit code is 1 when `problems` is not 0 (attachments missing or in collision, index rows in collision).
- `wp media-helper index dates [<path>...] [--attachment=<ids>] [--source=<id> --limit=<n>] [--format=table|json|csv]`: the dates the index holds (effective date and its origin, date of the name, embedded date, date set by hand, trashed). By attachment ID the path follows the remap, so the dates can be compared before and after.

## Speed of `paths remap` (fix after the first rehearsal)

The first dry run on a large site was slow. Causes found by reading the code, not measured: a query by path for **each** attachment to
detect a collision (the value of `_wp_attached_file` has no index, so each query read every such row of the site: the time grew with
the square of the number of attachments), one query by attachment for the provenance meta, and six scans of the posts and meta tables to
count old addresses (one per prefix). Now: the paths already taken below each new prefix are read once and kept in memory, the provenance
flag comes in the same query as the attachments, and the old addresses are counted in one pass per table. `--no-urls` skips that count
(the `wp search-replace` lines are still printed; the json has `urls.counted: false`). The progress of the long steps is written to the
error output (attachments checked every 5,000, index rows every 10,000), so the answer on the standard output stays alone.

## WP-CLI and the `--no-xxx` options (fix after the second rehearsal)

WP-CLI reads `--no-xxx` as the negation of a flag `xxx` (`$assoc['xxx'] = false`). The first versions declared `[--no-urls]`, `[--no-cache]` and
`[--no-mtime-fallback]` and read `$assoc['no-urls']`: WP-CLI refused `--no-urls` as an unknown parameter before the command ran, and the
flags could never have been seen. They are now declared in the positive (`[--urls]`, `[--cache]`, `[--mtime-fallback]`, on by default) and
read with `\WP_CLI\Utils\get_flag_value( $assoc, 'urls', true )`; the user still writes `--no-urls`. `CliOptionsTest` checks on the source
that no command declares or reads a `no-xxx` option and that every option read is declared; it does not load WP-CLI, so the first run on
a real site remains the real test of the parsing.

## Fix after the first real run on staging: collisions the dry run did not foresee

Real run on a copy of production, indexing paused, nothing scanned before: the dry run said 68,978 files, 1,608 directories, 0 collisions; the real run moved 38,783 files and 936 directories and reported
30,867 collisions (30,195 files, 672 directories: the totals add up exactly, 68,978 = 38,783 + 30,195 and 1,608 = 936 + 672) and exited with 1. The attachments had moved all the same (34,226).

**Cause (deduced from the numbers, not seen in that database).** The dry run looks for a row already at the new path and found none, so no row was there before the run. Every collision was therefore between two rows
of the **old** tree that go to the same new path in the same run: the index can hold the same path under several sources (a source that was reordered, removed or added later leaves stale rows, marked missing, for
thirty days, so the same file is held by `eric-2026` and by `uploads`, say). The rewrite gives every row the new source, so the second row of a path collided with the first. A dry run does not write, so the first row had
not "moved" and nothing collided: the dry run did not simulate its own moves.

**Fix.**
- The dry run and the real run now follow the same pass: the rows already sent to a path in this pass are remembered (`claimed`), so the two find the same collisions.
- Rows of the same path are **merged**, not refused: the one with data of a person (trashed, a date set by hand) stays, else the one that is not marked missing, else the first; the others are dropped (`index.merged`), and the rows
  below a directory that is dropped are given to the one that stays. Only two rows that **both** hold data of a person are a collision.
- A real run (`--yes`) **plans the whole index first**, with no write, and goes on only when there is no collision (`refused: true` otherwise, nothing changed, not even the attachments): it can no longer leave the attachments
  moved and the index half rewritten. A refused run records nothing to undo.
- The report says which sources hold the rows under each prefix (`index.sources`): a prefix held by several sources is the sign of stale duplicates. The joining directories are counted once in a dry run (11 became 2).
- The old source kept for an undo is now the one that holds most live rows under the prefix (before: none when two sources held rows, so an undo gave the rows back the new source).

**Undo after a run that had collisions (the run before this fix).** It is not complete: the attachments, the cache and the guid come back, but the rows of the index that were moved are given back to the **new** source (the old one
was not recorded), the rows that had collided were never moved, and the joining directories stay. It was not tried. After such a run, the clean way back is the database as it was (or a new synchronisation), then a new run.
After a run of the fixed version, an undo restores the attachments exactly and the index approximately (the dropped duplicates are not put back: they are rebuilt by a re-scan, which an undo should be followed by).

**See before a run what would collide (read-only).** The dry run now says it (`index.merged`, `index.collisions`, `index.sources`). The same by SQL, to compare:
`SELECT source_id, COUNT(*) AS n, SUM(missing_since IS NOT NULL) AS missing FROM wp_media_helper_files WHERE path >= 'photos/' AND path < 'photos0' GROUP BY source_id;` (rows by source under the old roots),
`SELECT COUNT(*) FROM (SELECT path_hash FROM wp_media_helper_files WHERE path >= 'photos/' AND path < 'photos0' GROUP BY path_hash HAVING COUNT(*) > 1) d;` (paths held more than once),
`SELECT source_id, COUNT(*) FROM wp_media_helper_files WHERE path >= 'cumulus/' AND path < 'cumulus0' GROUP BY source_id;` (rows already under the new roots).
