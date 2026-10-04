<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 026: Name date patterns, embedded dates and user dates

Status: **proposed** (design only, not implemented). Depends on
[slice 025](025-database-file-index.md).

## Goal

Make the effective date of a file precise and configurable: several name patterns
per source with presets for common devices, dates read from the content of files,
and a date set by the user for the exceptions.

## Background

The date in a file name is the primary source on purpose. It is how files without
metadata (GPX tracks, subtitles) get a date, and how a video assembled with a tool
that writes unrelated metadata is placed on the right day, by renaming it. Its
limit is that a file whose name has no date is not found by it. Embedded dates
cover those files.

## Name date patterns

A source has an **ordered list of patterns**; the first that matches the name
gives the date. A pattern is written with the same `{date:…}` placeholders as the
path and filter patterns, so that literal text is never mistaken for a date letter:

| Syntax | Meaning |
|--------|---------|
| `{date:Ymd}` | A date, with the usual date format letters: `Y` year, `m` month, `d` day, `H` hour, `i` minute, `s` second, `v` milliseconds. Separators may be inside, as in `{date:Y-m-d}` |
| `[ … ]` | An optional part, for example `{date:Ymd}[_{date:His}]` |
| `*` | Any run of characters |
| anything else | Literal text |

A pattern is matched against the **whole name without its extension**, so a
pattern for a date anywhere in a name starts and ends with `*`:
`*{date:Ymd}[_{date:His}]*`. A run of digits next to a date field must not
continue the field: `20261002121549` is not read as a longer number. A date is
accepted only if it is a real calendar date and a real time.

- If a pattern has no time or the optional time is absent, the date takes the
  median time of the day, 12:00:00 site time, with precision `day`.
- Time zone of a time in a name: the site's, unless a per-source time zone is set
  (open question).

### Presets

Offered when adding a source, to be checked against real file names before they
are fixed. Starting list, from common conventions:

| Name | Pattern |
|------|---------|
| Android camera | `IMG_{date:Ymd}_{date:His}*` and `VID_{date:Ymd}_{date:His}*` |
| Pixel | `PXL_{date:Ymd}_{date:His}{date:v}*` |
| Samsung and others | `{date:Ymd}_{date:His}*` |
| Screenshots | `Screenshot_{date:Ymd}-{date:His}*` |
| WhatsApp | `*-{date:Ymd}-WA*` (date only) |
| Dated with separators | `*{date:Y-m-d}[ _T]{date:H.i.s}*`, `*{date:Y-m-d}*` |
| Any date | `*{date:Ymd}[_{date:His}]*` |

The existing filter pattern is converted into a first pattern of this form.

## Embedded dates

Read when a file is first indexed or when its size or modification time changes,
by type, and only when the name has no date (a name date wins):

| Type | Source | When |
|------|--------|------|
| Images | IPTC creation date, else EXIF `DateTimeDigitized`, through the core reader | At discovery, in the background batches (cheap per file) |
| Video, audio | Creation date of the container | Not at discovery: at registration, or by a background pass, since files can be large and slow to open |
| GPX | Start of the first track segment, else the `metadata` time; the end time and the duration are kept | By a background pass, with a bounded and safe XML reader (no entities, no network, size limit) |
| Others | None | The modification time applies |

EXIF has no time zone and core stores the local clock time as if it were UTC, so
the value is re-read as site time (see the date model). `embedded_state` records
whether the date was read, absent, or not applicable, so that files are not read
again for nothing.

When the name gives a day without a time and the embedded date falls on the same
day, the embedded time refines the median time. When the days differ (for
example a video assembled later), the median time is kept.

## Date set by the user

An action *Set date* writes `date_override`. It wins over every other source and
survives re-scans. The panel shows which source gave each date, so that a surprising
date can be understood and, if needed, overridden.

## Interface

- The source form lists the name patterns in order, with presets, a test field
  ("this file name gives this date") and the per-source option to use the modification
  time as a fallback (on by default, see slice 025).
- Items show their date and its source in the advanced mode of the panel.

## Security

- Patterns are compiled to regular expressions with every literal escaped and a
  length limit, and are only matched against file names, never run as code.
- GPX and other embedded readers handle untrusted files: bounded size and time, no
  external entities, no network access.

## Non-goals

- Dates in the names of directories.
- Sidecar files (a JSON or XMP next to a photo).
- Reading dates from PDF and office documents.

## Acceptance criteria

1. Several patterns per source are tried in order, with optional time parts.
2. Presets exist and the form tests a name against the list.
3. A date alone is placed at 12:00:00 site time, and a time in the name is used.
4. Images without a date in the name are placed by their capture date, and the
   result is the same whether the file is listed or registered.
5. A date set by the user survives a re-scan.
6. Files are not read again unless their size or modification time changed.
7. The date and its source can be shown for an item.

## Open questions

- Per-source time zone for dates in names.
- Exact syntax and the escaping of the characters `[`, `]`, `{`, `*` in literal text.
- Which presets to ship, from real file names.
- GPX: which time to use for tracks that span midnight (start, by default).
