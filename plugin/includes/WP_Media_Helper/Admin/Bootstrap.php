<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

class Bootstrap {

	public static function init(): void {
		new ExternalSourceSettingsPage();
		new EditorPanel();
		new EditorMediaController();
	}
}
