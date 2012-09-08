<?php
/**
 * @package		Blogs
 * @category	modules
 * @author		Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright	Copyright (c) 2011-2012 by Nazar Mokrynskyi
 * @license		MIT License, see license.txt
 */
namespace	cs\modules\Blogs;
use			\h;
global $Index, $L, $Page, $Config;
$module_configuration	= $Config->module(MODULE);
$Index->apply_button	= false;
$Page->title($L->general);
$Index->content(
	h::{'table.cs-left-all.cs-fullwidth-table.cs-left-even.cs-right-odd tr| td'}(
		[
			h::info('posts_per_page'),
			h::{'input[type=number][min=1]'}([
				'name'		=> 'general[posts_per_page]',
				'value'		=> $module_configuration->posts_per_page
			])
		],
		[
			h::info('maximum_number_of_sections_for_post'),
			h::{'input[type=number][min=1]'}([
				'name'		=> 'general[max_sections]',
				'value'		=> $module_configuration->max_sections
			])
		],
		[
			h::info('enable_comments'),
			h::{'input[type=radio]'}([
				'name'		=> 'general[enable_comments]',
				'value'		=> [0, 1],
				'in'		=> [$L->no, $L->yes],
				'checked'	=> $module_configuration->enable_comments
			])
		]
	).
	h::{'input[type=hidden][name=mode][value=general]'}()
);