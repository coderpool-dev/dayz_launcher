using PrepiskaLauncher.Core;
using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Services.Mods;

/// <summary>
/// Готовит моды сервера к запуску: проверяет их в Steam, исправляет/отбрасывает недоступные ID,
/// подписывается на недостающие и ждёт, пока Steam их скачает.
/// </summary>
public sealed class ModPreparationService(WorkshopService workshop, SteamLibrary steam, UnavailableModRegistry unavailableMods)
{
    private static readonly TimeSpan StatusTimeout = TimeSpan.FromMinutes(2);
    private static readonly TimeSpan DownloadTimeout = TimeSpan.FromMinutes(15);
    private static readonly TimeSpan ProgressPollInterval = TimeSpan.FromSeconds(3);
    private static readonly TimeSpan FinalCheckRetryInterval = TimeSpan.FromSeconds(30);

    /// <summary>Текст для строки статуса.</summary>
    public event Action<string>? StatusChanged;

    /// <summary>Лаунчер сейчас ждёт, пока Steam скачает моды.</summary>
    public bool IsWaitingForSteam { get; private set; }

    /// <returns>true — все моды на месте и можно запускать игру.</returns>
    public async Task<bool> EnsureModsReadyAsync(DayZServer server)
    {
        if (server.ModIds.Count == 0)
            return true;

        var status = await workshop.RunAsync(WorkshopAction.Status, server.ModIds, StatusTimeout);
        if (!status.Ok)
        {
            ReportStatus("Не удалось проверить моды: " + (status.Error ?? "неизвестная ошибка"));
            return false;
        }

        if (await RepairShiftedWorkshopIdsAsync(server, status.Results))
        {
            status = await workshop.RunAsync(WorkshopAction.Status, server.ModIds, StatusTimeout);
            if (!status.Ok)
            {
                ReportStatus("Не удалось проверить моды после исправления ID: " + (status.Error ?? "неизвестная ошибка"));
                return false;
            }
        }

        var removedIds = RemoveUnavailableMods(server, status.Results);
        if (removedIds.Count > 0)
        {
            unavailableMods.Remember(server.Id, removedIds);
            Log.Write($"ModsReady PRUNED unavailable={removedIds.Count} ids=[{string.Join(",", removedIds)}] remainingMods={server.ModIds.Count}");
            ReportStatus($"Удалены недоступные Workshop ID: {removedIds.Count}.");

            if (server.ModIds.Count == 0)
                return true;
        }

        var missingIds = FindMissingMods(server, status.Results);
        LogWorkshopItems("ModsReady ITEM", status.Results, missingIds);
        if (missingIds.Count == 0)
        {
            ReportStatus($"Все моды готовы: {server.ModIds.Count} шт.");
            return true;
        }

        var missingSet = missingIds.ToHashSet(StringComparer.Ordinal);
        if (status.Results.Where(item => missingSet.Contains(item.Id)).Any(IsSteamBusyWith))
            return await WaitForDownloadsAsync(server, missingIds);

        if (!await SubscribeAsync(server, missingIds))
            return false;

        return await WaitForDownloadsAsync(server, missingIds);
    }

    private async Task<bool> SubscribeAsync(DayZServer server, IReadOnlyList<string> missingIds)
    {
        var libraryRoots = steam.LibraryRoots();
        WorkshopResult subscribe;

        IsWaitingForSteam = true;
        try
        {
            var subscribeTask = workshop.RunAsync(WorkshopAction.Subscribe, missingIds, StatusTimeout);
            do
            {
                ReportDownloadProgress(server, CountNotReady(missingIds, libraryRoots));
                await Task.WhenAny(subscribeTask, Task.Delay(ProgressPollInterval));
            }
            while (!subscribeTask.IsCompleted);

            subscribe = await subscribeTask;
        }
        finally
        {
            IsWaitingForSteam = false;
        }

        LogWorkshopItems("ModsReady SUBSCRIBE ITEM", subscribe.Results, missingIds);
        if (!subscribe.Ok)
        {
            ReportStatus("Не удалось подписаться на моды: " + (subscribe.Error ?? "неизвестная ошибка"));
            return false;
        }

        return true;
    }

