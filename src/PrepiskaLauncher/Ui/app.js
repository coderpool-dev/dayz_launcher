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
    renderServerList('#serversList', state.servers || [], 'Серверы пока не загружены. Нажмите «Обновить».', `${state.search}|${state.limit}`);
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
  setText('#heroServer', selected ? displayServerName(selected) : 'Выберите сервер');
  setText('#heroMeta', selected
    ? 'Моды сервера будут проверены перед запуском.'
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
        </p>`;

  return `
    <div class="${classes}" data-id="${server.id}" role="button" tabindex="0">
      <div class="server-main">
        <b>${escapeHtml(displayServerName(server))}</b>${tags}
        <small>${details}</small>
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
