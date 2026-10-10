<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 040: Persistence of the filters

Status: **implemented**. Changes the persistence scope decided in [015](015-extensible-media-filter-contract.md).

## Decision

- The **date** belongs to the post, and to it alone (post meta `wp_media_helper_date`), as before.
- The **other filters** (attachment state, sources, file categories, file name) belong to the pair **user and post**, and
  to it alone. Scope name: `user_post`.
- The former fallback to "the latest value of the user" is removed. It was surprising: filtering on unattached files while
  editing a post changed the starting view of every new post. A post that has no choice of the user starts from the defaults.
- The filter "show the trash" is not stored (asked again by the panel).

## Details

- Storage: user meta `_wp_media_helper_filter_<key>_by_post`, a map post ID => value and time, pruned to the 200 most
  recently used posts of the user. The meta `_wp_media_helper_filter_<key>_latest` of earlier versions is neither read nor
  written; *Reset* (user data) deletes it with the others.
- API: `MediaFilters::resolveUserPost()` and `persistUserPost()` (they replace the `...ThenUser` methods).
- A request without a post (ID 0) uses the defaults and stores nothing.
