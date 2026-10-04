<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 025: Database file index and incremental discovery

Status: **implemented**. Followed by
[slice 026](026-name-date-patterns-and-embedded-dates.md) and
[slice 027](027-hidden-files.md). Builds on
[slice 024](024-source-priority-and-ownership.md) for ownership.

## Goal

Separate **discovering** files from **selecting** the files of a day. Discovery
fills a persistent index, cheaply and incrementally. Selection is a query on that
index by date. The date of a file no longer depends on the folder it sits in, and
the index can later carry user metadata for files that are not imported.

## Background

- Today the list for a day comes from scanning one directory, resolved from the
  panel date by the path pattern, and keeping the names that contain a string
  (see [Dates in WP Media Helper](../IA/date-model.md)). The path pattern was
  introduced to avoid walking the whole tree.
- The result is cached in JSON files, one per source and day, and reused while the
  modification time of that directory is unchanged. A file added in a
  sub-directory is missed.
- The JSON files sit in the uploads directory and are a public-exposure risk on
  some servers (R2 of the [security audit](../IA/security-audit.md)).
- A hidden flag, a date set by the user or other notes on a file that is not
  imported cannot be stored in attachment meta, since there is no attachment.

Expected scale, from the target site: about **5,000 directories and more than
110,000 files**, only part of which is mounted today. All figures below are
estimates to be measured.

## The index

Two tables, with the site's prefix (`$wpdb->prefix`, so one set per site on a
multisite network), created with `dbDelta()` and versioned by an option.

### `…media_helper_dirs`

| Column | Purpose |
|--------|---------|
| `id` | Primary key |
| `path_hash` (unique), `path` | Directory, relative to the uploads directory. The hash is the key, because path columns are case-insensitive in MySQL |
| `parent_id` | Walk the tree without reading the disk |
| `mtime` | Modification time seen at the last scan |
| `last_scanned`, `last_seen`, `missing_since` | Scheduling and clean-up |

### `…media_helper_files`

| Column | Purpose |
|--------|---------|
| `id` | Primary key |
| `path_hash` (unique), `path` | File, relative to the uploads directory (the same key as `_wp_attached_file`, so a file is matched with its attachment exactly) |
| `dir_id`, `source_id` | Directory, and **owner** source, recomputed when the order or states of sources change (slice 024) |
| `name`, `ext`, `kind` | Basename, extension, and category (image, video, other) for the filename and type filters |
| `size`, `mtime` | Fingerprint: a change triggers a new read of the dates |
| `name_date`, `name_date_precision` | Date read from the name (slice 026 adds several patterns); precision `day` or `second` |
| `embedded_date`, `embedded_state` | Date read from the content, and whether it was read, absent or not applicable (slice 026) |
| `date_override` | Date set by the user (slice 026) |
| `effective_date`, `effective_day`, `date_source` | Result of the rule below, the day it falls on (indexed), and which source produced it |
| `hidden`, `hidden_by`, `hidden_at` | User metadata (slice 027) |
| `first_seen`, `last_seen`, `missing_since` | Life cycle |

Indexes: unique `path_hash`; `(effective_day, hidden)`; `(source_id, effective_day)`;
`dir_id`; `last_seen`. Columns written by a scan never overwrite user columns
(`hidden`, `date_override`).

The table is a **cache and an annotation store, never a source of authority** for
security: every action still resolves and confines the real path (slices 021 and
024), and a file that disappeared is simply marked missing.

## Effective date

One rule, shared by the panel and by registration so that what the panel shows for
a day is what the library sorts by:

1. a date forced by the user (slice 026);
2. the date in the file name;
3. the date embedded in the file (slice 026);
4. the modification time of the file.

A date found without a time takes the median time of the day, 12:00:00 site time
(see the date model). In this slice, steps 1 and 3 are absent: the date comes from
the name, else from the modification time. `AttachmentDate` adopts the shared rule
and this order, with the capture date joining at step 3 in slice 026.

