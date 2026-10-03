<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Security audit and residual weaknesses

Second audit of the plugin, made after the hardening described in the
[constraints](constraints.md) and in slices
[020](../slices/020-pagination-and-entry-limit.md) and
[021](../slices/021-allowed-base-directory.md).

## Scope and limits

- Manual review of all PHP and JavaScript under `plugin/`, version 0.1.0.
- The findings come from reading the code. The PHP logic was exercised with
  unit tests and small stubs; **nothing was tested inside a running WordPress,
  with a real web server, or with the WordPress test suite**. Statements about
  WordPress core or web-server behavior are marked *to verify* when they were
  not checked.
- No third-party library is bundled, so there is no dependency audit.
- Out of scope: WordPress core, the web server, the host.

## Trust model recap

| Actor | Trusted to | Not trusted to |
|-------|------------|----------------|
| Site owner (code, `wp-config.php`, web server) | Define the allowed base directory, server rules | — |
| Administrator (`manage_options`) | Choose sources inside the allowed base | Reach the rest of the file system (matters on multisite and hardened sites) |
| Users with `upload_files` | Import and attach files from enabled sources | Choose paths outside source roots |
| Users with `edit_posts` | Browse the panel for their posts | Import, delete or modify other users' media |
| Anyone else | — | Everything |

## Fixed since the first audit

| Issue | Fix |
|-------|-----|
| Any readable file could be registered as an attachment (`import` / `attach`) | Paths are canonicalised and must be below the root of an enabled source; `upload_files` is required; file type is checked with `wp_check_filetype()` |
| Deleting or detaching other users' attachments | Per-attachment `delete_post` / `edit_post` checks |
| Symbolic links in the scanner | Links are ignored and each file is verified against the real root |
| Index stored in the world-writable temp directory; cached entries trusted | Private folder (`0700`) with guard files; entries reduced to plain path strings |
| Invalid dates reaching `DateTimeImmutable` | Strict `Y-m-d` validation with fallback |
| Unbounded bulk requests and listings | Configurable `max_entries` (1-500, default 100) with pagination |
| Absolute directory and foreign `other_post_id` returned to the browser | Removed / blanked |
| Roots anywhere on the file system | Roots and thumbnail caches confined to an allowed base directory |
| **`path_pattern` could list files outside the root** (found in this audit) | The resolved directory is verified to be inside the root (`SourceIndexer`); `..` and links are rejected. Earlier documentation wrongly said this was already covered |

## Residual weaknesses

Severity reflects impact on a typical deployment if the weakness is exploited,
not the effort needed to fix it.

### R1 - High: files under uploads may be downloadable or executable

Roots must be under the uploads directory, which web servers serve. Anything in
a mounted Nextcloud folder is therefore reachable over HTTP. Worse, if the web
server executes PHP in uploads (the default of many setups, including the
official WordPress Docker image), **a `.php` file placed in a source by anyone
who can write to the source (for example a collaborator on a shared Nextcloud
folder) can be executed**. A read-only mount does not prevent execution.

- Status: *documented*. The settings page warns about public access, and the
  README gives rules for denying access and script execution.
- Mitigation: deny PHP execution and, for private files, all access to the
  source directories and thumbnail caches in the web-server configuration.
  Alternatively the site owner can set `WP_MEDIA_HELPER_ALLOWED_BASE` to a
  location that is not served.
- Not fixable inside the plugin: the web server decides.

### R2 - Medium: the index is reachable over HTTP where `.htaccess` is not honored

The index (`uploads/wp-media-helper-index/<source>-<date>.json`) lists the
absolute paths of the files of a source. It has a predictable name and is
protected only by `.htaccess` and an `index.php`. On nginx, or on Apache with
`AllowOverride None`, it can be downloaded and discloses file names and server
paths. Moving the index from the temp directory to uploads introduced this
exposure on those servers.

- Status: *open*.
- Recommendation: store the index outside any served directory (a path
  filtered by the site owner, or the database), or at least use an unguessable
  directory name stored in an option.

### R3 - Medium: public attachment metadata discloses file names and server paths

Status: **fixed for new registrations** by [slice 022](../slices/022-wordpress-native-registration.md):
the `guid` is a URL, the provenance meta holds a relative path, and no stored
metadata holds a server path. Attachments from earlier versions are rewritten
when next recognised. File names and titles remain public, as for any
attachment.

Importing creates an attachment whose `guid` is the absolute file-system path
and whose title is the file name. Attachments with no parent are normally
readable anonymously through the REST media endpoint (*to verify*), so
`guid.rendered` may disclose the server layout and file names of private
sources, even for files never published.

- Status: *open*.
- Recommendation: set `guid` to the public URL (core already maps absolute paths
  inside uploads to URLs), and decide whether unattached imports should be
  readable by anonymous users at all.

### R3b - Medium: absolute server paths in other plugins' attachment metadata

