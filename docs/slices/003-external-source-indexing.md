# External source indexing

## Summary

This slice covers the integration point between the configured external source, the date-based path resolver, and the filesystem scanner. The goal is to discover files that belong to a source and a target date without scanning the entire filesystem or guessing the correct directory structure.

## Context

The admin configuration and path-resolution layers define where a source should be read from and which file names are relevant. The next step is to turn that metadata into a deterministic lookup flow: resolve the directory for the requested date, apply the configured filter, and return the matching file list for the indexer or media lookup flow.

## User story

As a plugin developer or media lookup workflow, I want to discover files for a configured external source and a target date so that the gallery can show the relevant media without scanning unrelated directories.

## Goals

- Resolve the target directory from an external source definition and date context.
- Apply the source-specific filter pattern to narrow the file set.
- Return a deterministic list of files for the requested date bucket.
- Keep the behavior reusable by later indexing and UI layers.
- Fail early on malformed source definitions or unsupported patterns.

## Functional requirements

### 1. Indexer contract

The indexer must accept:

- a configured external source array,
- a target date as a `DateTimeInterface`,
- an optional source context string.

It must return:

- the resolved directory path,
- the compiled filter string,
- the list of matching file paths.

### 2. Resolver integration

The indexer must reuse the date resolver for:

- `{date:Y}`, `{date:m}`, `{date:d}`, `{date:Ymd}`
- `{source}` when a source identifier or context is available

When the source defines no path pattern, the directory resolves directly to the configured root.

### 3. Filter application

After the directory is resolved, the indexer applies the configured filter pattern to the filenames found there. If no filter is configured, the indexer should return all files from the resolved directory.

### 4. Safety and determinism

The indexer must:

- reject invalid or malformed patterns,
- fail loudly rather than silently scanning the wrong directory,
- use absolute paths only,
- preserve deterministic ordering when suitable.

### 5. Output contract

The indexer output should be a list of absolute filesystem paths whose filenames satisfy the compiled pattern. Empty results are valid when no file matches.

## Non-goals

This slice does not include:

- thumbnail generation,
- attachment registration,
- admin UI work,
- database persistence for the index,
- background refresh scheduling.

## Acceptance criteria

1. A source with a valid path pattern resolves to the correct target directory for a date.
2. The configured filter pattern is compiled and applied to discovered files.
3. A static source without a path pattern resolves directly to its root.
4. Unsupported or malformed placeholders are rejected with a clear error.
5. The indexer returns absolute paths for files that match the date and filter context.

## Notes

This slice sits directly above the resolver and scanner layers. It is intentionally narrow: the goal is to make the source/date lookup flow reusable and testable before later slices add caching, refresh logic, and attachment operations.
