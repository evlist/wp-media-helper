# Media list enrichment

## Summary

This slice focuses on the list of external media returned for the selected date context. The current panel already resolves the media list and exposes the refresh status, but it still presents raw file paths without any user-facing metadata or structure. The goal is to enrich the list so the editor can quickly understand which files match the selected date and which ones are likely relevant before attaching anything.

## Context

The runtime layers already provide:

- a selected date context persisted on the post,
- a refresh-aware controller for the media panel,
- a source/date resolver,
- a dated index and an explicit refresh contract,
- a UI panel that reads the resolved file list.

The remaining gap is the display layer: the list should become a proper user-visible media list instead of a simplified, text-only dump of file paths.

## User story

As a content editor, I want the external media list to be readable and informative so that I can quickly identify which files correspond to the selected date without having to inspect raw paths one by one.

## Goals

- transform the raw list of file paths into useful media entries,
- keep the file list aligned with the selected source/date context,
- provide a small, stable set of metadata for each item,
- keep the UI logic thin and not duplicate filesystem logic,
- prepare the data contract for later selection and attachment interactions.

## User interactions

### 1. Select a date

The user chooses the date that defines the media context. The selected date is already persisted on the post and reused when the editor is reopened.

### 2. Review the external media list

The panel displays the file list for that date in a human-readable way, with enough metadata to recognize the relevant file without opening it manually.

### 3. Understand the status of each item

The list should help answer questions such as:

- is the file part of the current date context?
- is it an image or another file type?
- is the file considered valid for attachment in this workflow?
- is it newly refreshed or already known?

## Functional requirements

### 1. Stronger item contract

The list should resolve each entry to a richer structure, not just a bare string path.

At minimum, each item should expose:

- `id` or stable identifier,
- `name` (basename),
- `path` (absolute or working path used by the resolver),
- `type` (image / document / other),
- `source_id` or source label,
- `date` (selected context),
- `is_valid_for_attachment` when the file type is supported.

### 2. User-friendly rendering

The panel should render entries as card-like or list-like items with a compact layout that includes:

- filename,
- file type badge or icon,
- optional thumbnail preview for images,
- source/date context,
- validity status for attachment.

### 3. No duplicate business logic

The item enrichment should not reimplement the file scanning logic. It should consume the existing runtime contract and enrich it for display only.

### 4. Stable compatibility with existing payloads

The panel currently receives a payload with `files` as a string array. The enrichment layer should remain backward compatible while preparing a richer payload structure for the next slice.

### 5. Usability without full attachment flow

This slice should not yet attach the media. It only needs to make the list usable, scannable, and representative enough for later selection and insertion.

## Proposed display behavior

For each media item, the UI may display:

- filename,
- extension or media type,
- whether it is an image,
- whether it is valid for upload/attachment,
- the source/date context used to resolve it,
- whether the file is already known to WordPress or not.

The panel should not show raw internal absolute paths as its primary representation.

## Non-goals

This slice does not include:

- multi-select and attachment insertion,
- media library registration,
- upload from external source to WordPress library,
- image editing or transformation,
- background synchronization of external source files.

## Acceptance criteria

1. The panel no longer relies on raw string-only file entries as the main user-facing object.
2. Each file entry exposes enough metadata to be recognized and grouped by type.
3. The source/date context is still visible in the list item.
4. A file type or validity indicator is present where relevant.
5. The UI remains deterministic and stable even when the produced list is empty.
6. The data contract remains compatible with the refresh orchestration layer.

## Notes

This is a presentation-focused slice. The primary objective is to make the list readable and actionable without prematurely introducing attachment logic. It should prepare the panel for the next slice, where selection and insertion behavior will be added.
