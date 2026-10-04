<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Dates in WP Media Helper

This document describes how dates work **in the current version** (0.1.0, after
slice 022), where each one comes from, and why they do not always agree. It ends
with options under consideration, none of which is decided.

## Summary

Several different things are called "date". They are independent:

| Notion | What it is | Where it lives | Set by | Used for |
|--------|------------|----------------|--------|----------|
| **Panel date** | The calendar day the editor wants media for | Post meta `wp_media_helper_date` (per post), `Y-m-d`, no time, no time zone | The date control of the panel | Deciding **where to look**: the directory and the file name filter |
| **Directory and file name dates** | The date written in a folder layout or in file names | The file system | Whatever put the files there (Nextcloud client, WordPress upload, you) | Matched against the panel date through the path and filter patterns |
| **Attachment date** | The date of the media in the WordPress library | `post_date` and `post_date_gmt` of the attachment | The plugin, at registration (slice 022) | Sorting and date queries in the Media Library |
| **File dates** | Capture date (EXIF/IPTC), creation date of a video, modification time, a date inside the file name | In the files | The camera, the device, the file system | Inputs to the attachment date |

Two further dates exist but are not used by this plugin: the **publication date of
the article**, and the **date WordPress puts in its own upload folders**.

## 1. The panel date

- It is stored in the post meta `wp_media_helper_date` of posts and pages (type
  string, exposed in the REST API, writable by users who can edit the post). It
  is saved with the post, not immediately: changing it marks the post as modified.
- When the meta is empty, the control starts at the current day in the site time
  zone, computed when the editor loads.
- It is a pure calendar date. It says nothing about a time or a time zone.
- Every request carries it in the `filters` payload. The server accepts only a
  real `Y-m-d` date and otherwise falls back to the current day.
- It has **no link with the date of the article**: an article about a trip can be
  written weeks later and still list the photos of the day of the trip.
- Changing it returns the list to its first page.

## 2. From the panel date to a list of files

For each source, the panel date is turned into a directory and a filename filter
by two patterns:

- the **path pattern** gives the directory below the source root, for example
  `{date:Y}/{date:m}/{date:d}`;
- the **filter pattern** keeps the files whose name **contains** a string, for
  example `{date:Ymd}`.

`{date:...}` accepts any PHP date format, and `{source}` is the source id. An
empty path pattern means the whole source root, scanned recursively; an empty
filter keeps every file.

What this means in practice:

- **The files themselves are not examined.** Nothing reads their EXIF data, their
  modification time or their content to decide whether they belong to the
  requested day. The date only decides where to look and which names to keep. A
  source without path pattern but with the filter `{date:Ymd}` finds files by the
  date written in their names.
- The list can therefore contain files whose own date differs from the panel date
  (see section 4).
- Each listed item carries a `date` field, but it is **the requested panel date**,
  not a date of the file. The panel does not use it.
- The date structure of a response also has a `date_range`; it is always empty
  today.

### Index and freshness

The list of a source for a day is cached in a file named
`<source id>-<YYYYMMDD>.json` in the plugin's index folder. It is reused as long as
the **modification time of the resolved directory** is not newer than the cache.
That time changes when entries are added to or removed from that directory, but
not when a file appears in one of its sub-directories, and the scan is recursive.
The *Refresh* button forces a new scan, and an open panel re-checks every 30
seconds.

## 3. The attachment date

It is set once, when a file is registered in the Media Library (slice 022), in
this **provisional** order:

1. the image capture date: core's `created_timestamp`, read from the IPTC creation
   date, else from the EXIF `DateTimeDigitized` field (not `DateTimeOriginal`);
2. the creation date of a video or audio file, when the container has one;
3. a date written in the file name, such as `20261002_121549`, `2026-10-02` or
   `2026-10-02 12.15.49`. The time is read when the name has one; a date alone
   is given the **median time of the day, 12:00:00 site time**. Its date in GMT is
   then the same day for any site time zone within twelve hours of UTC (midnight
   would give the previous GMT day east of UTC), and it sorts in the middle of the
   day. East of UTC+12, such as New Zealand in summer, only the GMT date differs;
   the site date is always the one in the name;
4. the modification time of the file;
5. the current time.

Time zones: EXIF carries no time zone, and core stores the local wall-clock time as
if it were UTC. The plugin re-reads that value as **site time**. For a photo taken
at 12:15:49 local time on 2026-10-02 in Paris, the attachment date is
`2026-10-02 12:15:49` and its GMT form `2026-10-02 10:15:49`. File name dates are
also taken as site time, and modification times and video dates are real instants
converted to site time.

Other points:

- A file that already has an attachment keeps it, with its own date, whichever tool
  created it. The date is never updated afterwards.
- The source that provided the date is not recorded.
- The attachment also keeps the capture timestamp in its image metadata
  (`image_meta.created_timestamp`), in the "wall-clock stored as UTC" form of core,
  so it is **not** a true instant.
