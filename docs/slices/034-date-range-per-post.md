<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 034: A period of dates for a post

Status: **proposed** (design and open questions only, not scheduled). Builds on
[025](025-database-file-index.md) (the index is queried by day) and
[029](029-user-interface.md) (the gallery). Related: [035](035-search-across-dates.md),
[037](037-eligibility-reconciliation.md); the vocabulary is that of the
[media filter model](../IA/media-filter-model.md).

## Goal

A post often covers more than one day: a trip, a week-end, a festival. Today the panel has **one
date**, and the editor changes it day after day to find the photos. Let a post have a **period**, and
list its files together, grouped by day.

## What exists

- One date per post, in the post meta `wp_media_helper_date` (`Y-m-d`), saved with the post; it starts
  at the publication day of a published post and at today for a draft.
- Every request carries that date; the index answers a day (`effective_day = %s`, a key on
  `(effective_day, hidden)`), and the path-pattern hints look at the directories of the day before,
  the day and the day after.
- The state payload already has an unused `date_range` field, and `MediaPanelState::resolve()` takes an
  end date; nothing fills it.

## Behavior

- **Data.** A second post meta `wp_media_helper_date_end` (`Y-m-d`, optional, same sanitising as the first).
  Empty means a single day, so every existing post keeps working without a migration. The end cannot be
  before the start (it is raised to the start), and the span is limited (31 days by default, a setting
  between 1 and 366) because each day costs a query and, when the index is not complete, hints.
- **Panel.** The *Date* control becomes *From* and *To*, with *To* left empty for one day. Next/previous
  buttons move the day or the whole period by its length. The default for a published post stays its
  publication day.
- **List.** Files of the period, oldest first, with a **day header** between days in the gallery (a
  full-width separator with the date and the number of files of that day); the count shown is the total
  of the period; infinite scroll and the maximum number of entries work across days.
- **Index.** One query `effective_day BETWEEN %s AND %s` with the same filters, ordered by
  `effective_date`, `name`, with the limit and offset of the page. The hints (path pattern) are resolved
  for the days of the period within a small cap (for example 7 days, middle first); beyond it the
  background passes find the files, with the usual notice while the index is incomplete.
- **Server contract.** The `filters` payload gets `date_end`; the response `date_range` is filled; the
  panel state for several days is one list, not several merged lists, so the order and the pages are
  stable.

## Questions to settle

1. **Is the period an eligibility rule?** In the filter model the post date is an *attachment eligibility*
   filter, shared by the editors and a guard for attachment actions. The code does not enforce it: any
   editor can attach any file listed, and the date is a convenient starting point. The period raises the
   question again (see [037](037-eligibility-reconciliation.md)): keep it a navigation default, or make
   it a rule.
2. **A period or a list of days?** A trip with a pause, two week-ends: only a continuous period in this
   slice.
3. **Time.** A period ends at the end of its last day, in the site's time zone, as dates do everywhere
   else (see the [date model](../IA/date-model.md)).
4. **The span limit** when the user needs a month: raise the setting, or page by week.
5. **Day headers and counts** need a query (`COUNT(*) GROUP BY effective_day`) or are computed from the
   page; the first is exact and cheap with the index.

## Non-goals

- Several periods per post, time-of-day ranges, a calendar view (see 035 for days that have files).
- Changing the date of a file.

## Acceptance criteria

1. A post with no end date behaves exactly as today.
2. A period lists the files of all its days, grouped by day, in order, with a correct count.
3. The span is limited and the limit is explained when reached.
4. Hiding, importing, attaching and the featured image work from a period list as from a day.
5. The period is saved with the post and restored when it is reopened.
