# Other-post attachment filter

## Summary

This slice uses the extensible filter contract to control whether the panel includes media already attached to another post.

By default, the panel shows media that is unattached or attached to the current post. The user may switch the attachment-scope filter to include media attached elsewhere. Those items remain protected from reassignment or removal and are presented with enough context to explain why their actions are unavailable.

## Context

The attachment lifecycle now distinguishes:

- media not registered in WordPress,
- media registered but unattached,
- media attached to the current post,
- media attached to another post.

The server already prevents silently reassigning or deleting media belonging to another post. The panel now needs a clear visibility rule for those items.

This filter is the first concrete extension built on the shared filter contract from slice 015. It must use the same filter registry, state object, request structure, persistence resolution, and control-rendering mechanism rather than adding a standalone toggle.

## User story

As an editor, I want media attached to another post hidden during normal work so that I can focus on available files, while retaining the ability to reveal and inspect those media when necessary.

## Goals

- add an `attachment_scope` filter through the common filter registry,
- hide media attached to other posts by default,
- let users include those media explicitly,
- persist the choice per user and post with user-level fallback,
- identify the parent post when another-post media is visible,
- prevent destructive or reassignment actions on protected media,
- preserve compact sidebar behavior.

## Filter definition

Rather than a single enumerated scope, the filter models the three underlying attachment states as independent toggles. This avoids inventing named combinations for every useful subset and keeps the control legible.

The filter key is `attachment_scope`. It uses a multi-select-of-states type with `user_post_then_user` scope.

The available states are:

- `unattached`: the media has no plugin-managed attachment, or one that is not associated with any post,
- `current`: the media is attached to the post currently being edited,
- `other`: the media is attached to a different post.

The filter value is the set of states to include, for example `["unattached", "current"]`. An item is shown when its resolved state is a member of the selected set. An empty set is not a valid persisted value; the UI must always keep at least one state selected.

Default value:

- `["unattached", "current"]`.

Resolution order:

1. current user's set for the current post,
2. current user's latest global set,
3. the default set.

Changing the value stores both the user-post set and the user's latest global set, as defined by slice 015. No reset-to-default control is added.

This model also removes the need for named combinations such as "current or unattached" or "other or unattached": every one of the eight possible combinations is simply the set of checked states, including the previously awkward-to-name "other or unattached" (`["unattached", "other"]`, i.e. anything not attached to the current post).

## Attachment-state enrichment

Each matching plugin-managed attachment must expose enough state for filtering and rendering. At minimum:

- `attachment_id`,
- `post_parent`,
- `is_attached_to_current_post`,
- `is_attached_to_other_post`,
- parent post title when available,
- parent post edit URL when the current user can edit that post.

The external media item remains identified by its source path and stable item ID. WordPress attachment information is enrichment, not the discovery source of truth.

If multiple plugin-managed attachment records match the same external item, the server must resolve the state deterministically and avoid presenting the item as safely available when any active record belongs to another post.

## Filtering behavior

### 1. Resolving an item's state

For every external media item, the server resolves exactly one of the three states:

- `unattached` when no plugin-managed attachment exists, or the matching attachment has no post parent,
- `current` when the matching attachment's post parent is the current post,
- `other` when the matching attachment's post parent is a different post.

### 2. Default selection

With the default set `["unattached", "current"]`, the response excludes items resolved as `other`.

The response includes:

- files not in the WP media library,
- imported but unattached files,
- files attached to the current post.

### 3. Including other-post media

When the selected set includes `other`, the response also includes items attached to a different post.

Every included item must carry its resolved state so the client does not infer availability from visibility alone.

### 4. Current post requirement

When no persisted current post ID exists, an attached item cannot be resolved as `current`. The server should resolve it as `other` and preserve the safe default behavior.

## Filter control

The filter is rendered in the common filter area introduced by slice 015, as three independent checkboxes rather than a single selector:

- `Unattached`,
- `Attached to this post`,
- `Attached to another post`.

