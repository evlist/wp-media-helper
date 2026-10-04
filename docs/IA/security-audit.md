<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Security audit and residual weaknesses

Third audit of the plugin. The second was made after the hardening described in the
[constraints](constraints.md) and in slices
[020](../slices/020-pagination-and-entry-limit.md) and
[021](../slices/021-allowed-base-directory.md). This one covers what slices 022 to 028 added:
native registration, the thumbnail cache and the panel previews, source ownership and the
default uploads source, name patterns and embedded dates, hidden files and the Reset
tool. Sections are updated in place; the findings of this audit are listed first.

## Scope and limits

- Manual review of all PHP and JavaScript under `plugin/`, version 0.1.0, with a focus on
  every `wp_ajax_*` and `admin_post_*` entry point (below) and on each place that writes,
  deletes or serves a file.
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
| Administrator (`manage_options`) | Choose sources inside the allowed base, reset the plugin data | Reach the rest of the file system (matters on multisite and hardened sites) |
| Users with `upload_files` | Import and attach files from active sources, hide and show files site-wide, see previews of listed images | Choose paths outside source roots, or files owned by another source or excluded |
| Users with `edit_posts` | Browse the panel for their posts | Import, delete or modify other users' media |
| Anyone else | — | Everything |

## Entry points and their checks

| Entry point | Capability | Nonce | Notes |
|-------------|-----------|-------|-------|
| `wp_media_helper_media_panel_state` (listing) | `edit_posts` | `wp_media_helper_media_panel` | Hidden files only for `wp_media_helper_can_see_hidden_files`. See R4 |
| `wp_media_helper_bulk_media` | `upload_files` for import/attach; `edit_post` per attachment for attach/detach/remove; `wp_media_helper_can_hide_files` for hide/show | same | Paths resolved to a file of an active source by ownership; maximum number of items |
| `wp_media_helper_panel_mode`, `wp_media_helper_save_filter` | `edit_posts` | same | Values whitelisted |
| `wp_media_helper_thumbnail` (preview, GET) | `upload_files` | same, in the URL | Path resolved to an image of an active source, not hidden, size and memory limits. See R13 and R14 |
| `wp_media_helper_test_name_pattern` | `manage_options` | own nonce | Nothing stored; patterns limited (length, wildcards) |
| `admin_post_*`: save sources, rescan, use uploads, reset | `manage_options` | one per action | Reset also needs the word `RESET` |
| WP-Cron events (index run, maintenance, thumbnails) | none (internal) | — | Bounded by a time budget; the index run holds a lock |
| Front end (`image_downsize` filter) | anonymous | — | Creates a missing size of an attachment imported by this plugin. See R17 |

## Found and fixed in this audit

| Finding | Fix |
|---------|-----|
| **The Reset of the thumbnail cache deleted every file under the cache directory.** The directory can be moved with a filter; moved over a source (for example `uploads/photos`) a Reset would have deleted the originals | The Reset refuses when the cache is, or contains, the root of a source, and only deletes files named like thumbnails (`<name>-<W>x<H>.<ext>`) and the generator's temporary files. Directories are removed only when empty |
| A preview request for an SVG or a file other than a raster image could be answered with a script-capable content type | Raster images only (a file that is not recognised by `getimagesize()` gets a 404, SVG is refused explicitly), `nosniff`, and `Content-Security-Policy: default-src 'none'; sandbox` on the response |
| A very large image made the image editor run out of memory: a fatal error at every view of the page that rendered it (front end) or of the panel | A size is attempted only if the decoded image should fit in the memory limit (`wp_media_helper_thumbnail_memory_factor`), and a size that failed is not tried again for ten minutes |
| Wildcards in a name pattern multiplied the cost of a failed match | At most three `*` per pattern; PCRE limits already bounded the worst case |
| The name entered in the pattern test was not bounded | Cut to 255 characters |
| R8: `wp_media_helper_date` accepted any string | `sanitize_callback`: a real `Y-m-d` day or an empty value |
| R6: removal relied on core not finding the file | Fixed earlier (slice 022): `wp_delete_file` is refused for the duration of a removal, also in the Reset |

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
| **`path_pattern` could list files outside the root** (found in this audit) | The resolved directory is verified to be inside the root (`IndexScanner`); `..` and links are rejected. Earlier documentation wrongly said this was already covered |

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
- Update (slices 024 and 028): a source on the uploads directory itself is possible and the
  files of private folders it contains could be listed and imported. A built-in list of
  directories is never listed (`woocommerce_uploads`, backups, caches, logs, and the temporary
  folder of Bulk Media Register that a test found), extensible by a filter. It is a default,
  not a guarantee: see R19.

### R2 - Medium: the index is reachable over HTTP where `.htaccess` is not honored

Status: **fixed** by [slice 025](../slices/025-database-file-index.md): the index is
in database tables and holds no server path, so there is no file to serve. The
JSON files and their folder are deleted when the plugin upgrades. The text below
describes the former behavior.

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

