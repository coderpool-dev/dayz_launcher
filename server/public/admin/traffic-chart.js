(function () {
    'use strict';
    var root = document.querySelector('[data-traffic-activity]');
    if (!root) return;
    var report = JSON.parse(root.querySelector('[data-traffic-data]').textContent);
    var points = report.series;
    var source = null;
    var colors = { google: '#6998ff', yandex: '#f4cd62', direct: '#a8afbd', steam: '#67cbd1', telegram: '#65b9f4', vk: '#8b83f1', other: '#fd805c', unknown: '#737682' };
    var defaults = { google: 'Google', yandex: 'Яндекс', direct: 'Без источника' };
    var keys = ['google', 'yandex', 'direct'].concat(report.sources.filter(function (row) { return row.visits > 0; }).map(function (row) { return row.source; }))
        .filter(function (key, index, all) { return all.indexOf(key) === index; });
    var rows = keys.map(function (key) {
        return report.sources.find(function (row) { return row.source === key; }) || { source: key, label: defaults[key] || key, visits: 0 };
    });
    var bars = root.querySelector('[data-traffic-bars]');
    var axis = root.querySelector('[data-traffic-axis]');
    var title = root.querySelector('[data-traffic-title]');
    var detail = root.querySelector('[data-traffic-detail]');
    var filters = root.querySelector('[data-traffic-sources]');
    var buttons = [];
    var number = new Intl.NumberFormat('ru-RU');
    var plurals = new Intl.PluralRules('ru');

    function color(key) { return colors[key] || '#d695c5'; }
    function label(key) { return defaults[key] || rows.find(function (row) { return row.source === key; }).label; }
    function visits(value) { return number.format(value) + ' ' + ({ one: 'визит', few: 'визита', many: 'визитов', other: 'визита' })[plurals.select(value)]; }
    function element(tag, className, text) {
        var node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    function value(point) { return source === null ? point.visits : (point.sources[source] || 0); }
    function info(index) {
        if (index === null) {
            title.textContent = source === null ? 'Все источники' : label(source);
            detail.textContent = (report.granularity === 'hour' ? 'Визиты по часам' : 'Визиты по дням') + ' · МСК';
            return;
        }
        var point = points[index];
        title.textContent = point.day + (report.granularity === 'hour' ? ' · ' + point.label : '') + ' · ' + visits(value(point));
        detail.textContent = (source === null ? keys : [source]).filter(function (key) { return point.sources[key] > 0; })
            .map(function (key) { return label(key) + ': ' + number.format(point.sources[key]); }).join(' · ') || 'Нет визитов';
    }
    function filterButton(key, count) {
        var button = element('button', 'traffic-source');
        button.type = 'button';
        var heading = element('span', 'traffic-source-heading');
        if (key !== null) {
            var dot = element('span', 'traffic-source-dot');
            dot.style.backgroundColor = color(key);
            dot.setAttribute('aria-hidden', 'true');
            heading.append(dot);
        }
        heading.append(element('span', 'traffic-source-name', key === null ? 'Все источники' : label(key)), element('strong', '', number.format(count)));
        if (key !== null) heading.append(element('span', 'traffic-source-share', (report.visits ? Math.round(count / report.visits * 100) : 0) + '%'));
        button.append(heading);
        if (key !== null) {
            var track = element('span', 'traffic-source-track');
            var fill = element('span', '');
            fill.style.width = (report.visits ? count / report.visits * 100 : 0) + '%';
            fill.style.backgroundColor = color(key);
            track.append(fill);
            button.append(track);
        }
        button.addEventListener('click', function () { source = source === key ? null : key; render(); });
        buttons.push({ node: button, key: key });
        filters.append(button);
    }
    function render() {
        bars.replaceChildren();
        axis.replaceChildren();
        var maximum = Math.max(4, Math.ceil(Math.max.apply(null, points.map(value).concat([1])) / 4) * 4);
        var grid = element('div', 'traffic-grid-lines');
        grid.setAttribute('aria-hidden', 'true');
        for (var step = 4; step >= 0; step--) {
            axis.append(element('span', '', number.format(maximum * step / 4)));
            grid.append(element('span', ''));
        }
        bars.append(grid);
        bars.style.minWidth = points.length > 31 ? (points.length * 14) + 'px' : '';
        points.forEach(function (point, index) {
            var column = element('div', 'traffic-column');
            var button = element('button', 'traffic-bar');
            button.type = 'button';
            button.disabled = point.future;
            button.setAttribute('aria-label', point.day + ' ' + point.label + ': ' + (point.future ? 'ещё не наступило' : visits(value(point))));
            var stack = element('span', 'traffic-bar-stack');
            stack.style.height = (value(point) / maximum * 100) + '%';
            (source === null ? keys : [source]).forEach(function (key) {
                var segment = element('span', '');
                segment.style.height = ((point.sources[key] || 0) / (value(point) || 1) * 100) + '%';
                segment.style.backgroundColor = color(key);
                stack.append(segment);
            });
            button.append(stack);
            button.addEventListener('mouseenter', function () { info(index); });
            button.addEventListener('mouseleave', function () { if (document.activeElement !== button) info(null); });
            button.addEventListener('focus', function () { info(index); });
            button.addEventListener('click', function () { info(index); });
            button.addEventListener('blur', function () { info(null); });
            button.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
                var target = bars.querySelectorAll('button')[index + (event.key === 'ArrowRight' ? 1 : -1)];
                if (target && !target.disabled) { event.preventDefault(); target.focus(); }
            });
            column.append(button, element('span', 'traffic-tick' + (index === 0 ? ' traffic-tick-first' : index === points.length - 1 ? ' traffic-tick-last' : ''), index % Math.max(1, Math.ceil(points.length / 6)) === 0 || index === points.length - 1 ? point.label : ''));
            bars.append(column);
        });
        buttons.forEach(function (item) { item.node.setAttribute('aria-pressed', String(item.key === source)); });
        info(null);
    }
    filterButton(null, report.visits);
    rows.forEach(function (row) { filterButton(row.source, row.visits); });
    render();
})();
