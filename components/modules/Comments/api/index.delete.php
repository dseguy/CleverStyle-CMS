<?php
/**
 * @package		Comments
 * @category	modules
 * @author		Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright	Copyright (c) 2011-2015, Nazar Mokrynskyi
 * @license		MIT License, see license.txt
 */
namespace	cs\modules\Comments;
use
	h,
	cs\Config,
	cs\Event,
	cs\Language,
	cs\Page,
	cs\Route,
	cs\User;
/**
 * Provides next events:<br>
 *  api/Comments/delete<code>
 *  [
 *   'Comments'			=> <i>&$Comments</i>		//Comments object should be returned in this parameter (after access checking)<br>
 *   'delete_parent'	=> <i>&$delete_parent</i>	//Boolean parameter, should contain boolean true, if parent comment may be deleted by current user<br>
 *   'id'				=> <i>id</i>				//Comment id<br>
 *   'module'			=> <i>module</i>			//Module<br>
 *  ]</code>
 */
$Config			= Config::instance();
if (!$Config->module('Comments')->active()) {
	error_code(404);
	return;
}
if (!User::instance()->user()) {
	error_code(403);
	return;
}
$Route = Route::instance();
if (!isset($Route->route[0], $_POST['module'])) {
	error_code(400);
	return;
}
$Comments		= false;
$delete_parent	= false;
Event::instance()->fire(
	'api/Comments/delete',
	[
		'Comments'		=> &$Comments,
		'delete_parent'	=> &$delete_parent,
		'id'			=> $Route->route[0],
		'module'		=> $_POST['module']
	]
);
$L				= Language::instance();
$Page			= Page::instance();
if (!is_object($Comments)) {
	error_code(500);
	$Page->error($L->comment_deleting_server_error);
	return;
}
/**
 * @var Comments $Comments
 */
if ($result = $Comments->del($Route->route[0])) {
	$Page->json($delete_parent ? h::{'icon.cs-comments-comment-delete.cs-pointer'}('trash-o') : '');
} else {
	error_code(500);
	$Page->error($L->comment_deleting_server_error);
}
