<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 030: Authenticated access to files and thumbnails

Status: **proposed, not scheduled** (design and open questions only, to be taken up later). Answers residual risks R1 and R12 of the
[security audit](../IA/security-audit.md). Builds on slices
[021](021-allowed-base-directory.md), [023](023-thumbnails-in-cache.md),
[024](024-source-priority-and-ownership.md) and [027](027-hidden-files.md).

## Goal

Let a site keep the files of a source, and their thumbnails, **private**: they are sent only
through an authenticated endpoint of the plugin, never by the web server directly. Today
everything under the uploads directory is public to whoever knows the URL (R1), and no setting of
the plugin can change that, since the web server answers before PHP runs.

## Two constraints that shape the design

1. **The plugin cannot stop the web server from serving a file.** A file under uploads is served
   by Apache or nginx without PHP. Making it private needs a rule in the web-server configuration
   (deny the directory, or send the request to PHP). The plugin can generate and document the rule,
   and may write an `.htaccess` file where Apache honors it, but it cannot enforce anything alone.
2. **A file used in a published post must stay reachable by visitors.** A private original that is
   imported and put in an article is, by the editor's decision, published. A rule that applies to
   a whole directory cannot tell "not yet imported" from "imported and published".

## Proposal

### A. A serving endpoint

`GET /?wp-media-helper-file=<id>` (or a REST route), where `<id>` identifies a file of the index or
an attachment, never a path. It resolves the file with the same rules as import (an active source,
not excluded, not hidden for those who may not see hidden files), checks the visitor's right to see
it (below), and sends the file with the right content type, `nosniff`, a restrictive
`Content-Security-Policy` and conditional-request support. On servers that support it, it hands the
transfer to the web server (`X-Accel-Redirect`, `X-Sendfile`) so PHP does not stream large files.

### B. A visibility per source

A new setting on each source, three values:

| Visibility | Who can fetch its files | Effect on import |
|------------|------------------------|------------------|
| **Public** (default, today) | Anyone, through the normal URL | None |
| **Published when imported** | Not imported: users who may upload, through the endpoint. Imported (an attachment exists): anyone, through the endpoint, or through the normal URL if the server allows it | Importing is the decision to publish |
| **Private** | Users with a capability (default `upload_files`), through the endpoint | Import is allowed, but the attachment is private: it can be used in the editor, and a visitor sees nothing |

"Published when imported" is the case wanted for a photo library: the originals not yet chosen stay
private, and the files used in articles are public.

### C. URLs

For attachments of a non-public source, `wp_get_attachment_url`, `image_downsize` and the
`srcset` filter return the endpoint URL. This changes the URL of existing images, so it is a per-source
choice, off for existing sources, with a migration note: URLs already written into post content keep
pointing to the direct path, which the server rule then denies. Options: leave them (they break), or
add a redirect from the old path to the endpoint for imported files.

### D. Thumbnails

- The **panel previews** of files that are not published go to a **separate directory that is not
  served** (outside uploads, or inside it but denied), and are sent by the endpoint. The existing
  public cache keeps the sizes of imported, published attachments.
- A file that stops being published (hidden, removed from the library) has its public sizes deleted,
  as hiding does today.

### E. The web-server rule

The settings page shows, for each non-public source, the rule to add (Apache and nginx), computed
from the real directories (sources, thumbnail cache), and a **check** that fetches a test URL to
tell whether the directory is really denied. Without the rule the visibility setting protects
nothing: the page says so.

## Security

- The endpoint is the only reader of private files: it re-resolves the path from the identifier at
  the time of the request (no TOCTOU), refuses links, and never takes a path from the client.
- Capability and visibility are checked on every request, not cached in the URL.
- Identifiers are not guessable for private files when the visibility is *Private* (a signed value
  or a long random key stored in the index), so a leaked URL does not reveal a sequence.
- The nonce-in-URL and server-path-in-URL issues of the current preview endpoint (R14) disappear:
  the URL carries an identifier.
- Large files: byte ranges, `X-Accel-Redirect` where available, and no `set_time_limit` games.

## Non-goals

- DRM, watermarking, expiring links for visitors.
- Protecting files outside the uploads directory (sources are inside it anyway).
- Replacing the web-server configuration: the plugin helps to write and check it, it does not
  edit server files other than an optional `.htaccess`.

## Acceptance criteria

1. A file of a *Private* source cannot be fetched anonymously through the endpoint, and, with the
   rule in place, not through its direct URL either.
2. A file of a *Published when imported* source is fetched anonymously only when an attachment exists.
3. A hidden file is not served to a user who may not see hidden files.
4. The URLs of the attachments of a non-public source point to the endpoint, in the Media Library,
   in `srcset` and in the REST API.
5. The panel previews of unpublished files are not in a served directory.
6. The settings page shows the rule for the real directories and tells whether it works.
7. Existing sources and their URLs are unchanged until the setting is changed.

## Open questions

- Existing URLs in post content when a source becomes non-public: redirect, rewrite, or accept the
  breakage with a warning?
- Is the Apache `.htaccess` write acceptable, or only generate text to copy?
- Performance on a site with many images in a published post: the endpoint is a PHP request per
  image unless the server hands the transfer back. Is "Published when imported" served directly
  by the web server for imported files (public directory) a better answer than going through PHP?
- Does the REST API (`media_details`, `source_url`) need to hide a private source's files from
  anonymous users even though it only returns URLs?