A file with no date in its name now falls back to its modification time, where the
filter pattern used to leave it out. This can show files that were not listed
before. **Decided: the fallback is on by default.** A per-source setting "use the
modification time when the name has no date" lets a source opt out; a file with no
usable date is then not placed on any day. Because a modification time can be
changed by a copy or a synchronisation, slice 026 shows which source gave each date
and lets the user set one.

## Discovery

Walk the tree of each source, skipping what other sources own and the built-in
exclusions (slice 024), and keep the tables up to date.

- **Incremental.** For a known directory whose modification time is unchanged, the
  disk entries are not read: its children come from the table and only their
  modification times are checked. A changed directory is read, files are inserted
  or updated, missing ones are marked, new sub-directories are queued. A new file in
  a sub-directory changes the time of that sub-directory, so it is found. The
  cost of a run with no change is about one `stat` per directory (about 5,000
  here).
- **Not authoritative.** A directory time is a hint (as the project constraints
  say): some file systems do not update it, and nothing detects an edit made in
  place. A **full re-read** of every directory runs on a schedule (WordPress cron,
  weekly by default) and on demand.
- **Resumable batches.** The first scan of 110,000 files must not run in one
  request. A queue of directories is processed within a time and size budget (for
  example 15 seconds per run), then continues by a single scheduled event, with
  progress visible in the settings page. A lock per source prevents two runs.
- **Hints from the path pattern.** When the panel asks for a day, the directories
  that the source's path pattern resolves for that day (and the days around it) are
  checked **synchronously**, within a small budget, so that files just added to
  today's folder appear at once. The path pattern therefore becomes a *hint* about
  where new files are likely to be, and no longer defines what "a day" means. It
  stays optional; if measurements show the incremental walk is fast enough, it can
  be dropped.
- **Disappeared files.** Marked missing, then deleted after a retention period
  (30 days by default). User columns are kept while the row exists.

## Selection

The panel's list for a day is a query: files whose `effective_day` is the panel
date, not missing, owned by the selected sources, not hidden (slice 027). A day is
a small set (tens to hundreds of rows), so the attachment scope, type and filename
filters and the pagination stay in PHP, as today, applied to that set. The
attachment state is looked up for those rows only.

## Migration and settings

- The plugin is at version 0.1.0: the JSON index files and their folder are
  deleted, and nothing else is migrated.
- A table version option drives `dbDelta()`, and uninstall removes the tables (to
  be confirmed).
- `filter_pattern` is read as a name date pattern: a value made of `{date:…}`
  placeholders and literals is converted, so `{date:Ymd}` means "the name contains
  a date written as `Ymd`". Slice 026 replaces it with a list of patterns.
- The settings page describes the path pattern as a hint and shows the scan
  progress, a *Re-scan now* button and the last full scan.

## Security

- Queries use `$wpdb->prepare()`; paths are stored relative to uploads, so the
  index holds no server path and nothing is served from a file. R2 of the audit
  disappears with the JSON files.
- Capabilities and nonces of the endpoints are unchanged; confinement of real paths
  is not delegated to the table.
- Scan work is bounded by a budget and a lock, which also bounds forced refreshes
  by authenticated users (R4 and R5 of the audit still apply to who may trigger a
  scan).

## Sizing and measurement

On the target figures the file table would hold about 110,000 rows, tens of
megabytes with its indexes (an estimate). The cost of the first scan depends on the
disk and, for network mounts, on latency. Acceptance includes a benchmark on a
synthetic tree of 5,000 directories and 110,000 files, generated by a script like
the fixture script of the development container, measuring: first scan time and
the number of runs it needs, a no-change refresh, a refresh after adding files in
a deep directory, and the response time of a day's list.

## Implementation notes

Code in `plugin/includes/WP_Media_Helper/Index/` and
`MediaSource/{NamePattern,FileDates}.php`:

- `Schema` creates the tables with `dbDelta()` (versioned by an option checked on
  each load) and deletes the JSON files of the previous index.
- `IndexStore` and `WpdbIndexStore`: the only SQL, plain and portable, values
  always prepared, and the columns of the user never written by a scan.
- `IndexScanner`: the incremental, resumable walk (below). `ScanBudget` limits a run
  in time and in directories.
