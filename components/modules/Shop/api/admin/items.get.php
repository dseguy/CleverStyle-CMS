<?php
/**
 * @package   Shop
 * @category  modules
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2014-2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
 */
namespace cs\modules\Shop;
use
	cs\Page,
	cs\Route;

$Page       = Page::instance();
$Route      = Route::instance();
$Items = Items::instance();
if (isset($_GET['ids'])) {
	$items = $Items->get(explode(',', $_GET['ids']));
	if (!$items) {
		error_code(404);
	} else {
		$Page->json($items);
	}
} elseif (isset($Route->ids[0])) {
	$item = $Items->get($Route->ids[0]);
	if (!$item) {
		error_code(404);
	} else {
		$Page->json($item);
	}
} else {
	$Page->json(
		$Items->get(
			$Items->get_all()
		)
	);
}
