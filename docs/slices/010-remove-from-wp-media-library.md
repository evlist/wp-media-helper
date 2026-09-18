# Remove from WP media library

## Summary

This slice covers the complementary action to import: removing a media item that has already been imported into the standard WordPress media library. The objective is to keep the media lifecycle symmetrical and explicit, so the plugin can both import a file from an external source and later detach it from WordPress when needed.

## Context

The previous slice introduces the import flow: a user selects an item from the external media list and imports it into WordPress. Once imported, the item becomes part of the standard WordPress media library, and the plugin must be able to manage that state gracefully.

The next step is the reverse operation: if an imported file is no longer desired in the library, the editor should be able to remove it cleanly without affecting the external source itself.

## User story

As a content editor, I want to remove a media item from the WordPress media library when it is no longer needed, without deleting it from the external source that originally supplied it.

## Goals

- support removal of an already imported WordPress media item,
- keep the external source intact,
- update the UI state immediately after removal,
- preserve a clear distinction between external source state and WordPress library state,
- keep the wordings and lifecycle simple and explicit.

## Functional requirements

### 1. Removal is explicit

The user must trigger a deliberate remove action on an item that is currently in the WordPress media library.

The action should be clearly named and positioned as a management action, not as a destructive action on the external source.

### 2. External source remains untouched

Removing the WordPress attachment must not delete or modify the file in the external source directory. It only removes the WordPress-side record of the imported media item.

### 3. UI state is recalculated immediately

After removal, the list item must be updated to reflect the new state:

- Not in WP media library

This update should not require a full editor reload.

### 4. Import and removal are symmetric

The lifecycle should remain coherent:

- import makes the file part of WP media library,
- remove makes it leave the WP media library,
- the external source remains the authoritative source of discovery.

### 5. Re-import remains possible

Once removed from the WordPress library, the same external item should again be eligible for import in the panel without ambiguity.

## Non-goals

This slice does not include:

- deleting files from the external source filesystem,
- deleting files from the WordPress filesystem outside the attachment lifecycle,
- bulk removal of all imported media,
- editing metadata for imported media beyond standard WordPress attachment behavior.

## Acceptance criteria

1. A user can remove a media item that is currently in the WordPress media library.
2. The external source file remains intact after removal.
3. The UI state updates immediately to “Not in WP media library”.
4. The item can be imported again later without ambiguity.
5. The operation remains distinct from source refresh or external file deletion.

## Notes

This slice complements the import flow and keeps the lifecycle honest: the WordPress media library is a local management layer on top of the external source. The external source is still the place where files are discovered, while the WordPress library is the place where they may be imported and later removed.
