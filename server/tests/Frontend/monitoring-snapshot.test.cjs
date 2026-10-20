const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

class Element {
    constructor(tag = 'div') {
        this.tag = tag; this.children = []; this.attributes = {}; this.dataset = {};
        this.value = ''; this.checked = false; this.hidden = false; this.textContent = '';
    }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) {
        this.children = children.flatMap(child => child.tag === 'fragment' ? child.children : [child]);
    }
    setAttribute(key, value) { this.attributes[key] = value; }
    addEventListener() {}
    querySelector() { return new Element(); }
}

function monitoring() {
    const parts = new Map();
    const get = selector => {
        if (!parts.has(selector)) parts.set(selector, new Element());
        return parts.get(selector);
    };
    const fields = 'q country map version perspective sort mode style collection populated modded available hardcore'.split(' ');
    get('form').elements = Object.fromEntries(fields.map(key => [key, new Element()]));
    get('form').elements.country.value = 'all';
    const savedRow = new Element('tr');
    get('[data-servers]').append(savedRow);
    get('[data-count]').textContent = 'Серверов: 7 · Игроков: 62';
    get('[data-updated]').textContent = 'Данные на 20.10.2026 09:00 МСК';
    const root = { querySelector: get, dataset: { api: '/api/monitoring' } };
    let resolve, reject;
    const pending = new Promise((yes, no) => { resolve = yes; reject = no; });
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../public/landing/monitoring.js'), 'utf8'), {
        document: { documentElement: { lang: 'ru' }, querySelector: () => root,
            createElement: tag => new Element(tag), createDocumentFragment: () => new Element('fragment') },
        window: { monitoringText: text => text, location: { search: '' } },
        URLSearchParams, Intl, AbortController, fetch: () => pending,
        setTimeout: () => 1, clearTimeout() {}, setInterval() {},
    });
    return { get, savedRow, resolve, reject };
}

test('keeps the rendered server list, totals and snapshot time while the API is pending', () => {
    const view = monitoring();
    assert.equal(view.get('[data-servers]').children[0], view.savedRow);
    assert.equal(view.get('table').hidden, false);
    assert.match(view.get('[data-count]').textContent, /Серверов: 7/);
    assert.match(view.get('[data-updated]').textContent, /20.10.2026/);
    assert.equal(view.get('table').attributes['aria-busy'], 'true');
});

test('keeps the snapshot and reports a failed API refresh', async () => {
    const view = monitoring();
    view.reject(new Error('API blocked'));
    await new Promise(setImmediate);
    assert.equal(view.get('[data-servers]').children[0], view.savedRow);
    assert.equal(view.get('table').hidden, false);
    assert.equal(view.get('[data-message]').hidden, false);
    assert.match(view.get('[data-message]').textContent, /сохранённый снимок/);
    assert.equal(view.get('table').attributes['aria-busy'], 'false');
});

test('replaces the saved snapshot when a successful response has no matching servers', async () => {
    const view = monitoring();
    view.resolve({ ok: true, json: async () => ({ servers: [], timestamp: 0 }) });
    await new Promise(setImmediate);
    assert.equal(view.get('[data-servers]').children.length, 0);
    assert.equal(view.get('table').hidden, true);
    assert.match(view.get('[data-count]').textContent, /Серверов: 0/);
    assert.equal(view.get('table').attributes['aria-busy'], 'false');
});
