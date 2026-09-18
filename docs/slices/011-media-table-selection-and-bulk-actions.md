# Media table selection and bulk actions

## Summary

This slice replaces the current one-action-per-item list with a compact media table inspired by the WordPress `WP_List_Table` interaction model. The table remains rendered inside the Gutenberg editor sidebar, but it adopts familiar WordPress table conventions: row checkboxes, a select-all checkbox, row actions, and bulk actions.

The slice focuses on selecting and managing external media items. It does not yet attach selected items to the current post.

## Context

The previous slices provide:

- configured external sources,
- date-based path resolution and refresh-aware indexing,
- an enriched list of external media items,
- registration of an external file as a WordPress media-library entry,
- removal of that WordPress-side entry without deleting the external source file.

The current panel displays each item independently and offers only a single action at a time. This is insufficient for a workflow involving several media files. The next useful step is to introduce a reusable selection model before implementing attachment to the post.

## User story

As a content editor, I want to select several files in the external media table and apply an action to them together, while retaining the possibility of acting on one row individually.

## Goals

- make the media list behave like a familiar WordPress administration table,
- support single-row and multiple-row selection,
- expose import and removal as individual and bulk actions,
- preserve the complete external media list and its per-item WP status,
- report partial success when a bulk operation contains failures,
- prepare a stable selection contract for the later attachment slice.

## Design direction

The table should follow the interaction principles of `WP_List_Table` without using the PHP `WP_List_Table` class directly.

The panel is rendered in the Gutenberg editor with JavaScript and WordPress components. Rendering a PHP `WP_List_Table` inside it would introduce a second state model and make checkbox, loading, and AJAX updates harder to coordinate. The implementation should therefore use a client-side table while keeping the familiar WordPress ergonomics.

The table should remain compact and suitable for the editor sidebar. It is a management surface, not a media gallery or a second Media Library screen.

## User interactions

### 1. Select individual rows

Each media item has a checkbox. Selecting a row adds its stable item identifier to the current selection.

Selection must be keyed by the item identifier or canonical path, not by the current row index, so refreshes do not select the wrong file.

### 2. Select all visible rows

A checkbox in the table header selects or deselects all currently displayed rows.

The header checkbox must support the usual three states:

- unchecked when no visible row is selected,
- checked when all visible rows are selected,
- indeterminate when only some visible rows are selected.

The selection applies to the current result set. It must not silently include files that are not currently displayed.

### 3. Individual row actions

Each row provides the action appropriate to its current status:

- `Import` when the item is not in the WP media library,
- `Remove` when the item is in the WP media library.

The action affects only that row and updates its status when the server confirms success.

### 4. Bulk actions

A bulk-action control is displayed above the table and applies to the selected rows.

The initial actions are:

- `Import` selected items,
- `Remove` selected items.

The control must be disabled when no rows are selected. The implementation may reject actions that are not meaningful for the complete selection, or may process each row according to its current state, but the behavior must be explicit and predictable.

### 5. Clear selection after an operation

After a successful bulk operation, the processed rows should be deselected. Rows that failed should remain selected so the editor can inspect or retry them.

A single-row action should not clear unrelated selections.

## Table columns

The first version should expose only information useful for scanning and management:

- selection checkbox,
- file name,
- file type,
- WP media-library status,
- row actions.

The table should not expose absolute filesystem paths as the main label. The existing item metadata remains available to the implementation and may be shown through a tooltip or secondary detail when useful.

The status labels remain:

- `In WP media library`,
- `Not in WP media library`.

## Functional requirements

### 1. Stable selection state

The client must maintain a selected-item set independently from the rendered row order. Selection must survive a non-destructive state update when the same items remain in the result set.

When a refresh replaces the result set, selections for items no longer present must be removed. Selections for matching items may be preserved if their stable identifiers remain valid.

### 2. Bulk request contract

The server must receive an explicit list of selected items. Each item should contain enough information to validate and process it, preferably:

- stable item id,
- source id,
- external path.

The server must not trust the client-provided import status. It must determine whether an item can be imported or removed from the WordPress-side state.

### 3. Per-item results

A bulk response must identify the result for every requested item. At minimum, each result should include:

- item id or path,
- success or failure,
- resulting `is_imported` state when successful,
- an error message when unsuccessful.

One failed item must not hide successful operations performed on the other selected items.

### 4. Concurrency and loading state

While a bulk operation is running:

- the table selection controls are disabled,
- row and bulk actions are disabled,
- the current rows remain visible,
- the UI communicates that processing is in progress.

The implementation should avoid sending overlapping import or remove operations for the same row.

### 5. State updates

After a successful operation, only the affected rows should change status. The complete external list must remain visible.

The client must not rebuild the list from the response in a way that loses unrelated rows or imported states.

### 6. Compatibility with existing endpoints

The existing single-item import and removal behavior may remain available for row actions. Bulk actions may use a new endpoint, provided they share the same validation and attachment lifecycle rules.

In particular:

- importing registers a WordPress attachment without copying the external file into `uploads`,
- removing deletes only the WordPress-side registration,
- removing must never delete or modify the external source file.

## Error handling

The table should surface a concise operation-level notice when a bulk request partially or completely fails.

Per-row failures should remain associated with their rows where practical. A failed operation must not optimistically flip the row status.

If the server reports that an item has disappeared from the external source or is no longer registered in WordPress, the row should be reconciled with the next state refresh rather than silently treated as successful.

## Non-goals

This slice does not include:

- attaching selected media to the current post,
- inserting media into post content,
- bulk deletion of external source files,
- replacing the WordPress Media Library,
- pagination or advanced filtering beyond the current source/date result set,
- a PHP-rendered `WP_List_Table` screen outside the Gutenberg editor.

## Acceptance criteria

1. The panel displays the external media items in a compact table-like layout.
2. Each row can be selected independently.
3. The header checkbox supports unchecked, checked, and indeterminate states.
4. A user can apply `Import` or `Remove` to one row.
5. A user can select several rows and apply a bulk `Import` or `Remove` action.
6. Bulk operations return and display per-item success or failure results.
7. Successful operations update only the affected rows and preserve the rest of the list.
8. Failed rows do not appear successful and remain available for retry or inspection.
9. The external source files remain untouched by both import and removal.
10. The selection model is stable enough to be reused by the later post-attachment slice.

## Notes

This slice establishes the interaction foundation for the next lifecycle step. Once the table can select multiple media items reliably, the following slice can add an attachment action that registers missing items when necessary and associates all selected attachments with the current post in one explicit operation.