Each checkbox toggles membership of its state in the selected set. This reads directly as "include this category or not" and avoids asking the editor to recognize a named combination.

The UI must prevent unchecking the last remaining state, since an empty set has no defined meaning. The three checkboxes are shown identically in both Simple and Advanced panel modes: the panel mode only controls whether WP media-library presence is exposed, and is unrelated to which attachment states the editor wants to see. Reducing the checkboxes in Simple mode would make a selection made in one mode unexplainable after switching to the other.

The control filters the result set independently of presentation mode.

Changing any checkbox triggers a media-state request and preserves selected IDs that remain in the result set. Items hidden by the new set are removed from the active selection.

## Other-post item rendering

When the selected set includes `other` and an item resolves to that state, the row displays a warning state distinct from the normal current-post status.

The row should show:

- `Attached to another post`,
- parent post title when available,
- a link to edit that post when permitted.

The warning must remain visible in Simple mode even though media-library details are hidden. In Advanced mode it appears alongside the library and attachment states.

The presentation must remain compact and must not reintroduce horizontal table scrolling.

## Action protection

An item attached to another post is read-only from the current post panel.

The panel must disable or omit individual actions that would change its lifecycle from the current context:

- `Attach`,
- `Detach`,
- `Remove`.

`Import` is also unnecessary for an item already represented by the protected WordPress attachment.

Bulk requests remain protected server-side. The server must inspect every item and reject or return `no_change` for an item attached to another post, even if a stale or crafted client sends an action. Client-side hiding is not a security or integrity boundary.

A mixed bulk operation may process eligible items while returning a protected result for other-post items. One protected item must not prevent valid actions on unrelated selected items.

## Request and response contract

The media-state request includes:

```json
{
  "filters": {
    "attachment_scope": ["unattached", "current"]
  }
}
```

The response includes the effective normalized filter and attachment-state enrichment for every returned item.

Per-item action results should distinguish a protected other-post outcome from a generic failure, for example:

- operation: `protected_other_post`,
- success: false or no-change according to the established bulk-result convention,
- a concise message suitable for partial-operation reporting.

## Performance considerations

The server should avoid loading full post objects for every attachment when only parent IDs are needed for filtering.

Parent titles and edit URLs should be resolved only for returned other-post items, particularly when the default scope excludes them.

Attachment lookup should reuse the existing matching pass rather than scanning all attachments separately for import state, current-post state, and other-post state.

## Error behavior

If a parent post no longer exists, the item remains protected until its attachment relationship is explicitly reconciled. It may be labeled `Attached to another post` without a title.

If the user cannot edit or view the parent post, the panel shows the warning without exposing a restricted title or edit URL.

If filter persistence fails, the selected scope remains active for the current session when possible.

## Non-goals

This slice does not include:

- transferring an attachment from one post to another,
- detaching media from another post,
- deleting another post's attachment,
- bulk conflict resolution,
- filtering by post author,
- filtering by media type, name, or source,
- a reset-to-default control.

## Acceptance criteria

1. `attachment_scope` is registered through the common filter contract as a set of `unattached`, `current`, and `other` states.
2. Its default is `["unattached", "current"]`.
3. The default result excludes media resolved as `other`.
4. Selecting `other` includes other-post media with explicit protected state.
5. The filter resolves and persists through current user/current post, user-global, then default.
6. The UI prevents deselecting every state at once.
7. Other-post rows identify their parent post when permissions allow.
8. Other-post rows are visibly protected in both Simple and Advanced modes.
9. Individual attach, detach, remove, and import actions are unavailable for protected rows.
10. The server rejects or safely ignores lifecycle actions against another post's media.
11. Mixed bulk operations continue processing eligible items.
12. Changing the selected set preserves selections for items that remain visible and removes hidden items from selection.
13. The external source file and the other post's attachment remain untouched.

## Notes

Visibility does not imply ownership. Including the `other` state exists for inspection and awareness, not for cross-post media management. Reassignment, transfer, or another-post detachment would require an explicit future workflow with its own permissions and confirmation rules.
