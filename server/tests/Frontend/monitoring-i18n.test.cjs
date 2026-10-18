const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

test('every dynamic monitoring message is translated and Russian stays unchanged', () => {
    const assets = path.resolve(__dirname, '../../public/landing');
    const context = { window: {}, document: { documentElement: { lang: 'en' } } };
    vm.runInNewContext(fs.readFileSync(path.join(assets, 'monitoring-i18n.js'), 'utf8'), context);
    const source = fs.readFileSync(path.join(assets, 'monitoring.js'), 'utf8');
    const messages = [...source.matchAll(/\bt\('((?:\\.|[^'\\])*)'\)/g)].map(match => match[1]);
    assert.ok(messages.length > 40);
    for (const message of messages) {
        assert.doesNotMatch(context.window.monitoringText(message), /[а-яё]/i, message);
        context.document.documentElement.lang = 'ru';
        assert.equal(context.window.monitoringText(message), message);
        context.document.documentElement.lang = 'en';
    }
});