- GPX content, the date of the directory and the other possible sources are not
  used yet; see the list in [slice 022](../slices/022-wordpress-native-registration.md).
- The attachment date is independent of the panel date and of the folder the file
  is in. The Media Library sorts by it.

## 4. Why a date in a path can be misleading

A path pattern assumes that folders are organised by the date of the media. That
holds only when whatever created the folders did so.

| Layout | Who decides the folder | Date used |
|--------|------------------------|-----------|
| Nextcloud auto-upload into date folders | The Nextcloud client | According to its settings, normally the date of the photo. This is what the date patterns were designed for. |
| WordPress upload into `uploads/YYYY/MM` | WordPress (option *Organize my uploads into month- and year-based folders*) | **The date of the post the media is attached to** (except for a page), otherwise **the day of the upload**. Never the date of the photo. This was checked in the WordPress source. |
| WordPress upload without that option | Nobody | None: everything is in `uploads/` |
| Files registered in place by another tool | Wherever they were | None imposed |
| Your own organisation | You | Whatever you chose |

Consequences:

- A photo taken on 2026-09-30 and uploaded on 2026-10-02 through WordPress lands in
  `uploads/2026/10`. With the pattern `{date:Y}/{date:m}/{date:d}` it is **not**
  found for 2026-09-30 (the day it was taken) and is found, by a month pattern, for
  October. Its attachment date, set from the capture, will say 2026-09-30.
- With a pattern down to the day, the folder date and the panel date match only if
  the folder date is the capture date. Uploads made through WordPress are in the
  wrong folder for the purpose.
- Time zones add edge cases: the device or Nextcloud client may name folders and
  files in its own time zone, the panel date and the attachment date use the site
  time zone, so a photo taken near midnight can sit one day away.
- The same media can therefore have three different dates: the folder it is in,
  the date in its name, and the attachment date.

Practical rule today: use a date-based path pattern only for sources organised by
capture date. For sources that WordPress itself fills, use an empty path pattern
and a date filter on the file name, or accept that the list reflects the upload
month.

## 5. Known limitations of the current version

- No filtering on the real date of a file, only on folder and file names.
- The freshness check of the index ignores changes in sub-directories.
- The `date` of a listed item is the requested date, not the date of the file.
- The source of the attachment date is not recorded, and its order is not
  configurable.
- The panel date is stored per post, not per user.
- A date in a file name has a fixed set of recognised patterns, without a per-source
  pattern.

## 6. Options under consideration (not decided)

**A. Keep the panel date as "where to look", and say so.** Rename the control and
the settings so that a date means a folder or a file name, not the date of the
media, and document the patterns that match each layout. No code change beyond
wording.

**B. Index the real date of each file.** The persistent index already described in
the README would also hold each file's *effective date*, computed by the same rules
as the attachment date (capture date, name, modification time...). A day would then
select the files whose effective date is that day, wherever they are, which fixes
the WordPress upload case. The directory pattern becomes an optimisation to avoid
scanning everything. Costs: reading metadata at indexing time (EXIF is cheap, GPX
needs parsing), invalidation by modification time, and the time zone rules.

**C. A date range or a tolerance.** Select a day plus or minus a number of days, or
an interval. The response already has a `date_range` field, unused.

**D. A per-source "date semantics".** Each source declares whether its dates mean
*folder*, *file name* or *file date*, which also decides how the panel date is
applied to it.

### Details agreed so far

- **Name patterns read the time when present, and use 12:00:00 otherwise.**
  Implemented for the attachment date. The same rule will apply to the date used
  to select files, so that a file named with a date alone is placed at the middle
  of that day.
- Decided for the next slices ([025](../slices/025-database-file-index.md),
  [026](../slices/026-name-date-patterns-and-embedded-dates.md),
  [027](../slices/027-hidden-files.md)): separating the **discovery** of files (a scan, made
  incremental by remembering directories and their modification times) from the
  **selection** of a day (a query on an index). The index becomes a database table
  holding, per file, the candidate dates, an effective date and its source, and
  user metadata such as a hidden flag, which cannot live in the attachment meta of a
  file that is not imported. The path pattern becomes a hint saying which directories
  to check first. Default order of the effective date: a date forced by the user,
  the date in the name, the embedded date, the modification time. Hidden files are
  global to the site. Several name patterns can be set per source, with presets for
  common devices. The target library has about 5,000 directories and more than
  110,000 files, so the first scan must be resumable and the next ones incremental.
- Open: when the name gives a day without a time and the embedded metadata gives a
  time on the same day, whether to refine the median time with the embedded one
  (and keep 12:00:00 when the days differ, as for a video edited later).

### Questions

- Which semantics do you want by default for a source: folder, file name, or the
  real date of the file?
- Should the attachment date and the date used by the panel always come from the
  same rules, so that what the panel shows for a day is what the library sorts by?
- Is a tolerance or a range needed for photos taken around midnight?
- Should the source of the attachment date be recorded, so that it can be shown or
  recomputed?
