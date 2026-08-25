# Targeted refresh orchestration

## Summary

This slice adds the request-time orchestration layer that decides whether a source/date lookup should reuse cached results or trigger a targeted refresh before the UI layer is involved. The goal is to keep the freshness decision and the refresh trigger in one small, reusable service without writing any UI logic yet.

## Context

The lower layers already know how to:

- resolve a directory from a source and a date,
- scan the matching files,
- persist a local cache,
- decide whether the cache is stale.

The next step is to coordinate these building blocks into one request-time decision point that can be consumed by the gallery or other downstream consumers without assuming a specific UI implementation.

## User story

As a runtime workflow, I want a single service that can determine whether the current external media results are fresh and, if needed, refresh only the impacted source/date context before returning the resulting file list.

## Goals

- centralize the logic that decides whether a refresh is needed,
- keep refreshes targeted to one source/date lookup,
- support forced refreshes without changing the broader contract,
- expose a deterministic result payload ready for later UI consumption.

## Functional requirements

### 1. Refresh orchestration contract

The orchestration layer must accept:

- a configured source,
- a target date,
- an optional source context,
- an optional forced-refresh flag.

It must return a status payload with at least:

- `refresh_required` boolean,
- `files` list,
- `directory` path,
- `stale` boolean,
- `reason` string or null.

### 2. Decision logic

The service must:

- reuse the existing cache when the directory is still fresh,
- trigger a refresh when the cache is missing or stale,
- allow callers to force a refresh when they explicitly want the latest data.

### 3. Refresh safety

The orchestration layer must:

- stay local to the request-time check,
- never assume UI state,
- work with the underlying cache/index layers rather than a browser session or DOM state.

### 4. Result payload

The payload should be simple enough to be consumed later by the media panel or any other request handler, while still keeping the freshness decision explicit.

## Non-goals

This slice does not include:

- actual admin or editor UI code,
- scheduled tasks,
- background workers,
- attachment registration,
- gallery rendering logic.

## Acceptance criteria

1. A fresh cache is reused without triggering a refresh.
2. A stale cache triggers a targeted refresh for the affected directory.
3. A forced refresh bypasses the cache and returns fresh results.
4. The orchestration result is explicit enough to be consumed by later UI layers without additional guesswork.

## Notes

This is the final non-UI service slice before the media panel and gallery workflows are implemented. It keeps the refresh decision explicit and reusable so the later UI can simply react to the provided state instead of re-implementing freshness logic.
