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
	cs\Index,
	cs\Language\Prefix,
	cs\Page;
function make_url ($arguments) {
	$base_url = 'admin/Shop/items/?';
	return $base_url.http_build_query(array_merge((array)$_GET, $arguments));
}

function make_header ($title, $field) {
	$order_by = @$_GET['order_by'] ?: 'created';
	$icon     = $order_by == $field ? h::icon(@$_GET['asc'] ? 'caret-up' : 'caret-down') : '';
	$asc      = $order_by == $field ? !@$_GET['asc'] : false;
	return h::a(
		"$title $icon",
		[
			'href' => make_url(
				[
					'order_by' => $field,
					'asc'      => $asc,
					'page'     => 1
				]
			)
		]
	);
}

Index::instance()->buttons = false;
$L                         = new Prefix('shop_');
$Page                      = Page::instance();
$Page->title($L->items);
$Categories  = Categories::instance();
$Items       = Items::instance();
$page        = @$_GET['page'] ?: 1;
$module_data = Config::instance()->module('Shop');
$count       = @$_GET['count'] ?: $module_data->items_per_page_admin;
$items       = $Items->get($Items->search(
	(array)$_GET,
	$page,
	$count,
	@$_GET['order_by'] ?: 'id',
	@$_GET['asc']
));
$items_total = $Items->search(
	[
		'total_count' => 1
	] + (array)$_GET,
	$page,
	$count,
	@$_GET['order_by'] ?: 'id',
	@$_GET['asc']
);
$Page->content(
	h::{'h3.uk-lead.cs-center'}($L->items).
	h::{'cs-table[list][with-header]'}(
		h::{'cs-table-row cs-table-cell'}(
			make_header('id', 'id'),
			$L->title,
			make_header($L->category, 'category'),
			make_header($L->price, 'price'),
			make_header($L->in_stock, 'in_stock'),
			make_header($L->listed, 'listed'),
			$L->action
		).
		h::cs_table_row(array_map(
			function ($item) use ($L, $Categories, $module_data) {
				return h::cs_table_cell(
					[
						$item['id'],
						$item['title'],
						h::a(
							$Categories->get($item['category'])['title'],
							[
								'href' => "admin/Shop/items/?category=$item[category]"
							]
						),
						sprintf($module_data->price_formatting, $item['price']),
						$item['in_stock'] ?: ($item['soon'] ? $L->available_soon : 0),
						h::a(
							h::icon($item['listed'] ? 'check' : 'minus'),
							[
								'href' => "admin/Shop/items/?listed=$item[listed]"
							]
						),
						h::{'button.uk-button.cs-shop-item-edit'}(
							$L->edit,
							[
								'data-id' => $item['id']
							]
						).
						h::{'button.uk-button.cs-shop-item-delete'}(
							$L->delete,
							[
								'data-id' => $item['id']
							]
						)
					],
					[
						'class' => $item['listed'] ? 'uk-alert-success' : ($item['in_stock'] || $item['soon'] ? 'uk-alert-warning' : 'uk-alert-danger')
					]
				);
			},
			$items
		) ?: false)
	).
	pages($page, ceil($items_total / $count), function ($page) {
		return make_url([
			'page' => $page
		]);
	}, true).
	h::{'p button.uk-button.cs-shop-item-add'}($L->add)
);
