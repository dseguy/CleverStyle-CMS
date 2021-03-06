<?php
/**
 * @package   Static Pages
 * @category  modules
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2011-2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
 */
namespace cs\modules\Static_pages;
use
	cs\Cache,
	cs\Event,
	cs\User;
Event::instance()->on(
	'admin/System/components/modules/uninstall/process',
	function ($data) {
		if ($data['name'] != 'Static_pages' || !User::instance()->admin()) {
			return true;
		}
		time_limit_pause();
		$Pages      = Pages::instance();
		$Categories = Categories::instance();
		$structure  = $Pages->get_structure();
		while (!empty($structure['categories'])) {
			foreach ($structure['categories'] as $category) {
				$Categories->del($category['id']);
			}
			$structure = $Pages->get_structure();
		}
		unset($category);
		if (!empty($structure['pages'])) {
			foreach ($structure['pages'] as $page) {
				$Pages->del($page);
			}
		}
		unset(
			$structure,
			Cache::instance()->Static_pages
		);
		time_limit_pause(false);
		return true;
	}
);
