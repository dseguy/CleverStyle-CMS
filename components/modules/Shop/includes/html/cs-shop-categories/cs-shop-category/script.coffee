###*
 * @package   Shop
 * @category  modules
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2014-2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
###
Polymer(
	ready : ->
		@$.img.innerHTML	= @querySelector('#img').outerHTML
		@href				= @querySelector('#link').href
		$(@querySelector('#nested')).addClass('uk-grid uk-grid-small uk-grid-width-1-4 uk-width-1-1')
);
