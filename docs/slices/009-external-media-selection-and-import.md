# External media selection and import

## Summary

This slice covers the user action that turns a discovered external file into a WordPress media item. The data and UI layers now know how to resolve and display the source/date list, and the next step is to let the editor select one of those files and import it into the standard WordPress media library.

## Context

The plugin already provides the following:

- configured external sources,
- date-based path resolution,
- file discovery for a requested source/date context,
- targeted refresh and cache validation,
- a media panel that presents a readable list of files.

The remaining gap is the actual “take action” flow: from a file listed in the external media panel, the editor must be able to import it into the WordPress media library and continue working with it as a normal media item.

## User story

As a content editor, I want to select one of the external media files shown in the panel and import it into the WordPress media library so that it can be managed like any other media asset.

## Goals

- allow the editor to select an external media item,
- import the selected file into WordPress in a controlled, explicit flow,
- keep the provenance of the file source alongside the imported item,
- update the list state immediately after import,
- prepare the path toward insertion into the editor content.

## Functional requirements

### 1. Selection in the enriched list

The user can select an item from the media list rendered by the panel. The item should expose enough metadata to identify it unambiguously, including:

- item id,
- file name,
- path,
- source id,
- date context,
- import status.

### 2. Import into the standard WP media library

When the user triggers import, the plugin creates a standard WordPress media attachment for the selected external file, using the same WordPress media lifecycle as any other upload or attachment.

### 3. Import status tracking

Each item must carry a status that indicates whether it is already present in the WordPress media library.

The relevant UI state is:

- In WP media library
- Not in WP media library

This status is a presence check, not a judgment on file type or file quality.

### 4. Source provenance is preserved

The imported representation should keep a record of which external source it came from, without losing the WordPress attachment metadata.

This enables the plugin to distinguish between:

- a WordPress-native media item,
- and an external item that has been imported into WordPress.

### 5. Immediate refresh of the item state

Once import succeeds, the media list must update immediately. The same entry should move from “Not in WP media library” to “In WP media library” without requiring a full editor reload.

## Non-goals

This slice does not include:

- insertion of the imported media into the post content,
- bulk import of all external files,
- deletion of external files from their source,
- managing upload conflicts across duplicates,
- building a full media-library browser.

## Acceptance criteria

1. A user can select an item from the external media list.
2. The selected item can be imported into WordPress through the plugin workflow.
3. The item state updates from “Not in WP media library” to “In WP media library” after successful import.
4. The source provenance remains available after import.
5. Items already imported are not re-imported blindly in the same workflow.
6. The operation is explicit and user-visible, without making the panel feel like a generic WordPress upload UI.

## Notes

This slice is intentionally focused on the import flow. It is the bridge between external source discovery and the standard WordPress media lifecycle. The next slice then deals with removal from the WordPress library, which is the symmetrical lifecycle action to the import step.
