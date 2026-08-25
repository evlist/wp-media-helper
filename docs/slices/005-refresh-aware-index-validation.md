# Refresh-aware index validation

## Summary

This slice adds the explicit stale-directory validation used by the local media index. The goal is to make the cache refresh decision deterministic and reusable before the broader UI and background-refresh layers are introduced.

## Context

The local index can persist discovered files for a source/date context, but it still needs a clear answer to the question: "Has the directory changed since the cache was written?" This slice adds that check using the resolved directory mtime and exposes the result to the runtime caller before a refresh is triggered.

## User story

As a media lookup workflow, I want to know whether a cached external-source result is stale so that I can refresh only the impacted directory and avoid unnecessary rescans.

## Goals

- Detect whether a cached source/date result is still valid.
- Treat invalid or missing cache payloads as stale.
- Support explicit forced-refresh flows without weakening the cache contract.
- Keep the runtime check simple, deterministic, and testable.

## Functional requirements

### 1. Stale-directory contract

The refresh check must accept:

- a configured source,
- a target date,
- an optional source context.

It must return a boolean indicating whether a refresh is needed.

### 2. Refresh conditions

A refresh is needed when:

- the cache entry is missing,
- the cached payload is malformed or unreadable,
- the resolved directory is different from the cached directory,
- the current directory mtime is newer than the cached directory mtime.

### 3. Forced refresh

A runtime caller may explicitly request a refresh even when the cache appears fresh. This bypasses the local cache for the current lookup and forces a fresh scan of the resolved directory.

### 4. Runtime safety

The validation layer must:

- clear filesystem stat caches before reading directory mtimes,
- treat missing or unreadable metadata as stale,
- return consistent results across repeated checks in the same request.

## Non-goals

This slice does not include:

- scheduled background refresh jobs,
- gallery invalidation UX,
- attachment registration,
- asynchronous refresh orchestration,
- cross-source global index coordination.

## Acceptance criteria

1. A valid cache entry is reused while the directory remains unchanged.
2. A changed directory marks the cache as stale and triggers a refresh.
3. Invalid cache payloads are treated as stale and rebuilt.
4. A forced refresh always re-evaluates the source/date directory.

## Notes

This slice formalizes the refresh decision step in the local index flow. It keeps the logic local and predictable so later slices can build on it with request-time UX, scheduling, and broader gallery invalidation without changing the contract.
