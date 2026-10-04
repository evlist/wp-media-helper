<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 036: Changing a source, and files that went missing

Status: **proposed** (design and open questions only, not scheduled). Answers the open question
"Editing a source with existing media" of the [open questions](../IA/media-source-open-questions.md).
Builds on [022](022-wordpress-native-registration.md), [024](024-source-priority-and-ownership.md) and
[025](025-database-file-index.md).

## Goal

Say what happens to the media already imported when a source is changed or removed, and when the files
themselves move on disk, and give the administrator what they need to repair it.

## What each change does today

| Change | Index | Imported attachments |
|--------|-------|----------------------|
| Rename a source | Unchanged (the identifier is kept) | Unchanged |
| Reorder, change a state | Rescanned (the exclusions changed) | Unchanged: recognised by their file path, not by the source |
| Change the root | The source is reset and rescanned | Unchanged; still recognised if the files stay where they are |
| Remove a source | Its rows are forgotten by the maintenance run | Unchanged; their provenance meta names a source that no longer exists |
| A file is **renamed or moved on disk** (a Nextcloud sync, a tidy-up) | The old row goes missing, a new row appears | **The attachment now points to a path that does not exist**: broken images in posts and in the library |

The last line is the real exposure: the plugin stores a path, and the files live in a folder that something
else manages.

## Behavior

1. **A report of attachments whose file is missing.** A section of the settings page (and a count in the
   admin notice when it is not zero) lists the attachments imported by this plugin whose file does not exist,
   from `_wp_attached_file` and the uploads directory, bounded and paged. It runs on demand, and as a
   background check after a scan reports many missing files.
2. **Relinking.** For a missing file the index offers candidates: a file with the same name and size, or the
   same size and modification time, in an active source. Actions per attachment: *Relink* (update
   `_wp_attached_file`, the provenance path and the `guid`; no file is touched), *Remove from the library*
   (the existing action), *Ignore*. A bulk *Relink all that have exactly one candidate*, with a summary.
3. **Removing a source** shows how many imported attachments it covers and says they are kept; an option
   *Forget their provenance* clears the provenance meta of those attachments.
4. **Changing a root** shows how many imported attachments would no longer be under any active source (they
   keep working as attachments, but the panel will not show their file as "in the library" for that source).
5. **Rename detection in the index** (shared with the hidden-file flags of [027](027-hidden-files.md)): a
   missing file and a new file with the same size and modification time in the same scan are treated as one
   file that moved, so flags and, with this slice, attachments can follow it automatically, with a log line.

## Questions to settle

1. **Automatic or asked.** Relinking is safe when the size, the modification time and the name agree;
   anything less is proposed, never done silently.
2. **Where the check runs.** On every scan (cheap: a flag on the files that have an attachment), or on demand.
3. **Attachments of other tools** (Bulk Media Register): the plugin recognises them by path, so the report may
   include them with a different label, or only list those it created.
4. **Thumbnails** of a relinked file: the cache is keyed by the relative path of the original, so the old
   sizes are orphans (purge) and the new ones are made when asked.
5. **Posts that embed the old URL** in their content are not rewritten here; the `guid` and the attachment
   URL change, the text of posts does not.

## Non-goals

- Rewriting the content of posts.
- Watching the file system.

## Acceptance criteria

1. The report lists exactly the attachments whose file is missing, and nothing else.
2. Relinking changes the attachment's path, URL and provenance and never a file.
3. Removing or re-rooting a source tells the administrator what stays imported.
4. Nothing is changed without a click, except the optional automatic relinking of exact matches, which is off
   by default.
