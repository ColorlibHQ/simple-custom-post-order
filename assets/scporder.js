(function ($) {
    $('table.posts #the-list, table.pages #the-list').sortable({
        'items': 'tr',
        'axis': 'y',
        'helper': fixHelper,
        'update': function (e, ui) {
            $.post( scporder_vars.ajax_url, {
                action: 'update-menu-order',
                order: $('#the-list').sortable('serialize'),
                nonce: scporder_vars.nonce
            });
        }
    });
    $('table.tags #the-list').sortable({
        'items': 'tr',
        'axis': 'y',
        'helper': fixHelper,
        'update': function (e, ui) {
            $.post( scporder_vars.ajax_url, {
                action: 'update-menu-order-tags',
                order: $('#the-list').sortable('serialize'),
                nonce: scporder_vars.nonce
            });
        }
    });
    var fixHelper = function (e, ui) {
        ui.children().children().each(function () {
            $(this).width($(this).width());
        });
        return ui;
    };

    /****
     * Fix for table breaking
     */
    jQuery(window).on( 'load', function () {

        // make the array for the sizes
        var td_array = new Array();
        var i = 0;

        jQuery('#the-list tr:first-child').find('td').each(function () {

            td_array[i] = $(this).outerWidth();

            i += 1;
        });

        jQuery('#the-list').find('tr').each(function () {
            var j = 0;
            $(this).find('td').each(function () {

                var paddingx = parseInt($(this).css('padding-left').replace('px', '')) + parseInt($(this).css('padding-right').replace('px', ''));
                $(this).width(td_array[j] - paddingx);

                j += 1;
            });
        });

        var y = 0;

        // check if there are items in the table
        if(jQuery('#the-list > tr.no-items').length == 0){
            jQuery('#the-list').parent().find('thead').find('th').each(function () {

                var paddingx = parseInt($(this).css('padding-left').replace('px', '')) + parseInt($(this).css('padding-right').replace('px', ''));
                $(this).width(td_array[y] - paddingx);

                y += 1;
            });

            var z = 0;
            jQuery('#the-list').parent().find('tfoot').find('th').each(function () {

                var paddingx = parseInt($(this).css('padding-left').replace('px', '')) + parseInt($(this).css('padding-right').replace('px', ''));
                $(this).width(td_array[z] - paddingx);

                z += 1;
            });
        }

    });

    /*****
     *  End table breaking fix
     */

})(jQuery)
