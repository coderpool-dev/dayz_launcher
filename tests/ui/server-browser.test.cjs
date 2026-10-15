const { test } = require('node:test');
const assert = require('node:assert/strict');
const browser = require('../../src/PrepiskaLauncher/Ui/server-browser.js');
const server = (name, values = {}) => ({ name, mapName: 'Bitterroot', version: '1.29.163709', players: 20, maxPlayers: 40,
  online: true, categories: { modes: ['pvp'], style: 'modded', hardcore: false }, ...values });

test('map and version combine before the display limit', () => {
  const items = [server('Wrong map', { mapName: 'Namalsk' }), server('Wrong version', { version: '1.30' }), server('Correct')];
  assert.deepEqual(browser.filter(items, { map: 'Bitterroot', version: '1.29.163709' }, 1).servers.map(s => s.name), ['Correct']);
});
test('historical rank ignores favorites and sponsors and excludes insufficient data', () => {
  const items = [server('Ad', { sponsor: true, favorite: true, population: { ready: true, activePercent: 30, average: 50 } }),
    server('Stable', { population: { ready: true, activePercent: 90, average: 20 } }), server('No samples')];
  assert.deepEqual(browser.filter(items, { collection: 'stable' }).servers.map(s => s.name), ['Stable', 'Ad']);
});
test('night ranking excludes missing window data, but retains valid zero', () => {
  const items = [server('Missing', { population: { ready: true, night: null } }), server('Zero', { population: { ready: true, night: 0 } })];
  assert.deepEqual(browser.filter(items, { collection: 'night' }).servers.map(s => s.name), ['Zero']);
});
test('mode, perspective and free-slot filters combine', () => {
  const items = [server('Correct', { perspective: '1pp' }), server('Full', { perspective: '1pp', players: 40 }), server('Third person', { perspective: '3pp' })];
  assert.deepEqual(browser.filter(items, { mode: 'pvp', perspective: '1pp', available: true }).servers.map(s => s.name), ['Correct']);
});
test('country filter: one country, all countries and unknown', () => {
  const items = [server('Moscow', { country: 'RU' }), server('Berlin', { country: 'DE' }), server('Nowhere', { country: '' })];
  assert.deepEqual(browser.filter(items, { country: 'RU' }).servers.map(s => s.name), ['Moscow']);
  assert.deepEqual(browser.filter(items, { country: 'all' }).servers.map(s => s.name), ['Moscow', 'Berlin', 'Nowhere']);
  assert.deepEqual(browser.filter(items, { country: 'unknown' }).servers.map(s => s.name), ['Nowhere']);
});
test('country list starts with Russia even when it has fewer servers', () => {
  const items = [server('A', { country: 'US' }), server('B', { country: 'US' }), server('C', { country: 'DE' }), server('D', { country: 'RU' }), server('E')];
  assert.deepEqual(browser.countries(items), [{ value: 'RU', count: 1 }, { value: 'US', count: 2 }, { value: 'DE', count: 1 }, { value: 'unknown', count: 1 }]);
  assert.deepEqual(browser.countries([server('A', { country: 'US' })])[0], { value: 'RU', count: 0 });
});
test('facets preserve custom maps and count versions', () => {
  assert.deepEqual(browser.facet([server('A'), server('B'), server('C', { mapName: 'Alteria' })], 'map'), [{ value: 'Alteria', count: 1 }, { value: 'Bitterroot', count: 2 }]);
  assert.deepEqual(browser.facet([server('A'), server('B')], 'version'), [{ value: '1.29.163709', count: 2 }]);
});
