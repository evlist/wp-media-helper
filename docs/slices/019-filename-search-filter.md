# Filename search filter

## Summary

This slice adds a text-based filename filter to the shared filter contract from slice 015.

The panel can already display large result sets from a date and source context. A filename search gives the editor a simple and portable way to narrow those results without building a separate ad hoc path for every future search feature.

## Context

The media panel already resolves a list of files from external sources. Once the list grows beyond a single day or a small source, editors need a quick way to isolate a file by name.

Adding search as a one-off client filter would duplicate logic and make it hard to preserve consistency across the panel. This slice turns it into a standard filter with normalized request payloads, persistence, and fallback semantics.

## User story

As an editor, I want to type part of a filename and immediately narrow the visible list, so I can find a specific file quickly without scrolling through each result.

As a developer, I want filename search to plug into the filter registry like the other controls rather than becoming a separate special case.

## Goals

- define a `filename` or `search` filter in the registry,
- support a lightweight text query against filenames,
- keep the filter under the standard persistence model,
- keep the client and server behavior predictable and compact,
- preserve the current selection for items that remain visible after a search change.

## Filter definition

The filter key is `filename`.

It uses a text type with `user_post_then_user` scope.

Supported values:

- a plain text query string, possibly empty,
- the query is matched against each item's filename, not the full source path.

Default value:

- empty string.

Resolution order:

1. current user's value for the current post,
2. current user's latest global value,
3. empty string.

The value is stored as a user preference but does not create a dedicated reset control. An explicit empty string simply clears the current user-post value while keeping the general preference aligned with the latest user choice.

## Matching behavior

The filter matches against the basename of each file, not the absolute path.

The comparison should be case-insensitive and should include partial matches.

Examples:

- `summit` matches `20260810-summit.png`
- `route` matches `20260810-route.gpx`
- `2026` matches multiple files from the same period

This is deliberately simple: a substring match is enough for the first version. No ranking or regex syntax is required.

## Normalization rules

The server trims the query, collapses repeated whitespace, and strips leading/trailing separators.

The client should not send an unbounded query string. Empty or whitespace-only values normalize to the empty string.

A normalized empty string means “no filename restriction.”

## UI behavior

The filter is rendered in the common filter area introduced by slice 015.

A compact text input is enough:

- placeholder: `Search filenames`
- optional search icon or inline indicator
- submit not required; live filtering is acceptable

The input should remain easy to use in the sidebar and should not interfere with the date or source controls.

Changing the value triggers a media-state request and preserves selected IDs that remain visible after the search narrows the list.

## Request and response contract

The media-state request includes:

```json
{
  "filters": {
    "filename": "summit"
  }
}
```

The response includes the effective normalized filename filter value and the filtered item list.

## Server-side behavior

The server resolves the effective `filename` query before returning results.

Matching is performed on the filename component only, and it should ignore the full path. This keeps the behavior stable even when two sources have different roots.

When the query is empty, the filter effectively removes itself from the logic and all items remain eligible.

## Error behavior

If the stored query value contains unsupported characters or invalid UTF-8, it should be normalized to an empty string rather than failing the full request.

If persistence fails, the query remains active for the current session while the user continues working.

## Non-goals

This slice does not include:

- regex-based search,
- fuzzy ranking or relevance scoring,
- full-text search across file contents,
- search across metadata such as title, source name, or tags,
- a dedicated “search mode” separate from the filter registry,
- a reset-to-default control.

## Acceptance criteria

1. `filename` is registered as a standard filter definition.
2. The default value is the empty string.
3. Matching is case-insensitive and based on the basename.
4. The request payload uses the common `filters` object.
5. The filter resolves and persists through current user/current post, user-global, then default.
6. Empty or whitespace-only queries normalize to the empty string.
7. The selected item list is preserved for items that remain visible after a search change.
8. The filter is compact, sidebar-friendly, and consistent with the registry design from slice 015.

## Notes

This is intentionally the smallest useful text filter: partial filename search, no ranking, no regex, no full-content indexing. It gives the editor a practical lookup tool while keeping the filter model predictable and reusable for future search features.