Status: *partly mitigated* by slice 025. Previews and hiding need `upload_files`; the
listing itself still needs only `edit_posts`. A forced refresh no longer scans the whole
source in the request: it reads the few hinted directories (at most one second per
source) and asks for a background pass, which is bounded by a time budget and a
lock. Listing the file names of every source for any day is unchanged.

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

Status: **fixed** in this audit (see above). The text below describes the former behavior.

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
- There is no uninstall routine: options, tables, user meta (filters, panel mode) and
  post dates remain after the plugin is deleted. The Reset section (slice 027's companion tool)
  removes them on demand, and a routine for `uninstall.php` could reuse it.

### R12 - Low: the thumbnail cache must be protected like the sources

Sources are under the uploads directory, so their originals are as public as the web server
makes them (R1). The previews of the panel and the sizes of imported images are stored in
`uploads/thumbnails`, at a URL that follows the path of the original: anyone who can guess one
can guess the other, and the original is the more revealing of the two. The cache therefore adds no
exposure of its own.

It matters only when R1 is mitigated by denying HTTP access to the **source directories**: the
cache directory must be denied too, or the previews stay public while the originals are not. The
README says to block both; the settings page lists the public URL of each source (the cache is
listed in the README rule, not on the page).

- Status: *documented*, same class as R1. A first version of this audit rated it *high*, as a
  new exposure; that was wrong, since it is the same exposure as the originals.
- Recommendation: add the cache directory to the rules that deny access to private sources, and
  list its public URL on the settings page next to those of the sources.
- Hiding a file deletes its previews (slice 027).

### R13 - Low to medium: previews are generated on request by any user who can upload

Each listed image whose preview is missing costs a decode and a resize on request. The browser
loads the rows on screen only and the size and memory limits apply, but nothing limits the
number of requests per user. A user with `upload_files` can already import, so the extra
power is CPU time, not access.

- Status: *open*. Recommendation: a per-user throttle, and a time budget for the generation.

### R14 - Low: the preview URL carries the nonce and the server path

The URL of the endpoint holds the nonce (bound to the user and session) and the absolute path of
the file, so both appear in the access logs and in a `Referer` sent from the admin page. The path was
already sent to the browser in the listing, and the nonce is useless without the session.

- Status: *open*, accepted. Recommendation: use a key in place of the path (an index identifier).

### R15 - Low: the Reset is irreversible

It requires `manage_options`, a nonce and the word `RESET`. Its default selection leaves out
what is not a preference (post dates, the whole cache, imported media) after a test showed that
a default-on option lost the dates of every post. It deletes only thumbnails among files, and
removes attachments from the library without deleting their files. A database backup is the only
way back.

### R16 - Low: site-wide actions by any user who can upload

Hiding and showing a file affects every user. There is no log of who hid what (the index keeps
`hidden_by` and `hidden_at` on the row, not a history). Sites can restrict both capabilities
with `wp_media_helper_can_hide_files` and `wp_media_helper_can_see_hidden_files`.

### R17 - Low: anonymous visitors can trigger the creation of a size

A page that renders an image imported by this plugin creates its missing sizes on the first
view, whoever the visitor is. This is the model of Thumbnails Folder. Only registered sizes of
real attachments are created, the image is read from the source (never modified), and the size and
memory limits and the failure delay of this audit apply. A burst of first views after a large
import can still cost CPU; the background event after registration exists to avoid that.

### R18 - Low: the thumbnail cache cannot be told from other tools' files

The cache folder may also hold files of Thumbnails Folder. The Reset (whole cache) and hiding delete
files by their name (`<name>-<W>x<H>.<ext>`), which can match a file of another tool in the same
folder. This is documented in the README and the Reset screen.

### R19 - Medium: the built-in exclusions are a default, not a guarantee

A source on the uploads directory lists every image under it except the excluded directories. A
private folder not on the list (a form plugin, a download manager, a custom directory) can be
listed to every user who can upload, and imported. A test found one unlisted folder within hours (the
temporary folder of another plugin, harmless but noisy).

- Status: *mitigated* by the list, the filter, and the opt-in button of slice 028 (nothing is
  scanned until an administrator chooses it).
- Recommendation: a screen to add excluded directories without code, and keeping the one-click
  source clearly optional.

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

The unit tests cover the pure logic (confinement, ownership, validation, pagination,
filters, the thumbnail layout and service, the index, hiding at the data level, the file
part of the Reset). The AJAX handlers, capability checks, nonce handling and settings
page are not covered by automated tests, because they need WordPress. Adding
integration tests with the WordPress test suite would protect the controls
above from regressions; capability checks for each endpoint and role are the
first candidates.

## Priorities after this audit

1. R19 (a screen for excluded directories) and R4 (require `upload_files` for the listing).
2. R12: show the public URL of the thumbnail cache on the settings page, with the sources'.
3. R13 and R14 (throttle, identifiers instead of paths in preview URLs).
4. Integration tests of the endpoints above with the WordPress test suite.

## Planned fixes (from the second audit, kept for the record)

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
