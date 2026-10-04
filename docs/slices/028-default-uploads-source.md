<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 028: Default source on the uploads directory

Status: **proposed** (design only, not implemented). Depends on
[slice 024](024-source-priority-and-ownership.md) (a root may be the uploads
directory, ownership, exclusions), [slice 025](025-database-file-index.md) (cheap
incremental scanning) and [slice 022](022-wordpress-native-registration.md)
(recognition of attachments created by any tool).

## Goal

Work out of the box: a site that never configured a source behaves as if it had one
source, the WordPress uploads directory, with default settings. Files dropped there
by FTP, Nextcloud, a camera uploader or another import plugin can be listed and
imported without any configuration.

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

### Where the default lives

- The option holding the sources can be **absent** (never saved) or **present**,
  possibly with an empty list.
- Absent: the effective configuration is one **implicit** source `uploads`, named
  "Uploads", root = the allowed base (the uploads directory), state Active, default
  patterns, mtime fallback on, no thumbnail cache. It is shown on the settings page
  like any other source, marked as the default, and is not written to the database.
- As soon as the settings are saved, the list shown (including the default source,
  if the administrator kept it) is stored. An empty saved list means **no source**:
  the administrator can switch the default off.
- Nothing is migrated: a site that already saved sources keeps exactly them.

### Built-in exclusions

The default source, like every source rooted at the uploads directory, never lists:

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

- The settings page shows the default source with a note: "Default source: the
  uploads directory. Save the page to keep it, or remove it to use only the sources
  you add."
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

1. A fresh install lists unattached files of the uploads directory with no
   configuration, and files already attached show as attached.
2. A site with a saved empty list has no source.
3. The files of the built-in excluded directories, of the thumbnail caches and of
   dot directories are never listed nor importable, whatever `source_id` is sent.
4. The `wp_media_helper_excluded_directories` filter adds directories.
5. The default source is shown on the settings page and survives a save.
6. No warning about public exposure is shown for the default source.
7. A site with saved sources is unchanged after the upgrade.

## Open questions

- Opt-out (default on) as proposed here, or opt-in with a one-click "Use the uploads
  directory" on the settings page. Opt-in is safer for sites with private files in
  uploads; opt-out is what makes the plugin work out of the box.
- The contents of the built-in exclusion list.
- Whether derivatives of non-image files and previews generated by other plugins need
  a more general rule than the names used today.
