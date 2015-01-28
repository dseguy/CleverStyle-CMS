// Generated by CoffeeScript 1.4.0

/**
 * @package       Shop
 * @order_status  modules
 * @author        Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright     Copyright (c) 2014-2015, Nazar Mokrynskyi
 * @license       MIT License, see license.txt
*/


(function() {
  var cart;

  cart = cs.shop.cart;

  Polymer({
    units: 0,
    ready: function() {
      var $this;
      this.$.img.innerHTML = this.querySelector('#img').outerHTML;
      this.href = this.querySelector('#link').href;
      this.item_title = this.querySelector('#link').innerHTML;
      $this = $(this);
      this.item_id = $this.data('id');
      this.unit_price = $this.data('unit-price');
      this.price = $this.data('price');
      this.units = $this.data('units');
      this.unit_price_formatted = sprintf(cs.shop.settings.price_formatting, this.unit_price);
      return this.price_formatted = sprintf(cs.shop.settings.price_formatting, this.price);
    },
    unitsChanged: function() {
      var discount;
      if (parseInt(this.units)) {
        cart.set(this.item_id, this.units);
      } else {
        console.log(this.units);
        cart.del(this.item_id);
      }
      this.price = this.unit_price * this.units;
      this.price_formatted = sprintf(cs.shop.settings.price_formatting, this.price);
      discount = this.units * this.unit_price - this.price;
      return this.$.discount.innerHTML = discount ? (discount = sprintf(cs.shop.settings.price_formatting, discount), "(" + cs.Language.shop_discount + ": " + discount + ")") : '';
    }
  });

}).call(this);