'use strict';

/**
 * UI лаунчера. Работает внутри WebView2:
 *  - команды уходят в приложение через window.chrome.webview.postMessage({ type, ...payload });
 *  - приложение присылает снимок состояния через CoreWebView2.PostWebMessageAsJson (событие 'message').
 */

const app = {
  state: {
    busy: false,
    waitingForSteamDownloads: false,
    status: 'Готов к загрузке серверов.',
    search: '',
    limit: 0,
    playerName: 'Survivor',
    modSource: 'auto',
    qeLeanEnabled: false,
    profile: null,
    selectedId: null,
    selected: null,
    servers: [],
    favoriteServers: [],
    historyServers: [],
    installedMods: [],
    serversRevision: -1,
    update: null
  },
  renderedServersRevision: null,
  renderedSelectedId: null
};

/** Откуда получен список модов сервера (DayZServer.ModsSource). */
const MOD_SOURCE_LABELS = {
  dzsa: 'из списка серверов',
  a2s: 'с сервера (A2S)',
  unknown: 'не удалось определить'
};

/** Состояния мода из WorkshopHelper. */
const MOD_STATE_LABELS = {
  installed: 'Установлен',
  subscribed: 'Подписан, ждёт загрузки',
  not_subscribed: 'Нет подписки',
  needs_update: 'Есть обновление',
  pending: 'В очереди Steam',
  downloading: 'Качается'
};

const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => Array.from(document.querySelectorAll(selector));

function send(type, payload = {}) {
  if (!window.chrome?.webview) {
    showToast('Лаунчер запущен без WebView-моста');
    return;
  }

  window.chrome.webview.postMessage({ type, ...payload });
}

/** Лимит 0 — все серверы. Карточки добавляются порциями по мере прокрутки. */
const SERVER_LIST_CHUNK = 100;

function receiveState(state) {
  if (state.type === 'populationHistory') { receivePopulation(state); return; }
  app.state = { ...app.state, ...state };
  render();
}

document.addEventListener('DOMContentLoaded', () => {
  window.chrome?.webview?.addEventListener('message', (event) => receiveState(event.data));
  bindUi();
  send('init');
  render();
});

// ───────────────────────────── События ─────────────────────────────

function bindUi() {
  $$('.nav-item').forEach((item) => {
    item.addEventListener('click', () => openPage(item.dataset.page || 'home'));
  });

  $$('[data-open-page]').forEach((button) => button.addEventListener('click', () => openPage(button.dataset.openPage)));
  $('#page-home').addEventListener('click', (event) => {
    const button = event.target.closest('[data-home-server-id]');
    if (button && !button.disabled) send('select', { serverId: button.dataset.homeServerId });
  });

  $('#browseServersBtn').addEventListener('click', () => openPage('servers'));
  $('#playBtn').addEventListener('click', () => send('launch'));
  $('#refreshBtn').addEventListener('click', () => send('refresh'));
  $('#syncBtn').addEventListener('click', () => send('refresh'));
  $('#steamDownloadsBtn').addEventListener('click', () => send('steamDownloads'));
  $('#updateBtn').addEventListener('click', confirmUpdate);
  $('#updateLaterBtn').addEventListener('click', closeUpdateModal);
  $('#updateNowBtn').addEventListener('click', () => {
    closeUpdateModal();
    send('installUpdate');
  });
  $('#copyBtn').addEventListener('click', () => send('copy'));
  $('#favoriteBtn').addEventListener('click', () => send('favorite'));
  $('#deleteAllModsBtn').addEventListener('click', () => send('deleteAllMods'));

  $('#minimizeBtn').addEventListener('click', () => send('window', { command: 'minimize' }));
  $('#closeBtn').addEventListener('click', () => send('window', { command: 'close' }));
  $('.window-bar').addEventListener('mousedown', (event) => {
    if (!event.target.closest('button')) {
      send('window', { command: 'drag' });
    }
  });

  ['#serversList', '#favoritesList', '#historyList'].forEach(bindServerList);

  $('#searchBtn').addEventListener('click', applySearch);
  $('#limitSelect').addEventListener('change', applySearch);
  $('#serverFilters').addEventListener('change', (event) => {
    if (event.target.name === 'country') saveCountry(event.target.value);
    renderBrowserList();
  });
  $('#serverFilters').addEventListener('submit', event => event.preventDefault());
  $('#serverFilters').addEventListener('reset', () => setTimeout(() => {
    saveCountry($('#serverFilters').elements.country.value);
    renderBrowserList();
  }, 0));
  $('#populationClose').addEventListener('click', () => $('#populationDialog').close());
  $('#populationDialog').addEventListener('close', () => { app.populationRequestId = (app.populationRequestId || 0) + 1; clearTimeout(app.populationTimeout); });
  $('.population-period').addEventListener('change', requestPopulation);
  $('#searchInput').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      applySearch();
    }
  });

  $('#playerName').addEventListener('change', (event) => send('player', { playerName: event.target.value }));
  $('#qeLeanToggle').addEventListener('change', (event) => send('qeLean', { enabled: event.target.checked }));
  $('#modSourceSelect').addEventListener('change', (event) => send('modSource', { source: event.target.value }));
}

