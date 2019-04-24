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
    jQuery(window).load(function(){
        // fix for padding issue when table braking
        $('#the-list').before('<style>.widefat td, .widefat th{padding:8px 10px !important;}</style>');

        jQuery('#the-list').parent().find('thead').find('th').each(function () {
            $(this).width($(this).width());
        });

        jQuery('#the-list tr').each(function () {
            $(this).width($(this).width());
        });

        jQuery('#the-list').find('td').each(function () {
            $(this).width($(this).width());
        });

        jQuery('#the-list').width(jQuery('#the-list').width());
    });

    /*****
     *  End table breaking fix
     */

})(jQuery)