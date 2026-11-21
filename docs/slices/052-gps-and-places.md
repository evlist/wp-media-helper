<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 052: Where the files were taken (GPS) and the places

Status: **phases A (reading) and C (the contract) implemented, not tried on a real site yet; phase B (the places) to do.** Related: [025](025-file-index.md) and [026](026-index-cron.md) (the index and its passes), [036](036-library-reorganisation.md), [023](023-thumbnails-in-cache.md) (thumbnails: the mapping thumbnail to original),
[049](049-pause-and-path-remap.md) (pause). Asked for by the module *Index* of Otherguise (`docs/design/index.md` of that repository: *Who geolocates* and *Places from coordinates*), which reads the result through a filter and does no geocoding.

## Goal

The photographs of the blog arrive from Nextcloud during the day and the plugin already discovers files incrementally. It should also **know where each file was taken** (the GPS of a photo, the start and end points of a GPX track) and, later, **name the place** (hamlet, village,
town, county, state, country), as the files arrive, so that Otherguise can group photos by time and distance and choose the level of the places it shows. This plugin only gathers and keeps the data and hands it out through a filter; the policy of grouping and display is Otherguise's.

## Phases

1. **A. Reading, locally, no network.** The coordinates of the photos (EXIF GPS), the capture time to the second, and the start and end points of the GPX tracks, in the file index, by a background pass of its own. *Implemented first.*
2. **B. The places.** A cache table of the answers of a reverse geocoding service, and a job that asks for the places of the files that have coordinates, **within the usage policy of the service** (see below). Off until the administrator turns it on and chooses the service.
3. **C. The contract.** The filter `wp_media_helper_file_geo` and the public function `wp_media_helper_get_file_geo()`.

## Privacy

- Coordinates are personal data (where the author was, when). They stay **in the database**, are never printed in a public page nor in a public REST answer, and the admin views that show them need a capability (`upload_files`, like the panel).
- **Phase A sends nothing anywhere.** Phase B sends **coordinates rounded to 3 decimals (about 100 m)** to the geocoding service, **only when the administrator has turned it on and chosen the service** (the setting is off by default: no coordinate leaves the site by default).
  Nothing else is sent (no file name, no date). With a self-hosted service nothing leaves the infrastructure of the site.

## Phase A: reading

- **Schema version 3** of `media_helper_files`: `gps_lat`, `gps_lon` (`decimal(9,6)`, null), `geo_state` (0 not read, 1 read with GPS, 2 read without), and, for a track, `gps_end_lat`, `gps_end_lon`, `track_start_at`, `track_end_at` (UTC, null). The same pattern as `embedded_state`: read once, **again only when the size or the time of the file changed** (the scan sets the state back to 0).
- **A pass of its own** (`Geo\GeoPass`, WP-Cron hook `wp_media_helper_geo_run`, its own lock and time budget): it takes the files whose state is 0 (photos `jpg`, `jpeg`, `tif`, `tiff`, `heic`/`heif` are not read by PHP, and GPX files), newest first, and reads them. The scan of the directories is not slowed
  down: it only records the file, and the pass opens the header of the photos afterwards (an `exif_read_data()` per photo, once). A file that cannot be read is recorded as *without* (state 2), not tried again until it changes. The pass stops while the index is paused ([049](049-pause-and-path-remap.md)).
- **The EXIF GPS** is read from the original (`GPSLatitude`, `GPSLatitudeRef`, `GPSLongitude`, `GPSLongitudeRef`, as rationals), refused when out of range or exactly 0, 0 (a camera without a fix).
- **The capture time to the second** is the `effective_date` already kept (the time of the name when the name has one, else the EXIF `DateTimeOriginal`, else the modification time), with `date_source` and the precision, so that the consumer knows how much to trust it.
- **A GPX track**: `<trkpt>`, `<rtept>` and `<wpt>` with a `<time>`; the **start** is the point of the smallest time and the **end** that of the largest (not the first and last of the file, and not the `<metadata><time>`, which is often the time of the export). When no point has a time,
  the first and the last point of the file. Read with a stream reader (`XMLReader`): a long track is not loaded in memory; external entities are not loaded.
- **Command** `wp media-helper geo status` (counts by state) and `wp media-helper geo run [--limit=<n>] [--reset]` (reads now, reports; `--reset` forgets what was read).