function applySearch() {
  send('search', {
    search: $('#searchInput').value,
    limit: Number($('#limitSelect').value) || 0
  });
}

function openPage(page) {
  $$('.nav-item').forEach((nav) => {
    const active = nav.dataset.page === page;
    nav.classList.toggle('active', active);
    if (active) nav.setAttribute('aria-current', 'page');
    else nav.removeAttribute('aria-current');
  });
  $$('.page').forEach((section) => section.classList.remove('active'));
  $(`#page-${page}`)?.classList.add('active');
}

// ───────────────────────────── Отрисовка ─────────────────────────────

function render() {
  const { state } = app;
  const selected = state.selected;
  const profile = state.profile || {};
  const status = state.status || 'Готов.';

  setText('#statusText', status);
  $('#statusText').title = status;
  $('#steamDownloadsBtn').hidden = !state.waitingForSteamDownloads;
  renderUpdateButton(state.update, !!state.busy);
  $('#busyDot').classList.toggle('busy', !!state.busy);
  $('#playBtn').disabled = !!state.busy || !selected || !!selected.unavailable;
  $('#browseServersBtn').hidden = !!selected;
  $('#playBtn').hidden = !selected;
  $('#copyBtn').hidden = !selected;
  $('#favoriteBtn').hidden = !selected;
  $('#copyBtn').disabled = !selected;
  $('#favoriteBtn').disabled = !selected;

  renderSelectedServer(selected);
  renderHomeServers('#homeFavorites', state.favoriteServers || [], 'Добавляйте серверы в избранное звёздочкой в списке.');
  renderHomeServers('#homeHistory', state.historyServers || [], 'Здесь появятся серверы, на которых вы играли.');
  renderProfile(profile, state.playerName);

  $('#playerName').value = state.playerName || 'Survivor';
  $('#modSourceSelect').value = state.modSource || 'auto';
  $('#qeLeanToggle').checked = !!state.qeLeanEnabled;
  if (document.activeElement !== $('#searchInput')) {
    $('#searchInput').value = state.search || '';
  }
  $('#limitSelect').value = String(state.limit || 0);

  // Большой список перерисовываем только когда он реально изменился,
  // а при смене выбранного сервера лишь переносим подсветку.
  if (app.renderedServersRevision !== state.serversRevision) {
    refreshServerFacets();
    renderBrowserList();
    app.renderedServersRevision = state.serversRevision;
  } else if (String(app.renderedSelectedId ?? '') !== String(state.selectedId ?? '')) {
    highlightSelectedServer('#serversList', state.selectedId);
  }
  app.renderedSelectedId = state.selectedId;

  renderServerList('#favoritesList', state.favoriteServers || [], 'В избранном пока нет серверов.');
  renderServerList('#historyList', state.historyServers || [], 'История появится после запуска сервера.');
  renderServerMods(selected);
  renderInstalledMods(state.installedMods || []);
}