Observed on the target site and confirmed in the sources of Thumbnails Folder
1.4.0 and WordPress trunk: Thumbnails Folder deliberately stores an absolute
`path` in each size entry of `_wp_attachment_metadata`, and the REST media
endpoint returns `media_details` as stored. The server path of every thumbnail
is therefore part of the API response. This plugin does not write such keys;
the migration planned in [slice 023](../slices/023-thumbnails-in-cache.md)
removes them, and a REST filter can hide them in the meantime. Check
`/wp-json/wp/v2/media/<id>` on the site to see what is exposed.

### R4 - Medium: any user with `edit_posts` can enumerate sources and force rescans

The panel and the listing endpoint require only `edit_posts`. A Contributor
cannot import (no `upload_files`) but can list the file names of every enabled
source for any date, and `force_refresh` triggers a rescan on every call with
no rate limit.

- Status: *open*.
- Recommendation: require `upload_files` (or a dedicated capability) for the
  panel and the listing, and throttle forced refreshes per user.

### R5 - Low to medium: resource exhaustion by authenticated users

Status: attachment lookups are now exact queries on `_wp_attached_file`, in
chunks, instead of loading every imported attachment. The scan itself and forced
refreshes are unchanged.

`max_entries` bounds responses and bulk size, not the work done to produce
them: a scan reads the whole resolved directory tree, each editor tab polls
every 30 seconds, and attachment lookups load every imported attachment
(`get_posts` with no limit) once per item of a bulk request.

- Status: *open*, partly mitigated by the persistent index and the pagination.
- Recommendation: cap the number of scanned files and the scan depth, look up
  attachments by indexed meta value, and share one lookup per request.

### R6 - Low: removal depends on WordPress internals to spare the original file

Status: **fixed** by slice 022. During removal a `wp_delete_file` filter refuses
every deletion, in addition to removing `_wp_attached_file` first. Removal applies
to the attachments of the file whichever tool created them, since it never deletes
a file.

`remove` deletes the attachment with `wp_delete_post( $id, true )`, after
deleting `_wp_attached_file` so that core finds no file to delete. If another
plugin or filter restores that value, WordPress would delete the original file
of a source whose root is writable.

- Status: *open*.
- Recommendation: add a `wp_delete_file` guard that refuses any path inside a
  source root or cache.

### R7 - Low: cross-user state leakage

Attachment state (imported, attached to another post) is computed over all
imported attachments whoever owns them, and `import` reports success for an
attachment owned by another user. No content leaks, but existence and
attachment status do.

- Status: *open*, accepted for a shared editorial workflow.

### R8 - Low: unsanitized post meta

`wp_media_helper_date` is registered with `show_in_rest` and no sanitize
callback, so a user who can edit the post can store an arbitrary string. It is
validated when used.

- Status: *open*. Recommendation: add a `sanitize_callback` that applies the
  same `Y-m-d` validation.

### R9 - Low: checks happen at action time only

Path confinement is verified when an action runs. Nothing reads or serves
external files today, so a time-of-check/time-of-use race is not exploitable
yet. Any future feature that reads, serves or writes these files (thumbnails,
downloads) must re-resolve and re-check the path at that moment. Rules for the
thumbnail cache are in [slice 021](../slices/021-allowed-base-directory.md).

### R10 - Low: robustness and cleanup

- A corrupted source option (not an array) makes `ExternalSourceSettings::getAll()`
  throw, which ends an AJAX request with a server error instead of a message.
- There is no uninstall routine: options, user meta (filters, panel mode) and
  the index files remain after the plugin is deleted.

### R11 - Informational

- One nonce is shared by all editor endpoints. It is bound to the user and
  session, and every endpoint also checks a capability, so this is acceptable.
- Output is escaped (`esc_html`, `esc_attr`, `esc_url`) in PHP, and the
  JavaScript renders text through React or an escaping helper. No `eval`,
  `unserialize`, shell execution or user-controlled regular expression exists.
- On a single-site installation without `DISALLOW_FILE_MODS`, an
  administrator can already run code. No plugin setting can prevent a malicious
  administrator, and none is claimed to.

## Test gaps

The unit tests cover the pure logic (confinement, validation, pagination,
filters). The AJAX handlers, capability checks, nonce handling and settings
page are not covered by automated tests, because they need WordPress. Adding
integration tests with the WordPress test suite would protect the controls
above from regressions; capability checks for each endpoint and role are the
first candidates.

## Planned fixes

Several residual weaknesses are addressed by the proposed slices:

- R3 (server path in `guid`), R3b and R5 (attachment scan): [slice 022](../slices/022-wordpress-native-registration.md).
- R6 (deletion of originals): the `wp_delete_file` guard of slice 022.
- Resource exhaustion by on-demand thumbnails (new risk): the allow-list and
  limits of [slice 023](../slices/023-thumbnails-in-cache.md).
- R1, R2 and R4 remain independent of those slices.

## Suggested order of work

1. R2 and R3: both are code changes with a clear fix.
2. R4: a one-line capability change plus throttling.
3. R6 and R8: small guards.
4. R5 and R10: robustness.
5. Integration tests for the AJAX handlers.
