using PrepiskaLauncher.Core;
using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Services.Mods;

/// <summary>Скачанный мод вместе с его состоянием в Steam.</summary>
public sealed record InstalledModStatus(
    string Id,
    string Name,
    string Path,
    long SizeBytes,
    string State,
    string Message,
    bool NeedsUpdate,
    bool IsDownloading,
    bool IsDownloadPending,
    double Percent,
    DateTime UpdatedAt);

/// <summary>
/// Список скачанных модов со статусами из Steam. Кэшируется, т.к. каждый запрос
/// статуса запускает WorkshopHelper и инициализирует Steamworks.
/// </summary>
public sealed class InstalledModsCatalog(WorkshopService workshop)
{
    private static readonly TimeSpan CacheTtl = TimeSpan.FromSeconds(30);

    private IReadOnlyList<InstalledModStatus> _cached = [];
    private DateTimeOffset _cachedAt = DateTimeOffset.MinValue;
    private bool _isLoading;

    public void Invalidate() => _cachedAt = DateTimeOffset.MinValue;

    /// <param name="cachedOnly">Не обращаться к Steam (например, пока Steam качает моды).</param>
    public async Task<IReadOnlyList<InstalledModStatus>> GetAsync(bool cachedOnly)
    {
        if (cachedOnly || _isLoading || DateTimeOffset.UtcNow - _cachedAt < CacheTtl)
            return _cached;

        _isLoading = true;
        try
        {
            _cached = await LoadAsync();
            _cachedAt = DateTimeOffset.UtcNow;
        }
        catch (Exception ex)
        {
            Log.Write("InstalledMods ERROR " + ex.Message);
        }
        finally
        {
            _isLoading = false;
        }

        return _cached;
    }

    private async Task<IReadOnlyList<InstalledModStatus>> LoadAsync()
    {
        var installed = workshop.GetInstalledMods();
        if (installed.Count == 0)
            return [];

        var status = await workshop.RunAsync(WorkshopAction.Status, installed.Select(mod => mod.Id), TimeSpan.FromMinutes(2));
        var itemsById = status.Results
            .Where(item => !string.IsNullOrWhiteSpace(item.Id))
            .GroupBy(item => item.Id, StringComparer.Ordinal)
            .ToDictionary(group => group.Key, group => group.Last(), StringComparer.Ordinal);

        return installed.Select(mod =>
        {
            itemsById.TryGetValue(mod.Id, out var item);
            return new InstalledModStatus(
                Id: mod.Id,
                Name: !string.IsNullOrWhiteSpace(item?.Name) ? item.Name : $"Workshop {mod.Id}",
                Path: mod.Path,
                SizeBytes: item?.SizeBytes ?? 0,
                State: item?.State ?? "installed",
                Message: item?.Message ?? "Installed",
                NeedsUpdate: item?.NeedsUpdate ?? false,
                IsDownloading: item?.IsDownloading ?? false,
                IsDownloadPending: item?.IsDownloadPending ?? false,
                Percent: item?.Percent ?? 0,
                UpdatedAt: mod.UpdatedAt);
        }).ToList();
    }
}
