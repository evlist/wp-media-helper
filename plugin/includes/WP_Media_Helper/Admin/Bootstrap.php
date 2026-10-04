<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Index\Cron;
use WP_Media_Helper\Index\Schema;
use WP_Media_Helper\MediaSource\AdditionalTypes;
use WP_Media_Helper\Thumbnails\Thumbnails;

class Bootstrap {

	public static function init(): void {
		Schema::maybeUpgrade();
		Cron::register();
		AdditionalTypes::register();
		new ExternalSourceSettingsPage();
		new EditorPanel();
		new EditorMediaController();
		new AttachmentUrls();
		new Thumbnails();
		new PostDateMeta();
	}
}
