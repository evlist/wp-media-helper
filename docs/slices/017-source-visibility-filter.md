# Source visibility filter

## Summary

This slice adds the first real source selector to the shared filter contract introduced in slice 015.

The panel may ingest media from multiple configured sources. The user should be able to narrow the visible list to one or several sources without reworking the request payload, persistence model, or filter registry for each source-specific rule.

## Context

The existing panel already supports a date-driven source resolution path and a source ID parameter in the media-state request. That logic works, but it is not part of a common filter model. As soon as the panel needs more than one visibility control, the source selector will drift away from the other filters unless it is declared as a normal filter definition.

The current implementation also exposes source selection in a way that behaves like a special case rather than a first-class filter. The goal is to make `source` a managed filter with the same resolution, defaulting, and persistence semantics as the other filters in the contract.

## User story

As an editor, I want to focus on one source at a time or on a subset of sources, so that the sidebar stays useful when multiple repositories are configured.

As a developer, I want source selection to be declared as a standard filter rather than as a one-off URL parameter silo.

## Goals

- define a `source` filter in the filter registry,
- keep source visibility under the same persisted resolution model as other user-specific filters,
- let users choose “all sources” or specific sources,
- keep request payloads normalized under a single `filters` object,
- preserve the current result selection when the visible source set shrinks or grows,
- keep the default behavior compatible with the existing “all configured sources” fallback.

## Filter definition

The filter key is `source`.

It uses an option-set type with `user_post_then_user` scope.

Supported values:

- `all`: include every configured source,
- one or more selected sources, represented internally by stable source identifiers.

The user-facing value is always the configured source name. Source IDs are
implementation details used to identify the selected source reliably in
requests and persistence; they must not be presented as the choice labels.

Default value:

- `all`.

Resolution order:

1. current user's value for the current post,
2. current user's latest global value,
3. `all`.

When the user changes the source selection, the plugin stores both the user-post entry and the latest user-global preference, following the contract from slice 015. No reset-to-default control is added.

## Filter semantics

The `source` filter modifies which configured sources participate in the media-state request.

It does not change the underlying source configuration itself. It only changes the visible set of source results for the current panel state.

When the selected source list is empty or invalid, the server should normalize it back to `all` rather than returning an empty or broken result set.

Only enabled and otherwise usable sources count as available sources for this
filter. Disabled or invalid configurations do not create visible choices.

The filter control follows the number of available sources:

- zero active sources: hide the source filter and return an empty media list;
- one active source: hide the source filter and use that source implicitly;
- more than one active source: show the source filter with `All sources` and one
  named choice per active source.

The internal filter contract remains present in all cases. With one active
source, the effective value is that source even though the user does not need
to choose it explicitly.

## UI behavior

The filter is rendered in the common filter area introduced by slice 015.

A compact selector is preferred, using either:

- a checkbox list of available sources, or
- a single select that includes `All sources` plus the configured source names.

When more than one source is active, a checkbox list is preferred because it
allows selecting a subset of sources without inventing named combinations.
The labels use source names; IDs remain invisible technical values.

When zero or one source is active, no source selector is rendered.

The control must remain compact enough for the sidebar, and it must operate independently of the panel mode.

Changing the source selection triggers a new media-state request and preserves selected IDs that remain visible after the redraw.

## Request and response contract

The media-state request includes the normalized filter object:

```json
{
  "filters": {
    "date": "2026-08-10",
    "source": ["all"]
  }
}
```

The response includes the effective normalized filters so the client can reconcile persisted, defaulted, or normalized values.

## Server-side behavior

The server resolves the effective source filter before resolving the media list.

If the filter is `all`, the panel behaves as it does today and merges results from every configured source.

If the filter is a list of source identifiers, the panel only uses the matching configured sources. The response should also expose the source names used for the effective choices so the client can render names without knowing how IDs are generated.

If a configured source has been removed or renamed, it should be ignored without crashing the request. The effective filter normalizes to the still-valid sources, or to `all` when no valid sources remain. A source rename must not be treated as a new user-visible choice when its stable internal ID is unchanged.

## Error behavior

If the stored source value refers to a removed source, it is normalized to the next valid source selection or to `all`. If only one active source remains, it is used implicitly and the selector stays hidden.

If the user’s preference cannot be persisted, the selection remains active for the current session and the panel continues to work.

## Non-goals

This slice does not include:

- changing source configuration itself,
- source-specific permissions model,
- cross-source deduplication rules beyond the existing list merge behavior,
- deleting or editing sources from this panel,
- introducing another persistence layer outside the common filter contract.

## Acceptance criteria

1. `source` is registered as a standard filter definition.
2. The default value is `all`.
3. The request contract carries `source` under the `filters` object.
4. Resolution order is current user/current post, then current user global, then default.
5. A selection of one or more configured sources works without breaking the panel.
6. Users see source names rather than source IDs.
7. With zero active sources, the source selector is hidden and the result is empty.
8. With one active source, the source selector is hidden and that source is used implicitly.
9. With multiple active sources, users can select all or a subset by name.
10. Invalid or stale internal source identifiers are gracefully normalized.
11. The panel preserves selections for items that remain visible after a source change.
12. The filter behaves consistently with the other filters defined in slice 015.

## Notes

This slice is intentionally narrow: it makes source selection a real filter without redefining the source configuration model. The source registry remains the source of truth; the filter simply narrows the visible result set.
