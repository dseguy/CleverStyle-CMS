// Generated by CoffeeScript 1.4.0

/**
 * @package   Shop
 * @category  modules
 * @author    Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright Copyright (c) 2014-2015, Nazar Mokrynskyi
 * @license   MIT License, see license.txt
*/


(function() {

  (function(cart, L) {
    return Polymer({
      in_cart: 0,
      add_to_cart_text: L.shop_add_to_cart,
      already_in_cart_text: L.shop_already_in_cart,
      domReady: function() {
        var $this;
        $this = $(this);
        this.item_id = $this.data('id');
        this.in_cart = cart.get(this.item_id);
        return UIkit.tooltip(this.$.in_cart, {
          animation: true,
          delay: 200
        });
      },
      add: function() {
        return this.in_cart = cart.add(this.item_id);
      }
    });
  })(cs.shop.cart, cs.Language);

}).call(this);
