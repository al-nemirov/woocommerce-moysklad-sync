jQuery(function($) {
    var wcMsStatesCache = [];

    function wcMsEsc(str) {
        return $('<div/>').text(String(str || '')).html();
    }

    function wcMsBuildWcStatusOptions(selectedVal) {
        var h = '<option value="">— Не менять —</option>';
        $('#ms_send_on option').each(function() {
            var v = $(this).val();
            var sel = (v === selectedVal) ? ' selected' : '';
            h += '<option value="' + wcMsEsc(v) + '"' + sel + '>' + wcMsEsc($(this).text()) + '</option>';
        });
        return h;
    }

    function wcMsBuildMsStateOptions(selectedVal, keepCurrentAsFallback) {
        var h = '<option value="">— Выберите статус МойСклад —</option>';
        var hasSelected = false;
        wcMsStatesCache.forEach(function(st) {
            if (!st || !st.id) return;
            var id = String(st.id);
            var sel = (id === String(selectedVal || '')) ? ' selected' : '';
            if (sel) hasSelected = true;
            h += '<option value="' + wcMsEsc(id) + '"' + sel + '>' + wcMsEsc(st.name || id) + '</option>';
        });
        if (keepCurrentAsFallback && selectedVal && !hasSelected) {
            h += '<option value="' + wcMsEsc(selectedVal) + '" selected>' + wcMsEsc(selectedVal) + '</option>';
        }
        return h;
    }

    function wcMsMapRow(wcStatus, msState) {
        var row = '<tr>';
        row += '<td><select name="wc_ms_map_pairs[wc][]" style="width:100%;">' + wcMsBuildWcStatusOptions(wcStatus || '') + '</select></td>';
        row += '<td><select name="wc_ms_map_pairs[ms][]" class="wc-ms-ms-state-select" style="width:100%;">' + wcMsBuildMsStateOptions(msState || '', true) + '</select></td>';
        row += '<td><button type="button" class="button-link-delete wc-ms-map-remove" aria-label="Удалить сопоставление">×</button></td>';
        row += '</tr>';
        return row;
    }

    function wcMsAddMapRow(wcStatus, msState) {
        var tbody = $('#wc_ms_status_pairs_body');
        if (!tbody.length) return;
        tbody.find('.wc-ms-map-empty').remove();
        tbody.append(wcMsMapRow(wcStatus, msState));
    }

    function wcMsInitMapRowsFromLegacy() {
        var tbody = $('#wc_ms_status_pairs_body');
        if (!tbody.length || tbody.children('tr').not('.wc-ms-map-empty').length) {
            return;
        }
        var base = (typeof wc_ms.status_map === 'object' && wc_ms.status_map !== null && !Array.isArray(wc_ms.status_map)) ? wc_ms.status_map : {};
        var keys = Object.keys(base);
        keys.forEach(function(msId) {
            wcMsAddMapRow(base[msId], msId);
        });
    }

    wcMsInitMapRowsFromLegacy();

    var $settings = $('.wc-ms-settings');
    if ($settings.length) {
        function wcMsSettingsTab(slug) {
            if (!slug) {
                slug = 'connect';
            }
            $settings.find('.wc-ms-tab').removeClass('is-active').attr('aria-selected', 'false');
            $settings.find('.wc-ms-tab[data-wc-ms-tab="' + slug + '"]').addClass('is-active').attr('aria-selected', 'true');
            $settings.find('.wc-ms-panel').removeClass('is-active');
            $settings.find('[data-wc-ms-panel="' + slug + '"]').addClass('is-active');
            var $wh = $('#wc-ms-webhook-block');
            if (slug === 'reverse') {
                $wh.slideDown(120);
            } else {
                $wh.hide();
            }
            try {
                localStorage.setItem('wc_ms_settings_tab', slug);
            } catch (e) {}
        }
        $settings.on('click', '.wc-ms-tab', function(e) {
            e.preventDefault();
            wcMsSettingsTab($(this).data('wc-ms-tab'));
        });
        var initTab = 'connect';
        try {
            var saved = localStorage.getItem('wc_ms_settings_tab');
            if (saved && $settings.find('[data-wc-ms-panel="' + saved + '"]').length) {
                initTab = saved;
            }
        } catch (err) {}
        if (window.location.hash && /^#ms-/.test(window.location.hash)) {
            var hashSlug = window.location.hash.replace(/^#ms-/, '');
            if ($settings.find('[data-wc-ms-panel="' + hashSlug + '"]').length) {
                initTab = hashSlug;
            }
        }
        wcMsSettingsTab(initTab);
    }

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
                sel.empty().append('<option value="">— Как в МойСклад по умолчанию —</option>');
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
                sel2.empty().append('<option value="">— Не указывать в заказе —</option>');
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
                sel3.empty().append('<option value="">— Первый статус в вашем МойСклад —</option>');
                d.states.forEach(function(st) {
                    var opt = $('<option>').val(st.id).text(st.name);
                    if (st.id === cur3) opt.prop('selected', true);
                    sel3.append(opt);
                });

                wcMsStatesCache = Array.isArray(d.states) ? d.states : [];
                $('#wc_ms_status_pairs_body .wc-ms-ms-state-select').each(function() {
                    var cur = $(this).val();
                    $(this).html(wcMsBuildMsStateOptions(cur, true));
                });
            }

        }).fail(function() {
            btn.prop('disabled', false);
            result.text('Ошибка сети').css('color', 'red');
        });
    });

    function wcMsToggleCronRow() {
        var v = $('#wc_ms_sync_trigger').val();
        var show = (v === 'cron' || v === 'status_and_cron');
        $('.wc-ms-cron-row').toggle(show);
    }
    if ($('#wc_ms_sync_trigger').length) {
        wcMsToggleCronRow();
        $('#wc_ms_sync_trigger').on('change', wcMsToggleCronRow);
    }

    $(document).on('click', '#wc_ms_map_add_btn', function() {
        wcMsAddMapRow('', '');
    });
    $(document).on('click', '.wc-ms-map-remove', function() {
        var tbody = $('#wc_ms_status_pairs_body');
        $(this).closest('tr').remove();
        if (!tbody.children('tr').length) {
            tbody.append('<tr class="wc-ms-map-empty"><td colspan="3"><em>Добавьте первую пару кнопкой «+ Добавить сопоставление».</em></td></tr>');
        }
    });

    function wcMsGetPreviewOrderId() {
        var manual = $('#wc_ms_preview_order_id_manual').val();
        if (manual !== undefined && String(manual).trim() !== '') {
            return parseInt(manual, 10) || 0;
        }
        return parseInt($('#wc_ms_preview_order_id').val(), 10) || 0;
    }

    $('#wc_ms_preview_btn').on('click', function() {
        var btn = $(this);
        var msg = $('#wc_ms_preview_ajax_msg');
        var box = $('#wc_ms_preview_result');
        var oid = wcMsGetPreviewOrderId();
        if (!oid) {
            msg.text('Выберите заказ или введите ID.').css('color', '#b32d2e');
            return;
        }
        btn.prop('disabled', true);
        msg.text('Загрузка…').css('color', '#666');
        box.empty();
        $.post(wc_ms.ajax_url, {
            action: 'wc_ms_preview_order',
            nonce: wc_ms.nonce,
            order_id: oid
        }, function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                msg.text('').css('color', '');
                box.html(response.data.html);
            } else {
                msg.text(response.data || 'Ошибка').css('color', '#b32d2e');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            msg.text('Ошибка сети').css('color', '#b32d2e');
        });
    });

    $('#wc_ms_export_btn').on('click', function() {
        var btn = $(this);
        var msg = $('#wc_ms_preview_ajax_msg');
        var oid = wcMsGetPreviewOrderId();
        if (!oid) {
            msg.text('Выберите заказ или введите ID.').css('color', '#b32d2e');
            return;
        }
        if (!window.confirm('Выгрузить заказ #' + oid + ' в МойСклад?')) {
            return;
        }
        btn.prop('disabled', true);
        msg.text('Выгрузка…').css('color', '#666');
        $.post(wc_ms.ajax_url, {
            action: 'wc_ms_export_order',
            nonce: wc_ms.nonce,
            order_id: oid
        }, function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                msg.empty().css('color', 'green');
                msg.append(document.createTextNode(response.data.message || 'Готово.'));
                if (response.data.ms_url) {
                    msg.append(document.createTextNode(' '));
                    var a = document.createElement('a');
                    a.href = response.data.ms_url;
                    a.target = '_blank';
                    a.rel = 'noopener';
                    a.textContent = 'Открыть в МС';
                    msg.append(a);
                }
            } else {
                msg.text(response.data || 'Ошибка').css('color', '#b32d2e');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            msg.text('Ошибка сети').css('color', '#b32d2e');
        });
    });
});
