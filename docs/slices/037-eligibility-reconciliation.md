<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 037: Eligibility rules and what to do when they change

Status: **proposed** (design and open questions only, not scheduled). Settles a question left open by the
[media filter model](../IA/media-filter-model.md). Related: [034](034-date-range-per-post.md),
[035](035-search-across-dates.md).

## Goal

Decide what the post date (or period) *means*: a rule that says which files may be attached to the post, or only
a convenient place to start looking. Then, if it is a rule, define what happens to the media already attached
when the rule changes.

## The situation

- The filter model describes **attachment eligibility** filters: persisted with the post, shared by its
  editors, evaluated by the server and used as **guards** for attachment actions; and **visibility** filters,
  personal to the user, which never grant eligibility. The post date is classed as the first eligibility filter.
- The code treats it as a **starting point**. The server does not refuse to attach a file because its day is not
  the post's date; any editor who can import can attach any file the panel lists, and the list can show other
  days by changing the date. Nothing detects an attached file that is "outside" the date.
- Slices 034 (a period) and 035 (search across dates) make the difference visible: a search result is by design
  outside the post's date.

## Decision to take

**Option A, the date is a navigation default (recommended).** Say so in the model: dates, sources and types
are visibility/navigation settings; attachment actions are guarded only by capability, ownership of the
attachment (another post's), and the allowed sources. The model document is corrected, no reconciliation
exists, and 034/035 are free to show anything.

**Option B, the date is a rule.** The server refuses to attach a file outside the period, the panel shows
attached files outside it as inconsistencies, and changing the period needs the workflow below. This is
what the model describes. It costs a guard in the bulk action (a day is known for every indexed file), a way to
list attached files, and a conflict workflow; and it forbids the use cases of 035 unless a post can lift the
rule.

## If option B: the conflict workflow

1. **Detection.** The attachments of the post (`post_parent`) whose file's effective day is outside the new
   period, found with the index (the day of each attached file) and listed with their dates.
2. **Where they show.** A *Needs attention* section of the panel, explicitly outside the normal candidate
   list (the model's "exception to the composition rule"), not mixed with it.
3. **Acceptance of the change.** The new period is accepted at once, and the inconsistency reported (policy
   "keep and warn"); nothing is detached without a click.
4. **Actions.** *Keep* (mark as accepted for this post), *Detach* (the existing action, with confirmation
   when several), and a bulk form of both, bounded by the maximum number of entries; failures are reported per
   item like the other bulk actions.
5. **Permissions.** `edit_post` for the post and for each attachment, as for detaching today.
6. **Concurrency.** Two editors changing the period: last save wins, the inconsistency is recomputed on load.

## Questions to settle

1. A or B. The rest of this document applies only to B.
2. If B, per post or site-wide (a setting *Restrict attachments to the dates of the post*)?
3. Files already attached when the rule is introduced (a migration): accepted as they are.
4. Source and type rules (the model's other eligibility filters) follow the same machinery, or are dropped.

## Non-goals

- Automatic detaching.
- Per-user rules.

## Acceptance criteria

1. The model document states which option is in force, and the code agrees with it.
2. (B) Attaching a file outside the period is refused by the server with a clear reason.
3. (B) After a change of period, the attached files outside it are listed apart and nothing is detached until
   asked.