function renderUpdateButton(update, busy) {
  const button = $('#updateBtn');
  button.hidden = !update;
  button.disabled = busy;
  if (update) {
    button.textContent = `Обновить до ${update.version}`;
    button.title = update.notes || 'Доступна новая версия лаунчера';
  }
}

/** Показывает окно с версией и списком изменений; установка — только после подтверждения. */
function confirmUpdate() {
  const update = app.state.update;
  if (!update) return;

  setText('#updateTitle', `Доступна версия ${update.version}`);
  setText('#updateNotes', update.notes || '');
  $('#updateModal').hidden = false;
  $('#updateNowBtn').focus();
}

function closeUpdateModal() {
  $('#updateModal').hidden = true;
}

function renderSelectedServer(selected) {
  $('.home-server').classList.toggle('is-empty', !selected);
  $('#homeStats').hidden = !selected;
  $('#heroServer').title = selected ? displayServerName(selected) : '';
  setText('#heroServer', selected ? displayServerName(selected) : 'Выберите, где играть');
  setText('#heroMeta', selected
    ? (selected.unavailable ? 'Сервер недоступен. Выберите другой в списке серверов.' : 'Моды сервера будут проверены и скачаны перед запуском.')
    : 'Откройте список серверов и выберите, где играть.');
  setText('#selectedPlayers', selected ? `${selected.players} / ${selected.maxPlayers || '?'}` : '-');
  setText('#selectedMap', selected?.mapName || selected?.map || '-');
  setText('#selectedAddress', selected ? `${selected.ip}:${selected.port}` : '-');
  setText('#selectedMods', selected ? `${selected.mods?.length || 0}` : '-');
  setText('#modsSource', MOD_SOURCE_LABELS[selected?.modsSource] || '');

  const favoriteBtn = $('#favoriteBtn');
  favoriteBtn.classList.toggle('active', !!selected?.favorite);
  favoriteBtn.textContent = selected?.favorite ? 'В избранном' : 'В избранное';
}

function renderHomeServers(selector, servers, emptyText) {
  const list = $(selector);
  const content = servers.length ? servers.slice(0, 3).map((server) => `
    <button class="home-quick-server" data-home-server-id="${escapeHtml(server.id)}" ${server.unavailable ? 'disabled' : ''} title="${escapeHtml(displayServerName(server))}">
      <span><b>${escapeHtml(displayServerName(server))}</b><small>${server.unavailable ? 'Недоступен' : `${escapeHtml(server.mapName || server.map || 'Карта не указана')} · ${server.players || 0} / ${server.maxPlayers || '?'}`}</small></span>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-5-5 5 5-5 5"/></svg>
    </button>
  `).join('') : `<p class="home-group-empty">${escapeHtml(emptyText)}</p>`;
  // Сохраняем фокус на быстрых ссылках при фоновых снимках состояния.
  if (list.renderedContent !== content) {
    const focusedId = list.contains(document.activeElement) ? document.activeElement.dataset.homeServerId : null;
    list.innerHTML = content;
    list.renderedContent = content;
    if (focusedId) list.querySelector(`[data-home-server-id="${CSS.escape(focusedId)}"]`)?.focus({ preventScroll: true });
  }
}

function renderProfile(profile, playerName) {
  setText('#profileName', profile.displayName || playerName || 'Survivor');
  setText('#steamProfileLabel', profile.steamName ? 'Steam профиль' : 'Игровой профиль');

  const avatar = $('#profileAvatar');
  avatar.style.backgroundImage = profile.avatarUri
    ? `url("${profile.avatarUri}")`
    : '';
}

