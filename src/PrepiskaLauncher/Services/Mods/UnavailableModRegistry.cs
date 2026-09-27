using PrepiskaLauncher.Core;

namespace PrepiskaLauncher.Services.Mods;

/// <summary>
/// Запоминает Workshop ID, которых больше нет в Steam (удалены/скрыты автором), отдельно для
/// каждого сервера, чтобы не пытаться скачивать их при каждом запуске.
/// </summary>
public sealed class UnavailableModRegistry(AppSettings settings)
{
    private readonly Dictionary<long, HashSet<string>> _byServer = [];

    public IReadOnlySet<string> For(long serverId) => GetOrLoad(serverId);

    public void Remember(long serverId, IEnumerable<string> modIds)
    {
        var unavailable = GetOrLoad(serverId);
        var changed = false;
        foreach (var modId in modIds.Where(id => !string.IsNullOrWhiteSpace(id)))
            changed |= unavailable.Add(modId);

        if (!changed)
            return;

        settings.UnavailableWorkshopIds[serverId] = unavailable.Order(StringComparer.Ordinal).ToList();
        settings.Save();
    }

    private HashSet<string> GetOrLoad(long serverId)
    {
        if (_byServer.TryGetValue(serverId, out var unavailable))
            return unavailable;

        unavailable = settings.UnavailableWorkshopIds.TryGetValue(serverId, out var saved)
            ? saved.Where(id => !string.IsNullOrWhiteSpace(id)).ToHashSet(StringComparer.Ordinal)
            : new HashSet<string>(StringComparer.Ordinal);

        _byServer[serverId] = unavailable;
        return unavailable;
    }
}
