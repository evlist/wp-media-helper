<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 048: Redating attachments made by other methods (Otherguise media)

Status: **proposal**. Depends on [047](047-redate-existing-attachments.md) (the service, the tool and the command) and on
[042](042-attachment-methods.md) (attachment methods). Related: the Otherguise module `media` and its command
`wp otherguise media migrate`, which belong to the Otherguise repository.

## Goal

Make the redate of 047 reach the attachments that are linked to posts by an attachment method of another plugin (Otherguise
`media`), including those that this plugin did not register, and let a migration use it as a step.

## What exists

- A method (042) links attachments to posts for a context (web, print). The attachments themselves may come from this plugin, from
  Bulk Media Register, or from `wp otherguise media migrate`, and may have been created with the date of their creation.
- 047 selects attachments by type, date, parent or origin, but not by **how they are attached**: an attachment linked only by an
  Otherguise method has `post_parent` 0, so a selection by parent misses it.

## Proposal

1. **Selection by method and post** in the service and the tool of 047, using the contract of 042 (never the tables of another
   plugin): `--method=<id>` (`native`, `otherguise`, …), `--post=<id>` / `--post-type=<type>` and `--context=<slug>` select the
   attachments that `Method::attached()` / `posts_of()` return for those posts. `--method=any` takes every method, which is
   the union the cohabitation already computes (`wp_media_helper_get_attached_media()`).
2. **Attached to no post** is also a selection (`--unattached`), for stray imports found by the panel.
3. **Date relative to the post**, as an option for this selection only: `--within-post-range` flags attachments whose proposed date
   is outside a window around the post's own date (default 7 days), because a photo of a trip is dated by its capture, but a
   scan or a screenshot dated years apart is probably wrong. The plan marks these rows; they are not blocked.
4. **Ordering consequence**: the gallery block (043) with `orderby=date` follows these dates, so the plan shows, per post, the order
   before and after for the first rows (an option, not a default column).
5. **Migration step**: `wp otherguise media migrate` may call the redate through the public function `wp_media_helper_redate( $ids, $args )`
   (the service of 047, no admin screen), documented in the contract note of 042. The call is optional and guarded by
   `function_exists`. The migration owner decides whether to call it; this plugin never calls Otherguise.
6. **Safeguards**: an attachment attached by several methods is redated once; an attachment shared by several posts (allowed by
   Otherguise) is redated by its file, not by a post; the previous dates are saved and undone as in 047.

## Open questions

- **Does the Otherguise `media` module expose the attachments of a post through the contract** (`Method::attached`) without loading
  the whole post? To check in its source, as in 042; the batch relies on it.
- **A date different per context** (an attachment may be print-only): not a thing, an attachment has one date. Stated so nobody
  expects it.
- **Who owns the command**: `wp media-helper redate` here, `wp otherguise media migrate` there; 048 keeps both thin and the shared
  logic in this plugin.

## Non-goals

- Reading or writing the data of Otherguise outside the contract of 042.
- Changing which attachments are attached to which post or mode.

## Acceptance criteria

1. `wp media-helper redate --method=otherguise --post=<id>` plans exactly the attachments the method attaches to that post, including
   those with `post_parent` 0.
2. With `--method=any`, an attachment attached by two methods appears once.
3. `--unattached` selects only attachments with no method and no parent.
4. The function `wp_media_helper_redate()` gives the same plan and result as the command for the same arguments.
5. Without Otherguise active, the method selections list nothing and the other selections behave as in 047.

## Suggested steps

1. Selection by method, post, context and unattached in the 047 service, with tests using fake methods.
2. The relative-to-post flag and the before/after order in the plan.
3. The public function and its line in the contract note of 042; try with the migration on the testing site.
