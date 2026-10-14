<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 045: Editing MIME types and filter categories

Status: **proposal**. Continues [032](032-file-types.md), whose first delivery has a fixed list of categories in code
(`FileTypes::CATEGORIES`, filter `wp_media_helper_file_categories`) and a text field for the additional types.

## Goal

Let an administrator **see and edit** the list of file types the site accepts, and **define the categories** used by the media
filter and the icons, from one settings screen, without editing code.

## What exists

- The additional types are one textarea (`extension mime/type` per line) added to `upload_mimes`; the types WordPress accepts
  are not shown anywhere.
- The categories are a constant in `FileTypes` (image, video, audio, document, subtitles, gps, archive, other) with fixed
  labels and icons; a site can only change them with the PHP filter.
- A file whose extension is in no category is `other`; the panel filter shows only the categories and their checkboxes.

## Proposal

1. **One table of types** (settings page "File types"), one row per extension, merging:
   - the types of WordPress and of other plugins (`get_allowed_mime_types()` before our additions), read-only origin shown;
   - the types added by this plugin, editable.

   Columns: extension, MIME type, category, **status** (allowed by WordPress / added here / blocked), importable (yes / no with
   the reason), number of indexed files. Search and filter on the columns.
2. **Edit the list**:
   - add a type (extension + MIME + category), edit and remove the ones added here;
   - **block** a type that WordPress allows (removed from `upload_mimes`, so neither imported nor uploaded), e.g. `bmp` or
     `ico`; shown as blocked, reversible;
   - the dangerous list of 032 stays code-controlled: those rows are shown locked, with a short explanation, and cannot be added
     whatever the screen or the filter says;
   - the textarea of 032 becomes an **import / export** of the same lines (and its stored value is migrated into rows, with no
     loss).
3. **Define the categories**:
   - a category has a slug, a label (translatable by a string typed per language, else the slug's label), an icon (a dashicon
     chosen in a list) and an order; the built-in eight stay by default and may be renamed, reordered, hidden or reset;
   - new categories can be added (e.g. *Scans*, *Maps*, *Fonts*), and each extension belongs to **one** category (a table
     column), `other` being the fallback and never deleted;
   - deleting a category sends its extensions back to `other` after a confirmation that names how many are affected;
   - **the panel filter and the tile icons follow**: the checkboxes, the "All" checkbox (041), the persisted choices (040) and
     the media library column use this list. A saved choice that names a category that no longer exists is dropped silently.
4. **Where it is stored**: an option holding categories (slug, label, icon, order) and the extension → category / MIME map. The
   filter `wp_media_helper_file_categories` and a new `wp_media_helper_file_types` still run **after** the option, so code can
   force a value; the screen shows a row set by code as locked.
5. **Reclassification**: changing a category of an extension takes effect at once for display; the index stores the category
   per file (or computes it on read: to be decided, below), so no rescan of the sources is needed.

## Open questions

- **Category stored in the index or computed on read?** Computed on read is simpler and needs no migration; a stored value
  makes a SQL filter and counts faster on very large sources. Proposal: computed from the extension, with a cached
  extension → category map, as long as the filter stays in PHP.
- **A MIME type with several extensions** (`jpg`, `jpeg`, `jpe`): one row per extension, MIME repeated.
- **Role access**: administrators only, as for the additional types (they change what the whole site accepts).
- **Translation of custom labels**: one label per site language, or a single label? Proposal: a single label for the first
  delivery.

## Non-goals

- Allowing types that can run code (still refused, see 032).
- Content-based detection beyond the check that WordPress already does (a separate item of 032).
- Per-source category lists (still open in 032).

## Acceptance criteria

1. The table shows every type the site accepts with its origin, category and import status; the types of WordPress are not
   editable but can be blocked.
2. A type added on the screen can be imported; a dangerous one is refused with the same message as today.
3. Renaming, reordering or adding a category changes the panel filter and the icons without a rescan.
4. Deleting a category moves its extensions to `other` after confirmation; a persisted filter naming it is ignored.
5. A site that has only the textarea of 032 keeps the same types after the upgrade.

## Suggested steps

1. Read-only table of the types (origin, category, importable, count).
2. Editing the added types and blocking types of WordPress; migration of the textarea; import / export.
3. Editable categories (label, icon, order, add, delete) and the filter / icons following them.
4. Locked rows set by code, and tests for the migration and the persisted filters.
