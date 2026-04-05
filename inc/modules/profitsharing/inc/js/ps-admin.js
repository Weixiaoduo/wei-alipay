(function($){
  function makeAutocomplete(selector, actionKey){
    var $el = $(selector);
    if(!$el.length || typeof $el.autocomplete !== 'function'){ return; }
    $el.autocomplete({
      minLength: 2,
      source: function(request, response){
        $.getJSON((window.WooAlipayPSAdmin ? WooAlipayPSAdmin.ajax_url : ajaxurl), {
          action: (window.WooAlipayPSAdmin ? WooAlipayPSAdmin.actions[actionKey] : ''),
          term: request.term
        }).done(function(data){
          // Expect an array of { label, value }
          var arr = Array.isArray(data) ? data : (data && data.data ? data.data : []);
          response($.map(arr, function(item){ return { label: item.label, value: item.value }; }));
        }).fail(function(){ response([]); });
      }
    });
  }

  $(function(){
    makeAutocomplete('#product_id', 'products');
    var $cat = $('#category_id');
    if ($cat.length && $cat.is('input')) {
      makeAutocomplete('#category_id', 'categories');
    }
  });
})(jQuery);
