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
	h,
	cs\Config,
	cs\Language\Prefix,
	cs\Page,
	cs\Route;
$Config          = Config::instance();
$L               = new Prefix('shop_');
$Page            = Page::instance();
$Route = Route::instance();
$Categories      = Categories::instance();
$all_categories  = $Categories->get_for_user($Categories->get_all());
$all_categories  = array_combine(array_column($all_categories, 'id'), $all_categories);
$categories_tree = [];
foreach ($all_categories as $category) {
	$categories_tree[$category['parent']][] = $category['id'];
}
unset($category);
$current_category = 0;
if ($Route->path) {
	$current_category = array_slice($Route->path, -1)[0];
	$current_category = explode(':', $current_category);
	$current_category = (int)array_pop($current_category);
}
$module_path     = path($L->shop);
$categories_path = path($L->categories);
if (isset($all_categories[$current_category])) {
	$category = $all_categories[$current_category];
	$Page->title($category['title']);
	$canonical_url     = "{$Config->base_url()}/$module_path/$categories_path/".path($category['title']).":$category[id]";
	$Page->Description = description($category['description']);
	unset($category);
} elseif ($current_category === 0) {
	$canonical_url = "{$Config->base_url()}/$module_path";
} else {
	error_code(404);
	return;
}
$Page->canonical_url($canonical_url);
$page = (int)@$_GET['page'] ?: 1;
if ($page == 1 && @$categories_tree[$current_category]) {
	$categories_list = [];
	foreach ($categories_tree[$current_category] as $category) {
		$category                            = $all_categories[$category];
		$categories_list[$category['title']] =
			h::{'img#img'}([
				'src'   => $category['image'] ?: Items::DEFAULT_IMAGE,
				'title' => h::prepare_attr_value($category['title'])
			]).
			h::{'h1 a#link'}(
				$category['title'],
				[
					'href' => "$module_path/$categories_path/".path($category['title']).":$category[id]"
				]
			).
			h::{'#description'}($category['description'] ?: false).
			h::{'section#nested article[is=cs-shop-category-nested]'}(array_map(function ($category) use ($all_categories, $module_path, $categories_path) {
				$category = $all_categories[$category];
				return
					h::{'img#img'}([
						'src'   => $category['image'] ?: Items::DEFAULT_IMAGE,
						'title' => h::prepare_attr_value($category['title'])
					]).
					h::{'h1 a#link'}(
						$category['title'],
						[
							'href' => "$module_path/$categories_path/".path($category['title']).":$category[id]"
						]
					);
			}, @$categories_tree[$category['id']] ?: []) ?: false);
	}
	ksort($categories_list);
	$Page->content(
		h::{'section[is=cs-shop-categories] article[is=cs-shop-category]'}(array_values($categories_list))
	);
	unset($categories_list);
}
if (!$current_category) {
	return;
}
$count = (int)@$_GET['count'] ?: $Config->module('Shop')->items_per_page;
$Items = Items::instance();
$items = $Items->get($Items->search(
	[
		'listed'   => 1,
		'category' => $current_category
	] + (array)$_GET,
	$page,
	$count,
	@$_GET['order_by'] ?: 'id',
	@$_GET['asc']
));
if (!$items) {
	return;
}
$items_total     = $Items->search(
	[
		'listed'      => 1,
		'category'    => $current_category,
		'total_count' => 1
	] + (array)$_GET,
	$page,
	$count,
	@$_GET['order_by'] ?: 'id',
	@$_GET['asc']
);
$base_items_path = "$module_path/".path($L->items).'/'.path($all_categories[$current_category]['title']).'/';
foreach ($items as &$item) {
	$item = [
		h::{'img#img'}([
			'src'   => @$item['images'][0] ?: Items::DEFAULT_IMAGE,
			'title' => h::prepare_attr_value($item['title'])
		]).
		h::{'h1 a#link'}(
			$item['title'],
			[
				'href' => $base_items_path.path($item['title']).":$item[id]"
			]
		).
		h::{'#description'}(truncate($item['description'], 200) ?: false),
		[
			'data-id'       => $item['id'],
			'data-date'     => $item['price'],
			'data-price'    => $item['price'],
			'data-in_stock' => $item['in_stock'],
			'data-soon'     => $item['soon']
		]
	];
}
unset($item);
$Page->content(
	h::{'section[is=cs-shop-category-items] article[is=cs-shop-category-item]'}(array_values($items)).
	pages($page, ceil($items_total / $count), function ($page) use ($canonical_url) {
		return "$canonical_url/?page=$page";
	}, true)
);
