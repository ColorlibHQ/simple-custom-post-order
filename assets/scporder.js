(function ($) {
    // Defined before the .sortable() calls below read it — as a `var` assigned
    // afterwards it was still undefined there, so no helper was used and the
    // dragged row's cells collapsed. Pins each cell (<td>/<th>) to its width.
    var fixHelper = function (e, ui) {
        ui.children().each(function () {
            $(this).width($(this).width());
        });
        return ui;
    };

    // Only real item rows. A plain 'tr' also matched an open Quick Edit row
    // (id "edit-N") and the Bulk Edit row (serialized as bulk[]=edit → ID 0),
    // which made the server reject the whole save with a 403.
    var originalOrder = '';
    function orderValues() {
        var values = {}, complete = true;
        $('#the-list').children(items).each(function () {
            var marker = this.querySelector('.scpo-order-snapshot');
            var raw = marker && marker.getAttribute('data-value');
            if (raw !== 'null' && !/^-?\d+$/.test(raw || '')) { complete = false; return; }
            values[this.id.replace(/^.*-/, '')] = raw === 'null' ? null : Number(raw);
        });
        return complete ? JSON.stringify(values) : undefined;
    }
    function saveOrder(action) {
        var list = $('#the-list');
        list.sortable('disable');
        var payload = {
            action: action,
            order: list.sortable('serialize'),
            original_order: originalOrder,
            nonce: scporder_vars.nonce
        };
        var values = orderValues();
        if (values !== undefined) { payload.original_values = values; }
        $.post(scporder_vars.ajax_url, payload).done(function(response) {
            if (response && response.success && response.data && response.data.order_values) {
                var values = response.data.order_values;
                list.children(items).each(function () {
                    var id = this.id.replace(/^.*-/, '');
                    var marker = this.querySelector('.scpo-order-snapshot');
                    if (marker && Object.prototype.hasOwnProperty.call(values, id)) {
                        marker.setAttribute('data-value', String(values[id]));
                    }
                });
            }
            if (!response || !response.success) {
                window.alert(response && response.data && response.data.message || scporder_vars.i18n.error);
            }
        }).fail(function(xhr) {
            var data = xhr.responseJSON && xhr.responseJSON.data;
            window.alert(data && data.message || scporder_vars.i18n.error);
        }).always(function() { list.sortable('enable'); });
    }

    var items = 'tr:not(.inline-edit-row):not(.no-items):not(#bulk-edit)';

    // The saved values rise down a list shown in its custom order. If they
    // don't, a theme or another plugin sorted it, and a save would make that
    // order the custom one (see the Modern sorter's shownInSavedOrder()).
    var previous = null, shownInOrder = true;
    $('#the-list').children(items).each(function () {
        var marker = this.querySelector('.scpo-order-snapshot');
        var raw = marker && marker.getAttribute('data-value');
        if (!/^-?\d+$/.test(raw || '')) { return; }
        if (previous !== null && Number(raw) < previous) { shownInOrder = false; return false; }
        previous = Number(raw);
    });
    if (!shownInOrder) {
        var strings = scporder_vars.i18n || {};
        $('<div class="notice notice-warning inline scpo-order-notice"><p></p></div>')
            .find('p').text(strings.notInOrder || 'Drag and drop is turned off here: this list is not shown in its custom order, so your theme or another plugin is sorting it.').end()
            .insertBefore($('#the-list').closest('table'));
        return;
    }

    $('table.posts #the-list, table.pages #the-list').sortable({
        'items': items,
        'axis': 'y',
        'helper': fixHelper,
        'start': function () { originalOrder = $('#the-list').sortable('serialize'); },
        'update': function (e, ui) {
            saveOrder('update-menu-order');
        }
    });
    $('table.tags #the-list').sortable({
        'items': items,
        'axis': 'y',
        'helper': fixHelper,
        'start': function () { originalOrder = $('#the-list').sortable('serialize'); },
        'update': function (e, ui) {
            saveOrder('update-menu-order-tags');
        }
    });

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
