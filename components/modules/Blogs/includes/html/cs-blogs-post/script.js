// Generated by CoffeeScript 1.4.0

/**
 * @package   Blogs
 * @category  modules
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
*/


(function() {

  (function(L) {
    return Polymer({
      publish: {
        can_edit: false,
        can_delete: false,
        comments_enabled: false
      },
      edit_text: L.edit,
      delete_text: L["delete"],
      created: function() {
        return this.jsonld = JSON.parse(this.querySelector('script').innerHTML);
      },
      ready: function() {
        return this.$.content.innerHTML = this.jsonld.content;
      }
    });
  })(cs.Language);

}).call(this);
