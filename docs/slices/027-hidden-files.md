<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 027: Hidden files

Status: **proposed** (design only, not implemented). Depends on
[slice 025](025-database-file-index.md).

## Goal

Let editors hide files that should not be offered, for example photos not meant to
be published, even though they are not imported. Hidden files are not listed unless
asked for, and can be restored.

## Behavior

- Hiding is **global to the site**, not per user. It is stored on the file's row in
  the index (`hidden`, `hidden_by`, `hidden_at`), so it survives re-scans and applies
  to files that have no attachment.
- Hidden files are excluded from every list. A filter **Show hidden files**
  (persisted for the user like the other filters of slice 015) lists them, marked
  as hidden, so they can be restored.
- Actions **Hide** and **Show** (restore) in the bulk actions and per item, subject
  to the maximum number of items of a request (slice 020).
- Hiding has no other effect: it does not touch an attachment, a file or a post. A
  hidden file that is already imported stays in the Media Library.
- Capability: the one required to import (`upload_files`), since the effect is
  site-wide. The nonce and the confinement of paths are the same as for the other
  actions.

## Identity and renames

The identity of a row is the path. A file renamed or moved on disk would lose its
flags. To limit that, when a directory is re-read, a missing file and a new file with
the same size and modification time in the same scan are treated as a rename and
the user columns are carried over. A move between directories is detected the same
way within one run. This is a heuristic and is documented as such.

## Other metadata

The same row can carry later user metadata (a note, a tag) without changing the
design. The date set by the user (slice 026) is stored there too.

## Interface

- A *Show hidden files* toggle among the filters, and a *Hidden* status for items.
- *Hide* and *Show* entries in the bulk action menu and the item actions.

## Security

- The endpoint requires the capability and the nonce; the path of each item is
  resolved and confined before its row is touched.
- A request is bounded by the maximum number of items.

## Non-goals

- Per-user hiding.
- Hiding by rule (patterns, dates, folders).
- Deleting files.

## Acceptance criteria

1. A hidden file is not listed, and is listed with its status when *Show hidden
   files* is on.
2. Hiding and showing a file works for a file that is not imported, and survives a
   re-scan.
3. A renamed file keeps its flags when it is detected as a rename.
4. Only users with the capability can hide or show, and a request is bounded.
5. Hiding does not change attachments or files.

## Open questions

- Should hidden files be excluded from the *Remove*, *Attach* and *Import* actions
  or only from the lists?
- Retention of the flag when a file is missing for a long time.
