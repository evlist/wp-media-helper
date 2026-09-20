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

The filter key is `attachment_scope`.

It uses an option-set type with `user_post_then_user` scope.

Supported values:

- `current_or_unattached`: show items unattached or attached to the current post,
- `all`: also show items attached to another post.

Default value:

- `current_or_unattached`.

Resolution order:

1. current user's value for the current post,
2. current user's latest global value,
3. `current_or_unattached`.

Changing the value stores both the user-post value and the user's latest global value, as defined by slice 015. No reset-to-default control is added.

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

### 1. Default scope

With `current_or_unattached`, the response excludes items whose matching plugin-managed attachment is associated with a post other than the current post.

The response includes:

- files not in the WP media library,
- imported but unattached files,
- files attached to the current post.

### 2. All scope

With `all`, the response includes every matching external item, including those attached to another post.

The item must carry its protected other-post state so the client does not infer availability from visibility alone.

### 3. Current post requirement

When no persisted current post ID exists, other-post comparison cannot be performed reliably. The server should treat all attached media as attached elsewhere and preserve the safe default behavior.

## Filter control

The filter is rendered in the common filter area introduced by slice 015.

A compact option selector is preferred over a standalone ad hoc toggle because the values represent a scope rather than a simple feature switch. Suggested labels:

- `Available for this post`,
- `All matching media`.

The control should remain understandable in both Simple and Advanced panel modes. It filters the result set independently of presentation mode.

Changing the filter triggers a media-state request and preserves selected IDs that remain in the result set. Items hidden by the new scope are removed from the active selection.

## Other-post item rendering

When `all` includes an item attached elsewhere, the row displays a warning state distinct from the normal current-post status.

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
    "attachment_scope": "current_or_unattached"
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

1. `attachment_scope` is registered through the common filter contract.
2. Its default is `current_or_unattached`.
3. The default result excludes media attached to another post.
4. `all` includes other-post media with explicit protected state.
5. The filter resolves and persists through current user/current post, user-global, then default.
6. Other-post rows identify their parent post when permissions allow.
7. Other-post rows are visibly protected in both Simple and Advanced modes.
8. Individual attach, detach, remove, and import actions are unavailable for protected rows.
9. The server rejects or safely ignores lifecycle actions against another post's media.
10. Mixed bulk operations continue processing eligible items.
11. Changing scope preserves selections for items that remain visible and removes hidden items from selection.
12. The external source file and the other post's attachment remain untouched.

## Notes

Visibility does not imply ownership. The `all` scope exists for inspection and awareness, not for cross-post media management. Reassignment, transfer, or another-post detachment would require an explicit future workflow with its own permissions and confirmation rules.
