using System.Net.Http.Json;
using System.Text.Json;
using PrepiskaLauncher.Models;
using PrepiskaLauncher.Services.Backend;

namespace PrepiskaLauncher.Services.Servers;

public sealed class ServerMonitoringService(BackendClient backend)
{
    private static readonly JsonSerializerOptions Options = new() { PropertyNameCaseInsensitive = true };
    public long? SummaryUpdatedAt { get; private set; }

    public async Task<IReadOnlyDictionary<string, ServerPopulation>> GetSummaryAsync(CancellationToken ct)
    {
        var summary = await backend.Http.GetFromJsonAsync<Summary>(backend.Url("api/monitoring/summary"), Options, ct);
        SummaryUpdatedAt = summary?.UpdatedAt;
        return IsFresh(SummaryUpdatedAt) && summary?.Servers is { } servers ? servers : new Dictionary<string, ServerPopulation>();
    }

    internal static bool IsFresh(long? timestamp) => timestamp is { } value
        && value <= DateTimeOffset.UtcNow.ToUnixTimeSeconds() + 60
        && value >= DateTimeOffset.UtcNow.ToUnixTimeSeconds() - 3600;

    public async Task<PopulationSeries> GetHistoryAsync(DayZServer server, int days, CancellationToken ct)
    {
        var key = Uri.EscapeDataString(Key(server));
        return await backend.Http.GetFromJsonAsync<PopulationSeries>(backend.Url($"api/monitoring/history?server={key}&days={(days == 7 ? 7 : 1)}"), Options, ct)
            ?? throw new InvalidOperationException("История сервера недоступна.");
    }

    public static string Key(DayZServer server) => $"{server.Ip}:{server.EffectiveQueryPort}";

    private sealed record Summary(long? UpdatedAt, Dictionary<string, ServerPopulation> Servers);
}
