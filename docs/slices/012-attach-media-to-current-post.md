# Attach media to the current post

## Summary

This slice adds the bulk action that associates selected external media items with the post currently being edited.

Attachment is distinct from presence in the WordPress media library. An item may be registered in WordPress without being attached to the current post. Conversely, attaching an external item must register it first when necessary, without asking the editor for a separate import confirmation and without showing an error for that normal prerequisite.

## Context

The previous slices provide:

- discovery of external media for the selected source and date,
- registration of an external file as a virtual WordPress attachment without copying it to `uploads`,
- removal of that WordPress-side registration while leaving the external file untouched,
- a table with selection, row actions, and extensible bulk actions.

The missing lifecycle step is associating one or more selected media attachments with the post currently open in the block editor.

## User story

As a content editor, I want to attach selected external media to the post I am editing, even when some selected files have not yet been registered in the WordPress media library.

## Goals

- add an `Attach to post` bulk action,
- attach every selected item to the current post,
- register unimported selected items automatically as part of the same operation,
- keep the external file in its original location,
- expose attachment state separately from media-library state,
- preserve the current table selection after the action.

## Terminology

The implementation must keep these concepts distinct:

- **In WP media library**: the file has a WordPress attachment record.
- **Attached to current post**: that attachment record has the current post as its `post_parent`.
- **External source**: the original filesystem location. It remains authoritative and is never copied, moved, or deleted by attachment.

## User interactions

### 1. Attach selected media

The editor selects one or more rows and chooses `Attach to post` from the bulk-action menu.

For each selected item:

1. if it is not in the WP media library, the plugin registers its virtual attachment automatically;
2. the plugin assigns the current post as the attachment’s `post_parent`;
3. the row updates to show that it is attached to the current post.

The editor must not have to execute `Import` first. Automatic registration is an internal prerequisite of attachment, not a separate workflow requiring confirmation.

### 2. Attach an already imported item

When the item is already in the WP media library but is not attached to the current post, the operation only updates its post association.

### 3. Re-attach an item already attached to the current post

The operation is idempotent. It succeeds without creating a duplicate attachment or displaying an error.

## Functional requirements

### 1. Current post identity

The server must receive the current post ID and verify that the current user can edit it.

Attaching requires a persisted post. When the editor is creating a new unsaved post, the UI must ensure it is saved before making the request, or clearly defer the action until a post ID exists. The server must never attach an item to an arbitrary or unverified post ID.

### 2. Bulk request contract

The request must contain:

- current post ID,
- source ID,
- selected item IDs and external paths.

The server must resolve import state and attachment state itself. It must not trust state flags sent by the client.

### 3. Automatic virtual import

For every selected item without a matching plugin-managed attachment, the server must create the same virtual attachment record used by the existing import flow.

This automatic import must:

- leave the source file in place,
- avoid a copy into `uploads`,
- preserve source ID and source-path provenance metadata,
- be silent in the normal UI flow.

A real failure, such as an unreadable source file or attachment insertion failure, must still be reported for that item.

### 4. Attachment operation

The server associates the matching attachment with the current post through the standard WordPress attachment relationship (`post_parent`).

The implementation must not alter attachment provenance metadata or the external source path while attaching.

### 5. Item state and table columns

Each item must expose whether it is attached to the current post, for example through an `is_attached_to_current_post` boolean.

The table should show an explicit attachment status in addition to the existing WP media-library status. The wording should make clear that the status concerns the current post, not the global library.

### 6. Per-item results

The bulk response must contain an outcome for every selected item, including:

- item ID or path,
- success or failure,
- resulting WordPress attachment ID,
- resulting media-library state,
- resulting current-post attachment state,
- a message for actual failures.

One failed item must not roll back successful attachments for the other selected items unless an explicit transactional design is introduced later.

### 7. UI state

After a successful operation, only the affected rows update their media-library and current-post attachment states. The external list remains complete.

The current selection remains intact so the editor can chain a subsequent action.

## Non-goals

This slice does not include:

- inserting media into post content or blocks,
- setting a featured image,
- attaching media to another post,
- modifying or deleting the external source file,
- copying external files into `uploads`,
- a bulk detach action.

## Acceptance criteria

1. `Attach to post` is available as a bulk action for selected rows.
2. A selected unimported file is registered and attached in one server operation without a separate confirmation.
3. A selected imported file is attached without a duplicate registration.
4. An already attached item can be attached again without error or duplicate records.
5. The external source file remains untouched in every successful path.
6. The table distinguishes global library presence from attachment to the current post.
7. Per-item failures do not hide successful attachments of other selected rows.
8. The current row selection remains after the operation.

## Notes

This slice deliberately treats import as an implementation detail of attachment. The user-facing action is attaching media to the post; whether a virtual attachment record had to be created first should not interrupt that workflow.
