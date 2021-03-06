// Generated by CoffeeScript 1.4.0

/**
 * @package   Picturefill
 * @category  plugins
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2015, Nazar Mokrynskyi
 * @license   MIT License
*/


(function() {

  Polymer({
    ready: function() {
      var _this = this;
      return setTimeout((function() {
        return picturefill({
          elements: [_this.querySelector('img')]
        });
      }), 0);
    }
  });

}).call(this);
