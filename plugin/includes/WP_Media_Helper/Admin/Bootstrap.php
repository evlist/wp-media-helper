<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Cli\AttachmentsCommand;
use WP_Media_Helper\Cli\IndexCommand;
use WP_Media_Helper\Cli\MediaHelperCommand;
use WP_Media_Helper\Cli\PathsCommand;
use WP_Media_Helper\Cli\SourceCommand;
use WP_Media_Helper\Index\Cron;
use WP_Media_Helper\Maintenance\OrphanCheck;
use WP_Media_Helper\Index\Schema;
use WP_Media_Helper\MediaSource\AdditionalTypes;
use WP_Media_Helper\Thumbnails\Thumbnails;

class Bootstrap {

	public static function init(): void {
		Schema::maybeUpgrade();
		Cron::register();
		MediaHelperCommand::register();
		PathsCommand::register();
		SourceCommand::register();
		IndexCommand::register();
		AttachmentsCommand::register();
		AdditionalTypes::register();
		OrphanCheck::register();
		new OrphanNotices();
		new OrphansPage();
		new ExternalSourceSettingsPage();
		new EditorPanel();
		new EditorMediaController();
		new AttachmentUrls();
		new Thumbnails();
		new PostDateMeta();
		new MediaLibraryColumn();
		new \WP_Media_Helper\Blocks\GalleryBlock();
	}
}
