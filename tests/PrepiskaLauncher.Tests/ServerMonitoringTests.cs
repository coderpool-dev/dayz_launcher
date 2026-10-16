using System.Text.Json;
using PrepiskaLauncher.Bridge;
using PrepiskaLauncher.Models;
using PrepiskaLauncher.Services.Servers;

namespace PrepiskaLauncher.Tests;

public class ServerMonitoringTests
{
    [Theory]
    [InlineData("Deathmatch | PvP | Vanilla+ | Hardcore", "deathmatch", "vanilla-plus", true)]
    [InlineData("ПВЕ | ПВП зоны", "pve", "vanilla", false)]
    [InlineData("Roleplay server", "rp", "vanilla", false)]
    public void ClassifiesExplicitNames(string name, string mode, string style, bool hardcore)
    {
        var tags = ServerCategories.Classify(new DayZServer { Name = name });
        Assert.Contains(mode, tags.Modes);
        Assert.Equal(style, tags.Style);
        Assert.Equal(hardcore, tags.Hardcore);
    }

    [Fact]
    public void UnknownDoesNotBecomeSurvival()
    {
        Assert.Empty(ServerCategories.Classify(new DayZServer { Name = "Admin Paradise" }).Modes);
    }

    [Fact]
    public void DtoIncludesVersionCategoriesAndPopulationButCacheDoesNotStorePopulation()
    {
        var server = new DayZServer { Name = "PvP", Ip = "1.2.3.4", QueryPort = 27016, Port = 2302, Version = "1.29.163709",
            Population = new ServerPopulation { Ready = true, Night = 10 } };
        var dto = ServerDto.From(server, false);
        Assert.Equal(server.Version, dto.Version);
        Assert.Equal(10, dto.Population!.Night);
        Assert.Contains("pvp", dto.Categories.Modes);
        Assert.DoesNotContain("Population", JsonSerializer.Serialize(server));
        Assert.Equal("1.2.3.4:27016", ServerMonitoringService.Key(server));
    }

    [Fact]
    public void OldAndFutureSummariesAreNotRanked()
    {
        var now = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        Assert.True(ServerMonitoringService.IsFresh(now));
        Assert.False(ServerMonitoringService.IsFresh(null));
        Assert.False(ServerMonitoringService.IsFresh(now - 3601));
        Assert.False(ServerMonitoringService.IsFresh(now + 3600));
    }

    [Fact]
    public void ObservedAveragesArePassedToCardsEvenBeforeRankingIsReady()
    {
        var population = JsonSerializer.Deserialize<ServerPopulation>(
            """{"ready":false,"nightAverage":0,"dayAverage":12.5} """,
            new JsonSerializerOptions { PropertyNameCaseInsensitive = true });
        var server = new DayZServer { Population = population };
        var dto = ServerDto.From(server, false);
        Assert.False(dto.Population!.Ready);
        Assert.Equal(0, dto.Population.NightAverage);
        Assert.Equal(12.5, dto.Population.DayAverage);
        Assert.Null(dto.Population.Night);
    }

    [Fact]
    public void ParserHonorsApiPerspectiveAndOfflineState()
    {
        using var json = JsonDocument.Parse("""{"name":"PvP", "ip":"1.2.3.4", "queryPort":2302, "perspective":"1pp", "online":false, "map":"custom_map", "mapName":"Custom Map"} """);
        var server = ServerJsonParser.Parse(json.RootElement);
        Assert.Equal("1pp", server.Perspective);
        Assert.False(server.Online);
        Assert.Equal("Custom Map", server.MapName);
    }

    [Theory]
    [InlineData("bitterroot")]
    [InlineData("1.29.163709")]
    [InlineData("1.2.3.4:2302")]
    public void SearchSupportsMapVersionAndGamePort(string search)
    {
        var server = new DayZServer { Name = "Alpha", Map = "bitterroot", MapName = "Bitterroot", Version = "1.29.163709", Ip = "1.2.3.4", QueryPort = 27016, GamePort = 2302 };
        Assert.Single(ServerSearch.FilterAndSort([server], search, null));
    }
}
