# Local index and refresh

## Summary

This slice adds the first persistent local index for external media discovery. The goal is to avoid repeatedly scanning every configured source on each request while still guaranteeing that the final gallery reflects the current filesystem state.

## Context

The resolver and scanner layers can already resolve a date-specific directory and return matching files, but the plugin is still too eager to re-scan large external trees. The next step is to add a lightweight persistent metadata index: keep the last discovered file list for a source/date context, check whether the relevant directory changed, and refresh only when needed.

## User story

As a plugin workflow, I want to cache the discovered files for a configured external source so that repeated requests for the same date do not rescan the entire directory tree unless the directory changed.

## Goals

- Persist the latest discovered file list for a source/date context.
- Reuse cached results when the relevant directory has not changed.
- Refresh only the impacted source when the directory mtime indicates a change.
- Keep the logic deterministic and testable without introducing background processing yet.

## Functional requirements

### 1. Cached lookup contract

The index layer must accept:

- a configured source,
- a target date,
- an optional source context,
- an optional forced refresh flag.

It must return the known file list for that source/date context.

### 2. Refresh trigger

A refresh is needed when:

- there is no cached entry for the source/date context,
- the cached metadata is invalid or unreadable,
- the resolved directory mtime is newer than the cached directory mtime.

### 3. Persistence format

The cache should store at least:

- source identifier,
- resolved directory,
- recorded directory mtime,
- discovered file list,
- refresh timestamp.

The storage format may be JSON or another lightweight local file representation.

### 4. Runtime safety

The index layer must:

- treat invalid cache payloads as stale,
- fail fast rather than silently returning wrong files,
- keep the index local to the application runtime and not rely on shell or external services.

## Non-goals

This slice does not include:

- scheduled background refresh tasks,
- asynchronous gallery updates,
- thumbnail generation,
- attachment registration,
- multi-source global index orchestration.

## Acceptance criteria

1. A source/date lookup reuses cached results when the directory has not changed.
2. A changed directory triggers a fresh lookup and updates the persisted cache.
3. Invalid or missing cache payloads are treated as stale and refreshed.
4. The index layer is local, predictable, and testable.

## Notes

This slice introduces the first persistent local index used for date-based external media discovery. It is intentionally narrow: the next slices can build on this with scheduled refreshes, stale-result UX, and gallery invalidation without changing the underlying contract.
