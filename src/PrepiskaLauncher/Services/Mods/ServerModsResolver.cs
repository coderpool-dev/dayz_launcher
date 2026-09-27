using PrepiskaLauncher.Core;
using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Services.Mods;

/// <summary>
/// Решает, откуда брать список модов сервера (API или A2S — см. <see cref="ModSources"/>),
/// и подменяет список модов данными, полученными напрямую с сервера.
/// </summary>
public sealed class ServerModsResolver(A2sRulesService a2s, AppSettings settings, UnavailableModRegistry unavailableMods)
{
    /// <summary>Известные ошибочные ID, которые некоторые серверы отдают в A2S.</summary>
    private static readonly Dictionary<string, ServerMod> KnownIdFixes = new(StringComparer.Ordinal)
    {
        ["1797720065"] = new ServerMod("1797720064", "WindstridesClothingPack")
    };

    private string Mode => ModSources.Normalize(settings.ModSource);

    public bool ShouldQueryA2s(DayZServer server) =>
        Mode == ModSources.A2s || (Mode == ModSources.Auto && server.ModIds.Count == 0);

    /// <summary>
    /// Запрашивает моды у сервера через A2S и применяет их, если режим A2S или API модов не дало.
    /// </summary>
    /// <param name="canApply">Дополнительная проверка перед применением (например, сервер всё ещё выбран).</param>
    /// <returns>true, если список модов сервера был заменён.</returns>
    public async Task<bool> RefreshFromA2sAsync(DayZServer server, string logContext, Func<bool> canApply, CancellationToken ct)
    {
        var apiModIds = server.ModIds.ToList();
        Log.Write($"{logContext} A2S QUERY ip={server.Ip} queryPort={server.EffectiveQueryPort}");

        var a2sMods = await a2s.QueryModsAsync(server.Ip, server.EffectiveQueryPort, ct, forceRefresh: true);
        Log.Write($"{logContext} MOD SOURCES apiMods={apiModIds.Count} a2sMods={a2sMods.Count} source='{server.ModsSource}' " +
                  $"server='{server.Name}' address={server.Ip}:{server.QueryPort} " +
                  $"apiIds=[{string.Join(",", apiModIds)}] a2sIds=[{string.Join(",", a2sMods.Select(mod => mod.Id))}]");

        var preferA2s = Mode == ModSources.A2s || apiModIds.Count == 0;
        if (a2sMods.Count == 0 || !preferA2s || !canApply())
            return false;

        ApplyA2sMods(server, a2sMods);
        return true;
    }

    private void ApplyA2sMods(DayZServer server, IEnumerable<ServerMod> a2sMods)
    {
        var unavailable = unavailableMods.For(server.Id);
        var mods = a2sMods
            .Select(FixKnownWrongId)
            .Where(mod => !string.IsNullOrWhiteSpace(mod.Id) && !unavailable.Contains(mod.Id))
            .DistinctBy(mod => mod.Id, StringComparer.Ordinal)
            .ToList();

        server.ModIds = mods.Select(mod => mod.Id).ToList();
        server.Mods = mods.Select(mod => string.IsNullOrWhiteSpace(mod.Name) ? $"Workshop {mod.Id}" : mod.Name).ToList();
        server.ModsSource = "a2s";

        if (unavailable.Count > 0)
            Log.Write($"A2S APPLY filteredUnavailable={unavailable.Count} ids=[{string.Join(",", unavailable)}] server='{server.Name}'");
    }

    private static ServerMod FixKnownWrongId(ServerMod mod)
    {
        if (!KnownIdFixes.TryGetValue(mod.Id, out var fixedMod))
            return mod;

        Log.Write($"A2S known repair oldId={mod.Id} newId={fixedMod.Id} name='{fixedMod.Name}'");
        return fixedMod;
    }
}
