# Path resolution and filtering

## Summary

This slice covers the logic that transforms a configured external source, a target date, and an optional context into a resolved filesystem path and a final filename filter. The goal is to ensure that the plugin can consistently find the correct directory for a date-based media source before it attempts to index or attach files.

## Context

A configured external source defines a root path and a set of pattern templates. Those templates are later combined with the requested date and source context to compute the directory where media should be looked up. This logic sits between the stored admin configuration and the filesystem scanning/indexing layer.

## User story

As a plugin developer or admin workflow component, I want to resolve a source-specific directory from a date and context so that media files can be discovered and validated without guessing path structure.

## Goals

- Resolve a valid target directory from a configured source and date.
- Support canonical placeholders such as `{date:Y}`, `{date:m}`, `{date:d}`, `{date:Ymd}`, and `{source}`.
- Allow optional filter patterns to narrow the relevant files after the base directory is known.
- Reject invalid or unsafe patterns before they are used in filesystem operations.
- Provide a deterministic and testable contract for later indexing and media-selection slices.

## Functional requirements

### 1. Resolver contract

The path resolver must accept the following inputs:

- a configured external source object,
- a target date as a `DateTimeInterface` or equivalent date value,
- an optional source identifier or context string,
- an optional filter pattern override,
- an optional path pattern override (if omitted, the source root is used directly).

It must return:

- a resolved base directory path,
- a compiled filter string,
- a validation result or error when the source configuration is invalid.

### 2. Supported placeholders

The resolver must support the following placeholders in the path pattern and filter pattern:

- `{date:Y}` → year, e.g. `2026`
- `{date:m}` → month, e.g. `08`
- `{date:d}` → day, e.g. `12`
- `{date:Ymd}` → compact date, e.g. `20260812`
- `{source}` → source identifier or context value

The implementation should allow only the supported token set and reject unknown placeholders.

### 3. Directory resolution

The resolver builds the final directory path from the configured source root and the source-specific path pattern. If the source does not depend on the date, the path pattern can be empty and the resolver falls back to the source root itself. In other words, a static source may simply point at its root directory, while a date-based source supplies a pattern such as `{date:Y}/{date:m}/{date:d}`.

Example:

- source root: `/var/www/media`
- path pattern: `{date:Y}/{date:m}/{date:d}`
- target date: `2026-08-12`
- resolved path: `/var/www/media/2026/08/12`

The resolver must:

- normalize slashes,
- avoid duplicate directory separators,
- preserve the configured root as the base path,
- ensure the final path remains within the allowed filesystem root.

### 4. Filter resolution

After the directory is resolved, the plugin may apply an optional filter pattern to narrow file candidates.

Example:

- source filter pattern: `{date:Ymd}`
- target date: `2026-08-12`
- compiled filter: `20260812`

The filter is not necessarily a glob pattern by itself; it is a matching contract used by the scanner or indexer to determine which files belong to the relevant date bucket.

The resolver must:

- compile the pattern using the same placeholder rules as the path pattern,
- reject malformed expressions,
- return an empty filter when the source defines none.

### 5. Pattern validation

The resolver must validate patterns before attempting filesystem access.

Validation rules:

- only supported placeholders may be used,
- malformed tokens must be rejected,
- empty tokens are invalid,
- unmatched braces are invalid,
- unsupported values such as `{foo}` or `{date:Q}` must fail fast,
- traversal-like patterns such as `../` or absolute path injection must be rejected when resolving a path.

This validation should happen at the resolver boundary, not only at the admin settings page, so runtime callers also benefit from the same safety checks.

### 6. Runtime safety

The path resolution layer must protect against unsafe input.

- It must reject path traversal patterns such as `../` in configured patterns.
- It must prevent the final directory from escaping the source root.
- It must treat invalid/malformed configuration as a runtime error rather than silently falling back to an unexpected path.

### 7. Error handling

The resolver should fail with explicit, meaningful errors for invalid configuration.

Examples:

- `Unknown placeholder: {foo}`
- `Unclosed template token in path pattern`
- `Date token requires a format, e.g. {date:Ymd}`
- `Resolved path escapes the configured source root`

These errors should be surfaced to the caller and can be converted into admin-side validation messages when needed.

## Non-goals

This slice does not include:

- recursive filesystem scanning,
- file indexing,
- thumbnail generation,
- attachment registration,
- WordPress admin screen implementation,
- custom database persistence logic.

## Acceptance criteria

1. A valid source, target date, and context resolve to the correct directory path.
2. Supported placeholders are correctly compiled in both path and filter patterns.
3. Unsupported or malformed placeholders are rejected with a clear error.
4. A path that escapes the configured source root is rejected.
5. Filter patterns can be resolved independently from the base directory.
6. Empty or invalid configuration results in deterministic runtime errors.
7. The resolver contract is reusable by both the indexer and the media lookup flow.

## Notes

This slice defines the core path and template logic used by later slices. The admin settings slice ensures a source can be configured and persisted, while this slice ensures that the stored configuration can be turned into a safe, valid filesystem target.
