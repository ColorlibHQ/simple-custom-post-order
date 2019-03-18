(function ($) {
    $('table.posts #the-list, table.pages #the-list').sortable({
        'items': 'tr',
        'axis': 'y',
        'helper': fixHelper,
        'update': function (e, ui) {
            $.post(ajaxurl, {
                action: 'update-menu-order',
                order: $('#the-list').sortable('serialize'),
            });
        }
    });
    $('table.tags #the-list').sortable({
        'items': 'tr',
        'axis': 'y',
        'helper': fixHelper,
        'update': function (e, ui) {
            $.post(ajaxurl, {
                action: 'update-menu-order-tags',
                order: $('#the-list').sortable('serialize'),
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

    $('#the-list').on('mousedown', function () {
        jQuery('#the-list').parent().find('thead').find('th').each(function () {
            $(this).width($(this).width())
        });
        jQuery('#the-list').find('td').each(function () {
            $(this).width($(this).width())
        });
    });

    $('#the-list').on('mouseup', function () {
        jQuery('#the-list').parent().find('thead').find('th').each(function () {
            $(this).removeAttr('style');
        });
        jQuery('#the-list').find('td').each(function () {
            $(this).removeAttr('style');
        });
    });

    /*****
     *  End table breaking fix
     */

})(jQuery)