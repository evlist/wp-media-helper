# Media panel integration

## Summary

This slice introduces the UI-facing integration layer that consumes the refresh orchestration contract and presents the external-source state in a media panel or similar admin/editor surface. The goal is to connect the already-proven runtime service layer to a user-visible state without reintroducing the lower-level refresh logic into the UI.

This slice should be described from the user's point of view: a person chooses a media context, inspects the current status, triggers a refresh when needed, and browses the matching media files returned for that context.

## Context

The backend slices already provide:

- source configuration,
- path resolution and filtering,
- source/date indexing,
- local cache freshness checks,
- targeted refresh orchestration with an explicit payload.

The remaining gap is the presentation layer: a media panel must be able to read the orchestration result, display whether a source/date context is stale or fresh, and trigger the appropriate refresh flow without duplicating server-side logic.

## User story

As a content editor or admin, I want a media panel to let me pick the relevant date context, see whether the matching media are stale or current, trigger a refresh when needed, and review the list of available media before choosing one.

## Goals

- consume the orchestrated refresh payload produced by the runtime layer,
- translate the payload into UI state such as fresh / stale / refresh required,
- keep the UI logic thin and read-only with respect to backend cache decisions,
- provide a clean contract for the eventual media component or panel,
- expose the actual user interactions needed to browse and refresh external media.

## User interactions

### 1. Select the date context

The user must be able to choose the date or date range that defines which external media are displayed.

Possible modes:

- single date,
- month view,
- date range,
- current date fallback when no explicit value is selected.

The selected context must be sent to the orchestrator to resolve the relevant external directory and files.

### 2. Inspect the current state

The panel must clearly show whether the current media set is:

- fresh,
- stale,
- being refreshed,
- unavailable or failed.

This status must be visible without requiring the user to infer it from raw backend metadata.

### 3. Trigger a refresh

The user must be able to request a refresh explicitly, for example via a button or action in the panel.

This action should:

- use the currently selected date context,
- call the orchestration layer,
- re-evaluate the source/date directory,
- refresh the visible results after the backend responds.

### 4. Review the media list

The UI must display the matching media files for the selected date context, including enough metadata to browse them effectively.

At minimum, the list should include:

- filename,
- source label,
- date context,
- path or thumbnail preview when available,
- status if a file is stale or newly refreshed.

### 5. See the refresh reason

When a refresh is required, the panel should show a brief human-readable reason, such as:

- cache missing,
- directory changed,
- forced refresh,
- stale cache entry,
- invalid cached payload.

## Functional requirements

### 1. Panel state contract

The UI layer must accept a refresh payload containing at least:

- `refresh_required`
- `files`
- `directory`
- `stale`
- `reason`

The panel should react to the payload instead of recalculating source freshness itself.

### 2. Display states

The panel must expose a clear user-facing state for the current source/date context, including:

- up-to-date,
- stale / refresh recommended,
- refresh in progress,
- refresh failed or unavailable.

### 3. Refresh trigger

When the UI decides to refresh, it should call the same orchestration service or equivalent request flow, not reimplement the underlying filesystem logic.

The trigger must be tied to a selected date context and must operate on the currently displayed source/date scope.

### 4. Explicit reason mapping

The panel should surface the backend reason when available, for example:

- missing cache,
- directory changed,
- forced refresh,
- stale metadata,
- invalid cached payload.

This keeps the user feedback aligned with the runtime contract.

### 5. Media result rendering

The UI must present the resolved media list in a way that matches the selected date context and the current source.

This means the interface should show, within the same view:

- the selected date or date range,
- the source name,
- the refresh status,
- the matching media items.

## Non-goals

This slice does not include:

- deep gallery rendering logic,
- attachment ingestion or registration,
- background scheduling,
- full media library redesign,
- server-side cache rewriting beyond the orchestration flow.

## Acceptance criteria

1. The UI reads the orchestration payload and does not re-implement cache validation.
2. The user can select a date or a date range that defines the media context.
3. A fresh directory is shown as current and does not trigger a visible stale state.
4. A stale directory clearly indicates that a refresh is required.
5. The user can trigger a targeted refresh without leaving the panel contract.
6. The panel displays a list of matching media for the selected context.
7. The panel shows the refresh status and a clear reason when stale or invalid.
8. The UI state remains deterministic and reflects the service-layer result.

## Notes

This is the first UI-facing slice and should remain intentionally thin. The runtime contract is already defined; the UI job is to render and react to it, not to decide filesystem freshness rules on its own.
