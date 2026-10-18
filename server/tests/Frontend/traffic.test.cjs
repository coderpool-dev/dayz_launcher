const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

function browser(options = {}) {
    const listeners = {};
    const requests = [];
    let now = 0;
    let tick;
    let cleared = false;
    const window = { addEventListener: (name, handler) => { listeners[name] = handler; } };
    window.self = window;
    window.top = options.frame ? {} : window;
    const document = { visibilityState: options.hidden ? 'hidden' : 'visible', addEventListener: (name, handler) => { listeners[name] = handler; } };
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../public/landing/traffic.js'), 'utf8'), {
        window, document, navigator: { webdriver: options.webdriver || false }, performance: { now: () => now },
        setInterval: callback => { tick = callback; return 1; }, clearInterval: () => { cleared = true; },
        fetch: async (url, request) => { requests.push({ url, request }); return { ok: true, json: async () => ({ confirmed: true }) }; }
    });
    return { listeners, requests, document, advance: milliseconds => { now += milliseconds; }, tick: () => { if (tick && !cleared) tick(); } };
}

test('reading without interaction and synthetic events never confirm', () => {
    const page = browser();
    page.advance(6000);
    page.tick();
    page.listeners.pointerdown({ isTrusted: false });
    assert.equal(page.requests.length, 0);
});

test('confirmation requires five visible seconds and a trusted interaction', async () => {
    const page = browser();
    page.listeners.keydown({ isTrusted: true });
    page.advance(4999);
    page.tick();
    assert.equal(page.requests.length, 0);
    page.advance(1);
    page.tick();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(page.requests.length, 1);
    assert.equal(page.requests[0].url, '/api/traffic/confirm');
    assert.equal(page.requests[0].request.headers['X-Traffic-Event'], 'engaged');
    assert.equal(page.requests[0].request.credentials, 'same-origin');
    page.tick();
    assert.equal(page.requests.length, 1);
});

test('time spent in a hidden tab is not counted', () => {
    const page = browser({ hidden: true });
    page.advance(10000);
    page.listeners.pointerdown({ isTrusted: true });
    page.document.visibilityState = 'visible';
    page.listeners.visibilitychange();
    page.listeners.pointerdown({ isTrusted: true });
    page.advance(3000);
    page.document.visibilityState = 'hidden';
    page.listeners.visibilitychange();
    page.advance(10000);
    page.tick();
    assert.equal(page.requests.length, 0);
    page.document.visibilityState = 'visible';
    page.listeners.visibilitychange();
    page.advance(2000);
    page.tick();
    assert.equal(page.requests.length, 1);
});

test('webdriver and embedded frames do not send analytics', () => {
    for (const options of [{ webdriver: true }, { frame: true }]) {
        const page = browser(options);
        page.advance(20000);
        page.tick();
        assert.deepEqual(page.listeners, {});
        assert.equal(page.requests.length, 0);
    }
});
