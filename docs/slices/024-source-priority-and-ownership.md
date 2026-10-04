<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 024: Source priority, ownership and states

Status: **implemented** (see "Implementation notes" at the end). It amends some rules of
[slice 021](021-allowed-base-directory.md) and constrains slices
[022](022-wordpress-native-registration.md) and
[023](023-thumbnails-in-cache.md).

## Goal

Let sources overlap safely. Sources form an **ordered list**; every file has
exactly one **owner**, the first source in the list whose root contains it.
A broad source such as `uploads` then lists only what the more specific
sources before it (for example `uploads/photos`) do not own, and never lists
the thumbnail cache.

## Background

The following situations were found while designing slices 022 and 023:

- a source on `uploads/2026` mixes originals with the derivative files that
  WordPress writes next to them, and receives native uploads;
- the global thumbnail cache (`uploads/thumbnails`) must never be listed as media;
- two sources can overlap (`uploads/photos` and `uploads`), which would list and
  scan the same files twice and make the source of a file ambiguous;
- WordPress's own layout varies (year/month folders, flat, custom `upload_dir`
  filters), so rules based on a fixed folder pattern are not reliable.

An explicit "folders to exclude" setting per source was considered and is not
needed: priority plus the built-in exclusions below cover these cases.

## Ownership

- The owner of a file is the **first source, in list order, whose canonical root
  contains the file**. The list order is the priority order.
- Ownership depends on the **root only**, never on the path pattern, the filter
  pattern or the requested date. Otherwise a file would change owner from one
  date to another and the lists would be unstable.
- Only Active and Excluded sources take part in ownership (see the states below).
- Two sources cannot have the same root (it is rejected when saving).
- Nested roots are allowed. A source whose root is inside the root of an
  **earlier** Active or Excluded source can never receive a file: the settings
  page shows a warning.

## Source states

The current "Enabled" checkbox becomes a state with three values. The names are
provisional.

| State | Lists files | Owns its tree | Effect on other sources |
|-------|-------------|---------------|--------------------------|
| **Active** | yes | yes | Later sources do not list its files |
| **Disabled** | no | no | The source is ignored as if it did not exist: its files fall to the following sources |
| **Excluded** | no | yes | Nothing lists its files, in this or any later source |

- Existing configurations migrate without behavior change: enabled becomes
  Active, not enabled becomes Disabled (which is what the current checkbox does).
- Suggested wording for the form:
  - Active: "Its files are listed and can be imported."
  - Disabled: "Ignored, as if it did not exist. Its files are handled by the
    sources that follow."
  - Excluded: "Not listed, and no other source lists its files either."
- Use Excluded to hide a directory from a broader source without listing it
  itself; use Disabled to switch a source off temporarily.

## Built-in exclusions

Independently of the list, the following are never listed or scanned by any
source: the global thumbnail cache (slice 023) and the plugin's own index
directory. Symbolic links remain ignored.

## Scanning

For a source, the scanner receives the canonical directories it must not enter:
the trees of earlier Active and Excluded sources that lie inside the directory it
scans, plus the built-in exclusions. They are **skipped, not filtered after the
fact**, so a broad source never walks the tree of a narrower one. The cached
index of a source is keyed by the source, the date **and a fingerprint of these
exclusions**, so reordering or changing a state invalidates it.

## Ownership at import and attach

The browser's `source_id` is only a hint. The server resolves the owner of the
canonical path with one function (conceptually `ownerOf( path )`) and:

- refuses the file when its owner is not an Active source;
- uses the real owner, not the claimed source, for provenance and further checks.

This closes the case of a file of `photos` imported by claiming it belongs to
`uploads`, and keeps path confinement (slice 021) as the single gate.

## Changes to the validation rules of slice 021

Because the cache and the index are excluded automatically:

- a root **may be the allowed base itself** (for example `uploads`), which a flat
  upload layout and FTP drop-boxes need; the base rule otherwise stays;
- a cache **may lie inside a root**, since it is excluded;
- a root may **not** be inside the cache, nor equal to it, and the cache may not
  contain a root that is not itself excluded in full.

These rules apply to every pair of root and cache, since the cache is now global.

## What this does not replace

- Derivative files that WordPress generates next to originals in its own upload
  folders (`-LxH`, `-scaled`, `-rotated`, `-e<digits>`) are still filtered by name
  and by the metadata of existing attachments, whatever the upload layout
  (slice 022).
- Thumbnail URLs of native attachments are left alone, and adoption of existing
  attachments stays explicit or limited to attachments without sizes next to the
  original (slices 022 and 023).

## History

The provenance recorded when a file was registered is historical. Reordering the
sources or changing a state never changes existing attachments: recognition uses
the file path (slice 022), not the recorded source id.

## Interface

The list of sources is ordered. Each source shows its priority number, a control
to move it up or down, and its state. The page shows warnings for shadowed
sources and for built-in exclusions that overlap a root.

## Security

Ownership resolution is part of the single confinement path used by import,
attach and listing, so the rule "a path must belong to the source that claims it"
is enforced on the server. Allowing a root equal to the uploads directory widens
what can be scanned, but not what the web server already serves, and the scanner
keeps ignoring links and files outside the scanned root. Scanning cost is bounded
by skipping owned trees.

## Non-goals

- A per-source list of excluded sub-directories.
- Ownership by path pattern or by date.
- Different priorities for different file types.

## Acceptance criteria

1. With `photos` before `uploads`, a file of `photos` is listed by `photos` only.
2. The thumbnail cache and the index directory are never listed, even when a
   source is the uploads directory.
3. An Excluded source's files are listed by no source; a Disabled source's files
   fall to the next sources.
4. Two sources with the same root are rejected; a shadowed source triggers a
   warning.
5. Importing a file with a wrong `source_id` uses the real owner, and a file
   whose owner is not Active is refused.
6. Reordering the list or changing a state refreshes the cached lists.
7. Existing configurations keep their behavior after migration.

## Open questions

- Final names of the three states.
- Whether an Excluded root must exist on disk, and whether it must be inside the
  allowed base (it lists nothing, but it reserves a tree).
- How to present reordering (drag and drop or buttons) in the settings page.

## Implementation notes

- `SourceState` holds the three states; a source saved with only an `enabled` flag is
  read as Active (true) or Disabled (false), and the next save writes `state`.
- `SourceOwnership::listing()` turns the ordered configuration into the active sources
  with their `exclusions`: canonical roots of earlier Active/Excluded sources and the
  thumbnail caches that lie inside the root (the whole root when it is owned by
  something else). `ActiveSources::all()` returns that list, so listing, scanning,
  hints, import and attach all use the same ownership.
- The scanner skips excluded directories while walking and forgets what it had
  indexed there. The exclusions fingerprint is part of the index configuration hash,
  so reordering or changing a state starts a full pass of the sources concerned.
- `PathConfinement::resolveFileInSources()` ignores the client's `source_id`: the owner
  is the first active source that contains the file outside its exclusions, and a
  file nobody lists is refused (also when its owner is Excluded or Disabled).
- A root may be the allowed base (typed `.` in the form). Two sources cannot share a
  root. A cache cannot be or contain a root; it may be inside one.
- The settings page shows the priority number, up/down buttons, a state menu and a
  warning for shadowed sources.
- Decided on the open questions: an Excluded source's root must exist and be inside
  the allowed base, like any other (simplest rule); states are Active / Disabled /
  Excluded; reordering uses buttons.
- Update (slice 023): the per-source thumbnail cache is gone. The one global cache
  directory is excluded from every listing, and no root may be inside or equal to it.
  The index directory exclusion is moot (the index is in the database).
