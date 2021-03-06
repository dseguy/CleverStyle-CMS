<?php
/**
 * @package   Blogs
 * @category  modules
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2011-2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
 */
namespace cs\modules\Blogs;
use
	h,
	cs\Config,
	cs\Event,
	cs\Index,
	cs\Language,
	cs\Page\Meta,
	cs\Page,
	cs\Route;

if (!Event::instance()->fire('Blogs/section')) {
	return;
}
$Config   = Config::instance();
$Index    = Index::instance();
$L        = Language::instance();
$Meta     = Meta::instance();
$Page     = Page::instance();
$Posts    = Posts::instance();
$Route    = Route::instance();
$Sections = Sections::instance();
/**
 * At first - determine part of url and get sections list based on that path
 */
$sections = $Sections->get_by_path(
	array_slice($Route->path, 1)
);
if (!$sections) {
	error_code(400);
	return;
}
$sections = $Sections->get($sections);
/**
 * Now lets set page title using sections names from page path
 * We will not remove `$section` variable after, since it will be direct parent of each shown post
 */
foreach ($sections as $section) {
	$Page->title($section['title']);
}
/**
 * Now add link to Atom feed for posts from current section only
 */
/** @noinspection PhpUndefinedVariableInspection */
$Page->atom(
	"Blogs/atom.xml/?section=$section[id]",
	implode($Config->core['title_delimiter'], [$L->latest_posts, $L->section, $section['title']])
);
/**
 * Set page of blog type (Open Graph protocol)
 */
$Meta->blog();
/**
 * Determine current page
 */
$page = max(
	isset($Route->ids[0]) ? array_slice($Route->ids, -1)[0] : 1,
	1
);
/**
 * If this is not first page - show that in page title
 */
if ($page > 1) {
	$Page->title($L->blogs_nav_page($page));
}
/**
 * Get posts for current page in JSON-LD structure format
 */
$posts_per_page = $Config->module('Blogs')->posts_per_page;
$posts          = $Posts->get_for_section($section['id'], $page, $posts_per_page);
/**
 * Render posts page
 */
if (!$posts) {
	$Index->content(
		h::{'p.cs-center'}($L->no_posts_yet)
	);
	return;
}
/**
 * Base url (without page number)
 */
$base_url = $Config->base_url().'/'.path($L->Blogs).'/'.path($L->section)."/$section[full_path]";
$Index->content(
	Helpers::posts_list(
		$posts,
		$section['posts'],
		$page,
		$base_url
	)
);
