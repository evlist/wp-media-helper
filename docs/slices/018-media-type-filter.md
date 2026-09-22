# Media type filter

## Summary

This slice adds a media-type filter to the common filter system introduced in slice 015.

The goal is to let the editor hide or reveal categories of files based on their effective type, while keeping the panel behavior consistent with the shared filter API and persistence model.

For the initial version, the filter only distinguishes three categories: `image`, `video`, and `other`.

## Context

The panel already resolves and enriches media items with a `type` value such as `png`, `jpg`, `txt`, or `gpx`. That is enough to group files by broad media category, but it is not yet represented as a first-class, persisted filter.

Without a shared filter definition, the UI would grow inconsistent rules for every classification feature. This slice formalizes the rule so future type-based filters can extend the same model.

## User story

As an editor, I want to focus on images or videos without having to visually scan unrelated files, while still being able to inspect other media when needed.

As a developer, I want media-category filtering to use the same registry and persistence rules as the other filters.

## Goals

- define a `media_type` filter in the common registry,
- support `image`, `video`, and `other` for the initial version,
- keep the filter under the standard `user_post_then_user` persistence model,
- hide or reveal rows based on the resolved file type,
- keep the UI compact and consistent with the rest of the filter area.

## Filter definition

The filter key is `media_type`.

It uses an option-set type with `user_post_then_user` scope.

Supported values:

- `image`: image-like files, such as PNG, JPG, GIF, or WEBP,
- `video`: video-like files, such as MP4, MOV, WEBM, or AVI,
- `other`: everything else, including text, GPS, documents, or unknown file types.

Default value:

- `["image", "video", "other"]`.

Resolution order:

1. current user's set for the current post,
2. current user's latest global set,
3. the default set.

This filter is represented as a list of included categories, not as a single named preset. That keeps the model explicit and avoids a growing list of ad hoc names such as `images_only` or `without_documents`.

## Type resolution

Each item already exposes a `type` field from the file extension, such as `png` or `txt`.

The server resolves that into the broad categories:

- `png`, `jpg`, `jpeg`, `gif`, `webp`, `svg` → `image`
- `mp4`, `mov`, `webm`, `avi`, `m4v` → `video`
- everything else → `other`

This mapping should remain intentionally conservative. The goal is not to build a full MIME taxonomy; it is to provide a useful and stable first-pass grouping.

## Filtering behavior

When the selected set includes a category, items in that category remain visible.

When a category is excluded, matching items are filtered out before rendering the result list.

The default set includes all three categories so the panel remains backward-compatible with the current behavior.

If a file has no detectable extension, it is treated as `other`.

## Filter control

The filter is rendered in the common filter area introduced by slice 015.

A compact checkbox group is preferred:

- `Images`
- `Videos`
- `Other`

The control should stay compact and should not require a separate modal or full-width UI.

The editor may toggle any combination of the three categories, but at least one category must remain selected.

Changing the selection triggers a media-state request and preserves selected IDs that remain visible after the filter change.

## Request and response contract

The media-state request includes:

```json
{
  "filters": {
    "media_type": ["image", "video"]
  }
}
```

The response includes the effective normalized filter and the enriched items with their resolved `type` and broad `media_type` classification.

## Performance considerations

The type classification is cheap and should be computed during item enrichment rather than rederived later in the UI.

The server should resolve the broad category once per item rather than repeatedly checking extension patterns at render time.

## Error behavior

Unknown type values in persisted storage should be normalized to the default set or ignored when they do not match a supported category.

If filter persistence fails, the active selection remains in effect for the current session.

## Non-goals

This slice does not include:

- per-format subfilters such as `jpg` vs `png`,
- image dimensions or video duration filtering,
- MIME sniffing beyond the extension-based default strategy,
- a reset-to-default control,
- filtering by metadata tags or file contents.

## Acceptance criteria

1. `media_type` is registered through the common filter contract.
2. The default value is `["image", "video", "other"]`.
3. The filter supports `image`, `video`, and `other` for the initial version.
4. The file-type classification is deterministic and extension-based.
5. The filter resolves and persists through current user/current post, user-global, then default.
6. The UI provides a compact checkbox group for the three categories.
7. At least one category remains selected at all times.
8. Filtering keeps the selected media list consistent with the visible result set.
9. The request payload uses the common `filters` object.

## Notes

This slice intentionally keeps the first version small and predictable. It builds a shared and reusable type filter without overcommitting to a broader media taxonomy. The next logical evolution would be more precise typing, but this is enough to make the filter system useful immediately.