/**
 * Список серверов может содержать десятки тысяч записей, поэтому карточки
 * добавляются порциями по мере прокрутки. Клики обрабатываются делегированием
 * (см. bindServerList), так что обработчики на каждую карточку не нужны.
 *
 * @param listKey Если ключ изменился (новый поиск/лимит) — прокрутка сбрасывается наверх,
 *                иначе (например, после добавления в избранное) позиция сохраняется.
 */
function renderServerList(selector, servers, emptyText, listKey = '') {
  const list = $(selector);
  const sameList = list.listKey === listKey;
  const keepRendered = sameList ? (list.renderedCount || 0) : 0;
  const scrollTop = sameList ? list.scrollTop : 0;

  list.servers = servers;
  list.listKey = listKey;
  list.renderedCount = 0;

  if (!servers.length) {
    list.innerHTML = `<div class="empty">${escapeHtml(emptyText)}</div>`;
    return;
  }

  list.innerHTML = '';
  appendServerCards(list, Math.max(SERVER_LIST_CHUNK, keepRendered));
  list.scrollTop = scrollTop;
}

function appendServerCards(list, count = SERVER_LIST_CHUNK) {
  const servers = list.servers || [];
  const next = servers.slice(list.renderedCount, list.renderedCount + count);
  if (!next.length) return;

  const selectedId = String(app.state.selectedId ?? '');
  list.insertAdjacentHTML('beforeend', next.map((server) => serverCardHtml(server, selectedId)).join(''));
  list.renderedCount += next.length;
}

function serverCardHtml(server, selectedId) {
  const classes = [
    'server',
    String(server.id) === selectedId ? 'active' : '',
    server.sponsor ? 'sponsor' : '',
    server.unavailable ? 'unavailable' : ''
  ].join(' ');
  const ping = server.ping > 0 ? ` · ${server.ping} ms` : '';
  const details = server.unavailable
    ? 'Сервера нет в текущем списке — возможно, он выключен'
    : `${server.players || 0} / ${server.maxPlayers || '?'}${ping} · ${escapeHtml(server.ip)}:${server.port}`;
  const tags = server.unavailable ? '' : `
        <p>
          ${server.sponsor ? '<span class="ad-badge">Реклама</span>' : ''}
          <span>${escapeHtml(server.mapName || server.map || 'map')}</span>
          <span>${escapeHtml((server.perspective || '3pp').toUpperCase())}</span>
          <span>${server.modsCount || 0} модов</span>
          <span>${escapeHtml(server.version || 'Версия неизвестна')}</span>
          ${server.country ? `<span>${escapeHtml(countryName(server.country))}</span>` : ''}
          ${ServerBrowser.categories(server).modes.map(mode => `<span title="По тегу в названии; не подтверждено">${escapeHtml(({ deathmatch: 'Deathmatch', pvp: 'PvP', pve: 'PvE', rp: 'Roleplay', survival: 'Survival' })[mode])}</span>`).join('')}
        </p>`;

  return `
    <div class="${classes}" data-id="${server.id}" role="button" tabindex="0">
      <div class="server-main">
        <b>${escapeHtml(displayServerName(server))}</b>${tags}
        <small>${details}</small>
        ${server.unavailable ? '' : `<div class="server-population"><small class="server-population-summary">${populationLabel(server)}</small><button data-population-id="${server.id}" title="История онлайна сервера">График</button></div>`}
      </div>
      <button class="server-favorite ${server.favorite ? 'active' : ''}" data-favorite-id="${server.id}"
              title="${server.favorite ? 'Убрать из избранного' : 'Добавить в избранное'}" aria-label="${server.favorite ? 'Убрать из избранного' : 'Добавить в избранное'}" aria-pressed="${!!server.favorite}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/></svg></button>
    </div>
  `;
}

function highlightSelectedServer(selector, selectedId) {
  const list = $(selector);
  list.querySelector('.server.active')?.classList.remove('active');
  list.querySelector(`.server[data-id="${CSS.escape(String(selectedId ?? ''))}"]`)?.classList.add('active');
}

