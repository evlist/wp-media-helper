<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Cli\AttachmentsCommand;
use WP_Media_Helper\Cli\GeoCommand;
use WP_Media_Helper\Cli\IndexCommand;
use WP_Media_Helper\Cli\MediaHelperCommand;
use WP_Media_Helper\Cli\PathsCommand;
use WP_Media_Helper\Cli\PlacesCommand;
use WP_Media_Helper\Cli\SourceCommand;
use WP_Media_Helper\Cli\ThumbnailsCommand;
use WP_Media_Helper\Index\Cron;
use WP_Media_Helper\Maintenance\OrphanCheck;
use WP_Media_Helper\Index\Schema;
use WP_Media_Helper\MediaSource\AdditionalTypes;
use WP_Media_Helper\Thumbnails\Thumbnails;

class Bootstrap {

	public static function init(): void {
		Schema::maybeUpgrade();
		\WP_Media_Helper\Geo\Places\PlacesSchema::maybeUpgrade();
		Cron::register();
		\WP_Media_Helper\Geo\GeoCron::register();
		\WP_Media_Helper\Geo\Places\PlacesCron::register();
		\WP_Media_Helper\Geo\GeoFilter::register();
		MediaHelperCommand::register();
		PathsCommand::register();
		SourceCommand::register();
		IndexCommand::register();
		AttachmentsCommand::register();
		GeoCommand::register();
		PlacesCommand::register();
		ThumbnailsCommand::register();
		AdditionalTypes::register();
		OrphanCheck::register();
		new OrphanNotices();
		new OrphansPage();
		new ExternalSourceSettingsPage();
		new EditorPanel();
		new EditorMediaController();
		new InsertController();
		new AttachmentUrls();
		new Thumbnails();
		new PostDateMeta();
		new MediaLibraryColumn();
		new \WP_Media_Helper\Blocks\GalleryBlock();
	}
}
