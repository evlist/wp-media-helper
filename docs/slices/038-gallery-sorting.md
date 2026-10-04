<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 038: Sorting the gallery

Status: **proposed** (design and open questions only, not scheduled). Related:
[029](029-gallery-ui.md) (the gallery), [034](034-date-range-per-post.md) (several days),
[035](035-search-across-dates.md) (search across dates), [032](032-file-types.md) (categories).

## Goal

Let the user choose how the files of the gallery are ordered (name, date, size, type, ...) and in which direction,
instead of the single fixed order of today.

## What exists

- One order, fixed in the index query: **effective date, then name** (`ORDER BY effective_date ASC, name ASC`), for the
  files of one day, merged across sources.
- The pagination (infinite scroll) cuts the sorted list into pages, so the order is decided before the cut.
- The filters (attachment state, source, type, file name, show hidden) are stored per user, per post then per user
  (`user_post_then_user`); the sort is a visibility choice of the same kind.

## Questions to settle

1. **Criteria.** Candidates: date and time (the default), name, size, file type, source, import state, dimensions
   (pixels), and the order of the sources then the file system. Which are worth the cost, given that each one has to
   be applied on the whole list before the page is cut.
2. **Direction.** Ascending and descending, one control per criterion or one toggle.
3. **Secondary criteria.** Always a stable tie-break (date, then name, then path) so that two requests give the same
   order and the infinite scroll never repeats or skips a file. Whether the user may chain two criteria.
4. **Where the sort is done.** The index can sort by date, name, size and type with an `ORDER BY` (and an index on the
   columns used). Sorting by import state or by attachment needs data that is not in the index and is merged after the
   query: sort in PHP on the day's list (a day is small), or limit the criteria to those the index knows.
5. **Name order.** Natural order (`IMG_2` before `IMG_10`) and case-insensitive, with the collation of the site language
   rather than a byte comparison.
6. **Several days.** With a period (034) or a search across dates (035): sort within each day, or across the whole
   result; the day headers of 034 only make sense when the date is the first criterion.
7. **Persistence.** Per user and per post, then per user, like the filters; a default in the settings for sites that
   want another default order. Whether the choice is reset when the date changes.
8. **Interface.** A small control in the filter area (a menu with the criterion and a direction button), the current
   order always visible, and the same on a phone. Accessibility: the state is announced, not only shown by an arrow.
9. **Interaction with grouping.** Whether the gallery can also group (by hour, by source, by type) with headers, or only sort.

## Non-goals

- Manual ordering by drag and drop, or an order saved with the post (that would be an editorial order of the attachments,
  a different feature).
- Sorting files that are not listed (hidden, ignored, excluded).

## Acceptance criteria

1. The user chooses a criterion and a direction; the list and the next pages follow it, without a repeated or a missing file.
2. The choice is remembered for that user and survives a reload, and a user with no choice sees the current order.
3. The order is deterministic: the same files give the same order on each request.
4. Names are compared in natural, case-insensitive order.
5. The filters, the selection mode and the infinite scroll keep working with any order.
