<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Dates in WP Media Helper

This document describes how dates work **in the current version** (0.1.0, after
slices 022 and 025), where each one comes from, and why they do not always agree.
It ends with options and decisions for the next slices. Slice 025 changed how a
day selects its files: the earlier behavior, in which the path and filter patterns
alone selected the files, is gone.

## Summary

Several different things are called "date". They are independent:

| Notion | What it is | Where it lives | Set by | Used for |
|--------|------------|----------------|--------|----------|
| **Panel date** | The calendar day the editor wants media for | Post meta `wp_media_helper_date` (per post), `Y-m-d`, no time, no time zone | The date control of the panel | Selecting the files whose **effective date** falls on that day |
| **Effective date of a file** | The date the index gives a file: the date in its name, else its modification time | Index (`effective_date`, `effective_day`, `date_source`) | The plugin, when it indexes the file (slice 025) | Listing a file under a day |
| **Directory dates** | The date written in a folder layout | The file system | Whatever put the files there (Nextcloud client, WordPress upload, you) | Only as a **hint** of where to look first, through the path pattern |
| **Attachment date** | The date of the media in the WordPress library | `post_date` and `post_date_gmt` of the attachment | The plugin, at registration (slice 022) | Sorting and date queries in the Media Library |
| **File dates** | Capture date (EXIF/IPTC), creation date of a video, modification time, a date inside the file name | In the files | The camera, the device, the file system | Inputs to the attachment date |

Two further dates exist but are not used by this plugin: the **publication date of
the article**, and the **date WordPress puts in its own upload folders**.

## 1. The panel date

- It is stored in the post meta `wp_media_helper_date` of posts and pages (type
  string, exposed in the REST API, writable by users who can edit the post). It
  is saved with the post, not immediately: changing it marks the post as modified.
- When the meta is empty, the control starts at **the day the post was published**
  for a post that is published, scheduled or private (an existing article one comes
  back to), and at the current day in the site time zone for a draft. That day is
  only displayed: it is not saved as a choice, so it follows the publication date
  until the editor picks a date.
- It is a pure calendar date. It says nothing about a time or a time zone.
- Every request carries it in the `filters` payload. The server accepts only a
  real `Y-m-d` date and otherwise falls back to the current day.
- It has **no link with the date of the article**: an article about a trip can be
  written weeks later and still list the photos of the day of the trip.
- Changing it starts the list again from its first lot.

## 2. From the panel date to a list of files

Each file of a source is **indexed** with an effective date (slice 025), and a day
is a query on that index: the files whose effective date falls on the panel day.

The effective date of a file is, in this order:

1. a date written in its **name**: first the source's *name date pattern* (for
   example `{date:Ymd}`, `IMG_{date:Ymd}_{date:His}`), then the generic forms
   (`20261002_121549`, `2026-10-02`, `2026-10-02 12.15.49`). The time is read when
   there is one; a date alone is placed at 12:00:00 site time, unless the file itself
   (the capture date of a photo) gives a time on that same day, which then replaces the
   median time (a capture on another day changes nothing: the name states the day);
2. for a **photo** (JPEG, TIFF) whose name has no date, its **capture date** (IPTC
   creation date, else EXIF), read once when the file is indexed and read again
   only if its size or time changed (slice 026). EXIF has no time zone, so the
   camera's clock time is taken as site time;
3. the **modification time** of the file, unless the source turns this fallback off,
   in which case the file is placed on no day.

Dates embedded in videos, audio and GPX files, and dates set by the user, are not
used yet. The dates are site time.

What this means in practice:

- **The folder a file is in no longer decides its day.** A file is listed under the
  day of its effective date wherever it sits in the source.
- The **path pattern** is only a hint, saying where new files of a day are likely to
  be. The directories it resolves for the requested day, the day before and the day
  after are checked at once, briefly, so that a file just added to today's folder
  appears immediately. A source without a path pattern is scanned from its root until
  its first pass has finished.
- The list can still contain files whose capture date differs from the panel date,
  because videos and GPX files have no embedded date read yet (see section 4).
- Each listed item carries a `date` field, but it is **the requested panel date**,
  not the date of the file. The panel does not use it.
- The date structure of a response also has a `date_range`; it is always empty
  today.

### Index and freshness

The index holds, per file, the dates found, the effective date and which source gave
it. It is updated by passes over the tree, run in the background:

- an **incremental** pass does not read a directory whose modification time has not
  changed, so a pass over an unchanged tree costs about one `stat` per directory, and
  a file added in a sub-directory is found because that directory changed;
- a **full** pass re-reads every directory (weekly, and on demand with *Re-scan
  now*), because a directory time is only a hint: some file systems do not update
  it, and an edit in place does not change it;
- the first scan of a source is split in runs of 15 seconds, resumes by itself, and
  until it has finished once the panel warns that the list may be incomplete;
- changing the name pattern or the modification-time setting of a source triggers a
  full pass, so every stored date is recomputed.

