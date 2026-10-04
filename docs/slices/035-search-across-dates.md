<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 035: Searching across dates

Status: **proposed** (design and open questions only, not scheduled). Builds on
[025](025-database-file-index.md) and [029](029-user-interface.md). Related: [034](034-date-range-per-post.md).

## Goal

Find a file when its day is not known: by name, by type or by state, over all days. Today everything goes
through a day, so a photo whose name one remembers (or an unused photo one wants to reuse) cannot be found
without guessing its date. Make the date a way to browse, not the only way in.

## What exists

- The filename filter narrows the list **of the selected day**.
- The index holds every file of every active source with its effective day, name, kind, size and state, and
  answers by day with a key on `(effective_day, hidden)`; there is no key on the name.
- The state of each listed file (imported, attached here or elsewhere) is resolved for the page being shown.

## Behavior

- **Scope.** Next to the filename field, an *All dates* option. When it is on and the field is not empty, the
  list is **every file whose name contains the text**, newest first, grouped by day as in 034, with the
  same visibility filters (sources, media type, attachment state, hidden). The date controls are greyed out
  while it is on. The text is bounded (255 characters), and `LIKE` wildcards are escaped.
- **Also without a name.** The same switch with an empty field would list everything, which is too much for a
  library of 100,000 files; the field is required, except for a small set of **saved searches** that make
  sense without text: *Not in the Media Library*, *Not attached anywhere* (to find photos to reuse), *Hidden*.
- **Days that have files.** The date control marks, in its calendar, the days of the month that have files
  and how many (one query, `GROUP BY effective_day`, for the month shown and the current filters), so one can
  jump to a day instead of stepping through empty ones.
- **Navigation.** Clicking a day header of the results sets the date and leaves the search.
- **Server.** A request carries `scope = all` and the text; the query adds `name LIKE %s` (escaped), orders by
  `effective_date DESC`, uses the page limit and offset, and returns the total. The index gets a key on
  the first characters of the name for prefix searches; a leading wildcard scans the table, which is
  acceptable for the target size and is measured with the benchmark script before and after.

## Questions to settle

1. **The filter model.** The date is classed as an *attachment eligibility* filter, which a visibility filter
   must not override ([model](../IA/media-filter-model.md)). In practice the date is not enforced (any editor
   can attach any file listed); searching across dates only works if the date is admitted to be a navigation
   default. The decision belongs to [037](037-eligibility-reconciliation.md) and to the model document, which
   should then say so.
2. **Case, accents and word order.** Case-insensitive substring is the minimum; accent-insensitive and
   several words (all must match) are cheap with the database collation and a split on spaces.
3. **Path.** Search in the directory part too (a person's name in the folder), or the file name only.
4. **Other fields.** Size, dimensions, date source (to find photos placed by their modification time) are
   possible filters on the same query, not in the first version.
5. **Cost** on a very large index, and a per-user limit on search requests.

## Non-goals

- Full-text search in the content of files, or ranking of results.
- Fuzzy matching.

## Acceptance criteria

1. A name typed with *All dates* finds the files of any day, with their counts and the usual actions.
2. Wildcards typed by the user are taken literally.
3. The saved searches list what their names say, across all sources the user can see.
4. The marked days of the calendar match what the day lists show.
5. Without *All dates* nothing changes.