function bindServerList(selector) {
  const list = $(selector);

  list.addEventListener('scroll', () => {
    if (list.scrollTop + list.clientHeight >= list.scrollHeight - 800) {
      appendServerCards(list);
    }
  });

  list.addEventListener('click', (event) => {
    const graphButton = event.target.closest('[data-population-id]');
    if (graphButton) {
      event.preventDefault(); event.stopPropagation();
      openPopulation(graphButton.dataset.populationId);
      return;
    }
    const favoriteButton = event.target.closest('.server-favorite');
    if (favoriteButton) {
      event.preventDefault();
      event.stopPropagation();
      send('favorite', { serverId: favoriteButton.dataset.favoriteId });
      return;
    }

    const card = event.target.closest('.server');
    if (card) selectServer(card);
  });

  list.addEventListener('keydown', (event) => {
    if (event.target.classList?.contains('server') && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      selectServer(event.target);
    }
  });
}

function selectServer(card) {
  if (card.classList.contains('unavailable')) return;
  send('select', { serverId: card.dataset.id });
  openPage('home');
}

function browserFilters() {
  const form = $('#serverFilters');
  const filters = {};
  ['collection', 'country', 'map', 'version', 'mode', 'style', 'perspective', 'populated', 'available', 'hardcore'].forEach(key => {
    const field = form.elements[key];
    filters[key] = field.type === 'checkbox' ? field.checked : field.value;
  });
  return filters;
}

function renderBrowserList() {
  const filters = browserFilters();
  const all = app.state.servers || [];
  const result = ServerBrowser.filter(all, filters, app.state.limit || 0);
  const eligible = all.some(server => server.population?.ready);
  const empty = filters.collection !== 'all' && !eligible
    ? 'Пока нет данных для этой подборки.'
    : 'Серверы не найдены. Измените поиск или сбросьте фильтры.';
  renderServerList('#serversList', result.servers, empty, JSON.stringify([app.state.search, app.state.limit, filters]));
  setText('#serverFilterStatus', `Найдено: ${result.count.toLocaleString('ru')} · Показано: ${result.servers.length.toLocaleString('ru')}`);
}

// По умолчанию — серверы в России; выбор другой страны запоминается между запусками лаунчера.
const DEFAULT_COUNTRY = 'RU';
const COUNTRY_KEY = 'serverCountry';
const regionNames = typeof Intl !== 'undefined' && Intl.DisplayNames ? new Intl.DisplayNames(['ru'], { type: 'region' }) : null;

function countryName(code) {
  if (!code || code === 'unknown') return 'Не определена';
  try { return regionNames?.of(code) || code; } catch { return code; }
}

function savedCountry() {
  try { return localStorage.getItem(COUNTRY_KEY) || ''; } catch { return ''; }
}

function saveCountry(value) {
  try {
    if (value === DEFAULT_COUNTRY) localStorage.removeItem(COUNTRY_KEY);
    else localStorage.setItem(COUNTRY_KEY, value);
  } catch { /* без хранилища выбор живёт до перезапуска */ }
}

function refreshCountryFacet() {
  const select = $('#serverFilters').elements.country;
  const servers = app.state.servers || [];
  // Пока у серверов нет стран (старый кэш или сайт без базы), фильтр по России спрятал бы весь список.
  const located = servers.some(server => server.country);
  const selected = app.countryChosen ? select.value : (savedCountry() || (located ? DEFAULT_COUNTRY : 'all'));
  const items = ServerBrowser.countries(servers, DEFAULT_COUNTRY);
  select.innerHTML = `<option value="all">Все страны (${servers.length})</option>`
    + items.map(item => `<option value="${escapeHtml(item.value)}">${escapeHtml(countryName(item.value))} (${item.count})</option>`).join('');
  select.querySelector(`option[value="${DEFAULT_COUNTRY}"]`).defaultSelected = true;
  if (selected !== 'all' && !items.some(item => item.value === selected)) select.add(new Option(countryName(selected) + ' (0)', selected));
  select.value = selected;
  if (located) app.countryChosen = true;
}

