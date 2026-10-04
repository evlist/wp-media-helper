<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Index\Cron;
use WP_Media_Helper\Index\Schema;

class Bootstrap {

	public static function init(): void {
		Schema::maybeUpgrade();
		Cron::register();
		new ExternalSourceSettingsPage();
		new EditorPanel();
		new EditorMediaController();
		new AttachmentUrls();
		new PostDateMeta();
	}
}
