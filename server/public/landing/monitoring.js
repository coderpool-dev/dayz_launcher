(function () {
    'use strict';
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

    function textElement(tag, text, className) {
        var element = document.createElement(tag);
        element.textContent = text;
        if (className) element.className = className;
        return element;
    }

    function syncUrl() {
        var url = new URL(window.location.href);
        ['q', 'map', 'version', 'perspective', 'sort', 'mode', 'style', 'collection', 'populated', 'modded', 'available', 'hardcore'].forEach(function (key) {
            var field = form.elements[key];
            var value = field.type === 'checkbox' ? (field.checked ? '1' : '') : field.value.trim();
            if (value && !(key === 'sort' && value === 'players') && !(key === 'collection' && value === 'all')) url.searchParams.set(key, value);
            else url.searchParams.delete(key);
        });
        window.history.replaceState(null, '', url);
    }

    function row(server) {
        var tr = document.createElement('tr');
        var name = document.createElement('td');
        name.append(textElement('span', server.name || 'Без названия', 'server-name'));
        var state = textElement('span', server.online ? 'Онлайн' : 'Офлайн', 'server-meta');
        if (server.online) state.classList.add('server-online');
        name.append(state);
        if (server.sponsor) name.append(textElement('span', 'Реклама', 'server-meta server-ad'));
        if (server.password) name.append(textElement('span', 'С паролем', 'server-meta'));
        var categories = server.categories || { modes: [] };
        var tags = textElement('div', '', 'server-tags');
        categories.modes.forEach(function (mode) {
            var tag = textElement('span', modeLabels[mode], 'server-tag');
            tag.title = 'По тегу в названии; не подтверждено';
            tags.append(tag);
        });
        if (categories.style === 'vanilla-plus') tags.append(textElement('span', 'Vanilla+', 'server-tag'));
        if (categories.hardcore) tags.append(textElement('span', 'Hardcore', 'server-tag'));
        name.append(tags);
        tr.append(name);
        var map = document.createElement('td');
        map.dataset.label = 'Карта / вид';
        map.append(textElement('span', server.mapName || server.map || '—'));
        map.append(textElement('span', server.perspective === '1pp' ? '1PP' : (server.perspective === '3pp' ? '1PP / 3PP' : '—'), 'server-meta'));
        map.append(textElement('span', server.version || 'Версия неизвестна', 'server-meta'));
        tr.append(map);
        var players = textElement('td', (server.players || 0) + ' / ' + (server.maxPlayers || 0), 'server-players');
        players.dataset.label = 'Игроки';
        players.append(textElement('span', modCount(server) + ' модов', 'server-meta'));
        tr.append(players);
        var population = server.population;
        var collection = form.elements.collection.value;
        var metric = population && population.ready ? (collection === 'night' || collection === 'day'
            ? (population[collection] === null ? 'Мало данных' : population[collection] + ' игроков')
            : population.activePercent + '% с игроками') : 'Мало данных';
        var history = textElement('td', metric, 'server-history');
        history.dataset.label = 'История';
        if (population) history.append(textElement('span', 'Покрытие ' + population.coverage + '%', 'server-meta'));
        var graph = textElement('button', 'График', 'server-copy');
        graph.type = 'button';
        graph.addEventListener('click', function () { openHistory(server); });
        history.append(graph);
        tr.append(history);
        var endpoint = document.createElement('td');
        var actions = textElement('div', '', 'server-address');
        var target = address(server);
        var code = textElement('code', target);
        var copy = textElement('button', 'Копировать', 'server-copy');
        copy.type = 'button';
        copy.title = 'Копировать адрес сервера';
        copy.setAttribute('aria-label', 'Копировать адрес ' + target);
        copy.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(target);
                root.querySelector('[data-copy-status]').textContent = 'Адрес ' + target + ' скопирован';
                copy.textContent = 'Скопировано';
                setTimeout(function () { copy.textContent = 'Копировать'; }, 1800);
            } catch (_) {
                var range = document.createRange();
                range.selectNodeContents(code);
                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                root.querySelector('[data-copy-status]').textContent = 'Адрес выделен для копирования';
            }
        });
        actions.append(code, copy);
        endpoint.append(actions);
        tr.append(endpoint);
        return tr;
    }

    function render() {
        var query = form.elements.q.value.trim().toLocaleLowerCase('ru');
        var terms = query.split(/\s+/).filter(Boolean);
        var collection = form.elements.collection.value;
        form.elements.sort.disabled = collection !== 'all';
        var matches = servers.filter(function (server) {
            var haystack = [server.name, address(server), server.ip + ':' + server.queryPort, server.mapName, server.map, server.country]
                .concat(server.mods || [], server.modIds || []).join(' ').toLocaleLowerCase('ru');
            return terms.every(function (term) { return haystack.includes(term); })
                && (!form.elements.map.value || (server.mapName || server.map || '—') === form.elements.map.value)
                && (!form.elements.version.value || (server.version || 'Не указана') === form.elements.version.value)
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
                    || String(a.name || '').localeCompare(String(b.name || ''), 'ru');
            }
            var sponsor = Number(Boolean(b.sponsor)) - Number(Boolean(a.sponsor));
            if (sponsor) return sponsor;
            if (sort === 'name') return String(a.name || '').localeCompare(String(b.name || ''), 'ru');
            var difference = sort === 'slots'
                ? Math.max(0, b.maxPlayers - b.players) - Math.max(0, a.maxPlayers - a.players)
                : (b.players || 0) - (a.players || 0);
            return difference || String(a.name || '').localeCompare(String(b.name || ''), 'ru');
        });
        var pages = Math.max(1, Math.ceil(matches.length / pageSize));
        page = Math.min(page, pages);
        var fragment = document.createDocumentFragment();
        matches.slice((page - 1) * pageSize, page * pageSize).forEach(function (server) { fragment.append(row(server)); });
        body.replaceChildren(fragment);
        var playerTotal = matches.reduce(function (total, server) { return total + Number(server.players || 0); }, 0);
        count.textContent = loaded
            ? 'Серверов: ' + matches.length.toLocaleString('ru') + ' / ' + servers.length.toLocaleString('ru') + ' · Игроков: ' + playerTotal.toLocaleString('ru')
            : (loading ? 'Загрузка серверов…' : 'Список не загружен');
        var eligible = servers.some(function (server) { return server.population && server.population.ready
            && (collection === 'stable' || collection === 'all' || server.population[collection] !== null); });
        message.textContent = loadError || (loaded && !matches.length ? (collection !== 'all' && !eligible
            ? 'Рейтинг ещё собирается. Нужны 7 дней наблюдений и покрытие от 70%. Текущий онлайн доступен в первой подборке.'
            : 'Серверы не найдены. Измените поиск или сбросьте фильтры.') : '');
        root.querySelector('[data-metric-heading]').textContent = collection === 'night' ? 'Ночь · среднее' : collection === 'day' ? 'День · среднее' : 'С игроками · 7 дней';
        root.querySelector('[data-history-status]').textContent = !loaded ? (loadError ? 'История не загружена' : 'История онлайна загружается…')
            : !historyMeta.fresh ? 'История временно недоступна. Текущий онлайн показан ниже.'
            : 'Рейтинг за 7 дней · МСК · Достаточно данных: ' + servers.filter(function (server) { return server.population && server.population.ready; }).length.toLocaleString('ru')
                + (historyMeta.startedAt ? ' · Сбор с ' + new Date(historyMeta.startedAt * 1000).toLocaleDateString('ru', { timeZone: 'Europe/Moscow' }) : '');
        message.hidden = !message.textContent;
        table.hidden = !matches.length;
        root.querySelector('[data-page]').textContent = 'Страница ' + page + ' из ' + pages;
        previous.disabled = page <= 1;
        next.disabled = page >= pages;
    }

    async function load() {
        if (loading) return;
        loading = true;
        loadError = '';
        refresh.disabled = true;
        refresh.textContent = 'Загрузка…';
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
            fillFacet('map', 'Все карты', function (server) { return server.mapName || server.map || '—'; });
            fillFacet('version', 'Все версии', function (server) { return server.version || 'Не указана'; });
            var updated = Number(payload.timestamp);
            root.querySelector('[data-updated]').textContent = Number.isFinite(updated) && updated > 0
                ? 'Данные на ' + new Date(updated * 1000).toLocaleString('ru', { timeZone: 'Europe/Moscow' }) + ' МСК'
                : '';
        } catch (_) {
            loadError = loaded ? 'Не удалось обновить список. Показаны ранее загруженные данные.' : 'Не удалось загрузить серверы. Попробуйте обновить список.';
        } finally {
            clearTimeout(timeout);
            loading = false;
            refresh.disabled = false;
            refresh.textContent = 'Обновить';
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
        Array.from(totals.keys()).sort(function (a, b) { return a.localeCompare(b, 'ru', { numeric: true }); })
            .forEach(function (value) { var option = textElement('option', value + ' (' + totals.get(value) + ')'); option.value = value; select.append(option); });
        if (selected && !totals.has(selected)) { var missing = textElement('option', selected + ' (0)'); missing.value = selected; select.append(missing); }
        select.value = selected;
        params.delete(field);
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
        chart.textContent = 'Загрузка графика…';
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
            if (!valid.length) { chart.textContent = 'Замеров пока нет. История появится после обновления списка.'; return; }
            var max = Math.max(1, ...valid.map(function (point) { return point.players; }));
            var ns = 'http://www.w3.org/2000/svg';
            var width = Math.max(280, chart.clientWidth);
            var right = width - 15;
            var svg = document.createElementNS(ns, 'svg');
            svg.setAttribute('viewBox', '0 0 ' + width + ' 200');
            svg.setAttribute('role', 'img');
            svg.setAttribute('aria-label', 'Средний онлайн по часам. Максимум ' + max + ' игроков. Пропуски данных не соединены.');
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
                var dateLabel = date.toLocaleString('ru', { timeZone: 'Europe/Moscow', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
                values.append(textElement('p', dateLabel + ' · ' + (point.players === null ? 'нет данных' : point.players + ' игроков · ' + point.samples + ' замеров')));
                if (point.players === null) { flush(); return; }
                var x = 40 + index / (points.length - 1) * (right - 40);
                var y = 170 - point.players / max * 150;
                segment.push(x + ',' + y);
                var dot = document.createElementNS(ns, 'circle'); dot.setAttribute('cx', x); dot.setAttribute('cy', y); dot.setAttribute('r', '2.5'); dot.setAttribute('class', 'history-dot');
                var title = document.createElementNS(ns, 'title'); title.textContent = dateLabel + ': ' + point.players + ' игроков'; dot.append(title); svg.append(dot);
            });
            flush();
            [0, points.length - 1].forEach(function (index) { var label = document.createElementNS(ns, 'text'); label.setAttribute('x', index ? right : '40'); label.setAttribute('y', '196'); label.setAttribute('text-anchor', index ? 'end' : 'start'); label.textContent = new Date(points[index].timestamp * 1000).toLocaleString('ru', { timeZone: 'Europe/Moscow', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); svg.append(label); });
            chart.replaceChildren(svg);
        } catch (_) {
            if (historyRequest === controller && historyDialog.open) chart.textContent = 'Не удалось загрузить историю. Выберите период ещё раз.';
        } finally { clearTimeout(timeout); }
    }

    function filter() { page = 1; syncUrl(); render(); }
    form.addEventListener('submit', function (event) { event.preventDefault(); clearTimeout(searchTimer); filter(); });
    form.addEventListener('input', function (event) {
        clearTimeout(searchTimer);
        if (event.target.name === 'q') searchTimer = setTimeout(filter, 180);
        else filter();
    });
    form.addEventListener('reset', function () { clearTimeout(searchTimer); params.delete('map'); params.delete('version'); setTimeout(filter, 0); });
    root.querySelector('[data-history-close]').addEventListener('click', function () { historyDialog.close(); });
    historyDialog.addEventListener('close', function () { if (historyRequest) historyRequest.abort(); });
    historyDialog.querySelector('fieldset').addEventListener('change', loadHistory);
    previous.addEventListener('click', function () { page--; render(); });
    next.addEventListener('click', function () { page++; render(); });
    refresh.addEventListener('click', load);
    load();
    setInterval(function () { if (!document.hidden) load(); }, 120000);
})();