function refreshServerFacets() {
  refreshCountryFacet();
  ['map', 'version'].forEach(field => {
    const select = $('#serverFilters').elements[field];
    const selected = select.value;
    const facets = ServerBrowser.facet(app.state.servers || [], field);
    select.innerHTML = `<option value="">${field === 'map' ? 'Все карты' : 'Все версии'}</option>`
      + facets.map(item => `<option value="${escapeHtml(item.value)}">${escapeHtml(item.value)} (${item.count})</option>`).join('');
    if (selected && !facets.some(item => item.value === selected)) select.add(new Option(selected + ' (0)', selected));
    select.value = selected;
  });
}

function populationLabel(server) {
  const population = server.population;
  const average = value => typeof value === 'number' && Number.isFinite(value) && value >= 0
    ? value.toLocaleString('ru', { maximumFractionDigits: 1 }) : '—';
  return `<span>Средний онлайн ночью: ${average(population?.nightAverage ?? population?.night)}</span><span>Средний онлайн днём: ${average(population?.dayAverage ?? population?.day)}</span>`;
}

function openPopulation(id) {
  const server = [...(app.state.servers || []), ...(app.state.favoriteServers || []), ...(app.state.historyServers || [])].find(server => String(server.id) === String(id));
  if (!server) return;
  app.populationServerId = server.id;
  setText('#populationTitle', displayServerName(server));
  $('[name=populationPeriod][value="1"]').checked = true;
  $('#populationDialog').showModal();
  requestPopulation();
}

function requestPopulation() {
  const requestId = app.populationRequestId = (app.populationRequestId || 0) + 1;
  $('#populationChart').textContent = 'Загрузка истории…';
  $('#populationValues').replaceChildren();
  clearTimeout(app.populationTimeout);
  app.populationTimeout = setTimeout(() => {
    if (app.populationRequestId === requestId) { app.populationRequestId++; setText('#populationChart', 'История недоступна. Выберите период, чтобы повторить запрос.'); }
  }, 18000);
  send('populationHistory', { serverId: app.populationServerId, requestId, days: Number($('[name=populationPeriod]:checked').value) });
}