    /// <summary>
    /// Ждёт, пока файлы модов появятся на диске, затем один раз подтверждает статус через Steam.
    /// Steamworks не дёргаем на каждом шаге — это мешает Steam качать.
    /// </summary>
    private async Task<bool> WaitForDownloadsAsync(DayZServer server, IReadOnlyCollection<string> pendingIds)
    {
        var startedAt = DateTimeOffset.UtcNow;
        var nextSteamCheckAt = DateTimeOffset.MinValue;
        var libraryRoots = steam.LibraryRoots();

        IsWaitingForSteam = true;
        try
        {
            while (DateTimeOffset.UtcNow - startedAt < DownloadTimeout)
            {
                var notReady = CountNotReady(pendingIds, libraryRoots);
                ReportDownloadProgress(server, notReady);

                if (notReady == 0 && DateTimeOffset.UtcNow >= nextSteamCheckAt)
                {
                    var status = await workshop.RunAsync(WorkshopAction.Status, server.ModIds, StatusTimeout);
                    if (status.Ok && FindMissingMods(server, status.Results).Count == 0)
                    {
                        ReportStatus($"Все моды скачаны: {server.ModIds.Count} шт.");
                        return true;
                    }

                    Log.Write($"ModsReady FINAL CHECK failed: {status.Error ?? "Steam has not finished installing mods"}");
                    nextSteamCheckAt = DateTimeOffset.UtcNow + FinalCheckRetryInterval;
                }

                await Task.Delay(ProgressPollInterval);
            }

            ReportStatus("Steam всё ещё качает моды. Дождитесь окончания загрузки и нажмите «Играть» снова.");
            return false;
        }
        finally
        {
            IsWaitingForSteam = false;
        }
    }

    /// <summary>
    /// Некоторые серверы отдают Workshop ID, сдвинутый на ±1 от настоящего. Если ID недоступен,
    /// проверяем соседние и берём тот, чьё имя совпадает с именем мода.
    /// </summary>
    private async Task<bool> RepairShiftedWorkshopIdsAsync(DayZServer server, IReadOnlyList<WorkshopItem> items)
    {
        var unavailableIds = items
            .Where(IsUnavailable)
            .Select(item => item.Id)
            .Where(id => ulong.TryParse(id, out _))
            .Distinct(StringComparer.Ordinal)
            .ToList();

        var repaired = false;
        foreach (var oldId in unavailableIds)
        {
            var originalName = server.ModNameAt(server.ModIds.IndexOf(oldId));
            var candidates = AdjacentIds(oldId);
            if (candidates.Count == 0)
                continue;

            var candidateStatus = await workshop.RunAsync(WorkshopAction.Status, candidates, TimeSpan.FromSeconds(30));
            if (!candidateStatus.Ok)
            {
                Log.Write($"Workshop repair status failed oldId={oldId} error='{candidateStatus.Error}'");
                continue;
            }

            var replacement = candidateStatus.Results
                .Where(item => !IsUnavailable(item))
                .FirstOrDefault(item => IsSameMod(originalName, item.Name));
            if (replacement is null)
                continue;

            ReplaceModId(server, oldId, replacement.Id, replacement.Name);
            unavailableMods.Remember(server.Id, [oldId]);
            Log.Write($"Workshop repair oldId={oldId} newId={replacement.Id} oldName='{originalName}' newName='{replacement.Name}' server='{server.Name}'");
            repaired = true;
        }

        return repaired;
    }

    private static List<string> AdjacentIds(string workshopId)
    {
        if (!ulong.TryParse(workshopId, out var id))
            return [];

        var candidates = new List<string>();
        if (id > 0) candidates.Add((id - 1).ToString());
        if (id < ulong.MaxValue) candidates.Add((id + 1).ToString());
        return candidates;
    }

    private static bool IsSameMod(string expectedName, string candidateName)
    {
        if (string.IsNullOrWhiteSpace(candidateName))
            return false;

        // Имя неизвестно — доверяем соседнему ID.
        if (string.IsNullOrWhiteSpace(expectedName) || expectedName.StartsWith("Workshop ", StringComparison.OrdinalIgnoreCase))
            return true;

        var expectedKey = NameMatchKey(expectedName);
        var candidateKey = NameMatchKey(candidateName);
        return expectedKey.Length > 0
            && candidateKey.Length > 0
            && (expectedKey.Contains(candidateKey, StringComparison.Ordinal) || candidateKey.Contains(expectedKey, StringComparison.Ordinal));
    }

    private static string NameMatchKey(string value) =>
        new(value.ToLowerInvariant().Where(char.IsLetterOrDigit).ToArray());

    private static void ReplaceModId(DayZServer server, string oldId, string newId, string? newName)
    {
        for (var i = 0; i < server.ModIds.Count; i++)
        {
            if (!string.Equals(server.ModIds[i], oldId, StringComparison.Ordinal))
                continue;

            server.ModIds[i] = newId;
            if (i < server.Mods.Count && !string.IsNullOrWhiteSpace(newName))
                server.Mods[i] = newName;
        }
    }