## Phase B: the places (to do)

**Usage policy of the public service**, read on `operations.osmfoundation.org/policies/nominatim/` (21 November 2026), which the code must respect and which is the reason for the settings below:

- an absolute maximum of **1 request per second**, **a single thread**, one machine, no distributed script; scripts that run longer than a day or at regular intervals are restricted to **4 requests per minute**; bulk geocoding of larger amounts of data is *not encouraged*;
- **periodic requests from an app are bulk geocoding**: strongly discouraged, acceptable only for an app with very few users **and caching of the results**; a client that sends the same query again may be blocked, so **results must be cached**;
- a valid **User-Agent or Referer identifying the application** (the stock one of a library does not do); the page does not require a contact;
- forbidden: systematic queries (including reverse queries in a grid), auto-complete, scraping the details page; the app must be able to **switch the service** at the request of the operator;
- **attribution** to OpenStreetMap contributors is required where the result is shown (ODbL): a requirement for the module that displays the places;
- for more: a commercial provider or **an instance of one's own**.

What follows from it:

- **Setting of the service**: the URL of the reverse endpoint, **empty by default = off**. The documentation says how to use the public one (small volumes only), a self-hosted instance, or another provider; the plugin does not choose for the administrator.
- **Rate limit by service**: with the public host (`nominatim.openstreetmap.org`) the plugin **never goes over 4 requests per minute** (the conservative reading, as the job runs at regular intervals) and one at a time; with another URL the limit is a setting (default 1 per second). A backfill of the ~34,000 existing attachments is on the order of
  18,000 calls: **at the public limit that is about three days of cron**, hence the advice to use an instance of one's own for the backfill and to let the public service only for the files that arrive.
- **Cache first**: the key is the coordinates rounded to 3 decimals and the language; a cell is asked once, whatever the number of files in it; the **whole answer** is kept (the object found, the chain of the address, the OSM type and id, the tags such as `wikidata`).
  A failure is counted and tried again later with a delay; **429 and 5xx**: the delay grows (honouring `Retry-After`), and the job stops for the run. No request in a loop on the same cell.
- **Identification**: `User-Agent: WP-Media-Helper/<version> (<site URL>)`, with a setting for a contact, and the `Referer` of the site.
- **Parameters**: `format=jsonv2`, `zoom=18`, `addressdetails=1`, `extratags=1`, `accept-language` from the locale of the site (a setting).
- **Own table** `media_helper_places`, own cron hook, own lock and time budget; an off switch; `wp media-helper geo places status|run|reset`.

## Phase C: the contract (implemented)

`apply_filters( 'wp_media_helper_file_geo', null, $address )` where `$address` is a path, a URL of the original, or a URL of a thumbnail of the cache (`uploads/thumbnails/<dir>/<name>-<w>x<h>.<ext>`, mapped back to the original); the answer is `null` or
`{ type: 'file', state, lat, lon, taken_at, taken_source, taken_precision, place }` for a photo and `{ type: 'track', state, start: { lat, lon, at, place }, end: { lat, lon, at, place } }` for a GPX track (`state`: `unread` before the pass has read the file, `none` when it has no position,
`positioned`; no position is given unless the state is `positioned`; `taken_at` is the date the plugin shows, in the time zone of the site, `taken_source` where it comes from: `name`, `embedded` or `mtime`; `at` of a track is UTC). `place` is `null` until phase B has run for the cell; it is asked
with the filter `wp_media_helper_place_of`. The same answer from code: `wp_media_helper_get_file_geo( $address )`. The code is `Geo\GeoFilter` and `Geo\FileGeo` (the mapping of an address, thumbnail included, to a key of the index). `place` is the cached answer of the service (none until phase B has run for the cell). Files outside the uploads directory (NextGEN, under `wp-content/gallery`)
are in the index only when a source covers them: with the allowed base moved to `WP_CONTENT_DIR` (`WP_MEDIA_HELPER_ALLOWED_BASE`) a source can be `wp-content/gallery`; their GPS is read like the others, they cannot be registered in the library.

## Tests

The EXIF rationals and signs, the refusal of 0, 0 and of out-of-range values; a GPX track in the file order that is not chronological, a track without times, a file with entities; the state machine (read once, again when the file changed); the rate limiter and the retry delays (phase B).
