using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;
using PrepiskaLauncher.Bridge;
using PrepiskaLauncher.Core;
using PrepiskaLauncher.Models;
using PrepiskaLauncher.Services;
using PrepiskaLauncher.Services.Mods;
using PrepiskaLauncher.Services.Servers;

namespace PrepiskaLauncher;

/// <summary>
/// Главное окно: безрамочная форма с WebView2, в которой работает UI из папки Ui.
/// Принимает команды от UI, вызывает сервисы и отправляет обратно снимок состояния.
/// </summary>
public sealed class MainForm : Form
{
    /// <summary>Лимит 0 — показывать все серверы.</summary>
    private const int AllServers = 0;
    private const int MaxHistorySize = 50;
    private static readonly Color BackgroundColor = Color.FromArgb(10, 12, 18);

    private static readonly JsonSerializerOptions UiJsonOptions = new() { PropertyNamingPolicy = JsonNamingPolicy.CamelCase };

    private readonly AppSettings _settings = AppSettings.Load();
    private readonly SteamLibrary _steam = new();
    private readonly ServerDirectoryService _serverDirectory = new();
    private readonly WorkshopService _workshop;
    private readonly GameLauncher _gameLauncher;
    private readonly ServerModsResolver _serverMods;
    private readonly ModPreparationService _modPreparation;
    private readonly InstalledModsCatalog _installedMods;

    private readonly WebView2 _webView = new();
    /// <summary>Фоновое обновление списка серверов. API пересобирает список раз в минуту, чаще смысла нет.</summary>
    private readonly System.Windows.Forms.Timer _autoRefreshTimer = new() { Interval = (int)TimeSpan.FromMinutes(5).TotalMilliseconds };
    private readonly SemaphoreSlim _cacheRefreshLock = new(1, 1);

    /// <summary>Текущая страница списка серверов (результат поиска).</summary>
    private readonly List<DayZServer> _servers = [];

    /// <summary>Снимки серверов из избранного и истории — чтобы показывать их, даже если их нет в текущем списке.</summary>
    private readonly Dictionary<long, DayZServer> _savedServers = [];

    private CancellationTokenSource? _loadServersCts;
    private CancellationTokenSource? _backgroundRefreshCts;
    private CancellationTokenSource? _selectionModsCts;

    private SteamProfile? _steamProfile;
    private bool _isUiReady;
    private bool _isBusy;
    private string _status = "Готов к загрузке серверов.";
    private string _search = "";
    private int _serverLimit = AllServers;
    private int _serversRevision;
    private long? _selectedServerId;

    private Task? _stateSendLoop;
    private bool _stateDirty;
    private bool _stateIncludeServers;

    public MainForm()
    {
        _workshop = new WorkshopService(_steam);
        _gameLauncher = new GameLauncher(_steam, _workshop);
        _installedMods = new InstalledModsCatalog(_workshop);

        var unavailableMods = new UnavailableModRegistry(_settings);
        _serverMods = new ServerModsResolver(new A2sRulesService(), _settings, unavailableMods);
        _modPreparation = new ModPreparationService(_workshop, _steam, unavailableMods);
        _modPreparation.StatusChanged += SetStatus;

        foreach (var server in _settings.SavedServers)
            _savedServers[server.Id] = server;

        Text = AppInfo.ProductName;
        Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath) ?? Icon;
        ClientSize = new Size(1280, 720);
        MinimumSize = Size;
        MaximumSize = Size;
        FormBorderStyle = FormBorderStyle.None;
        MaximizeBox = false;
        StartPosition = FormStartPosition.CenterScreen;
        BackColor = BackgroundColor;

        _webView.Dock = DockStyle.Fill;
        _webView.DefaultBackgroundColor = BackgroundColor;
        Controls.Add(_webView);

