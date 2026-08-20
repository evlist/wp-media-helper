# External source configuration

## Summary

This slice covers the admin-side configuration of external media sources used by the plugin. The goal is to let a site administrator define one or more read-only external directories, validate their settings, and store the configuration so the media workflow can resolve files from those sources without leaving the editor.

## Context

The plugin can display media from both the WordPress library and external directories. To do that reliably, each external source must be configured from a dedicated admin screen. The configuration must be explicit, validated, and easy to understand so it can be reused by the path resolution and indexing layers.

## User story

As an administrator, I want to configure one or more external media sources from the WordPress admin so that media from those directories can be indexed and attached through the plugin workflow.

## Goals

- Allow admins to create and edit external sources from the plugin settings page.
- Support source-specific configuration such as root path, path pattern, filter pattern, and thumbnail cache directory.
- Validate configuration before saving to prevent broken or unsafe paths.
- Persist the configuration in a way that can be consumed by the resolver and indexer.
- Provide clear feedback when a source is invalid or unavailable.

## Functional requirements

### 1. Admin page access

The configuration lives in the plugin settings area, under the existing Media Helper settings section.

- A user with the required admin capability can access the page.
- The page displays the list of configured external sources.
- The admin can add a new source, edit an existing one, or disable it without removing it.

### 2. Source fields

Each external source configuration includes the following fields:

- Name or label: human-readable identifier used in the admin UI.
- Root directory: absolute filesystem path to the external media root.
- Path pattern: optional pattern used to resolve the source directory for a given date and context. Supports placeholders such as `{date:Y}`, `{date:m}`, `{date:d}`, and `{source}`. If omitted, the resolver uses the configured root directory directly, which is valid for static sources that do not depend on the date.
- Filter pattern: optional pattern used to filter files after the target directory is resolved. Supports placeholders such as `{date:Ymd}`.
- Thumbnail cache directory: writable path used to store generated thumbnails for read-only sources.
- Enabled flag: allows the source to be ignored without deleting its configuration.

### 3. Validation rules

The configuration must be validated before it is persisted.

- Root directory must be an absolute path.
- Root directory must exist and be readable by the web server.
- Root directory must not point to a path outside the allowed filesystem scope.
- Path pattern must be valid and may contain supported placeholders only.
- Filter pattern must be valid and may contain supported placeholders only.
- Thumbnail cache directory, when provided, must be writable or creatable.
- Duplicate source names should be rejected or automatically normalized.
- Empty required values must trigger a validation error.

### 4. Error handling and feedback

The admin UI should provide immediate, clear feedback when configuration is invalid.

- Validation errors should be displayed inline next to the affected field.
- Failed directory checks should explain whether the issue is unreadable, missing, or not writable.
- Save operations should not partially persist invalid settings.
- If a source is disabled, it should remain listed but should not be used by the resolver.

### 5. Persistence

The plugin stores configurations in a structured format that can be read by the rest of the application.

- Configuration is saved as a single source record or as a collection of source definitions.
- Stored values should be normalized before save.
- The application should be able to read the active configuration without running filesystem checks on every request.

#### Persistence contract

This slice uses the WordPress options API rather than a custom database table. The configuration is stored in a single option keyed as `wp_media_helper_external_sources`.

Stored value shape:

```php
[
  [
    'id' => 'nextcloud-main',
    'name' => 'Nextcloud Main',
    'enabled' => true,
    'root' => '/var/www/media',
    'path_pattern' => '{date:Y}/{date:m}/{date:d}',
    'filter_pattern' => '{date:Ymd}',
    'thumbnail_cache' => '/var/www/media-cache',
  ],
]
```

Contract rules:

- `id` is a unique string identifier for the source.
- `name` is a human-readable label and must be non-empty.
- `enabled` is a boolean flag.
- `root` must be an absolute filesystem path and must exist/readable.
- `path_pattern` is optional and, when set, must only use supported placeholders.
- `filter_pattern` is optional and may be empty.
- `thumbnail_cache` is optional and, when provided, must be writable or creatable.
- The option must be read as an array and treated as invalid if the value is malformed.
- The plugin must sanitize and normalize values before persisting them.

This is the persistence schema for the first implementation. It is intentionally lightweight and avoids introducing a custom table until the data model requires larger-scale indexing or background processing.

### 6. Runtime usage

Once saved, the source configuration is used by the resolver and indexing components.

- A valid source can resolve paths for a target date and source context.
- A source can be included in the date-based media lookup flow.
- Invalid or disabled sources must not break the global media query.

## Non-goals

This slice does not include:

- bulk import of external media sources,
- remote/cloud storage integrations,
- automatic detection of sources from arbitrary filesystem roots,
- deep path traversal sanitization beyond the validation rules described here,
- custom UI redesign beyond the admin settings workflow.

## Acceptance criteria

1. An administrator can add a new external source from the plugin settings page.
2. The page validates the configured values before saving.
3. Invalid directories or write failures are surfaced as user-facing validation errors.
4. A saved source is available to the resolver and indexer for date-based media lookups.
5. An administrator can disable a source without deleting its configuration.
6. The saved state is deterministic and can be retrieved reliably by the application.
7. Unsupported or malformed patterns are rejected with a clear error message.

## Notes

This slice is intentionally focused on the admin configuration layer. The actual path resolution, indexing, and media lookup behavior are handled in later slices and rely on the configuration created here.
