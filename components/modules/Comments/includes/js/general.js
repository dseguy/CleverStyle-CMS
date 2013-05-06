/**
 * @package		Comments
 * @category	modules
 * @author		Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright	Copyright (c) 2011-2013, Nazar Mokrynskyi
 * @license		MIT License, see license.txt
 */
$(function () {
	$(document).on(
		'click',
		'.cs-comments-comment-write-send',
		blogs_add_comment
	).on(
		'click',
		'.cs-comments-comment-write-edit',
		blogs_edit_comment
	).on(
		'click',
		'.cs-comments-comment-write-cancel',
		blogs_comment_cancel
	).on(
		'click',
		'.cs-comments-comment-text',
		function () {
			blogs_comment_cancel();
			var textarea	= $('.cs-comments-comment-write-text');
			textarea.data(
				'parent',
				$(this).parent('article').prop('id').replace('comment_', '')
			).val('');
			typeof window.editor_deinitialization === 'function' && editor_deinitialization(
				textarea.prop('id')
			);
			$(this).after(
				$('.cs-comments-comment-write')
			);
			typeof window.editor_reinitialization === 'function' && editor_reinitialization(
				textarea.prop('id')
			);
			typeof window.editor_focus === 'function' && editor_focus(
				textarea.prop('id')
			);
			$('.cs-comments-comment-write-cancel').show();
			$('.cs-comments-add-comment').hide();
		}
	).on(
		'click',
		'.cs-comments-comment-edit',
		function () {
			blogs_comment_cancel();
			var textarea	= $('.cs-comments-comment-write-text'),
				parent		= $(this).parent('article'),
				text		= parent.children('.cs-comments-comment-text');
			textarea.data(
				'id',
				parent.prop('id').replace('comment_', '')
			).val(text.html());
			typeof window.editor_deinitialization === 'function' && editor_deinitialization(
				textarea.prop('id')
			);
			text.hide().after(
				$('.cs-comments-comment-write')
			);
			typeof window.editor_reinitialization === 'function' && editor_reinitialization(
				textarea.prop('id')
			);
			typeof window.editor_focus === 'function' && editor_focus(
				textarea.prop('id')
			);
			$('.cs-comments-comment-write-edit, .cs-comments-comment-write-cancel').show();
			$('.cs-comments-comment-write-send').hide();
		}
	).on(
		'click',
		'.cs-comments-comment-delete',
		blogs_delete_comment
	);
	function blogs_add_comment () {
		var textarea	= $('.cs-comments-comment-write-text');
		$.ajax(
			base_url+'/api/Comments/add',
			{
				cache		: false,
				data		: {
					item	: textarea.data('item'),
					parent	: textarea.data('parent'),
					module	: textarea.data('module'),
					text	: textarea.val()
				},
				dataType	: 'json',
				success		: function (result) {
					var no_comments	= $('.cs-blogs-no-comments');
					if (no_comments.length) {
						no_comments.remove();
					}
					if (textarea.data('parent') == 0) {
						$('.cs-comments-comments').append(result);
					} else {
						$('#comment_'+textarea.data('parent')).append(result);
					}
					blogs_comment_cancel();
				},
				error	: function (xhr) {
					if (xhr.responseText) {
						alert(json_decode(xhr.responseText).error_description);
					} else {
						alert(L.comment_sending_connection_error);
					}
				}
			}
		);
	}
	function blogs_edit_comment () {
		var textarea	= $('.cs-comments-comment-write-text');
		$.ajax(
			base_url+'/api/Comments/edit',
			{
				cache		: false,
				data		: {
					id		: textarea.data('id'),
					module	: textarea.data('module'),
					text	: textarea.val()
				},
				dataType	: 'json',
				success	: function (result) {
					$('#comment_'+textarea.data('id')).children('.cs-comments-comment-text').html(result);
					blogs_comment_cancel();
				},
				error	: function (xhr) {
					if (xhr.responseText) {
						alert(json_decode(xhr.responseText).error_description);
					} else {
						alert(L.comment_editing_connection_error);
					}
				}
			}
		);
	}
	function blogs_delete_comment () {
		var comment = $(this).parent('article'),
			id		= comment.prop('id').replace('comment_', '');
		$.ajax(
			base_url+'/api/Comments/delete',
			{
				cache		: false,
				data		: {
					id		: id,
					module	: $('.cs-comments-comment-write-text').data('module')
				},
				dataType	: 'json',
				success	: function (result) {
					var	parent	= comment.parent();
					comment.remove();
					blogs_comment_cancel();
					if (result && !parent.find('.cs-comments-comment').length && !parent.find('.cs-comments-comment-delete').length) {
						parent.find('.cs-comments-comment-edit').after(result);
					}
				},
				error	: function (xhr) {
					if (xhr.responseText) {
						alert(json_decode(xhr.responseText).error_description);
					} else {
						alert(L.comment_deleting_connection_error);
					}
				}
			}
		);
	}
	function blogs_comment_cancel () {
		$('.cs-comments-comment-text').show();
		var textarea	= $('.cs-comments-comment-write-text');
		textarea.data(
			'parent',
			0
		).data(
			'id',
			0
		).val('');
		typeof window.editor_deinitialization === 'function' && editor_deinitialization(
			textarea.prop('id')
		);
		$('.cs-comments-comments').next().after(
			$('.cs-comments-comment-write')
		);
		typeof window.editor_reinitialization === 'function' && editor_reinitialization(
			textarea.prop('id')
		);
		$('.cs-comments-comment-write-send').show();
		$('.cs-comments-comment-write-edit, .cs-comments-comment-write-cancel').hide();
		$('.cs-comments-add-comment').show();
	}
});