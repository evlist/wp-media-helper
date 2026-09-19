# Simple and advanced media panel modes

## Summary

This slice introduces two presentation modes for the editor media panel:

- **Simple mode**, the default, focuses on the editor's post-level workflow: attach media to the current post or remove it completely.
- **Advanced mode** exposes the underlying WordPress media-library lifecycle for editors who also manage media through the native WordPress library.

Both modes use the same external-source discovery, attachment records, AJAX endpoint, and server-side lifecycle rules. The difference is only which states and actions the panel exposes.

## Context

The existing model distinguishes two independent states for every external media item:

- whether a plugin-managed WordPress attachment record exists,
- whether that attachment is associated with the post currently being edited.

These distinctions are necessary for correct WordPress integration, but they are not equally useful to every editor.

An editor who works only through this plugin generally thinks in terms of the current post: a file is attached or not attached. Whether WordPress currently has a separate attachment record is an implementation detail because `Attach` creates it automatically and `Remove` removes it as part of the complete reverse operation.

An editor who also uses the standard WordPress Media Library needs the detailed distinction. That editor may want to import a file without attaching it, detach it while retaining it in the library, or inspect library presence independently from current-post attachment.

## User stories

As a content editor who works only in the post editor, I want a compact media panel that shows only whether files are attached to this post and offers only the actions I need.

As an editor who also uses the WordPress Media Library, I want to switch to an advanced view that exposes library presence and the additional lifecycle actions.

## Goals

- make Simple mode the default panel experience,
- keep Simple mode focused on current-post attachment,
- retain Advanced mode for library-aware workflows,
- let each user switch modes without affecting other users or post content,
- preserve the exact same server-side lifecycle and safety rules in both modes,
- maintain a compact table suitable for the editor sidebar.

## Mode selector

The panel provides a compact mode selector near the media-table controls. It presents:

- `Simple`,
- `Advanced`.

The selector should use an appropriate compact WordPress control, such as a segmented control or menu button. It must not consume a full table column or compete with the media actions.

Changing mode updates the visible status labels and actions immediately. It must not refresh the external source, clear the current item selection, or alter any media state.

## Preference persistence

The selected mode is a user-interface preference, not post content and not source configuration.

The plugin must persist it per WordPress user, preferably through user meta or the WordPress preferences store. The preference must:

- default to `simple` when it has never been set,
- apply across posts opened by the same user,
- not affect other users,
- not be stored in post metadata,
- not alter the external media source or attachment records.

If persistence is unavailable, the panel must still default safely to Simple mode for the current editor session.

## Simple mode

### Visible state

Simple mode exposes one status per file:

- `Attached to current post`,
- `Not attached to current post`.

It does not display whether the file is in the WP media library. A file that is registered but not attached and a file that is not registered at all have the same Simple-mode state: `Not attached to current post`.

### Available actions

Simple mode exposes only post-oriented lifecycle actions:

- `Attach` for an item not attached to the current post,
- `Remove` for an item attached to the current post.

The bulk-action menu exposes the same two actions:

- `Attach to post`,
- `Remove`.

`Attach` registers a virtual WordPress attachment automatically when necessary, then attaches it to the current post. The editor must not see an extra import step or confirmation.

`Remove` is the complete inverse of `Attach`: it detaches the item from the current post when applicable, then removes its plugin-managed WordPress media-library registration. It never deletes or alters the external source file.

### Hidden actions

Simple mode does not expose:

- `Import`,
- `Detach from post`,
- WP media-library status,
- media-library-specific terminology.

These operations remain available internally because the server uses them to implement the Simple-mode lifecycle, but they are not part of the Simple-mode vocabulary.

## Advanced mode

### Visible state

Advanced mode exposes both lifecycle dimensions:

- `In WP media library` / `Not in WP media library`,
- `Attached to current post` / `Not attached to current post`.

The current implementation may present the two states in a compact shared status cell to preserve sidebar width.

### Available actions

Advanced mode keeps all management actions:

- `Import`,
- `Attach`,
- `Detach`,
- `Remove`.

Their semantics remain unchanged:

- `Import` registers a virtual WP attachment without attaching it,
- `Attach` registers if necessary, then attaches to the current post,
- `Detach` removes only the current-post association,
- `Remove` removes the plugin-managed WP attachment record, detaching from the current post first when necessary.

The bulk-action menu exposes the same action set. Individual row actions should remain compact and contextually relevant while allowing the advanced editor to access all applicable operations.

## Functional requirements

### 1. One server model

Mode selection must not create separate endpoints or duplicate lifecycle logic. Both modes must continue to use the unified bulk-action contract, including row actions represented as a one-item bulk request.

The server must continue to decide import, attachment, detach, and removal state. It must not trust mode-specific client state.

### 2. Stable selection

Changing modes must preserve the selected item set. The same selected items must be eligible for an action after the view changes.

### 3. Action availability

The client may hide actions that do not belong to the current mode, but it must not change their server-side semantics.

A Simple-mode `Remove` must remain safe and complete even if the selected item was previously imported through Advanced mode.

### 4. Current post requirements

Actions that attach, detach, or remove media require a persisted current post and server-side `edit_post` permission validation, as defined by the previous slices. Mode selection does not relax or bypass these checks.

### 5. Result handling

The existing per-item result contract remains mode-neutral. The client updates hidden state fields even in Simple mode so that switching to Advanced mode immediately reflects the correct underlying state without requiring a special migration or reset.

## UI behavior

The table remains compact in both modes.

In Simple mode, the status cell uses the single current-post attachment state. In Advanced mode, the same area presents the two detailed state lines. The table must not introduce horizontal scrolling solely because Advanced mode shows more information.

The bulk-action selector should update its available actions when the mode changes. If a previously selected action is not available in the newly chosen mode, it resets to its neutral `Bulk action` state.

## Non-goals

This slice does not include:

- changing source discovery or refresh behavior,
- changing the virtual attachment implementation,
- adding new WordPress media actions beyond the existing lifecycle,
- changing post content or inserting media blocks,
- user roles or capability changes,
- organization-wide enforcement of one mode.

## Acceptance criteria

1. The panel opens in Simple mode for a user with no saved preference.
2. Simple mode displays only the current-post attachment state.
3. Simple mode exposes only `Attach` and `Remove` actions.
4. Simple-mode `Attach` automatically imports when necessary.
5. Simple-mode `Remove` detaches and removes the plugin-managed library record without touching the external source file.
6. Advanced mode displays both library presence and current-post attachment state.
7. Advanced mode exposes `Import`, `Attach`, `Detach`, and `Remove` as applicable actions.
8. Switching modes preserves the current source/date result set and the selected rows.
9. The selected mode persists per user and does not modify post metadata.
10. Both modes use the same server-side bulk-action contract and preserve existing permission checks.

## Notes

Simple mode is not a different media lifecycle. It is a deliberately smaller vocabulary over the same lifecycle: the editor sees only the post-level intent, while the plugin performs the required virtual attachment bookkeeping underneath. Advanced mode remains available for users who need direct control over the WordPress Media Library layer.
