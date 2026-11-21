<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

/**
 * Storage of the file index (slice 025).
 *
 * Rows are arrays. A directory row has `id`, `source_id`, `parent_id`, `path`
 * (the key), `mtime`, `scanned_run`, `queued_run`, `missing_since`. A file row has
 * `id`, `source_id`, `dir_id`, `path`, `name`, `ext`, `kind`, `size`, `mtime`,
 * the date columns, `hidden` and `missing_since`.
 *
 * Columns that belong to the user (`hidden`, `hidden_by`, `hidden_at`,
 * `date_override`) are never written by a scan.
 */
interface IndexStore {

	/**
	 * @return array<string, mixed>|null
	 */
	public function findDirectory( string $sourceId, string $key ): ?array;

	/**
	 * Inserts a directory, or revives and re-parents an existing one, and returns its id.
	 */
	public function saveDirectory( string $sourceId, string $key, int $parentId, int $now ): int;

	/**
	 * Direct sub-directories, including the missing ones.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function childDirectories( int $directoryId ): array;

	public function queueDirectory( int $directoryId, int $run ): void;

	/**
	 * Queues, in one operation, the sub-directories of a directory that are not missing.
	 */
	public function queueChildDirectories( int $directoryId, int $run ): void;

	/**
	 * Directories queued in the run and not yet read in it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nextQueued( string $sourceId, int $run, int $limit ): array;

	public function countPending( string $sourceId, int $run ): int;

	public function markScanned( int $directoryId, int $run, ?int $mtime, int $now ): void;

	/**
	 * Files of a directory, including the missing ones, keyed by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function filesInDirectory( int $directoryId ): array;

	/**
	 * @param array<int, array<string, mixed>> $files Rows with `key`, `name`, `ext`, `kind`, `size`, `mtime`,
	 *                                                  `width`, `height` (pixels as displayed, 0 when unreadable, null when not read), `name_date`, `name_date_precision`, `embedded_date`, `embedded_state`, `effective_date`,
	 *                                                  `effective_day`, `date_source`.
	 */
	public function insertFiles( string $sourceId, int $directoryId, array $files, int $now ): void;

	/**
	 * Updates the columns a scan owns (size, modification time, dates, missing flag).
	 *
	 * @param array<string, mixed> $fields
	 */
	public function updateFile( int $fileId, array $fields, int $now ): void;

	/**
	 * @param int[] $fileIds
	 */
	public function markFilesMissing( array $fileIds, int $now ): void;

	public function markDirectoryMissing( int $directoryId, int $now ): void;

	/**
	 * Files of the sources whose effective day is $day, oldest first. Hidden files
	 * (slice 027) are left out unless asked for.
	 *
	 * @param string[] $sourceIds
	 * @return array<int, array<string, mixed>>
	 */
	public function filesForDay( array $sourceIds, string $day, bool $includeHidden = false ): array;

	/**
	 * The files whose effective date is within a window, oldest first.
	 *
	 * @param string[] $sourceIds
	 * @param string   $start Y-m-d H:i:s, included.
	 * @param string   $end   Y-m-d H:i:s, included.
	 * @return array<int, array<string, mixed>> Rows like filesForDay().
	 */
	public function filesForRange( array $sourceIds, string $start, string $end, bool $includeHidden = false ): array;

	/**
	 * Hides or shows files, whatever the source: the flag is global to the site and kept
	 * on the row of the file, so it survives the scans. Returns how many rows changed.
	 *
	 * @param string[] $keys Index keys of the files (see KeyMapper).
	 */
	public function setHidden( array $keys, bool $hidden, int $userId, int $now ): int;

	/**
	 * The files with this key (the path relative to the uploads directory, or absolute outside it), with their date and position, from any source.
	 *
	 * @param string[] $keys
	 * @return array<int, array<string, mixed>> Rows with `path`, `kind`, `effective_date`, `name_date_precision`, `date_source`, `geo_state`, `gps_lat`, `gps_lon`, `gps_end_lat`, `gps_end_lon`, `track_start_at`, `track_end_at`.
	 */
	public function geoRowsByKeys( array $keys ): array;

	/**
	 * The files whose position was not read yet (state 0), newest first.
	 *
	 * @return array<int, array<string, mixed>> Rows with `id`, `source_id`, `path` (the key), `name`, `ext`, `kind`.
	 */
	public function filesNeedingGeo( int $limit ): array;

	/**
	 * Records what was read for a file: `geo_state` (1 with a position, 2 without) and, for a position, `gps_lat`, `gps_lon` and, for a track,
	 * `gps_end_lat`, `gps_end_lon`, `track_start_at`, `track_end_at`.
	 *
	 * @param array<string, mixed> $geo
	 */
	public function saveGeo( int $fileId, array $geo ): void;

	/**
	 * Forgets what was read (state back to 0, positions cleared), for one source or for all.
	 */
	public function resetGeo( ?string $sourceId = null ): int;

	/**
	 * Number of files by state of the reading of the position (0, 1, 2), not counting the missing ones.
	 *
	 * @return array<int, int>
	 */
	public function geoCounts(): array;

	/**
	 * The keys, among those given, of files that are hidden.
	 *
	 * @param string[] $keys
	 * @return string[]
	 */
	public function hiddenKeys( array $keys ): array;

	public function countFiles( string $sourceId ): int;

	public function countDirectories( string $sourceId ): int;

	/**
	 * Deletes rows that have been missing since before $before. Returns how many.
	 */
	public function purgeMissing( int $before ): int;

	public function deleteSource( string $sourceId ): void;
}
