<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 021: Allowed base directory for source roots

## Goal

Make every external source root live under a directory the site owner controls,
by default the WordPress uploads directory.

## Background

An earlier version accepted any absolute, existing, readable directory as a
root. Restricting it through an administrator-editable setting was rejected
because the administrator edits both values. Two points reopened the question:

- `manage_options` does not imply the right to run code on multisite networks
  or on sites with `DISALLOW_FILE_MODS`, where a free root lets an administrator
  reach files they could not otherwise read.
- Docker deployments can mount the wanted directories inside the uploads
  directory, so the restriction costs those setups nothing.

## Behavior

- The base directory is resolved by `AllowedBase::resolve()`: the
  `WP_MEDIA_HELPER_ALLOWED_BASE` constant, else the uploads base directory, then
  filtered through `wp_media_helper_allowed_base`. A result that is not a
  non-empty string (for example `false`) means no restriction.
- A root is allowed when, after `realpath()`, it is strictly below the base.
  The base itself is not allowed, so a dedicated sub-directory is always used.
- Saving a source whose root is outside the base fails validation, with a
  message naming the base.
- Sources already stored outside the base are **disabled at runtime** (they are
  ignored by the editor panel, bulk actions and indexing) and listed in a
  warning on the settings page. Stored data is not modified.
- The root field's help text names the base directory.

## Public URLs

Files under uploads are served by the web server. For each source inside
uploads, the settings page shows its public URL prefix and reminds the
administrator to block it if the files are private. The README gives Apache and
nginx rules.

Automatic protection was considered and not implemented:

- read-only mounts cannot receive an `.htaccess` file,
- nginx ignores `.htaccess`,
- a managed block in `uploads/.htaccess` could clash with other plugins, and
- a later slice may need to serve some external files.

It can be revisited if the plugin starts serving files itself.

## Non-goals

- Restricting the base from the admin UI.
- Migrating stored sources automatically.

## Acceptance criteria

1. Roots outside the allowed base are rejected on save.
2. Stored roots outside the allowed base are disabled and reported.
3. The base is configurable only by constant or filter, and can be lifted.
4. `..` and symbolic links cannot be used to escape the base.
5. The settings page shows the public URL prefix of sources inside uploads.
