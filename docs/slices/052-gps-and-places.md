<!-- SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com> -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Slice 052: Where the files were taken (GPS) and the places

Status: **phases A (reading), B (the places) and C (the contract) implemented; none tried on a real site yet.** Related: [025](025-file-index.md) and [026](026-index-cron.md) (the index and its passes), [036](036-library-reorganisation.md), [023](023-thumbnails-in-cache.md) (thumbnails: the mapping thumbnail to original),
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

## Phase B: the places (implemented)

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
- **Parameters**: `format=geocodejson`, `zoom=18`, `addressdetails=1`, `extratags=1`, `accept-language` from the locale of the site (a setting). **Why geocodejson** (changed after the first quality check on the library of the blog, 1,255 places): the address keys of `jsonv2` do not mean the same thing in every country
  (`municipality` was the arrondissement in France, the commune in Italy, the gmina in Poland, the district in Hungary), while `geocodejson` gives flat fields that are the same everywhere (`locality`, `district`, `city`, `county`, `state`, `country`) and the numbered administrative levels (`admin`: `level2` the country, `level4`, `level6`, `level8`...).
- **Own table** `media_helper_places` (kept when the index is reset), own cron hook, own lock and time budget; an off switch; `wp media-helper geo places status|run|reset`.

How it is built (`Geo\Places`):

- `PlacesSettings`: `places_url` (empty = off), `places_contact`, `places_language` (default: the locale of the site), `places_rate` (requests a minute, default 60, for a service other than the public one). The public host is detected: 4 requests a minute, one at a time, `https` only, whatever the setting.
  Validated when the settings are saved (settings page, *Places of the photos*).
- `PlaceStore` / `PlacesSchema`: one row per **cell** (the coordinates rounded to 3 decimals) and language, with the whole answer (`response`, JSON as it came), `osm_type`, `osm_id`, `place_id`, `display_name`, the service, `fetched_at`, and `state`:
  1 found, 2 nothing there (the sea: kept, not asked again), 3 failed (tried again after `next_try`: 1 h, 6 h, 1 day, 3 days), 4 given up (after 5 failures).
- `files.place_state` (index schema 4): 0 to do, 1 done, 2 given up, 3 waiting for a new try; set back to 0 when the position is read again. A GPX track needs the cells of its start and of its end.
- `PlacesJob`: newest files first; **the cache first** (a cell with an answer, or "nothing", is never asked again; a failed cell waits for its `next_try`); then, one request at a time, only when `RateLimiter` allows it: it **waits** the time the service asks for between two requests when that fits in the budget
  (50 s per run), else stops and the run is scheduled again at the time the next request is allowed. **A refusal (HTTP 429, 503, any 5xx, a service that does not answer) stops every request** and starts a back-off of the cell-independent kind: 2, 4, 8 minutes... up to 6 hours, never shorter than `Retry-After`, kept between runs and shared with the command.
- `NominatimClient`: the request `format=jsonv2&lat=&lon=&zoom=18&addressdetails=1&extratags=1&accept-language=`, the User-Agent `WP-Media-Helper/<version> (<site URL>[; <contact>])` and the `Referer` of the site; the transport is the HTTP API of WordPress.
- `PlacesCron`: hook `wp_media_helper_places_run`, started after the reading of the positions and by the hourly maintenance; it does nothing without a service, while the index is paused, or when the filter `wp_media_helper_geocoding` returns false.
- `PlaceAnswer`: reads an answer (geocodejson, and the older jsonv2 that a cache may still hold) and gives the levels under **stable names**: `name` (what was found), `kind` (`place hamlet`, `natural peak`), `locality`, `district`, `site` (the place name below the commune, for a finer grouping: the name of what was found when that is a place (`place locality`, `hamlet`, `isolated_dwelling`...), not a land use, boundary or building; else the locality, else the district; never the name of the commune itself; empty when none), `settlement` (the village, town or city: the commune in most countries; the numbered level 8 when the flat field is missing), `county`, `state`, `country`, `admin` (`level2`... as OpenStreetMap numbers them).
- `PlaceResolver`: the place of a position **from the cache only** (never a request), for the contract: `{ state: found|nothing, cell, language, osm_type, osm_id, display_name, levels: { name, kind, site, locality, district, settlement, county, state, country, admin }, answer (the whole answer), service, fetched_at, attribution }`.
- `PlaceQuality` and `wp media-helper geo places quality`: how many places have each level, which numbered levels are there, what was found, a random sample.
- `wp media-helper geo places status` (the service and its pace, the files and the cells by state, the back-off), `run [--limit=<n>] [--seconds=<n>]` (in the foreground, at the pace of the service), `reset [--all]` (forget the cells that failed, or all).