The *Refresh* button reads the hinted directories again and asks for an incremental
pass over the whole source; an open panel re-checks every 30 seconds.

## 3. The attachment date

It is set once, when a file is registered in the Media Library (slice 022). Since
slice 025 it follows the same rule as the index for the name and the modification
time, and the date in the **name comes first**, in this order:

1. a date written in the file name, such as `20261002_121549`, `2026-10-02` or
   `2026-10-02 12.15.49` (the source's name date pattern first). It is the user's own
   statement of the day, which also covers files without metadata and videos
   assembled by a tool that writes unrelated metadata. The time is read when the name has one; a date alone
   is given the **median time of the day, 12:00:00 site time**. Its date in GMT is
   then the same day for any site time zone within twelve hours of UTC (midnight
   would give the previous GMT day east of UTC), and it sorts in the middle of the
   day. East of UTC+12, such as New Zealand in summer, only the GMT date differs;
   the site date is always the one in the name;
2. the image capture date: core's `created_timestamp`, read from the IPTC creation
   date, else from the EXIF `DateTimeDigitized` field (not `DateTimeOriginal`);
3. the creation date of a video or audio file, when the container has one;
4. the modification time of the file, unless the source turns the fallback off;
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

## 4. Where the folder, the name and the real date disagree

The index no longer relies on the folder a file is in, but a folder layout still
says something about dates, and the three can disagree:

| Layout | Who decides the folder | Date it carries |
|--------|------------------------|-----------------|
| Nextcloud auto-upload into date folders | The Nextcloud client | According to its settings, normally the date of the photo. This is what the path pattern hint is meant for. |
| WordPress upload into `uploads/YYYY/MM` | WordPress (option *Organize my uploads into month- and year-based folders*) | **The date of the post the media is attached to** (except for a page), otherwise **the day of the upload**. Never the date of the photo. This was checked in the WordPress source. |
| WordPress upload without that option | Nobody | None: everything is in `uploads/` |
| Files registered in place by another tool | Wherever they were | None imposed |
| Your own organisation | You | Whatever you chose |

Consequences with the effective-date rule of slice 025:

- A photo taken on 2026-09-30 and uploaded on 2026-10-02 through WordPress lands in
  `uploads/2026/10`, and its name (for example `IMG_1234.jpg`) has no date. Since
  the capture date of photos is read (slice 026) it is listed under 2026-09-30, like
  its attachment date. A video or a GPX file named without a date still falls back
  to its **modification time**, usually the upload day.
  Photos named by the phone (`20260930_101500.jpg`) are placed correctly.
- A path pattern down to the day no longer hides such a file: it is found by the
  background passes, wherever it is. The pattern only makes new files of a day show
  up faster.
- Time zones add edge cases: a device may name files in its own time zone, the panel
  date and the attachment date use the site time zone, so a photo taken near midnight
  can sit one day away. The hints include the neighbouring days for that reason.
- The same media can therefore still have three dates: the one in its name, its
  modification time, and the attachment date.

Practical rule today: name files with their date, or wait for embedded dates
(slice 026). The modification-time fallback can be turned off per source if it
places files on misleading days.

## 5. Known limitations of the current version

- Dates embedded in videos, audio and GPX files are not read, and the user cannot set
  a date (rest of slice 026). Videos and GPX files do not refine the median time of a
  name with a day only at indexing (a video registered as an attachment does).
- A file's source of date is stored in the index but not shown, and the order is
  fixed.
- Modification times can be changed by copies and synchronisation.
- A date in a file name is recognised by the source's pattern and the generic forms
  only; there is a single pattern per source (slice 026 adds a list).
- The `date` of a listed item is the requested date, not the date of the file.
- The panel date is stored per post, not per user.
- The attachment date is computed when a file is registered and never updated.

## 6. Decisions and options

**Decided and implemented (slice 025).** Discovery is separate from selection: an
index in the database, filled by incremental, resumable passes, answers the day of
the panel by the effective date of the files. The path pattern is a hint. The
fallback to the modification time is on by default, with a per-source opt-out. A
date in a name is read with its time when present, and a date alone is placed at
12:00:00.

**Decided for the next slices** ([026](../slices/026-name-date-patterns-and-embedded-dates.md),
[027](../slices/027-hidden-files.md)): several name patterns per source with presets,
embedded dates (EXIF at discovery, video and GPX later), a date set by the user,
default order *user date, name, embedded date, modification time*, global hiding of
files, and the target library of about 5,000 directories and 110,000 files.

**Still open.**

- (Decided, slice 026.) When the name gives a day without a time and the embedded
  metadata gives a time on the same day, the embedded time is used; it is ignored when
  the days differ, as for a video assembled later.
- A date range or a tolerance, for photos taken around midnight (the response
  already has an unused `date_range`).
- Whether the attachment date should be recomputed when the index learns a better
  date.
- Whether the source of the attachment date should be recorded.
