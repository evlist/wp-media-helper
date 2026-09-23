<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Media filter model

## Purpose

The media workflow uses two distinct kinds of filters. They may expose similar
controls and values, but they have different responsibilities, persistence
scopes, and effects on media actions.

This distinction must remain explicit when extending the filter registry and
implementing future filter slices.

## Attachment eligibility filters

Attachment eligibility filters define which files are valid candidates for a
post. They are editorial rules shared by everyone editing that post.

Examples may include:

- an attachment date or date range,
- allowed media sources,
- allowed media types,
- filename inclusion or exclusion expressions.

Properties:

- persisted with the post;
- shared by all editors of that post;
- evaluated authoritatively by the server;
- applied before visibility filters;
- used as guards for attachment actions, not only as presentation controls.

The current selected date is the first attachment eligibility filter. Although
it is currently represented as one date, it should eventually support a date
range.

## Visibility filters

Visibility filters define which eligible files a user wants to see while
working. They help users find media in a potentially large result set but do
not change the post's attachment rules.

Examples may include:

- visible dates or date ranges, and potentially time ranges,
- visible sources,
- visible media types,
- attachment and import states,
- filename inclusion or exclusion expressions.

Properties:

- personal to the current user;
- resolved with the `user_post_then_user` preference scope where appropriate;
- applied only after attachment eligibility has been evaluated;
- never grant eligibility to a file excluded by an attachment filter;
- must not independently authorize or reject an attachment operation.

The `attachment_scope` filter is a visibility filter.

## Composition rule

The visible list is the intersection of attachment eligibility and the current
user's visibility choices:

```text
visible media = attachment-eligible media ∩ visibility-filtered media
```

A file that fails an attachment eligibility filter is not shown, even if it
would pass every visibility filter. This matches the current date behavior:
the panel only discovers and displays files in the post's selected date
context.

Visibility filters therefore operate on the already eligible candidate set.
They are not an override mechanism.

## Filters that exist in both categories

Some filter dimensions will probably exist twice, once in each category:

| Dimension | Attachment eligibility | Visibility |
|---|---|---|
| Date | Dates allowed for the post | Dates the user currently wants to inspect |
| Source | Sources allowed for the post | Sources currently visible to the user |
| Media type | Types allowed for attachment | Types currently visible |
| Filename | Required or forbidden filename expressions | Search/include/exclude expressions for browsing |

These are separate filter definitions even when they share a control type or
normalization helper. Their keys, persistence scopes, and semantics must not be
conflated.

For example, a post may permit images from sources A and B over a month, while
the current user temporarily views only source B and one day within that
month.

## Date evolution

The current post metadata stores one selected date. Future attachment
eligibility should support a date range.

This change affects more than the UI. It may require coordinated changes to:

- post metadata and migration from the current single-date value;
- path resolution across multiple date contexts;
- local index and cache keys;
- targeted refresh orchestration;
- filename pattern application;
- list merging and deduplication.

Date-range support should therefore be implemented as a dedicated slice, not
as an incidental extension of a visibility filter.

Visibility may later add a narrower date or time range within the post's
eligible date range. That value belongs to the current user's filter state and
must remain constrained by the post-level eligibility range.

## Changing attachment eligibility

Changing an attachment eligibility filter may make already attached media
ineligible. The project has not yet selected a reconciliation policy for this
case.

Possible policies include:

- keep existing attachments and apply the new rule only to future actions;
- keep them attached but surface an explicit inconsistency warning;
- require confirmation and detach ineligible attachments;
- prevent saving the new filter until conflicts are resolved.

The plugin must not choose one of these policies implicitly. In particular, a
filter change must not silently detach or remove existing media without a
separate, explicit product decision and workflow.

Before adding post-level eligibility filters beyond the current date, a future
slice must define:

1. how ineligible existing attachments are detected;
2. whether they remain visible for reconciliation even though they are outside
   the normal eligible candidate list;
3. whether the filter change is accepted immediately or requires conflict
   resolution;
4. what permissions and confirmations are required for destructive changes;
5. how bulk and partial failures are reported.

This reconciliation view is an exception to the normal composition rule: it
may need to display already attached, now-ineligible media specifically so the
editor can resolve the inconsistency. It must be an explicit conflict workflow,
not part of the ordinary media candidate list.

## Filter registry implications

The extensible filter contract should eventually declare more than persistence
scope. Each definition should include its role, for example:

```text
role = attachment_eligibility | visibility
```

The role determines:

- where the value is persisted;
- when it is evaluated;
- whether it guards attachment actions;
- whether it participates in conflict detection after a change.

Shared sanitizers and UI control types are encouraged, but attachment and
visibility filters must remain separate state entries with separate keys.

## Current classification

| Existing or planned filter | Current role |
|---|---|
| Post date | Attachment eligibility |
| `attachment_scope` | Visibility |
| Source visibility (slice 017) | Visibility |
| Media type (slice 018) | Visibility |
| Filename search (slice 019) | Visibility |

Future source, media-type, and filename attachment rules should be introduced
as separate post-scoped eligibility filters rather than changing the meaning of
slices 017-019.
