const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

class Element {
    constructor(tag = 'div') { this.tag = tag; this.children = []; this.style = {}; this.attributes = {}; this.events = {}; this.textContent = ''; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    setAttribute(key, value) { this.attributes[key] = value; }
    addEventListener(name, handler) { this.events[name] = handler; }
    querySelectorAll(tag) { return this.children.flatMap(child => (child.tag === tag ? [child] : []).concat(child.querySelectorAll(tag))); }
}

function chart() {
    const parts = Object.fromEntries(['data', 'bars', 'axis', 'title', 'detail', 'sources'].map(key => [key, new Element()]));
    parts.data.textContent = JSON.stringify({ granularity: 'hour', visits: 3,
        sources: [{ source: 'google', label: 'Google', visits: 2 }, { source: 'yandex', label: 'Яндекс', visits: 1 }],
        series: [{ day: '18.10.2026', label: '00:00', visits: 3, sources: { google: 2, yandex: 1 }, future: false },
            { day: '18.10.2026', label: '01:00', visits: 0, sources: {}, future: true }]
    });
    const root = { querySelector: selector => parts[selector.match(/data-traffic-(\w+)/)[1]] };
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../public/admin/traffic-chart.js'), 'utf8'), {
        document: { querySelector: () => root, createElement: tag => new Element(tag), activeElement: null }, Intl
    });
    return parts;
}

test('renders source totals and hourly values without mixing installs or downloads', () => {
    const view = chart();
    assert.equal(view.bars.querySelectorAll('button').length, 2);
    assert.equal(view.bars.querySelectorAll('button')[1].disabled, true);
    assert.equal(view.title.textContent, 'Все источники');
    assert.equal(view.detail.textContent, 'Визиты по часам · МСК');
    view.bars.querySelectorAll('button')[0].events.focus();
    assert.match(view.title.textContent, /00:00 · 3 визита/);
    assert.equal(view.detail.textContent, 'Google: 2 · Яндекс: 1');
});

test('source button filters the chart and toggles back without losing the controls', () => {
    const view = chart();
    const google = view.sources.querySelectorAll('button')[1];
    google.events.click();
    assert.equal(view.title.textContent, 'Google');
    assert.equal(google.attributes['aria-pressed'], 'true');
    assert.match(view.bars.querySelectorAll('button')[0].attributes['aria-label'], /2 визита/);
    google.events.click();
    assert.equal(view.title.textContent, 'Все источники');
    assert.equal(view.sources.querySelectorAll('button')[0].attributes['aria-pressed'], 'true');
    assert.equal(view.sources.querySelectorAll('button')[1], google);
});