        _autoRefreshTimer.Tick += (_, _) =>
        {
            if (_isBusy)
            {
                Log.Write("AutoRefresh SKIP because launcher is busy");
                return;
            }

            _ = RefreshServerCacheInBackgroundAsync(userRequested: false);
        };
    }

    // ───────────────────────────── Жизненный цикл окна ─────────────────────────────

    protected override async void OnShown(EventArgs e)
    {
        base.OnShown(e);
        Log.Write("App SHOWN");

        _steamProfile = _steam.FindProfile();
        if (!string.IsNullOrWhiteSpace(_steamProfile?.PersonaName)
            && string.Equals(_settings.PlayerName, PlayerNames.Default, StringComparison.OrdinalIgnoreCase))
        {
            _settings.PlayerName = PlayerNames.Normalize(_steamProfile.PersonaName);
            _settings.Save();
        }

        SyncQeLeanSettingFromPresets();
        await InitializeWebViewAsync();
    }

    /// <summary>Пресет управления могли поменять в самой игре, пока лаунчер был в фоне.</summary>
    protected override void OnActivated(EventArgs e)
    {
        base.OnActivated(e);
        if (!_isUiReady)
            return;

        var wasEnabled = _settings.QeLeanEnabled;
        SyncQeLeanSettingFromPresets();
        if (_settings.QeLeanEnabled != wasEnabled)
            _ = SendStateAsync();
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        _settings.PlayerName = PlayerNames.Normalize(_settings.PlayerName);
        _settings.Save();
        _loadServersCts?.Cancel();
        _backgroundRefreshCts?.Cancel();
        _selectionModsCts?.Cancel();
        base.OnFormClosing(e);
    }

    private async Task InitializeWebViewAsync()
    {
        try
        {
            // По умолчанию WebView2 хранит данные рядом с exe, а в папку установки может не быть прав на запись.
            var environment = await CoreWebView2Environment.CreateAsync(userDataFolder: AppPaths.WebViewDataDirectory);
            await _webView.EnsureCoreWebView2Async(environment);
            var settings = _webView.CoreWebView2.Settings;
            settings.AreDefaultContextMenusEnabled = false;
            settings.AreBrowserAcceleratorKeysEnabled = false;
#if DEBUG
            settings.AreDevToolsEnabled = true;
#else
            settings.AreDevToolsEnabled = false;
#endif

            _webView.CoreWebView2.WebMessageReceived += OnWebMessageReceived;
            _webView.CoreWebView2.NavigationCompleted += async (_, _) =>
            {
                _isUiReady = true;
                await SendStateAsync();
                _autoRefreshTimer.Start();
                await LoadServersAsync();
                _ = RefreshServerCacheInBackgroundAsync(userRequested: false);
            };

            _webView.Source = new Uri(AppPaths.UiIndexFile);
        }
        catch (Exception ex)
        {
            Log.Write("WebView INIT ERROR " + ex);
            MessageBox.Show(
                "Не удалось запустить WebView2. Установите Microsoft Edge WebView2 Runtime и перезапустите лаунчер.\n\n" + ex.Message,
                AppInfo.ProductName,
                MessageBoxButtons.OK,
                MessageBoxIcon.Error);
        }
    }

    // ───────────────────────────── Команды из UI ─────────────────────────────

    private async void OnWebMessageReceived(object? sender, CoreWebView2WebMessageReceivedEventArgs e)
    {
        try
        {
            var message = WebMessage.Parse(e.WebMessageAsJson);
            switch (message.Type)
            {
                case "init":
                    await SendStateAsync();
                    break;
                case "refresh":
                    _ = RefreshServerCacheInBackgroundAsync(userRequested: true);
                    break;
                case "search":
                    _search = message.GetString("search");
                    _serverLimit = NormalizeServerLimit(message.GetInt("limit"));
                    await LoadServersAsync();
                    break;
                case "select":
                    _selectedServerId = message.GetLong("serverId");
                    await SendStateAsync();
                    await RefreshSelectedServerModsAsync();
                    break;
                case "launch":
                    await LaunchSelectedServerAsync();
                    break;
                case "copy":
                    CopySelectedServerAddress();
                    break;
                case "favorite":
                    ToggleFavorite(message.GetLong("serverId"));
                    break;
                case "deleteMod":
                    await DeleteModAsync(message.GetString("modId"));
                    break;
                case "deleteAllMods":
                    await DeleteAllModsAsync();
                    break;
                case "updateMod":
                    await UpdateModAsync(message.GetString("modId"));
                    break;
                case "steamDownloads":
                    Process.Start(new ProcessStartInfo { FileName = "steam://open/downloads", UseShellExecute = true });
                    break;
                case "player":
                    _settings.PlayerName = PlayerNames.Normalize(message.GetString("playerName", _settings.PlayerName));
                    _settings.Save();
                    await SendStateAsync();
                    break;
                case "modSource":
                    await ChangeModSourceAsync(message.GetString("source", _settings.ModSource));
                    break;
                case "qeLean":
                    SetQeLean(message.GetBool("enabled"));
                    break;
                case "window":
                    HandleWindowCommand(message.GetString("command"));
                    break;
            }
        }
        catch (Exception ex)
        {
            Log.Write("WebMessage ERROR " + ex);
            SetStatus("Ошибка UI: " + ex.Message);
        }
    }

    // ───────────────────────────── Список серверов ─────────────────────────────

    private async Task LoadServersAsync()
    {
        Log.Write($"LoadServers START search='{_search}'");
        _loadServersCts?.Cancel();
        _loadServersCts = new CancellationTokenSource();
        var ct = _loadServersCts.Token;
        SetBusy(true, "Загружаю серверы из локального кэша...");

        try
        {
            var search = _search;
            var limit = _serverLimit > 0 ? _serverLimit : (int?)null;
            var result = await Task.Run(() => _serverDirectory.GetServersAsync(search, limit, ct), ct);
            Log.Write($"LoadServers RESULT success={result.Success} count={result.Count} source='{result.Source}' error='{result.Error}'");
            if (ct.IsCancellationRequested)
                return;

            _servers.Clear();
            _servers.AddRange(SortForDisplay(result.Servers));

            await UpdateSavedServersAsync(ct);

            if (_selectedServerId is not { } selectedId || (_servers.All(s => s.Id != selectedId) && !_savedServers.ContainsKey(selectedId)))
                _selectedServerId = _servers.FirstOrDefault()?.Id;

            SetStatus(result.Success ? ServerListStatus(result) : $"Ошибка: {result.Error}");
            _serversRevision++;
            await SendStateAsync(includeServers: true);
            await RefreshSelectedServerModsAsync();
        }
        catch (OperationCanceledException)
        {
            Log.Write("LoadServers CANCELED");
        }
        catch (Exception ex)
        {
            Log.Write("LoadServers ERROR " + ex);
            SetStatus("Ошибка загрузки: " + ex.Message);
        }
        finally
        {
            SetBusy(false);
        }
    }

    private async Task RefreshServerCacheInBackgroundAsync(bool userRequested)
    {
        if (!await _cacheRefreshLock.WaitAsync(0))
            return;

        _backgroundRefreshCts?.Cancel();
        _backgroundRefreshCts = new CancellationTokenSource();
        var ct = _backgroundRefreshCts.Token;

        try
        {
            if (userRequested)
                SetStatus("Обновляю список серверов...");

            var result = await Task.Run(() => _serverDirectory.RefreshServerCacheAsync(ct), ct);
            Log.Write($"Background refresh RESULT count={result.Count}");
            if (ct.IsCancellationRequested)
                return;

            await LoadServersAsync();
        }
        catch (OperationCanceledException)
        {
        }
        catch (Exception ex)
        {
            Log.Write("Background refresh ERROR " + ex);
            if (userRequested || !_serverDirectory.HasServerCache)
                SetStatus("Не удалось обновить список серверов: " + ex.Message);
        }
        finally
        {
            _cacheRefreshLock.Release();
        }
    }

    /// <summary>Подтягивает актуальные данные серверов из избранного и истории и сохраняет их снимки.</summary>
    private async Task UpdateSavedServersAsync(CancellationToken ct)
    {
        var savedIds = _settings.Favorites.Concat(_settings.History).Distinct().ToList();
        foreach (var server in await _serverDirectory.GetServersByIdsAsync(savedIds, ct))
            _savedServers[server.Id] = server;

        var knownSavedIds = savedIds.Where(_savedServers.ContainsKey).ToList();
        if (_settings.SavedServers.Count == knownSavedIds.Count)
            return;

        _settings.SavedServers = knownSavedIds.Select(id => _savedServers[id]).ToList();
        _settings.Save();
    }

    /// <summary>
    /// Избранное — наверху, остальное в порядке <see cref="ServerSearch"/> (по онлайну или по релевантности поиска).
    /// OrderBy в LINQ стабилен, поэтому исходный порядок внутри групп сохраняется.
    /// </summary>
    private List<DayZServer> SortForDisplay(IEnumerable<DayZServer> servers) =>
        servers.OrderByDescending(IsFavorite).ToList();

    private static int NormalizeServerLimit(int limit) =>
        limit <= 0 ? AllServers : Math.Clamp(limit, 25, 500);

    private string ServerListStatus(ServerListResult result) =>
        string.IsNullOrWhiteSpace(_search)
            ? $"Серверов: {result.TotalServers:N0} · игроков онлайн: {result.TotalPlayers:N0}"
            : $"Найдено: {result.Count:N0} из {result.TotalServers:N0} · игроков онлайн: {result.TotalPlayers:N0}";

    private DayZServer? SelectedServer() =>
        _selectedServerId is { } id
            ? _servers.FirstOrDefault(s => s.Id == id) ?? _savedServers.GetValueOrDefault(id)
            : _servers.FirstOrDefault();

    private DayZServer FindServerOrPlaceholder(long id) =>
        _servers.FirstOrDefault(s => s.Id == id)
        ?? _savedServers.GetValueOrDefault(id)
        ?? new DayZServer { Id = id, Name = $"Сервер {id} сейчас недоступен", Online = false };

    private void ToggleFavorite(long? serverId)
    {
        if ((serverId ?? SelectedServer()?.Id) is not { } targetId)
            return;

        if (!_settings.Favorites.Remove(targetId))
        {
            _settings.Favorites.Add(targetId);
            var server = _servers.FirstOrDefault(s => s.Id == targetId) ?? _savedServers.GetValueOrDefault(targetId);
            if (server is not null)
                SaveServerSnapshot(server);
        }

        _settings.Save();

        var sorted = SortForDisplay(_servers);
        _servers.Clear();
        _servers.AddRange(sorted);
        _serversRevision++;
        _ = SendStateAsync(includeServers: true);
    }

    private void SaveServerSnapshot(DayZServer server)
    {
        _savedServers[server.Id] = server;
        _settings.SavedServers = _savedServers.Values
            .Where(s => _settings.Favorites.Contains(s.Id) || _settings.History.Contains(s.Id))
            .ToList();
    }

    private void CopySelectedServerAddress()
    {
        if (SelectedServer() is not { } server)
            return;

        Clipboard.SetText($"{server.Ip}:{server.Port}");
        SetStatus("IP сервера скопирован в буфер обмена.");
    }

    // ───────────────────────────── Моды сервера и запуск ─────────────────────────────

    /// <summary>Уточняет моды выбранного сервера через A2S, если этого требует настройка источника модов.</summary>
    private async Task RefreshSelectedServerModsAsync()
    {
        if (SelectedServer() is not { } server || !_serverMods.ShouldQueryA2s(server))
            return;

        _selectionModsCts?.Cancel();
        _selectionModsCts = new CancellationTokenSource();
        var ct = _selectionModsCts.Token;

        try
        {
            var stillSelected = () => !ct.IsCancellationRequested && SelectedServer()?.Id == server.Id;
            if (await _serverMods.RefreshFromA2sAsync(server, "Selection", stillSelected, ct))
                await SendStateAsync();
        }
        catch (OperationCanceledException)
        {
        }
        catch (Exception ex)
        {
            Log.Write("Selection A2S ERROR " + ex);
        }
    }

    private async Task ChangeModSourceAsync(string source)
    {
        _settings.ModSource = ModSources.Normalize(source);
        _settings.Save();
        Log.Write($"Settings MOD SOURCE changed source='{_settings.ModSource}'");

        if (SelectedServer() is not null)
        {
            if (_settings.ModSource == ModSources.A2s)
                await RefreshSelectedServerModsAsync();
            else
                await LoadServersAsync(); // Перечитываем кэш, чтобы вернуть моды из API вместо полученных через A2S.
        }

        await SendStateAsync();
    }

    private async Task LaunchSelectedServerAsync()
    {
        if (_isBusy || SelectedServer() is not { } server)
            return;

        if (GameLauncher.IsGameRunning())
        {
            SetStatus("DayZ уже запущен или запускается. Закройте игру перед новым подключением.");
            return;
        }

        Log.Write($"Launch START server='{server.Name}' mods={server.ModIds.Count} address={server.Ip}:{server.Port}");
        _settings.PlayerName = PlayerNames.Normalize(_settings.PlayerName);
        _settings.History.Remove(server.Id);
        _settings.History.Insert(0, server.Id);
        _settings.History = _settings.History.Take(MaxHistorySize).ToList();
        SaveServerSnapshot(server);
        _settings.Save();

        SetBusy(true, "Проверяю моды перед запуском...");
        try
        {
            await ResolveModsBeforeLaunchAsync(server);

            if (!await _modPreparation.EnsureModsReadyAsync(server))
                return;

            SetStatus("Запускаю DayZ...");
            var result = await _gameLauncher.LaunchAsync(server, _settings.PlayerName);
            Log.Write($"Launch RESULT ok={result.Ok} message='{result.Message}'");
            SetStatus(result.Message);
        }
        catch (Exception ex)
        {
            Log.Write("Launch ERROR " + ex);
            SetStatus("Ошибка запуска: " + ex.Message);
        }
        finally
        {
            SetBusy(false);
        }
    }

    private async Task ResolveModsBeforeLaunchAsync(DayZServer server)
    {
        SetStatus("Уточняю список модов сервера...");

        if (!_serverMods.ShouldQueryA2s(server))
        {
            Log.Write($"Launch MOD SOURCE apiMods={server.ModIds.Count} source='{server.ModsSource}' mode='{ModSources.Normalize(_settings.ModSource)}' server='{server.Name}' address={server.Ip}:{server.QueryPort}");
        }
        else if (!await _serverMods.RefreshFromA2sAsync(server, "Launch", canApply: () => true, CancellationToken.None))
        {
            if (server.ModIds.Count > 0)
                Log.Write($"Launch MOD SOURCE fallback kept apiMods={server.ModIds.Count} because A2S returned empty server='{server.Name}'");
            else
                server.ModsSource = "unknown";
        }

        await SendStateAsync();
    }

    // ───────────────────────────── Скачанные моды ─────────────────────────────

    private async Task DeleteModAsync(string modId)
    {
        modId = modId.Trim();
        if (!Format.IsWorkshopId(modId))
        {
            SetStatus("Некорректный Workshop ID.");
            return;
        }

        SetBusy(true, $"Удаляю мод {modId}...");
        try
        {
            var result = await Task.Run(() => _workshop.DeleteModAsync(modId));
            Log.Write($"Workshop DELETE id={modId} ok={result.Ok} message='{result.Message}'");
            _installedMods.Invalidate();
            SetStatus(result.Message);
        }
        catch (Exception ex)
        {
            Log.Write("Workshop DELETE ERROR " + ex);
            SetStatus("Не удалось удалить мод: " + ex.Message);
        }
        finally
        {
            SetBusy(false);
        }
    }

    private async Task DeleteAllModsAsync()
    {
        var installed = _workshop.GetInstalledMods();
        if (installed.Count == 0)
        {
            SetStatus("Скачанные моды не найдены.");
            return;
        }

        SetBusy(true, $"Удаляю все моды: {installed.Count}...");
        try
        {
            var deleted = 0;
            var failures = new List<string>();
            foreach (var mod in installed)
            {
                var result = await _workshop.DeleteModAsync(mod.Id);
                if (result.Ok)
                    deleted++;
                else
                    failures.Add($"{mod.Id}: {result.Message}");

                SetStatus($"Удаляю моды: {deleted}/{installed.Count}");
            }

            _installedMods.Invalidate();
            if (failures.Count > 0)
                Log.Write("Workshop DELETE ALL failed: " + string.Join(" | ", failures));

            SetStatus(failures.Count == 0
                ? $"Все скачанные моды удалены: {deleted}."
                : $"Удалено {deleted}/{installed.Count}. Ошибок: {failures.Count}.");
        }
        catch (Exception ex)
        {
            Log.Write("Workshop DELETE ALL ERROR " + ex);
            SetStatus("Не удалось удалить все моды: " + ex.Message);
        }
        finally
        {
            SetBusy(false);
        }
    }

    /// <summary>Просит Steam перекачать/проверить мод.</summary>
    private async Task UpdateModAsync(string modId)
    {
        modId = modId.Trim();
        if (!Format.IsWorkshopId(modId))
        {
            SetStatus("Некорректный Workshop ID.");
            return;
        }

        SetBusy(true, $"Обновляю мод {modId}...");
        try
        {
            var result = await _workshop.RunAsync(WorkshopAction.Verify, [modId], TimeSpan.FromMinutes(5));
            var item = result.Results.FirstOrDefault(x => x.Id == modId);
            Log.Write($"Workshop UPDATE id={modId} ok={result.Ok} state='{item?.State}' percent={item?.Percent:0.##} error='{result.Error}'");
            if (!result.Ok)
            {
                SetStatus("Не удалось обновить мод: " + (result.Error ?? "неизвестная ошибка"));
                return;
            }

            _installedMods.Invalidate();
            SetStatus(item is null
                ? $"Обновление мода {modId} отправлено в Steam."
                : $"{item.Name}: {item.Message} ({item.Percent:0.#}%).");
        }
        catch (Exception ex)
        {
            Log.Write("Workshop UPDATE ERROR " + ex);
            SetStatus("Не удалось обновить мод: " + ex.Message);
        }
        finally
        {
            SetBusy(false);
        }
    }

    // ───────────────────────────── Настройки ─────────────────────────────

    private void SetQeLean(bool enabled)
    {
        _settings.QeLeanEnabled = enabled;
        var changedFiles = LeanKeybindService.Apply(enabled);
        _settings.Save();
        SetStatus($"QE {(enabled ? "включен" : "выключен")}. Изменено preset_User.xml: {changedFiles}.");
    }

    /// <summary>Пресет могли поменять в самой игре — подстраиваем переключатель под файлы на диске.</summary>
    private void SyncQeLeanSettingFromPresets()
    {
        if (LeanKeybindService.DetectEnabled() is not { } enabled || _settings.QeLeanEnabled == enabled)
            return;

        _settings.QeLeanEnabled = enabled;
        _settings.Save();
    }

    // ───────────────────────────── Состояние UI ─────────────────────────────

    private void SetBusy(bool busy, string? status = null)
    {
        _isBusy = busy;
        if (status is not null)
            _status = status;

        _ = SendStateAsync();
    }

    private void SetStatus(string status)
    {
        _status = status;
        _ = SendStateAsync();
    }

    /// <summary>
    /// Запрашивает отправку состояния в UI. Запросы, пришедшие подряд, склеиваются в одну отправку,
    /// а отправки идут строго по очереди — UI никогда не получит более старый снимок после более нового.
    /// Возвращаемая задача завершается, когда в UI ушло состояние, актуальное на момент вызова.
    /// </summary>
    private Task SendStateAsync(bool includeServers = false)
    {
        _stateDirty = true;
        _stateIncludeServers |= includeServers;
        return _stateSendLoop ??= RunStateSendLoopAsync();
    }

    private async Task RunStateSendLoopAsync()
    {
        try
        {
            // Даём накопиться синхронным запросам (SetBusy + SetStatus и т.п.) перед отправкой.
            await Task.Yield();
            while (_stateDirty)
            {
                _stateDirty = false;
                var includeServers = _stateIncludeServers;
                _stateIncludeServers = false;
                await PostStateAsync(includeServers);
            }
        }
        finally
        {
            _stateSendLoop = null;
        }
    }

    private async Task PostStateAsync(bool includeServers)
    {
        if (!_isUiReady || _webView.CoreWebView2 is null)
            return;

        try
        {
            var installedMods = await _installedMods.GetAsync(cachedOnly: _modPreparation.IsWaitingForSteam);
            var selected = SelectedServer();
            var state = new UiState
            {
                Busy = _isBusy,
                WaitingForSteamDownloads = _modPreparation.IsWaitingForSteam,
                Status = _status,
                Search = _search,
                Limit = _serverLimit,
                PlayerName = _settings.PlayerName,
                QeLeanEnabled = _settings.QeLeanEnabled,
                ModSource = ModSources.Normalize(_settings.ModSource),
                Profile = ProfileDto.From(_steamProfile, _settings.PlayerName),
                SelectedId = selected?.Id,
                Selected = selected is null ? null : SelectedServerDto.From(selected, IsFavorite(selected)),
                FavoriteServers = _settings.Favorites.Select(ToServerDto).ToList(),
                HistoryServers = _settings.History.Select(ToServerDto).ToList(),
                InstalledMods = installedMods.Select(InstalledModDto.From).ToList(),
                ServersRevision = _serversRevision,
                Servers = includeServers ? _servers.Select(s => ServerDto.From(s, IsFavorite(s))).ToList() : null
            };

            // Полный список серверов — несколько мегабайт JSON, сериализуем его вне UI-потока.
            var json = includeServers
                ? await Task.Run(() => JsonSerializer.Serialize(state, UiJsonOptions))
                : JsonSerializer.Serialize(state, UiJsonOptions);

            _webView.CoreWebView2?.PostWebMessageAsJson(json);
        }
        catch (Exception ex)
        {
            Log.Write("SendState ERROR " + ex);
        }
    }

    private ServerDto ToServerDto(long serverId)
    {
        var server = FindServerOrPlaceholder(serverId);
        return ServerDto.From(server, IsFavorite(server));
    }

    private bool IsFavorite(DayZServer server) => _settings.Favorites.Contains(server.Id);

    // ───────────────────────────── Окно без рамки ─────────────────────────────

    private const int WmNcLButtonDown = 0xA1;
    private const int HtCaption = 0x2;

    [DllImport("user32.dll")]
    private static extern bool ReleaseCapture();

    [DllImport("user32.dll")]
    private static extern nint SendMessage(nint hWnd, int msg, int wParam, int lParam);

    private void HandleWindowCommand(string command)
    {
        switch (command)
        {
            case "minimize":
                WindowState = FormWindowState.Minimized;
                break;
            case "drag":
                // Перетаскивание окна за "шапку", нарисованную в HTML.
                ReleaseCapture();
                SendMessage(Handle, WmNcLButtonDown, HtCaption, 0);
                break;
            case "close":
                Close();
                break;
        }
    }
}
