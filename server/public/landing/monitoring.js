(function () {
    'use strict';
    var locale = document.documentElement.lang === 'en' ? 'en' : 'ru';
    var t = window.monitoringText;
    var root = document.querySelector('[data-monitor]');
    if (!root) return;

    var form = root.querySelector('form');
    var body = root.querySelector('[data-servers]');
    var table = root.querySelector('table');
    var message = root.querySelector('[data-message]');
    var count = root.querySelector('[data-count]');
    var refresh = root.querySelector('[data-refresh]');
    var previous = root.querySelector('[data-prev]');
    var next = root.querySelector('[data-next]');
    var servers = [];
    var page = 1;
    var pageSize = 30;
    var loaded = false;
    var loading = false;
    var loadError = '';
    var searchTimer;
    var historyMeta = {};
    var historyDialog = root.querySelector('[data-history-dialog]');
    var historyRequest;
    var historyServer;
    var modeLabels = { deathmatch: 'Deathmatch', pvp: 'PvP', pve: 'PvE', rp: 'Roleplay', survival: 'Survival' };
    // По умолчанию показываем все страны; Россия идёт в списке первой.
    var defaultCountry = 'RU';
    var regionNames = typeof Intl !== 'undefined' && Intl.DisplayNames ? new Intl.DisplayNames([locale], { type: 'region' }) : null;
    var params = new URLSearchParams(window.location.search);
    ['q', 'perspective', 'sort', 'mode', 'style', 'collection'].forEach(function (key) {
        if (params.has(key)) form.elements[key].value = params.get(key);
    });
    if (!form.elements.sort.value) form.elements.sort.value = 'players';
    if (!form.elements.collection.value) form.elements.collection.value = 'all';
    ['populated', 'modded', 'available', 'hardcore'].forEach(function (key) {
        form.elements[key].checked = params.get(key) === '1';
    });

    function address(server) {
        return String(server.ip || '') + ':' + (server.gamePort || server.port || server.queryPort || 0);
    }

    function modCount(server) {
        return Number(server.modCount || (Array.isArray(server.modIds) ? server.modIds.length : 0));
    }

    function countryName(code) {
        if (!code) return t('Не определена');
        try { return (regionNames && regionNames.of(code)) || code; } catch (_) { return code; }
    }

    function matchesCountry(server) {
        var selected = form.elements.country.value;
        return selected === 'all' || (selected === 'unknown' ? !server.country : server.country === selected);
    }

    function textElement(tag, text, className) {
        var element = document.createElement(tag);
        element.textContent = text;
        if (className) element.className = className;
        return element;
    }

    function syncUrl() {
        var url = new URL(window.location.href);
        ['q', 'country', 'map', 'version', 'perspective', 'sort', 'mode', 'style', 'collection', 'populated', 'modded', 'available', 'hardcore'].forEach(function (key) {
            var field = form.elements[key];
            var value = field.type === 'checkbox' ? (field.checked ? '1' : '') : field.value.trim();
            if (value && !(key === 'sort' && value === 'players') && !(key === 'collection' && value === 'all')
                && !(key === 'country' && value === 'all')) url.searchParams.set(key, value);
            else url.searchParams.delete(key);
        });
        window.history.replaceState(null, '', url);
    }

    function row(server) {
        var tr = document.createElement('tr');
        var name = document.createElement('td');
        var link = textElement('a', server.name || t('Без названия'), 'server-name');
        link.href = '/monitoring/server/' + encodeURIComponent(server.ip + '-' + (server.queryPort || server.port));
        name.append(link);
        var state = textElement('span', server.online ? t('Онлайн') : t('Офлайн'), 'server-meta');
        if (server.online) state.classList.add('server-online');
        name.append(state);
        if (server.sponsor) name.append(textElement('span', t('Реклама'), 'server-meta server-ad'));
        if (server.password) name.append(textElement('span', t('С паролем'), 'server-meta'));
        var categories = server.categories || { modes: [] };
        var tags = textElement('div', '', 'server-tags');
        categories.modes.forEach(function (mode) {
            var tag = textElement('span', modeLabels[mode], 'server-tag');
            tag.title = t('По тегу в названии; не подтверждено');
            tags.append(tag);
        });
        if (categories.style === 'vanilla-plus') tags.append(textElement('span', 'Vanilla+', 'server-tag'));
        if (categories.hardcore) tags.append(textElement('span', 'Hardcore', 'server-tag'));
        name.append(tags);
        tr.append(name);
        var map = document.createElement('td');
        map.dataset.label = t('Карта / вид');
        map.append(textElement('span', server.mapName || server.map || '—'));
        map.append(textElement('span', server.perspective === '1pp' ? '1PP' : (server.perspective === '3pp' ? '1PP / 3PP' : '—'), 'server-meta'));
        map.append(textElement('span', server.version || t('Версия неизвестна'), 'server-meta'));
        if (server.country) map.append(textElement('span', countryName(server.country), 'server-meta'));
        tr.append(map);
        var players = textElement('td', (server.players || 0) + ' / ' + (server.maxPlayers || 0), 'server-players');
        players.dataset.label = t('Игроки');
        players.append(textElement('span', modCount(server) + (locale === 'en' && modCount(server) === 1 ? t(' мод') : t(' модов')), 'server-meta'));
        tr.append(players);
        var population = server.population;
        var history = textElement('td', '', 'server-history');
        history.dataset.label = t('Средний онлайн');
        ['night', 'day'].forEach(function (period) {
            var value = population && (typeof population[period + 'Average'] === 'number'
                ? population[period + 'Average'] : population[period]);
            var average = typeof value === 'number' && Number.isFinite(value) && value >= 0
                ? value.toLocaleString(locale, { maximumFractionDigits: 1 }) : '—';
            history.append(textElement('span', (period === 'night' ? t('Ночью: ') : t('Днём: ')) + average, 'server-average'));
        });
        var graph = textElement('button', t('График'), 'server-copy');
        graph.type = 'button';
        graph.addEventListener('click', function () { openHistory(server); });
        history.append(graph);
        tr.append(history);
        var endpoint = document.createElement('td');
        var actions = textElement('div', '', 'server-address');
        var target = address(server);
        var code = textElement('code', target);
        var copy = textElement('button', t('Копировать'), 'server-copy');
        copy.type = 'button';
        copy.title = t('Копировать адрес сервера');
        copy.setAttribute('aria-label', t('Копировать адрес ') + target);
        copy.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(target);
                root.querySelector('[data-copy-status]').textContent = t('Адрес ') + target + t(' скопирован');
                copy.textContent = t('Скопировано');
                setTimeout(function () { copy.textContent = t('Копировать'); }, 1800);
            } catch (_) {
                var range = document.createRange();
                range.selectNodeContents(code);
                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                root.querySelector('[data-copy-status]').textContent = t('Адрес выделен для копирования');
            }
        });
        actions.append(code, copy);
        endpoint.append(actions);
        tr.append(endpoint);
        return tr;
    }

    function render() {
        var query = form.elements.q.value.trim().toLocaleLowerCase(locale);
        var terms = query.split(/\s+/).filter(Boolean);
        var collection = form.elements.collection.value;
        form.elements.sort.disabled = collection !== 'all';
        var matches = servers.filter(function (server) {
            var haystack = [server.name, address(server), server.ip + ':' + server.queryPort, server.mapName, server.map, server.country]
                .concat(server.mods || [], server.modIds || []).join(' ').toLocaleLowerCase(locale);
            return terms.every(function (term) { return haystack.includes(term); })
                && matchesCountry(server)
                && (!form.elements.map.value || (server.mapName || server.map || '—') === form.elements.map.value)
                && (!form.elements.version.value || (server.version || t('Не указана')) === form.elements.version.value)
                && (!form.elements.mode.value || (form.elements.mode.value === 'unknown'
                    ? !server.categories.modes.length : server.categories.modes.includes(form.elements.mode.value)))
                && (!form.elements.style.value || server.categories.style === form.elements.style.value)
                && (!form.elements.hardcore.checked || server.categories.hardcore)
                && (collection === 'all' || (server.population && server.population.ready
                    && (collection === 'stable' || server.population[collection] !== null)))
                && (!form.elements.perspective.value || server.perspective === form.elements.perspective.value)
                && (!form.elements.populated.checked || (server.online && server.players > 0))
                && (!form.elements.modded.checked || modCount(server) > 0)
                && (!form.elements.available.checked || (server.online && !server.password && server.maxPlayers > server.players));
        });
        var sort = form.elements.sort.value;
        matches.sort(function (a, b) {
            if (collection !== 'all') {
                var metric = collection === 'stable' ? 'activePercent' : collection;
                return b.population[metric] - a.population[metric] || b.population.average - a.population.average
                    || String(a.name || '').localeCompare(String(b.name || ''), locale);
            }
            var sponsor = Number(Boolean(b.sponsor)) - Number(Boolean(a.sponsor));
            if (sponsor) return sponsor;
            if (sort === 'name') return String(a.name || '').localeCompare(String(b.name || ''), locale);
            var difference = sort === 'slots'
                ? Math.max(0, b.maxPlayers - b.players) - Math.max(0, a.maxPlayers - a.players)
                : (b.players || 0) - (a.players || 0);
            return difference || String(a.name || '').localeCompare(String(b.name || ''), locale);
        });
        var pages = Math.max(1, Math.ceil(matches.length / pageSize));
        page = Math.min(page, pages);
        var fragment = document.createDocumentFragment();
        matches.slice((page - 1) * pageSize, page * pageSize).forEach(function (server) { fragment.append(row(server)); });
        body.replaceChildren(fragment);
        var playerTotal = matches.reduce(function (total, server) { return total + Number(server.players || 0); }, 0);
        count.textContent = loaded
            ? t('Серверов: ') + matches.length.toLocaleString(locale) + ' / ' + servers.length.toLocaleString(locale) + t(' · Игроков: ') + playerTotal.toLocaleString(locale)
            : (loading ? t('Загрузка серверов…') : t('Список не загружен'));
        var eligible = servers.some(function (server) { return server.population && server.population.ready
            && (collection === 'stable' || collection === 'all' || server.population[collection] !== null); });
        message.textContent = loadError || (loaded && !matches.length ? (collection !== 'all' && !eligible
            ? t('Пока нет данных для этой подборки.')
            : t('Серверы не найдены. Измените поиск или сбросьте фильтры.')) : '');
        var historyStatus = root.querySelector('[data-history-status]');
        historyStatus.textContent = loaded && !historyMeta.fresh ? t('История временно недоступна.') : '';
        historyStatus.hidden = !historyStatus.textContent;
        message.hidden = !message.textContent;
        table.hidden = !matches.length;
        root.querySelector('[data-page]').textContent = t('Страница ') + page + t(' из ') + pages;
        previous.disabled = page <= 1;
        next.disabled = page >= pages;
    }

    async function load() {
        if (loading) return;
        loading = true;
        loadError = '';
        refresh.disabled = true;
        refresh.textContent = t('Загрузка…');
        table.setAttribute('aria-busy', 'true');
        render();
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 30000);
        try {
            var response = await fetch(root.dataset.api, { signal: controller.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            var payload = await response.json();
            if (!Array.isArray(payload.servers)) throw new Error('Invalid server list');
            servers = payload.servers;
            historyMeta = payload.history || {};
            loaded = true;
            fillCountries();
            fillFacet('map', t('Все карты'), function (server) { return server.mapName || server.map || '—'; });
            fillFacet('version', t('Все версии'), function (server) { return server.version || t('Не указана'); });
            var updated = Number(payload.timestamp);
            root.querySelector('[data-updated]').textContent = Number.isFinite(updated) && updated > 0
                ? t('Данные на ') + new Date(updated * 1000).toLocaleString(locale, { timeZone: 'Europe/Moscow' }) + t(' МСК')
                : '';
        } catch (_) {
            loadError = loaded ? t('Не удалось обновить список. Показаны ранее загруженные данные.') : t('Не удалось загрузить серверы. Попробуйте обновить список.');
        } finally {
            clearTimeout(timeout);
            loading = false;
            refresh.disabled = false;
            refresh.textContent = t('Обновить');
            table.setAttribute('aria-busy', 'false');
            render();
        }
    }

    function fillFacet(field, label, valueOf) {
        var select = form.elements[field];
        var selected = select.value || params.get(field) || '';
        var totals = new Map();
        servers.forEach(function (server) { var value = valueOf(server); totals.set(value, (totals.get(value) || 0) + 1); });
        var first = textElement('option', label); first.value = '';
        select.replaceChildren(first);
        Array.from(totals.keys()).sort(function (a, b) { return a.localeCompare(b, locale, { numeric: true }); })
            .forEach(function (value) { var option = textElement('option', value + ' (' + totals.get(value) + ')'); option.value = value; select.append(option); });
        if (selected && !totals.has(selected)) { var missing = textElement('option', selected + ' (0)'); missing.value = selected; select.append(missing); }
        select.value = selected;
        params.delete(field);
    }

    // «Все страны», затем Россия, затем остальные по числу серверов; «Не определена» — в конце.
    function fillCountries() {
        var select = form.elements.country;
        var selected = params.get('country') || select.value || 'all';
        var totals = new Map();
        servers.forEach(function (server) { var code = server.country || 'unknown'; totals.set(code, (totals.get(code) || 0) + 1); });
        var codes = Array.from(totals.keys()).filter(function (code) { return code !== defaultCountry && code !== 'unknown'; })
            .sort(function (a, b) { return totals.get(b) - totals.get(a) || countryName(a).localeCompare(countryName(b), locale); });
        codes.unshift(defaultCountry);
        if (totals.has('unknown')) codes.push('unknown');
        var all = textElement('option', t('Все страны (') + servers.length + ')'); all.value = 'all'; all.defaultSelected = true;
        select.replaceChildren(all);
        codes.forEach(function (code) {
            var option = textElement('option', countryName(code === 'unknown' ? '' : code) + ' (' + (totals.get(code) || 0) + ')');
            option.value = code;
            select.append(option);
        });
        if (selected !== 'all' && codes.indexOf(selected) < 0) { var missing = textElement('option', countryName(selected) + ' (0)'); missing.value = selected; select.append(missing); }
        select.value = selected;
        params.delete('country');
    }

    function openHistory(server) {
        historyServer = server;
        root.querySelector('#history-title').textContent = server.name;
        historyDialog.querySelector('[name="period"][value="1"]').checked = true;
        historyDialog.showModal();
        loadHistory();
    }

    async function loadHistory() {
        if (historyRequest) historyRequest.abort();
        var controller = new AbortController();
        historyRequest = controller;
        var chart = root.querySelector('[data-history-chart]');
        var values = root.querySelector('[data-history-values]');
        chart.textContent = t('Загрузка графика…');
        values.replaceChildren();
        var url = new URL(root.dataset.historyApi, location.origin);
        url.searchParams.set('server', historyServer.key);
        url.searchParams.set('days', historyDialog.querySelector('[name="period"]:checked').value);
        var timeout = setTimeout(function () { controller.abort(); }, 15000);
        try {
            var response = await fetch(url, { signal: controller.signal });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            var payload = await response.json();
            if (historyRequest !== controller || !historyDialog.open) return;
            var points = payload.points;
            var valid = points.filter(function (point) { return point.players !== null; });
            if (!valid.length) { chart.textContent = t('Замеров пока нет. История появится после обновления списка.'); return; }
            var max = Math.max(1, ...valid.map(function (point) { return point.players; }));
            var ns = 'http://www.w3.org/2000/svg';
            var width = Math.max(280, chart.clientWidth);
            var right = width - 15;
            var svg = document.createElementNS(ns, 'svg');
            svg.setAttribute('viewBox', '0 0 ' + width + ' 200');
            svg.setAttribute('role', 'img');
            svg.setAttribute('aria-label', t('Средний онлайн по часам. Максимум ') + max + t(' игроков. Пропуски данных не соединены.'));
            for (var tick = 0; tick <= 4; tick++) {
                var y = 170 - tick * 37.5;
                var line = document.createElementNS(ns, 'line');
                line.setAttribute('x1', '40'); line.setAttribute('x2', right); line.setAttribute('y1', y); line.setAttribute('y2', y); line.setAttribute('class', 'history-grid'); svg.append(line);
                var label = document.createElementNS(ns, 'text'); label.setAttribute('x', '2'); label.setAttribute('y', y + 4); label.textContent = Math.round(max * tick / 4); svg.append(label);
            }
            var segment = [];
            function flush() {
                if (segment.length > 1) { var path = document.createElementNS(ns, 'polyline'); path.setAttribute('points', segment.join(' ')); path.setAttribute('class', 'history-line'); svg.append(path); }
                segment = [];
            }
            points.forEach(function (point, index) {
                var date = new Date(point.timestamp * 1000);
                var dateLabel = date.toLocaleString(locale, { timeZone: 'Europe/Moscow', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
                values.append(textElement('p', dateLabel + ' · ' + (point.players === null ? t('нет данных') : point.players + t(' игроков · ') + point.samples + t(' замеров'))));
                if (point.players === null) { flush(); return; }
                var x = 40 + index / (points.length - 1) * (right - 40);
                var y = 170 - point.players / max * 150;
                segment.push(x + ',' + y);
                var dot = document.createElementNS(ns, 'circle'); dot.setAttribute('cx', x); dot.setAttribute('cy', y); dot.setAttribute('r', '2.5'); dot.setAttribute('class', 'history-dot');
                var title = document.createElementNS(ns, 'title'); title.textContent = dateLabel + ': ' + point.players + t(' игроков'); dot.append(title); svg.append(dot);
            });
            flush();
            [0, points.length - 1].forEach(function (index) { var label = document.createElementNS(ns, 'text'); label.setAttribute('x', index ? right : '40'); label.setAttribute('y', '196'); label.setAttribute('text-anchor', index ? 'end' : 'start'); label.textContent = new Date(points[index].timestamp * 1000).toLocaleString(locale, { timeZone: 'Europe/Moscow', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); svg.append(label); });
            chart.replaceChildren(svg);
        } catch (_) {
            if (historyRequest === controller && historyDialog.open) chart.textContent = t('Не удалось загрузить историю. Выберите период ещё раз.');
        } finally { clearTimeout(timeout); }
    }

    function filter() { page = 1; syncUrl(); render(); }
    form.addEventListener('submit', function (event) { event.preventDefault(); clearTimeout(searchTimer); filter(); });
    form.addEventListener('input', function (event) {
        clearTimeout(searchTimer);
        if (event.target.name === 'q') searchTimer = setTimeout(filter, 180);
        else filter();
    });
    form.addEventListener('reset', function () { clearTimeout(searchTimer); params.delete('map'); params.delete('version'); params.delete('country'); setTimeout(filter, 0); });
    root.querySelector('[data-history-close]').addEventListener('click', function () { historyDialog.close(); });
    historyDialog.addEventListener('close', function () { if (historyRequest) historyRequest.abort(); });
    historyDialog.querySelector('fieldset').addEventListener('change', loadHistory);
    previous.addEventListener('click', function () { page--; render(); });
    next.addEventListener('click', function () { page++; render(); });
    refresh.addEventListener('click', load);
    load();
    setInterval(function () { if (!document.hidden) load(); }, 120000);
})();
