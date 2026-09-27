using System.Text.Json;
using System.Text.Json.Serialization;
using PrepiskaLauncher.Core;
using PrepiskaLauncher.Models;
using PrepiskaLauncher.Services.Backend;

namespace PrepiskaLauncher.Services.Servers;

/// <summary>
/// Список серверов: загрузка из API (server/api/servers.php), локальный кэш на диске и поиск.
/// UI всегда читает из локального кэша, а обновление кэша идёт в фоне.
/// </summary>
/// <remarks>
/// Кэш дополнительно держится в памяти, чтобы поиск не перечитывал с диска файл на десятки мегабайт.
/// Наружу всегда отдаются копии серверов: вызывающий код может менять их список модов (A2S),
/// и эти изменения не должны попадать в кэш.
/// </remarks>
public sealed class ServerDirectoryService
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull
    };

    private readonly BackendClient _backend;

    /// <summary>Содержимое файла кэша и время его изменения, по которому понятно, что кэш устарел.</summary>
    private volatile CachedServerList? _memoryCache;

    private sealed record CachedServerList(DateTime WriteTimeUtc, List<DayZServer> Servers);

    public ServerDirectoryService(BackendClient backend)
    {
        _backend = backend;
    }

    public bool HasServerCache => File.Exists(AppPaths.ServerCacheFile);

    /// <summary>Серверы из локального кэша с поиском и лимитом. Если кэша нет — сначала загружает его из API.</summary>
    public async Task<ServerListResult> GetServersAsync(string search, int? limit, CancellationToken ct)
    {
        if (!HasServerCache)
            await RefreshServerCacheAsync(ct);

        if (!HasServerCache)
            return new ServerListResult { Success = false, Source = "local_cache", Error = "Локальный кэш серверов пуст. Дождитесь окончания обновления." };

        try
        {
            var servers = await GetCachedServersAsync(ct);
            if (servers.Count == 0)
            {
                await RefreshServerCacheAsync(ct);
                servers = await GetCachedServersAsync(ct);
            }

            return new ServerListResult
            {
                Success = true,
                Source = "local_cache",
                Servers = ServerSearch.FilterAndSort(servers, search, limit).Select(server => server.Clone()).ToList(),
                TotalServers = servers.Count,
                TotalPlayers = servers.Sum(server => server.Players)
            };
        }
        catch (Exception ex) when (ex is not OperationCanceledException)
        {
            return new ServerListResult { Success = false, Source = "local_cache", Error = ex.Message };
        }
    }

    /// <summary>Ищет серверы по ID (для избранного и истории), в т.ч. в сыром кэше API.</summary>
    public async Task<List<DayZServer>> GetServersByIdsAsync(IReadOnlyCollection<long> ids, CancellationToken ct)
    {
        if (ids.Count == 0 || !HasServerCache)
            return [];

        var requestedIds = ids.ToHashSet();
        var found = (await GetCachedServersAsync(ct))
            .Where(server => requestedIds.Contains(server.Id))
            .Select(server => server.Clone())
            .ToList();

        if (found.Count >= requestedIds.Count || !File.Exists(AppPaths.RawServerCacheFile))
            return found;

        // Сервер мог выпасть из отфильтрованного кэша — ищем его в сыром ответе API.
        await using var stream = File.OpenRead(AppPaths.RawServerCacheFile);
        using var document = await JsonDocument.ParseAsync(stream, cancellationToken: ct);
        if (document.RootElement.ValueKind != JsonValueKind.Array)
            return found;

        var foundIds = found.Select(server => server.Id).ToHashSet();
        foreach (var raw in document.RootElement.EnumerateArray())
        {
            if (raw.ValueKind != JsonValueKind.Object)
                continue;

            var server = ServerJsonParser.Parse(raw);
            if (requestedIds.Contains(server.Id) && foundIds.Add(server.Id) && ServerJsonParser.IsUsable(server))
                found.Add(server);
        }

        return found;
    }

    /// <summary>Загружает свежий список из API и перезаписывает локальный кэш.</summary>
    public async Task<ServerListResult> RefreshServerCacheAsync(CancellationToken ct)
    {
        var (loaded, source) = await LoadServersFromApiAsync(ct);
        var servers = ServerSearch.FilterAndSort(loaded, search: null, limit: null);
        if (servers.Count == 0)
            throw new InvalidOperationException("API не вернуло ни одного сервера.");

        await WriteAtomicallyAsync(AppPaths.ServerCacheFile, JsonSerializer.Serialize(servers, JsonOptions), ct);
        _memoryCache = new CachedServerList(File.GetLastWriteTimeUtc(AppPaths.ServerCacheFile), servers.Where(IsListed).ToList());

        return new ServerListResult
        {
            Success = true,
            Source = source,
            Servers = servers,
            TotalServers = servers.Count,
            TotalPlayers = servers.Sum(server => server.Players)
        };
    }

    /// <summary>Все серверы кэша; файл перечитывается, только если он изменился.</summary>
    private async Task<List<DayZServer>> GetCachedServersAsync(CancellationToken ct)
    {
        var writeTime = File.GetLastWriteTimeUtc(AppPaths.ServerCacheFile);
        var cached = _memoryCache;
        if (cached is not null && cached.WriteTimeUtc == writeTime)
            return cached.Servers;

        var text = await File.ReadAllTextAsync(AppPaths.ServerCacheFile, ct);
        var servers = (JsonSerializer.Deserialize<List<DayZServer>>(text, JsonOptions) ?? [])
            .Where(IsListed)
            .ToList();

        _memoryCache = new CachedServerList(writeTime, servers);
        return servers;
    }

    private static bool IsListed(DayZServer server) =>
        string.Equals(server.Source, "dzsa", StringComparison.OrdinalIgnoreCase) && ServerJsonParser.IsUsable(server);

    /// <summary>Запрашивает API; если оно недоступно — берёт последний сохранённый ответ с диска.</summary>
    private async Task<(List<DayZServer> Servers, string Source)> LoadServersFromApiAsync(CancellationToken ct)
    {
        try
        {
            var rawServers = await FetchRawServersAsync(ct);
            if (rawServers.Count > 0)
            {
                await File.WriteAllTextAsync(AppPaths.RawServerCacheFile, JsonSerializer.Serialize(rawServers, JsonOptions), ct);
                return (ParseUsable(rawServers), "api");
            }
        }
        catch (Exception ex) when (ex is not OperationCanceledException || !ct.IsCancellationRequested)
        {
            Log.Write($"Servers API ERROR url='{_backend.BaseUri}' error='{ex.Message}'");
        }

        if (File.Exists(AppPaths.RawServerCacheFile))
        {
            var text = await File.ReadAllTextAsync(AppPaths.RawServerCacheFile, ct);
            using var document = JsonDocument.Parse(text);
            var rawServers = ServerJsonParser.ExtractServerArray(document.RootElement);
            if (rawServers.Count > 0)
                return (ParseUsable(rawServers), "api_cache");
        }

        throw new InvalidOperationException("Не удалось загрузить список серверов из API, локальный кэш пуст.");
    }

    private async Task<List<JsonElement>> FetchRawServersAsync(CancellationToken ct)
    {
        using var response = await _backend.Http.GetAsync(_backend.Url("api/servers"), ct);
        response.EnsureSuccessStatusCode();

        await using var stream = await response.Content.ReadAsStreamAsync(ct);
        using var document = await JsonDocument.ParseAsync(stream, cancellationToken: ct);
        var root = document.RootElement;

        if (ServerJsonParser.TryGetApiError(root, out var error))
            throw new InvalidOperationException(error ?? "API списка серверов вернуло ошибку.");

        return ServerJsonParser.ExtractServerArray(root);
    }

    private static List<DayZServer> ParseUsable(IEnumerable<JsonElement> rawServers) =>
        rawServers.Select(ServerJsonParser.Parse).Where(ServerJsonParser.IsUsable).ToList();

    private static async Task WriteAtomicallyAsync(string path, string content, CancellationToken ct)
    {
        var tempPath = path + ".tmp";
        await File.WriteAllTextAsync(tempPath, content, ct);
        File.Move(tempPath, path, overwrite: true);
    }
}