- `IndexManager` and `ScanState`: when passes start, pending requests, the
  settings the stored dates depend on (a change triggers a full pass; a change of
  root clears the source), forgetting removed sources, retention.
- `DayIndex`: the list of a day, with the hints; `MediaPanelState` uses it.
- `Cron`: the hooks run by WordPress cron, with a lock.
- `NamePattern` and `FileDates`: the effective-date rule; `AttachmentDate` uses it.

Choices made while implementing:

- **Rows are per source** (unique on source and path). Two sources whose roots
  overlap used to each index the file; slice 024 now keeps the owned trees out of the
  scan and forces a full pass when the exclusions change (they are part of the
  configuration hash).
- **A directory read less than two seconds after it changed is stored without a
  time**, so the next pass reads it again: a file added in the same second as the
  scan would otherwise be missed until the next full pass.
- **Hints cover the day before, the day and the day after**, to catch folders named
  in another time zone. A source without path pattern is scanned from its root only
  until its first pass has finished.
- **Dot files and `Thumbs.db` / `desktop.ini` are skipped.** Without this, the
  modification-time fallback would list them.
- **Files that WordPress cannot register are still listed** (the panel already
  shows other types); the type filter applies.
- A full pass recomputes every date; an incremental pass only touches changed
  directories, so a changed pattern or fallback setting triggers a full pass.
- The *Refresh* button reads the hinted directories again and asks for an
  incremental pass of the whole source in the background.
- **Fixed while doing this:** unchecking *Enabled* in the settings form had no effect
  (an unchecked box sends nothing and the code read that as enabled). The form now
  sends a hidden `0` first.
- **Not done:** no `uninstall.php` yet (see the open question). The tables stay
  after the plugin is deleted.

## Measurements

`tests/benchmark/index-benchmark.php` builds a synthetic tree (default: 5,000
directories, 110,000 files, year/month/day) and measures the index. Results on
in-memory SQLite, which is a lower bound for time on a MySQL server (the number
of SQL queries is the portable figure):

| Measure | Result |
|---------|--------|
| First scan, 5,180 directories and 110,000 files | 9 s in 6 runs of 1,000 directories; 36,000 queries |
| Pass with no change | 2 s; 0 directories read, 5,180 unchanged; 10,600 queries (two per directory) |
| After adding 5 files in a deep directory | 1 directory read, 5 files added; same cost as the pass above |
| Hint scan of 3 directories | 8 ms, 32 queries |
| List of one day (22 files) | 0.3 ms, 1 query |
| Full pass | 5 s; 5,180 directories read; 31,000 queries |
| Memory | about 2 MB |

On MySQL, expect a no-change pass of about ten thousand queries to take a few
seconds to a few tens of seconds depending on latency; this is the cost of the
periodic pass and is paid in the background, in runs bounded by the time budget. It
has not been measured on MySQL, nor on a network file system.

## Non-goals

- Reading embedded dates, several name patterns and a user date (slice 026).
- Hiding files (slice 027).
- Searching across days or full-text search.
- Watching the file system for events.

## Acceptance criteria

1. The list of a day comes from the index, and its dates follow the effective-date
   rule.
2. An unchanged tree is refreshed with about one `stat` per directory.
3. A file added in a sub-directory appears after the next run, and at once when
   the sub-directory is one the path pattern points to for the requested day.
4. The first scan is split in resumable runs within the budget, with visible
   progress, and never runs twice at the same time.
5. A full re-read happens on a schedule and on demand.
6. No JSON index file remains and the index holds no server path.
7. The attachment date uses the same rule and order.
8. The benchmark above is documented, with its results (done, on SQLite).

## Open questions

- Decided: retention of missing files is 30 days; the day's neighbours are
  included in the hint; the scan budget is 15 seconds per run; incremental passes
  every 30 minutes and a full pass weekly (constants of `IndexManager` and `Cron`).
- Whether uninstall removes the tables. It is left out for now because the index
  will also hold user data (slice 027), which must not be dropped silently.
- Reducing the queries of a pass with no change further (a single query per
  directory would halve them).
- Measuring on MySQL and on a network mount.
