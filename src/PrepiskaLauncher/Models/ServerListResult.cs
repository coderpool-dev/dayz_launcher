namespace PrepiskaLauncher.Models;

public sealed class ServerListResult
{
    public bool Success { get; init; }
    public string Source { get; init; } = "none";

    /// <summary>Серверы после поиска и лимита.</summary>
    public List<DayZServer> Servers { get; init; } = [];

    /// <summary>Всего серверов в списке (без учёта поиска и лимита).</summary>
    public int TotalServers { get; init; }

    /// <summary>Всего игроков на всех серверах списка (без учёта поиска и лимита).</summary>
    public int TotalPlayers { get; init; }

    public string? Error { get; init; }

    public int Count => Servers.Count;
}