## Phase C: the contract (implemented)

`apply_filters( 'wp_media_helper_file_geo', null, $address )` where `$address` is a path, a URL of the original, or a URL of a thumbnail of the cache (`uploads/thumbnails/<dir>/<name>-<w>x<h>.<ext>`, mapped back to the original); the answer is `null` or
`{ type: 'file', state, lat, lon, taken_at, taken_source, taken_precision, place }` for a photo and `{ type: 'track', state, start: { lat, lon, at, place }, end: { lat, lon, at, place } }` for a GPX track (`state`: `unread` before the pass has read the file, `none` when it has no position,
`positioned`; no position is given unless the state is `positioned`; `taken_at` is the date the plugin shows, in the time zone of the site, `taken_source` where it comes from: `name`, `embedded` or `mtime`; `at` of a track is UTC). `place` is `null` until phase B has run for the cell; it is asked
with the filter `wp_media_helper_place_of`. Several files at once, with one query: `wp_media_helper_get_files_geo( array $addresses )` returns `address => answer or null`, in the order given.

**What an address may be.** A path (absolute or relative to the uploads) or a URL, whatever the form: `http` or `https`, with or without `www.`, protocol-relative, another host of the site (the hosts of the URLs of the uploads, the content, `home_url()`, `site_url()` and the filter `wp_media_helper_site_hosts`), a query string (`?t=…` of NextGEN) or a fragment, names with percent-encoding (`%20`, `%25`). The URL of another site is `null`. When the address is not an indexed file, it stands for its original if it is a thumbnail of the cache (`uploads/thumbnails/<dir>/<name>-<w>x<h>.<ext>`) or a size made by WordPress (`-300x200`, `-scaled`); the file itself always comes first. The thumbnails of NextGEN (`thumbs/thumbs_<name>`) are not mapped to their original: they keep their own GPS data, so they are answered like any other file when they are indexed (do not list `thumbs` in *Directories that are not listed* if the code of the site asks for them).

**The signal.** After a pass of the positions that read at least one position, and after a pass of the places that recorded at least one answer, the action `do_action( 'wp_media_helper_places_updated', int $count, string $source )` is sent (`$source` is `positions` or `places`). The code that asked for files whose place was missing may ask again.

The same answer from code for one file: `wp_media_helper_get_file_geo( $address )`. The code is `Geo\GeoFilter` and `Geo\FileGeo` (the mapping of an address, thumbnail included, to a key of the index). `place` is the cached answer of the service (see `PlaceResolver`: `levels` and `answer`) (none until phase B has run for the cell). Files outside the uploads directory (NextGEN, under `wp-content/gallery`)
are in the index only when a source covers them: with the allowed base moved to the content directory (`WP_MEDIA_HELPER_ALLOWED_BASE` set to `__DIR__ . '/wp-content'` in `wp-config.php`, since `WP_CONTENT_DIR` is not defined yet there, or the filter `wp_media_helper_allowed_base` in a must-use plugin) a source can be `wp-content/gallery`; their GPS is read like the others, they cannot be registered in the library.

## Tests

The EXIF rationals and signs, the refusal of 0, 0 and of out-of-range values; a GPX track in the file order that is not chronological, a track without times, a file with entities; the state machine (read once, again when the file changed); the rate limiter and the retry delays (phase B).

Observed on the library of the blog (285 then 49 places, France, Italy and Poland): `settlement` is the commune everywhere; about 10 % of the places have **no commune** (a point on the coast or in a marsh, outside the boundaries of any commune in the data): `settlement` and `county` are then empty and a consumer falls back to `site`, then `locality`.

**Precision of the cells** (setting `places_precision`, 3 by default): the cell is the position rounded to 3, 4 or 5 decimals (about 100 m, 10 m, 1 m) and the point asked for is the centre of the cell, up to some 60 m from the photo at 3 decimals, which on a beach or at the edge of a marsh can put it in the sea or outside the boundaries of the commune (about 10 % of the first places had no commune). The public service only gets 3 decimals
(privacy, and the cache its policy asks for); an instance of one's own can be given 4 or 5. The key of the cache holds the precision (at 3 it is the key of the first version), the columns `lat` and `lon` hold up to 6 decimals (places schema 2).

**Administrative boundary as the object found** (seen with 4 decimals: `Mauguio`, `Quartier du Port`): the service leaves the object out of the chain of its address, so the flat `city` is the level above (Montpellier for the commune of Mauguio). `PlaceAnswer` then takes the name of the object as `settlement` when it is the level 8, and as `site` when it is a level 9 or below (a quarter).

