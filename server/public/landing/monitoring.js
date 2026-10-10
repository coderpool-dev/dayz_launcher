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
    var params = new URLSearchParams(window.location.search);
    ['q', 'perspective', 'sort'].forEach(function (key) {
        if (params.has(key)) form.elements[key].value = params.get(key);
    });
    if (!form.elements.sort.value) form.elements.sort.value = 'players';
    ['populated', 'modded', 'available'].forEach(function (key) {
        form.elements[key].checked = params.get(key) === '1';
    });

    function address(server) {
        return String(server.ip || '') + ':' + (server.gamePort || server.port || server.queryPort || 0);
    }

    function modCount(server) {
        return Array.isArray(server.modIds) ? server.modIds.length : 0;
    }

    function textElement(tag, text, className) {
        var element = document.createElement(tag);
        element.textContent = text;
        if (className) element.className = className;
        return element;
    }

    function syncUrl() {
        var url = new URL(window.location.href);
        ['q', 'map', 'perspective', 'sort', 'populated', 'modded', 'available'].forEach(function (key) {
            var field = form.elements[key];
            var value = field.type === 'checkbox' ? (field.checked ? '1' : '') : field.value.trim();
            if (value && !(key === 'sort' && value === 'players')) url.searchParams.set(key, value);
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
        tr.append(name);
        var map = document.createElement('td');
        map.dataset.label = 'Карта / вид';
        map.append(textElement('span', server.mapName || server.map || '—'));
        map.append(textElement('span', server.perspective === '1pp' ? '1PP' : (server.perspective === '3pp' ? '1PP / 3PP' : '—'), 'server-meta'));
        tr.append(map);
        var players = textElement('td', (server.players || 0) + ' / ' + (server.maxPlayers || 0), 'server-players');
        players.dataset.label = 'Игроки';
        tr.append(players);
        var mods = textElement('td', String(modCount(server)));
        mods.dataset.label = 'Моды';
        tr.append(mods);
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
        var matches = servers.filter(function (server) {
            var haystack = [server.name, address(server), server.ip + ':' + server.queryPort, server.mapName, server.map, server.country]
                .concat(server.mods || [], server.modIds || []).join(' ').toLocaleLowerCase('ru');
            return terms.every(function (term) { return haystack.includes(term); })
                && (!form.elements.map.value || (server.mapName || server.map || '—') === form.elements.map.value)
                && (!form.elements.perspective.value || server.perspective === form.elements.perspective.value)
                && (!form.elements.populated.checked || (server.online && server.players > 0))
                && (!form.elements.modded.checked || modCount(server) > 0)
                && (!form.elements.available.checked || (server.online && !server.password && server.maxPlayers > server.players));
        });
        var sort = form.elements.sort.value;
        matches.sort(function (a, b) {
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
        message.textContent = loadError || (loaded && !matches.length ? 'Серверы не найдены. Измените поиск или сбросьте фильтры.' : '');
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
            loaded = true;
            var selectedMap = loaded && form.elements.map.value ? form.elements.map.value : params.get('map');
            form.elements.map.replaceChildren(textElement('option', 'Все карты'));
            form.elements.map.firstChild.value = '';
            Array.from(new Set(servers.map(function (server) { return server.mapName || server.map || '—'; })))
                .sort(function (a, b) { return a.localeCompare(b, 'ru'); })
                .forEach(function (name) { var option = textElement('option', name); option.value = name; form.elements.map.append(option); });
            if (selectedMap && !Array.from(form.elements.map.options).some(function (option) { return option.value === selectedMap; })) {
                var missing = textElement('option', selectedMap); missing.value = selectedMap; form.elements.map.append(missing);
            }
            form.elements.map.value = selectedMap || '';
            params.delete('map');
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

    function filter() { page = 1; syncUrl(); render(); }
    form.addEventListener('submit', function (event) { event.preventDefault(); clearTimeout(searchTimer); filter(); });
    form.addEventListener('input', function (event) {
        clearTimeout(searchTimer);
        if (event.target.name === 'q') searchTimer = setTimeout(filter, 180);
        else filter();
    });
    form.addEventListener('reset', function () { clearTimeout(searchTimer); params.delete('map'); setTimeout(filter, 0); });
    previous.addEventListener('click', function () { page--; render(); });
    next.addEventListener('click', function () { page++; render(); });
    refresh.addEventListener('click', load);
    load();
    setInterval(function () { if (!document.hidden) load(); }, 120000);
})();
