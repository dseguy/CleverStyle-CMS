// Generated by CoffeeScript 1.4.0

/**
 * @package   Shop
 * @category  modules
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2014-2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
*/


(function() {

  $(function() {
    var L, make_modal;
    L = cs.Language;
    make_modal = function(attributes, categories, title, action) {
      var modal;
      attributes = (function() {
        var attribute, attributes_, key, keys, _i, _len, _results;
        attributes_ = {};
        keys = [];
        for (attribute in attributes) {
          attribute = attributes[attribute];
          attributes_[attribute.title_internal] = "<option value=\"" + attribute.id + "\">" + attribute.title_internal + "</option>";
          keys.push(attribute.title_internal);
        }
        keys.sort();
        _results = [];
        for (_i = 0, _len = keys.length; _i < _len; _i++) {
          key = keys[_i];
          _results.push(attributes_[key]);
        }
        return _results;
      })();
      attributes = attributes.join('');
      categories = (function() {
        var categories_, category;
        categories_ = {};
        for (category in categories) {
          category = categories[category];
          categories_[category.id] = category;
        }
        return categories_;
      })();
      categories = (function() {
        var categories_, category, key, keys, parent_category, _i, _len, _results;
        categories_ = {};
        keys = ['-'];
        for (category in categories) {
          category = categories[category];
          parent_category = parseInt(category.parent);
          while (parent_category && parent_category !== category) {
            parent_category = categories[parent_category];
            if (parent_category.parent === category.id) {
              break;
            }
            category.title = parent_category.title + ' :: ' + category.title;
            parent_category = parseInt(parent_category.parent);
          }
          categories_[category.title] = "<option value=\"" + category.id + "\">" + category.title + "</option>";
          keys.push(category.title);
        }
        keys.sort();
        _results = [];
        for (_i = 0, _len = keys.length; _i < _len; _i++) {
          key = keys[_i];
          _results.push(categories_[key]);
        }
        return _results;
      })();
      categories = categories.join('');
      modal = $.cs.simple_modal("<form>\n	<h3 class=\"cs-center\">" + title + "</h3>\n	<p>\n		" + L.shop_parent_category + ":\n		<select name=\"parent\" required>\n			<option value=\"0\">" + L.none + "</option>\n			" + categories + "\n		</select>\n	</p>\n	<p>\n		" + L.shop_title + ": <input name=\"title\" required>\n	</p>\n	<p>\n		" + L.shop_description + ": <textarea name=\"description\"></textarea>\n	</p>\n	<p class=\"image uk-hidden\">\n		" + L.shop_image + ":\n		<a target=\"_blank\" class=\"uk-thumbnail\">\n			<img>\n			<br>\n			<button type=\"button\" class=\"remove-image uk-button uk-button-danger uk-width-1-1\">" + L.shop_remove_image + "</button>\n		</a>\n		<input type=\"hidden\" name=\"image\">\n	</p>\n	<p>\n		<span class=\"uk-progress uk-progress-striped uk-active uk-hidden uk-display-block\">\n			<span class=\"uk-progress-bar\"></span>\n		</span>\n		<button type=\"button\" class=\"set-image uk-button\">" + L.shop_set_image + "</button>\n	</p>\n	<p>\n		" + L.shop_category_attributes + ": <select name=\"attributes[]\" multiple required>" + attributes + "</select>\n	</p>\n	<p>\n		" + L.shop_title_attribute + ": <select name=\"title_attribute\" required>" + attributes + "</select>\n	</p>\n	<p>\n		" + L.shop_description_attribute + ":\n		<select name=\"description_attribute\" required>\n			<option value=\"0\">" + L.none + "</option>\n			" + attributes + "\n		</select>\n	</p>\n	<p>\n		" + L.shop_visible + ":\n		<label><input type=\"radio\" name=\"visible\" value=\"1\" checked> " + L.yes + "</label>\n		<label><input type=\"radio\" name=\"visible\" value=\"0\"> " + L.no + "</label>\n	</p>\n	<p>\n		<button class=\"uk-button\" type=\"submit\">" + action + "</button>\n	</p>\n</form>");
      modal.set_image = function(image) {
        modal.find('[name=image]').val(image);
        if (image) {
          return modal.find('.image').removeClass('uk-hidden').find('a').attr('href', image).find('img').attr('src', image);
        } else {
          return modal.find('.image').addClass('uk-hidden');
        }
      };
      modal.find('.remove-image').click(function() {
        return modal.set_image('');
      });
      if (cs.file_upload) {
        (function() {
          var progress, uploader;
          progress = modal.find('.set-image').prev();
          uploader = cs.file_upload(modal.find('.set-image'), function(image) {
            progress.addClass('uk-hidden').children().width(0);
            return modal.set_image(image[0]);
          }, function(error) {
            progress.addClass('uk-hidden').children().width(0);
            return alert(error.message);
          }, function(percents) {
            return progress.removeClass('uk-hidden').children().width(percents + '%');
          });
          return modal.on('hide.uk.modal', function() {
            return uploader.destroy();
          });
        })();
      } else {
        modal.find('.set-image').click(function() {
          var image;
          image = prompt(L.shop_image_url);
          if (image) {
            return modal.set_image(image);
          }
        });
      }
      return modal;
    };
    return $('html').on('mousedown', '.cs-shop-category-add', function() {
      return $.when($.getJSON('api/Shop/admin/attributes'), $.getJSON('api/Shop/admin/categories')).done(function(attributes, categories) {
        var modal;
        modal = make_modal(attributes[0], categories[0], L.shop_category_addition, L.shop_add);
        return modal.find('form').submit(function() {
          $.ajax({
            url: 'api/Shop/admin/categories',
            type: 'post',
            data: $(this).serialize(),
            success: function() {
              alert(L.shop_added_successfully);
              return location.reload();
            }
          });
          return false;
        });
      });
    }).on('mousedown', '.cs-shop-category-edit', function() {
      var id;
      id = $(this).data('id');
      return $.when($.getJSON('api/Shop/admin/attributes'), $.getJSON('api/Shop/admin/categories'), $.getJSON("api/Shop/admin/categories/" + id)).done(function(attributes, categories, category) {
        var modal;
        modal = make_modal(attributes[0], categories[0], L.shop_category_edition, L.shop_edit);
        modal.find('form').submit(function() {
          $.ajax({
            url: "api/Shop/admin/categories/" + id,
            type: 'put',
            data: $(this).serialize(),
            success: function() {
              alert(L.shop_edited_successfully);
              return location.reload();
            }
          });
          return false;
        });
        category = category[0];
        modal.find('[name=parent]').val(category.parent);
        modal.find('[name=title]').val(category.title);
        modal.find('[name=description]').val(category.description);
        category.attributes.forEach(function(attribute) {
          return modal.find("[name='attributes[]'] > [value=" + attribute + "]").prop('selected', true);
        });
        modal.find('[name=title_attribute]').val(category.title_attribute);
        modal.find('[name=description_attribute]').val(category.description_attribute);
        modal.set_image(category.image);
        return modal.find("[name=visible][value=" + category.visible + "]").prop('checked', true);
      });
    }).on('mousedown', '.cs-shop-category-delete', function() {
      var id;
      id = $(this).data('id');
      if (confirm(L.shop_sure_want_to_delete_category)) {
        return $.ajax({
          url: "api/Shop/admin/categories/" + id,
          type: 'delete',
          success: function() {
            alert(L.shop_deleted_successfully);
            return location.reload();
          }
        });
      }
    });
  });

}).call(this);
