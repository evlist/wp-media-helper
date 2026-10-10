<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 041: "All" checkbox and empty selections in the filters

Status: **implemented**. Applies to the filters *Sources*, *Attachment* and *Media type* of the panel (not to the file name).

## Behavior

- Each group starts with an **All** checkbox, as in the tables of WordPress: checked when everything is checked, dash
  (indeterminate) for a partial selection, unchecked when nothing is. A click checks everything when it is not checked,
  and unchecks everything when it is.
- **Nothing checked is allowed** (before, the last box could not be unchecked, so going from videos to photos meant checking
  photos first). It is stored for the user and the post like any other choice (slice 040) and survives a reload.
- With nothing checked in a filter, the server reads no source and lists nothing; the answer names the empty filters
  (`empty_filters`) and the panel shows "Nothing is selected in: …" with a *Select all* button that checks everything again.
- The summary of a collapsed group shows **All** in green, **None** in red (words as well as colors), or the checked names.
- All the sources checked is stored as `all`, so that a source added later is included.
- The defaults do not change (attachment: unattached and this post; sources and media types: all).
