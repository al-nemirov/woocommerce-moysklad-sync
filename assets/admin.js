jQuery(function($) {
    // Проверка подключения
    $('#wc_ms_test_btn').on('click', function() {
        var btn = $(this);
        var result = $('#wc_ms_test_result');
        btn.prop('disabled', true);
        result.text('Проверяю...').css('color', '#666');

        $.post(wc_ms.ajax_url, {
            action: 'wc_ms_test_connection',
            nonce: wc_ms.nonce,
            login: $('#ms_login').val(),
            password: $('#ms_password').val()
        }, function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                result.text(response.data).css('color', 'green');
            } else {
                result.text(response.data).css('color', 'red');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            result.text('Ошибка сети').css('color', 'red');
        });
    });

    // Загрузка сущностей из МС
    $('#wc_ms_load_btn').on('click', function() {
        var btn = $(this);
        var result = $('#wc_ms_load_result');
        btn.prop('disabled', true);
        result.text('Загружаю...').css('color', '#666');

        $.post(wc_ms.ajax_url, {
            action: 'wc_ms_load_entities',
            nonce: wc_ms.nonce
        }, function(response) {
            btn.prop('disabled', false);
            if (!response.success) {
                result.text(response.data || 'Ошибка').css('color', 'red');
                return;
            }
            var d = response.data;
            result.text('Загружено!').css('color', 'green');

            // Организации
            if (d.organizations) {
                var sel = $('#ms_org');
                var cur = sel.val();
                sel.empty().append('<option value="">— Первая по умолчанию —</option>');
                d.organizations.forEach(function(o) {
                    var opt = $('<option>').val(o.id).text(o.name);
                    if (o.id === cur) opt.prop('selected', true);
                    sel.append(opt);
                });
            }

            // Склады
            if (d.stores) {
                var sel2 = $('#ms_store');
                var cur2 = sel2.val();
                sel2.empty().append('<option value="">— Не указывать —</option>');
                d.stores.forEach(function(s) {
                    var opt = $('<option>').val(s.id).text(s.name);
                    if (s.id === cur2) opt.prop('selected', true);
                    sel2.append(opt);
                });
            }

            // Статусы МС
            if (d.states) {
                var sel3 = $('#ms_state');
                var cur3 = sel3.val();
                sel3.empty().append('<option value="">— По умолчанию (первый) —</option>');
                d.states.forEach(function(st) {
                    var opt = $('<option>').val(st.id).text(st.name);
                    if (st.id === cur3) opt.prop('selected', true);
                    sel3.append(opt);
                });

                // Маппинг статусов
                var tbody = $('#wc_ms_status_map_body');
                tbody.empty();
                // Получаем WC статусы из серверных данных
                var wcStatuses = <?php /* заполняется на сервере */ ?>{};
                // Берем из существующих select
                var wcOpts = '';
                $('#ms_send_on option').each(function() {
                    wcOpts += '<option value="' + $(this).val() + '">' + $(this).text() + '</option>';
                });

                d.states.forEach(function(st) {
                    var row = '<tr>';
                    row += '<td><span style="display:inline-block;width:12px;height:12px;border-radius:6px;background:#' + (st.color ? st.color.toString(16).padStart(6, '0') : 'ccc') + ';margin-right:5px;vertical-align:middle;"></span>' + st.name + '</td>';
                    row += '<td><select name="wc_ms_map[' + st.id + ']" style="width:100%;">';
                    row += '<option value="">— Не менять —</option>';
                    row += wcOpts;
                    row += '</select></td>';
                    row += '</tr>';
                    tbody.append(row);
                });

                // Удаляем старые hidden inputs
                $('input[name^="wc_ms_map["]').remove();
            }

        }).fail(function() {
            btn.prop('disabled', false);
            result.text('Ошибка сети').css('color', 'red');
        });
    });
});
