<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 039: The trash

Status: **implemented**. Replaces the "hidden files" wording and behavior of [027](027-hidden-files.md).

## Why

A "hidden" file suggested a display setting, but the intent is the one of a trash can: the file stays on the disk (some
sources are read-only), it is out of the gallery, and it must not stay in any post. It is seen again only by looking into
the trash.

## Behavior

- **Move to trash** (item menu, detail sheet, bulk action; red): after a confirmation, the attachments of the file are
  deleted from the media library, without deleting any file, whichever post they were attached to; the file is flagged in
  the index and its thumbnails are deleted from the cache. A featured image of the current post is removed.
- The confirmation says that the entries (title, caption, description) are lost and cannot be recovered, names the other
  posts concerned, and says that an image block inserted in a post keeps showing.
- **Restore from trash** and **Show the trash** (users allowed to): the file is back in the gallery, not attached, with a new
  media library entry only when it is imported again.
- Permissions: `wp_media_helper_can_trash_files`, `wp_media_helper_can_see_trash`; plus `delete_post` on each attachment.
  If one of them is refused, nothing is done for that file.

## Open

- Keep the properties of the deleted attachments (title, caption, alt text, parent post) so that a restore can bring them back.
- A view of the whole trash, and emptying it (forgetting the flags).
- Images inserted as blocks in the content are not touched; a report of the posts that still use the file could help.
