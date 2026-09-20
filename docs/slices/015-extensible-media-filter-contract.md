# Extensible media filter contract

## Summary

This slice introduces a common, extensible contract for every control that restricts or changes the media list shown in the editor panel.

The selected date is treated as the first filter rather than as a special standalone control. Future filters, including source, attachment scope, media type, and filename search, must plug into the same state, request, persistence, and rendering mechanisms.

The slice establishes the filtering foundation only. It does not yet add the filter for media attached to other posts; that behavior is introduced by the following slice.

## Context

The panel currently uses a date to resolve the external directory and filename pattern. Additional visibility controls will be needed as the media workflow grows. The first identified example is whether files attached to another post should be hidden or included.

Implementing each control independently would create several incompatible state and persistence paths. It would also make it difficult to answer basic questions consistently:

- which filters are active,
- where a filter value is stored,
- which fallback applies when a value has not been set for this post,
- how a filter is sent to the server,
- whether changing one filter should preserve the others.

The panel therefore needs a filter model before adding another filter control.

## User story

As an editor, I want the media panel to remember useful filter choices for each post while still carrying my normal working preferences to posts where I have not chosen a value yet.

As a developer, I want new filters to be added through a shared definition rather than by creating a new state, endpoint, and persistence strategy for each control.

## Goals

- model the date as a first-class media filter,
- introduce a registry of filter definitions,
- maintain one filter-state object in the client,
- transmit filters through one request contract,
- define persistence and fallback rules per filter,
- support both shared post data and personal user preferences,
- make future filter controls declarative and predictable,
- preserve the current result selection when filter changes permit it.

## Filter definition contract

Each filter definition should declare at least:

- `key`: stable identifier used in state and requests,
- `type`: date, option set, boolean, text, or another supported control type,
- `default`: fallback value when no persisted value exists,
- `scope`: persistence and resolution strategy,
- `sanitize`: server-side normalization rule,
- `serialize`: request representation when needed,
- `affects_query`: whether the filter changes source resolution, server filtering, or client visibility,
- `label` and available options for UI rendering.

A conceptual registry may look like:

```text
date             -> type=date, scope=post
source           -> type=options, scope=user_post_then_user
attachment_scope -> type=options, scope=user_post_then_user
```

The implementation does not have to expose the registry as a public plugin API in this slice, but the internal shape must support adding filters without duplicating the whole pipeline.

## Persistence scopes

### 1. Post scope

A post-scoped filter is shared editorial data. Its value belongs to the post and is visible to every user who edits that post.

Resolution order:

1. value stored for the post,
2. filter default.

The selected `date` uses this scope because it defines the media context of the post and is already persisted in post metadata.

### 2. User-post then user scope

A user-post filter is a personal working preference with a post-specific override.

Resolution order:

1. value set by the current user for the current post,
2. most recent global value set by the current user,
3. filter default.

This scope is appropriate for filters such as `attachment_scope` and, when source is only a view filter, `source`.

When the user changes such a filter, the plugin stores the value in both places:

- as the current user's value for this post,
- as the current user's latest global value.

This provides predictable behavior:

- returning to a previously filtered post restores the user's own choice for that post,
- opening a post without a personal value starts from the user's latest general preference,
- one editor never changes another editor's filter view.

The panel does not expose a `Use my default` or reset-override command. A new explicit choice simply replaces the current user-post value and the latest user preference.

### 3. Scope decision for source

`source` should use `user_post_then_user` when it only controls which source is visible in the panel.

If a later workflow establishes that source is editorial data intrinsic to the post, it should instead declare `post` scope. That decision must be made in the source-filter slice rather than hidden inside the generic filter framework.

## Data storage

Post-scoped values use registered post metadata where appropriate.

User-global and user-post values use user metadata or an equivalent WordPress preference store. User-post data should be stored in a structured user-owned map keyed by post ID rather than in post metadata, because it must remain private to the user.

The implementation should avoid creating an unbounded collection without maintenance. It may cap or prune old user-post entries when the map grows beyond a reasonable limit, while preserving recently used posts.

## Client state

The panel maintains a single `filters` object keyed by filter key.

Changing one filter must:

1. normalize the value locally,
2. update only that key in the filter object,
3. persist it according to its declared scope,
4. request or derive the new result set,
5. preserve selections for items that remain visible,
6. remove selections for items no longer present in the result set.

Mode selection from slice 014 is not a media filter. It changes presentation, not the result set, and remains a separate user preference.

## Request contract

Media-state requests send the active filters as one structured payload rather than adding unrelated top-level parameters for every new filter.

For example:

```json
{
  "date": "2026-09-20",
  "source": "all",
  "attachment_scope": "current_or_unattached"
}
```

The server must normalize every value against the filter definition before using it. Unknown filter keys should be ignored or rejected consistently; they must never be trusted as arbitrary query arguments.

For compatibility during migration, the existing top-level `date` and `source_id` parameters may be accepted temporarily, but the common filter payload becomes the canonical internal representation.

## Server-side filter resolution

The server resolves filter defaults and persisted values before running source discovery and state enrichment.

The resolved response should include the effective filters so the client can reconcile persisted, defaulted, or normalized values without guessing.

A filter may act at different stages:

- source resolution, such as `date` or `source`,
- attachment-state enrichment, such as `attachment_scope`,
- client-only presentation when the server already returns enough data.

The registry should make the stage explicit enough to avoid applying the same filter twice.

## UI rendering

Filter controls occupy a dedicated filter area near the top of the panel, separate from row actions and bulk actions.

The UI should remain compact and suitable for the sidebar. Filter definitions may render different controls according to type:

- date input for `date`,
- compact option selector for `source`,
- checkbox, toggle, or option selector for visibility scopes,
- text input for a future filename search.

The framework must not force every filter onto one horizontal row. Controls may wrap or use a compact expandable filter region as their number grows.

## Refresh behavior

Changing a filter that affects source resolution or server filtering triggers a new media-state request.

The explicit `Refresh` action retains its existing meaning: it forces source/index refresh for the currently resolved filter values. It does not reset filters.

The background poll and visibility refresh use the current resolved filter object.

## Error behavior

A failed preference write must not make the panel unusable. The new value may remain active for the current session, while a concise notice indicates that it could not be persisted when that information is useful.

A failed media-state request retains the previous visible list when practical rather than clearing the panel solely because a filter update failed.

Invalid persisted values are normalized to the next value in the filter's resolution order.

## Non-goals

This slice does not include:

- the filter that reveals media attached to another post,
- a filename search implementation,
- media-type filtering,
- source-filter behavior beyond defining its future contract,
- a visible reset-to-default action,
- organization-wide filter defaults,
- changing the media lifecycle actions.

## Acceptance criteria

1. The date is represented in the common filter state and request contract.
2. Filter definitions declare key, type, default, scope, and normalization behavior.
3. The client maintains one filter object rather than separate unrelated state paths.
4. Post-scoped values resolve from post data and then the filter default.
5. User-post values resolve from current user/current post, then current user global, then default.
6. Changing a user-post filter updates both its post-specific and latest-user values.
7. One user's filter choices do not alter another user's view of the same post.
8. No `Use my default` control is introduced.
9. The media-state endpoint returns the effective normalized filters.
10. New filters can be added without creating a separate persistence endpoint and request pipeline.

## Notes

Persistence scope is part of the filter definition, not an incidental UI decision. The date is shared editorial context, while visibility and browsing choices usually belong to the current user. Keeping this distinction explicit prevents collaboration from producing surprising filter changes.
