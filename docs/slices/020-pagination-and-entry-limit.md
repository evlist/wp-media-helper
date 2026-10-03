<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 020: Pagination and configurable entry limit

## Goal

Bound the amount of data handled by one editor request, without hiding any
media: results are paginated, and the page size is configurable.

## Background

A security review found that the editor endpoints accepted an unbounded number
of items per bulk request and returned every matching file at once. Capping
those inputs is only acceptable if the remaining entries stay reachable, hence
pagination.

## Setting

`max_entries` is stored in the `wp_media_helper_general_settings` option and
edited in the **General** section of *Settings → WP Media Helper*.

- Whole number between 1 and 500, default 100.
- An invalid submission is rejected with a message and the form is redisplayed
  with the submitted value; nothing is saved.
- An invalid stored value falls back to the default.

It serves two purposes:

1. page size of the media list,
2. maximum number of items accepted by a single bulk action.

## Request and response

The `wp_media_helper_media_panel_state` request accepts a `page` parameter
(1-based, not persisted). The response adds:

```json
"pagination": { "page": 2, "per_page": 100, "total": 250, "total_pages": 3 }
```

`files` contains only the items of the returned page, after attachment-scope
filtering. A `page` beyond `total_pages` is clamped to the last page, and an
empty result reports a single empty page.

## Bulk actions

A bulk request containing more than `max_entries` items is rejected with HTTP
400. Because selection is limited to the visible page, the panel never sends
more than a page.

## Panel behavior

- *Previous* and *Next* buttons and a "Page X of Y (N items)" label appear
  when there is more than one page, and are disabled while loading.
- Changing the date, source filter or attachment scope returns to page 1.
- Selection is kept only for items that remain visible, as for any refresh.

## Non-goals

- Cross-page selection.
- A cap on the number of files scanned in a directory; the scan still reads the
  whole resolved directory, and the persistent index avoids repeating it.

## Acceptance criteria

1. The setting is configurable, validated and defaults to 100.
2. The list endpoint returns at most `max_entries` items and pagination data.
3. Out-of-range pages are clamped.
4. Bulk requests above `max_entries` are rejected.
5. The panel can browse every page.
