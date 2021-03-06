// Generated by CoffeeScript 1.4.0

/**
 * @package		Prism
 * @author		Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright	Copyright (c) 2015, Nazar Mokrynskyi
 * @license		MIT License, see license.txt
*/


(function() {

  document.removeEventListener('DOMContentLoaded', Prism.highlightAll);

  Prism.highlightAll = function(async, callback) {
    var element, elements, _i, _len, _results;
    elements = document.querySelectorAll('code[class*="language-"], [class*="language-"] code, code[class*="lang-"], [class*="lang-"] code');
    _results = [];
    for (_i = 0, _len = elements.length; _i < _len; _i++) {
      element = elements[_i];
      if (element.matches('[contenteditable=true] *') || element.matches('.INLINE_EDITOR *')) {
        continue;
      }
      (element.parentNode.tagName === 'PRE' ? element.parentNode : element).classList.add('line-numbers');
      _results.push(Prism.highlightElement(element, async === true, callback));
    }
    return _results;
  };

  document.addEventListener('DOMContentLoaded', Prism.highlightAll);

}).call(this);