    /// <summary>Убирает из списка сервера моды, которых нет в Steam. Возвращает удалённые ID.</summary>
    private static List<string> RemoveUnavailableMods(DayZServer server, IReadOnlyList<WorkshopItem> items)
    {
        var unavailableIds = items
            .Where(IsUnavailable)
            .Select(item => item.Id)
            .ToHashSet(StringComparer.Ordinal);

        if (unavailableIds.Count == 0)
            return [];

        var removed = new List<string>();
        for (var i = server.ModIds.Count - 1; i >= 0; i--)
        {
            var modId = server.ModIds[i];
            if (!unavailableIds.Contains(modId))
                continue;

            removed.Add(modId);
            server.ModIds.RemoveAt(i);
            if (i < server.Mods.Count)
                server.Mods.RemoveAt(i);
        }

        removed.Reverse();
        return removed;
    }

    /// <summary>Steam ничего не знает о моде: нет имени, пути, размера и прогресса.</summary>
    private static bool IsUnavailable(WorkshopItem item)
    {
        if (string.IsNullOrWhiteSpace(item.Id))
            return false;

        var state = item.State?.Trim().ToLowerInvariant() ?? "";
        var hasName = !string.IsNullOrWhiteSpace(item.Name);
        var hasPath = !string.IsNullOrWhiteSpace(item.Path) && Directory.Exists(item.Path);
        var hasKnownSize = item.BytesTotal > 0 || item.SizeBytes > 0;
        var hasProgress = item.BytesDownloaded > 0 || item.Percent > 0;

        return !hasName && !hasPath && !hasKnownSize && !hasProgress
            && state is "" or "not_subscribed" or "unknown" or "not_found";
    }

    /// <summary>Steam уже качает/ставит мод в очередь — повторно подписываться не нужно.</summary>
    private static bool IsSteamBusyWith(WorkshopItem item)
    {
        var state = item.State?.Trim().ToLowerInvariant() ?? "";
        return item.Percent is > 0 and < 100
            || (item.BytesDownloaded > 0 && item.BytesTotal > 0 && item.BytesDownloaded < item.BytesTotal)
            || item.IsDownloading
            || item.IsDownloadPending
            || item.NeedsUpdate
            || state.Contains("download")
            || state.Contains("pending")
            || state == "installing";
    }

    private List<string> FindMissingMods(DayZServer server, IReadOnlyList<WorkshopItem> items)
    {
        var itemsById = items
            .Where(item => !string.IsNullOrWhiteSpace(item.Id))
            .GroupBy(item => item.Id)
            .ToDictionary(group => group.Key, group => group.Last(), StringComparer.Ordinal);

        var libraryRoots = steam.LibraryRoots();
        return server.ModIds
            .Where(id => !string.IsNullOrWhiteSpace(id))
            .Where(id => !itemsById.TryGetValue(id, out var item)
                || string.IsNullOrWhiteSpace(item.Path)
                || !steam.IsWorkshopModReady(id, libraryRoots))
            .ToList();
    }

    private int CountNotReady(IEnumerable<string> modIds, IReadOnlyList<string> libraryRoots) =>
        modIds.Count(id => !steam.IsWorkshopModReady(id, libraryRoots));

    private void ReportDownloadProgress(DayZServer server, int notReady) =>
        ReportStatus($"Моды: {server.ModIds.Count - notReady}/{server.ModIds.Count} готово, {notReady} загружается в Steam.");

    private void ReportStatus(string text) => StatusChanged?.Invoke(text);

    private static void LogWorkshopItems(string prefix, IReadOnlyList<WorkshopItem> items, IReadOnlyCollection<string> missingIds)
    {
        if (items.Count == 0)
        {
            Log.Write($"{prefix} none");
            return;
        }

        var missing = missingIds.ToHashSet(StringComparer.Ordinal);
        foreach (var item in items)
        {
            var path = item.Path ?? "";
            var pathExists = path.Length > 0 && Directory.Exists(path);
            var total = item.BytesTotal > 0 ? item.BytesTotal : item.SizeBytes;
            Log.Write($"{prefix} id={item.Id} missing={missing.Contains(item.Id)} unavailable={IsUnavailable(item)} state='{item.State}' " +
                      $"percent={item.Percent:0.##} bytes={Format.Bytes(item.BytesDownloaded)}/{Format.Bytes(total)} " +
                      $"pathExists={pathExists} name='{item.Name}' path='{path}'");
        }
    }
}
