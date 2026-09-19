# Detach media from the current post

## Summary

This slice adds the reverse of attachment: removing selected media from the post currently being edited and removing its plugin-managed WordPress media-library registration, while keeping the external source file intact.

It also introduces `Detach from post` as the non-destructive alternative. `Detach` removes only the association with the current post; `Remove` is the complete inverse of `Attach to post` and removes both the association and the WordPress media-library registration.

## Context

The preceding attachment slice introduces a second lifecycle state for each external media item:

- whether the item is in the WP media library,
- whether it is attached to the current post.

These states must remain independent. Removing an association with the current post is not the same as removing a file from the WordPress media library, and neither operation affects the external source file.

## User story

As a content editor, I want to either detach selected media from the post I am editing or remove it completely from both the post and the WordPress media library, without deleting the source file.

## Goals

- add a `Detach from post` bulk action,
- preserve the WordPress attachment record when detaching,
- preserve the external source file in every case,
- make the existing `Remove` action remove current-post media completely,
- update only the affected row states and preserve the selection.

## Terminology

- **Detach**: remove the association between a WordPress attachment and the current post by clearing or changing that attachment’s `post_parent`.
- **Remove from WP media library**: delete the plugin-managed WordPress attachment record while leaving the external source file untouched.
- **Remove**: the complete inverse of attachment. It detaches the item from the current post when applicable, then removes its plugin-managed WordPress attachment record.

## User interactions

### 1. Detach selected media

The editor selects one or more items attached to the current post and chooses `Detach from post`.

For each matching attachment, the server removes its association with the current post. The item remains in the WP media library and remains discoverable from the external source.

### 2. Detach an item that is not attached

The action is idempotent. If an item is already detached from the current post, the operation succeeds without an error message and without changing its media-library state.

### 3. Use the existing Remove action on an attached item

When an editor invokes `Remove` on an item attached to the current post, the plugin must first detach it from that post and then delete its plugin-managed WordPress attachment record. This applies to both row-level and bulk `Remove` operations.

### 4. Use Remove on an item not attached to the current post

When an item is not attached to the current post, `Remove` retains its existing meaning: remove the plugin-managed attachment record from the WP media library, without touching the external source file.

This distinction is evaluated per item so the server knows whether it must detach before removing the library record.

## Functional requirements

### 1. Current post validation

The request must include the current post ID. The server must verify that the user can edit it before changing associations.

As with attachment, the operation requires a persisted post ID. The UI must not issue a detach request for an unsaved post.

### 2. Detachment operation

For a plugin-managed attachment whose `post_parent` is the current post ID, the server clears the current post association using the WordPress attachment relationship.

The operation must preserve:

- the attachment record,
- source provenance metadata,
- the external source path,
- media-library presence.

### 3. Context-sensitive Remove operation

The existing remove handler and bulk remove handler must accept the current post ID and determine the item state server-side.

For each selected item:

1. if its matching plugin-managed attachment is attached to the current post, detach it;
2. remove the plugin-managed attachment record from the WP media library;
3. never delete or modify the external source file.

The server must not rely on a client-provided `is_attached_to_current_post` value for this decision.

### 4. Per-item results

A response must identify the action actually performed for every item, for example:

- `detached_and_removed`,
- `removed_from_library`,
- `no_change`.

It must also return resulting media-library and current-post attachment states. This allows the client to update mixed selections correctly.

### 5. UI state

The table must expose the current-post attachment status clearly enough for the editor to understand the effect of `Detach from post` and context-sensitive `Remove`.

After either operation:

- rows processed by `Detach from post` remain marked as in the WP media library,
- rows processed by `Remove` are marked as not in the WP media library,
- the external media list remains complete,
- the current selection remains intact.

### 6. Error behavior

Attempting to detach an item already detached from the current post is a successful no-op and must not show an error.

Actual failures, such as insufficient post permissions or a failed WordPress post update, must be returned per item without preventing other selected items from being processed.

## Non-goals

This slice does not include:

- deleting external source files,
- deleting a WordPress attachment that belongs to another post,
- reassignment to another post,
- insertion or removal of media blocks in post content,
- managing featured images.

## Acceptance criteria

1. `Detach from post` is available as a bulk action.
2. Detaching an item leaves it in the WP media library and leaves the external source file untouched.
3. Detaching an already detached item succeeds without an error message.
4. `Remove` on an item attached to the current post detaches it and deletes its library record.
5. `Remove` on an item not attached to the current post removes the plugin-managed library record as before.
6. A mixed bulk remove selection correctly detaches current-post media before removing its library record.
7. Results describe the action performed and the final state for every item.
8. The table updates affected rows without losing its current selection.

## Notes

`Detach` is the safe, non-destructive operation for keeping a media item in the WordPress library. `Remove` is intentionally stronger: it reverses `Attach to post` completely by removing both the current-post association and the plugin-managed library registration.
