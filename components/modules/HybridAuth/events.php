<?php
/**
 * @package   HybridAuth
 * @category  modules
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2011-2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
 */
namespace cs;
Event::instance()->on(
	'System/User/construct/after',
	function () {
		switch (Config::instance()->components['modules']['HybridAuth']['active']) {
			case 1:
				require __DIR__.'/events/enabled.php';
				require_once __DIR__.'/events/enabled/functions.php';
		}
	}
);
Event::instance()->on(
	'System/Index/construct',
	function () {
		if (!admin_path()) {
			return;
		}
		switch (Config::instance()->components['modules']['HybridAuth']['active']) {
			case -1:
				require __DIR__.'/events/uninstalled.php';
				break;
		}
	}
);
