<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 050: Cleaning the attachments of the library

Status: **implemented** (unit tests with an in-memory database; never tried on a real site). Asked for by the rollout that precedes the move of the
mounts ([049](049-pause-and-path-remap.md)): the move should start from a clean base. Related: [010](010-remove-from-wp-media-library.md) (removal of the records).

## What it does

`wp media-helper attachments clean (--source=<ids|roots|names> | --prefix=<paths>) [--duplicates] [--missing] [--expect-duplicates=<n>] [--expect-missing=<n>] [--save=<file>] [--format=json] [--yes]`

Dry run unless `--yes`; the flags are declared in the positive. At least one of `--duplicates` and `--missing`; at least one of `--source` and `--prefix` (`--prefix=.` is the whole library, never a default).

- **Scope**: posts of type `attachment` whose `_wp_attached_file` is under the roots of the sources or the prefixes. The other posts that carry that meta
  (the folder records of a media library plugin, post type `mgmlp_media_folder`, for example) are **counted by type** (`ignored`) and left alone.
- **Duplicates**: the same `_wp_attached_file` and the same `post_parent`. The lowest ID stays; the others are removed; the featured images that pointed to a
  removed twin point to the one that stays. When the parents differ nothing is removed and the group is reported (`parents_differ`).
- **Missing**: the file is not at the registered path. The duplicates this same run removes are not counted again (so a second run finds nothing). Refused, and the
  directory named, when a directory of the scope cannot be read or is empty, or when **every** attachment of a directory (more than one) has no file: an unmounted
  Nextcloud would make everything look orphan.
- **Guards on the numbers**: `--expect-duplicates=N`, `--expect-missing=N`, exact comparison; otherwise nothing is changed, the report says so and the exit code is 1.
- **`--save=<file>`** (required with `--yes`): the list (category, id, path, mime, parent, date, title, author, where it is used, the twin kept) written before any removal, as
  a new file (never overwritten, mode 0600), in a directory that exists and **outside the uploads directory** (which is public).
- **Report** (`--format=json`: the only output on the standard output; progress on the error output): `run`, `applied`, `saved`, `prefixes`, `scanned`,
  `ignored` (object), `duplicates {count, groups, parents_differ, items}`, `missing {count, items}`, `expected`, `removed` (ids), `problems`. Each item has `id`, `path`, `parent`, `mime`,
  `media` (the type before the slash of the mime type), `date`, `title`, `author`, `used {featured: [post ids], content: [post ids with wp-image-<id>]}`, and, for the missing
  ones, `found_elsewhere` (up to five paths of the index where a file of the same name is found; only as up to date as the index).

## What removing an attachment does here

- **Files**: never touched. `RecordRemover` removes `_wp_attached_file` first (core then finds no file, no size to delete) and refuses every `wp_delete_file` during the call.
  That is also what the removal of the panel (*Remove from library*) and the reset do, and it is the only removal that keeps a twin's file.
- **The index** does not depend on the attachments: no hook on the deletion, nothing to keep consistent. A file is shown as registered as long as an attachment keeps its path
  (`_wp_attached_file`), so a twin keeps it registered; a file whose last attachment is removed becomes importable again, which is right.
- **The thumbnail cache**: the plugin purges the cache files of the sizes listed in the metadata of a deleted attachment (`delete_attachment`), from its `_wp_attached_file`.
  Two attachments of the same path share the same cache files, so a normal deletion of the duplicate would delete the twin's cache thumbnails (made again on demand). Removing
  `_wp_attached_file` first avoids it: that purge then finds no path and does nothing.
- **Featured images**: core deletes the `_thumbnail_id` metas that point to a deleted attachment. For a duplicate they are moved to the twin first. For a missing file nothing can
  replace the image: the report says where it is used, and the saved list keeps it.

## Correction of `paths remap` made with it

`paths remap` only considers posts of type `attachment`. The others that carry a `_wp_attached_file` under an old prefix are reported in `ignored` (post type => count) and no longer as missing.
