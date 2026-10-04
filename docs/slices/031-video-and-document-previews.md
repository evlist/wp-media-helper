<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 031: Previews of videos and other files

Status: **proposed** (design and open questions only, not scheduled). Builds on
[slice 023](023-thumbnails-in-cache.md) (cache and previews) and
[slice 029](029-user-interface.md) (the gallery). Related: [032](032-file-types.md).

## Goal

A video, an audio file with a cover, a PDF or an animated image shows a picture in the gallery
instead of an icon, so it can be recognised as a photo can. Today only raster images have a
preview; everything else shows an icon, its extension and its name.

## What exists

- Previews of images in two sizes (long edge 320 and 640 px) in the global cache, made on request
  by an authenticated endpoint, with limits on pixels and memory (slices 023 and 029).
- Videos are recognised by extension and by the container metadata read for dates
  (`wp_read_video_metadata()`), but nothing extracts a frame.

## Candidate sources of a picture

| File | Source | Needs |
|------|--------|-------|
| Video | A frame (poster) taken a few seconds in | `ffmpeg` on the server, or a frame captured in the browser |
| Audio | The cover art in the tags (ID3 `APIC`, MP4 `covr`) | The core tag reader exposes it for some formats |
| PDF | First page | Imagick with Ghostscript (WordPress core already does this for its own PDFs) |
| Animated image | First frame | The image editor already decodes the first frame |
| HEIC and other photo formats | Decode and convert | An Imagick build with the delegate |
| GPX | A small map or a track outline | A drawing routine, no external service; not a picture of a file |

## Questions to settle

1. **Where is the frame taken?** Server side with `ffmpeg` is reliable but needs the binary and
   `exec`, which many hosts forbid. In the browser, a `<video>` element and a canvas can take a frame of a file
   the browser can play, without any server tool, but it needs the video to be fetched (large files),
   works only for formats the browser plays, and the result must be uploaded to be cached.
   Probably both: the server when it can, the icon otherwise.
2. **Security of running a program.** The command line is built from fixed arguments and
   `escapeshellarg()` of a canonical path of an active source; a time limit and an output size limit;
   no user-chosen options; the path of the binary comes from a constant or a filter (code, not the
   admin screen), like the allowed base.
3. **Cost.** Opening a large video is slow: generate in the background (WP-Cron, a bounded batch) and
   on demand with a short limit, and show the icon until it exists. Never at indexing.
4. **Cache and lifecycle.** Same layout and purge rules as image previews (hiding a file deletes its
   previews); the file name must not collide with a real size, since a poster has no `-WxH` of an
   original image: for example `<name>-poster-<W>x<H>.jpg`.
5. **Which frame?** A fixed offset (for example 1 s, or 10 % of the duration), capped; a black frame
   check is probably too much for a first version.
6. **Privacy.** Previews of files that are not published live in a public cache (see audit R12); the
   authenticated access of [slice 030](030-authenticated-file-access.md) would apply.
7. **Capability detection.** The settings page says what is available (`ffmpeg`, Imagick, Ghostscript)
   and what that gives.

## Non-goals

- Transcoding or serving video; a video player; subtitles.
- A thumbnail for every file type: unknown types keep the icon.

## Acceptance criteria

1. A video without a preview tool installed keeps its icon and nothing fails.
2. With a tool, the gallery shows a poster after at most one background run, and never blocks a request
   for more than a short, fixed time.
3. No path or option from the client reaches a command line.
4. Hiding or removing a file deletes its poster.
