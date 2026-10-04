<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

/**
 * What one scan run did.
 */
final class ScanResult {

	public bool $complete;
	public int $pending;
	public int $directoriesRead;
	public int $directoriesUnchanged;
	public int $filesAdded;
	public int $filesUpdated;
	public int $filesMissing;

	public function __construct( bool $complete, int $pending, int $directoriesRead = 0, int $directoriesUnchanged = 0, int $filesAdded = 0, int $filesUpdated = 0, int $filesMissing = 0 ) {
		$this->complete             = $complete;
		$this->pending              = $pending;
		$this->directoriesRead      = $directoriesRead;
		$this->directoriesUnchanged = $directoriesUnchanged;
		$this->filesAdded           = $filesAdded;
		$this->filesUpdated         = $filesUpdated;
		$this->filesMissing         = $filesMissing;
	}
}