function receivePopulation(message) {
  if (!$('#populationDialog').open || message.requestId !== app.populationRequestId) return;
  clearTimeout(app.populationTimeout);
  const chart = $('#populationChart');
  if (message.error) { chart.textContent = message.error; return; }
  const points = message.points || [];
  const observed = points.filter(point => Number.isFinite(point.players));
  if (!observed.length) { chart.textContent = 'Замеров пока нет. История появится после обновления списка.'; return; }
  const max = Math.max(1, ...observed.map(point => point.players));
  const width = Math.max(280, chart.clientWidth);
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${width} 200`);
  svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', `Средний онлайн по часам. Максимум ${max} игроков. Пропуски данных не соединены.`);
  function element(name, attrs, text) {
    const node = document.createElementNS(ns, name);
    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
    if (text !== undefined) node.textContent = text;
    svg.append(node); return node;
  }
  for (let i = 0; i <= 4; i++) {
    const y = 170 - i * 37.5;
    element('line', { x1: 40, x2: width - 15, y1: y, y2: y, class: 'population-grid' });
    element('text', { x: 2, y: y + 4 }, Math.round(max * i / 4));
  }
  let segment = [];
  const flush = () => { if (segment.length > 1) element('polyline', { points: segment.join(' '), class: 'population-line' }); segment = []; };
  const dateLabel = timestamp => new Date(timestamp * 1000).toLocaleString('ru', { timeZone: 'Europe/Moscow', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  points.forEach((point, index) => {
    const value = document.createElement('p'); value.textContent = `${dateLabel(point.timestamp)} · ${point.players === null ? 'нет данных' : `${point.players} игроков · ${point.samples} замеров`}`;
    $('#populationValues').append(value);
    if (!Number.isFinite(point.players)) { flush(); return; }
    const x = 40 + index / Math.max(1, points.length - 1) * (width - 55);
    const y = 170 - point.players / max * 150;
    segment.push(`${x},${y}`);
    const dot = element('circle', { cx: x, cy: y, r: 2.5, class: 'population-dot' });
    const title = document.createElementNS(ns, 'title'); title.textContent = `${dateLabel(point.timestamp)}: ${point.players} игроков`; dot.append(title);
  });
  flush();
  element('text', { x: 40, y: 196 }, dateLabel(points[0].timestamp));
  element('text', { x: width - 15, y: 196, 'text-anchor': 'end' }, dateLabel(points[points.length - 1].timestamp));
  chart.replaceChildren(svg);
}

function renderServerMods(selected) {
  const list = $('#modsList');
  if (!selected) {
    list.innerHTML = '<div class="empty">Выберите сервер, чтобы увидеть моды.</div>';
    return;
  }

  const mods = selected.mods || [];
  if (!mods.length) {
    list.innerHTML = '<div class="empty">Для сервера не найден список модов. При запуске лаунчер попробует уточнить его напрямую у сервера (A2S).</div>';
    return;
  }

  list.innerHTML = mods.map((mod, index) => `
    <div class="mod ok">
      <strong>${index + 1}</strong>
      <div><b>${escapeHtml(mod.name || `Workshop ${mod.id}`)}</b><small>${escapeHtml(mod.id)}</small></div>
      <span>Будет проверен<br>перед запуском</span>
      <i aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></i>
    </div>
  `).join('');
}

function renderInstalledMods(mods) {
  const list = $('#installedModsList');
  setText('#installedModsCount', `${mods.length}`);
  $('#deleteAllModsBtn').disabled = !mods.length;

  if (!mods.length) {
    list.innerHTML = '<div class="empty">Скачанные моды DayZ Workshop не найдены.</div>';
    return;
  }

  list.innerHTML = mods.map((mod) => {
    const downloading = mod.isDownloading || mod.isDownloadPending;
    const status = downloading
      ? `Качается ${Number(mod.percent || 0).toFixed(1)}%`
      : mod.needsUpdate ? 'Есть обновление' : (MOD_STATE_LABELS[mod.state] || mod.message || 'Установлен');
    const updateButton = mod.needsUpdate || downloading
      ? `<button class="update-mod" data-update-mod="${escapeHtml(mod.id)}">${downloading ? 'Проверить' : 'Обновить'}</button>`
      : '';

    return `
      <div class="installed-mod ${mod.needsUpdate ? 'needs-update' : ''} ${downloading ? 'downloading' : ''}">
        <div class="installed-mod-main">
          <b>${escapeHtml(mod.name || `Workshop ${mod.id}`)}</b>
          <small>${escapeHtml(mod.id)} · ${escapeHtml(mod.size || '0 B')} · ${escapeHtml(status)}</small>
          <em>${escapeHtml(mod.path || '')}</em>
        </div>
        <div class="installed-mod-actions">
          ${updateButton}
          <button class="danger small-delete" data-delete-mod="${escapeHtml(mod.id)}">Удалить</button>
        </div>
      </div>
    `;
  }).join('');

  list.querySelectorAll('[data-update-mod]').forEach((button) => {
    button.addEventListener('click', () => send('updateMod', { modId: button.dataset.updateMod }));
  });

  list.querySelectorAll('[data-delete-mod]').forEach((button) => {
    button.addEventListener('click', () => send('deleteMod', { modId: button.dataset.deleteMod }));
  });
}

// ───────────────────────────── Утилиты ─────────────────────────────

function displayServerName(server) {
  return server?.displayName || server?.name || 'Unknown';
}

function setText(selector, value) {
  const element = $(selector);
  if (element) {
    element.textContent = value;
  }
}

let toastTimer = null;

function showToast(message) {
  const toast = $('#toast');
  toast.textContent = message;
  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 2400);
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  })[char]);
}
