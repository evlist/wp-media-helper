<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 028: Default source on the uploads directory

Status: **proposed** (design only, not implemented). Depends on
[slice 024](024-source-priority-and-ownership.md) (a root may be the uploads
directory, ownership, exclusions), [slice 025](025-database-file-index.md) (cheap
incremental scanning) and [slice 022](022-wordpress-native-registration.md)
(recognition of attachments created by any tool).

## Goal

Make the most common configuration one click away: the settings page offers
**Use the uploads directory**, which adds one source on the WordPress uploads
directory with default settings. Files dropped there by FTP, Nextcloud, a camera
uploader or another import plugin can then be listed and imported without any other
configuration.

**Decision:** opt-in. The default is not applied silently, because the uploads
directory may hold private files; the administrator chooses it knowingly.

## Analysis

What the configuration gives up compared with dedicated sources:

- **Scan time.** The whole uploads tree is scanned instead of a few trees. The scan
  is incremental and resumable (slice 025), and the file types, derivatives and
  attachments already known are cheap to skip, so this only matters for the first
  pass on a very large site.
- **Per-source settings.** One name pattern, one mtime policy, one path pattern for
  the whole tree. A site that needs different rules for `uploads/photos` and
  `uploads/gpx` adds sources before it (slice 024).
- **Filtering by source.** There is a single source, so the source filter has
  nothing to separate. The panel can hide that filter when only one source exists.

These are minor. Two things are not, and must be settled for the default to be safe:

1. **The uploads directory holds more than media.** Besides the files of the Media
   Library it often holds private or technical trees: `woocommerce_uploads`
   (protected downloads), backup and migration archives, caches, logs, form
   attachments, plugin working directories. A default source would offer them to
   everyone with `upload_files`, and importing one would create a public attachment
   from a private file.
2. **Other tools' derivatives and caches** (`thumbnails/` of Thumbnails Folder,
   `cache/`, `-LxH` and `-scaled` variants) would be listed as media.

Other consequences, all acceptable:

- Files created by WordPress itself are already attachments: they show as attached
  (slice 022), and derivatives are filtered by name and by attachment metadata.
- Their date is the upload date through the modification time, which is the date
  WordPress gives them anyway (slice 026 may refine it with embedded dates).
- URLs under uploads are already public: the warning about exposed directories is
  not shown for this source, since nothing is newly exposed.

## Behavior

### The one-click source

- When no source is configured, the settings page shows a notice with a button
  **Use the uploads directory**. It adds, and saves, a source `uploads`, named
  "Uploads", root = the allowed base, state Active, default patterns, mtime fallback
  on, no thumbnail cache, placed last in the list (so narrower sources added before
  it keep their files). It is then an ordinary source: it can be edited, reordered
  or removed.
- The button also stays available when sources exist but none covers the uploads
  directory.
- Nothing is stored or scanned until the button is used. Existing configurations are
  never touched.

### Built-in exclusions

A source rooted at the uploads directory (the one-click source or one added by hand), never lists:

- every directory whose name starts with a dot (already skipped by the scanner);
- the thumbnail cache directories (slice 024), and the global cache when slice 023
  exists;
- a **built-in list of private or technical directories**, relative to uploads
  (for example `woocommerce_uploads`, `wc-logs`, `cache`, `backup*`, `ai1wm-backups`,
  `wpforms`, `elementor`, `sucuri`), extensible by the site owner with a filter
  `wp_media_helper_excluded_directories` (the same trust model as the allowed base:
  code, not the admin UI) and shown on the settings page;
- the directories of the Media Library layout are **not** excluded: they hold the
  files users expect to find, and attachments are recognised by path.

The list is a **default, not a guarantee**: a directory not on it can still be
listed. The README says so, and recommends the opposite choice (a dedicated
directory, the default source switched off) for sites that keep private files in
uploads.

### Safer import for the default source

- Only the file types WordPress accepts for the current user are offered
  (`wp_check_filetype()` with the user's allowed mime types), as for any source.
- Importing from the default source requires `upload_files`, as before. Sites that
  want it limited to administrators can use the existing capability filter.

## Interface

- The notice and button above, with a sentence saying what the directory may
  contain and pointing to the exclusions.
- The source filter of the editor panel is hidden when there is a single source.
- A first visit to the panel schedules the first pass in the background (slice 025);
  the panel shows the existing "indexing in progress" message until it is done.

## Security

The default widens what a user with `upload_files` can import, so the built-in
exclusions are part of the feature, not an option: without them the default is
refused. The scanner keeps ignoring links and anything outside the root; ownership
and path confinement (slice 024) are unchanged. The built-in list is covered by tests
and by a documented residual risk.

## Non-goals

- Guessing which uploads sub-directories are private from their content.
- Per-user visibility of files.
- Changing the behavior of sites that already saved a configuration.

## Acceptance criteria

1. After one click on **Use the uploads directory**, unattached files of the uploads
   directory are listed with no other configuration, and files already attached show
   as attached.
2. Without the click, no source exists and nothing is scanned.
3. The files of the built-in excluded directories, of the thumbnail caches and of
   dot directories are never listed nor importable, whatever `source_id` is sent.
4. The `wp_media_helper_excluded_directories` filter adds directories.
5. The source created by the button is an ordinary source and survives a save.
6. No warning about public exposure is shown for a source on the uploads directory.
7. A site with saved sources is unchanged after the upgrade.

## Open questions

- The contents of the built-in exclusion list.
- Whether derivatives of non-image files and previews generated by other plugins need
  a more general rule than the names used today.
