/**
 * @package ImpressPages
 * @copyright Copyright (C) 2011 ImpressPages LTD.
 * @license GNU/GPL, see ip_license.html
 */

(function($) {

    var methods = {
        init : function(options) {
            return this.each(function() {
                var $this = $(this);
                
                var data = $this.data('ipWidgetButton');
            
                // If the plugin hasn't been initialized yet
                if ( ! data ) {
                    $this.draggable({
                        connectToSortable : '.ipBlockSelector',
                        revert : 'invalid',
                        helper : 'clone',
                        stop: function(event, ui) { alert($(this).data('ipWidgetButton').name) }    
                    });
                    
                    $this.data('ipWidgetButton', {
                        name : $this.attr('id').substr(15)
                        
                    });
    
                }                
                    
                
                
                

            });
        },
        destroy : function() {
            // TODO
        },
        test : function () {
            return this.each(function() {
                alert('test ' + $(this).data('ipWidgetButton').name);
            });
        }
        
    };

    $.fn.ipWidgetButton = function(method) {

        if (methods[method]) {
            return methods[method].apply(this, Array.prototype.slice.call(arguments, 1));
        } else if (typeof method === 'object' || !method) {
            return methods.init.apply(this, arguments);
        } else {
            $.error('Method ' + method + ' does not exist on jQuery.ipWidgetButton');
        }

    };

})(jQuery);