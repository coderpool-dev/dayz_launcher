using PrepiskaLauncher.Models;
using PrepiskaLauncher.Services.Servers;

namespace PrepiskaLauncher.Tests;

public class ServerSearchTests
{
    private static DayZServer Server(string name, int players = 0, bool sponsor = false, string ip = "1.2.3.4", int queryPort = 2302) =>
        new() { Name = name, Players = players, Sponsor = sponsor, Ip = ip, QueryPort = queryPort, GamePort = 2302, Port = 2302 };

    [Fact]
    public void WithoutSearch_SponsorsFirstThenByPlayers()
    {
        var servers = new[]
        {
            Server("Small", players: 5),
            Server("Big", players: 100),
            Server("Sponsor", players: 1, sponsor: true)
        };

        var result = ServerSearch.FilterAndSort(servers, search: null, limit: null);

        Assert.Equal(["Sponsor", "Big", "Small"], result.Select(s => s.Name));
    }

    [Fact]
    public void Search_ExactMatchRanksAboveSubstringWithMorePlayers()
    {
        var servers = new[]
        {
            Server("PODPIVAS Livonia", players: 90),
            Server("Podpivas", players: 3)
        };

        var result = ServerSearch.FilterAndSort(servers, "podpivas", limit: null);

        Assert.Equal("Podpivas", result[0].Name);
    }

    [Fact]
    public void Search_ToleratesTypos()
    {
        var servers = new[] { Server("PODPIVAS #11 LIVONIA"), Server("Other server") };

        var result = ServerSearch.FilterAndSort(servers, "podpivaz", limit: null);

        Assert.Single(result);
        Assert.Equal("PODPIVAS #11 LIVONIA", result[0].Name);
    }

    [Fact]
    public void Search_ByAddress()
    {
        var servers = new[] { Server("First", ip: "185.207.214.122", queryPort: 2602), Server("Second", ip: "10.0.0.1") };

        var result = ServerSearch.FilterAndSort(servers, "185.207.214.122:2602", limit: null);

        Assert.Equal(["First"], result.Select(s => s.Name));
    }

    [Fact]
    public void Limit_TakesTopServers()
    {
        var servers = Enumerable.Range(1, 10).Select(i => Server($"Server {i}", players: i));

        var result = ServerSearch.FilterAndSort(servers, search: "", limit: 3);

        Assert.Equal([10, 9, 8], result.Select(s => s.Players));
    }
}
