using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using PrepiskaLauncher.Models;
using PrepiskaLauncher.Services.Backend;
using PrepiskaLauncher.Services.Servers;

namespace PrepiskaLauncher.Tests;

public class UpdateAndSponsorTests
{
    [Theory]
    [InlineData("1.1.0", "1.0.0", true)]
    [InlineData("1.10.0", "1.9.0", true)]
    [InlineData("1.0.0", "1.0.0", false)]
    [InlineData("0.9.9", "1.0.0", false)]
    [InlineData(null, "1.0.0", false)]
    [InlineData("garbage", "1.0.0", false)]
    public void IsNewer_ComparesSemanticVersions(string? candidate, string current, bool expected) =>
        Assert.Equal(expected, UpdateService.IsNewer(candidate, current));

    [Fact]
    public async Task ComputeSha256_MatchesServerFormat()
    {
        var path = Path.GetTempFileName();
        await File.WriteAllTextAsync(path, "installer 1.2.0");
        try
        {
            var expected = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes("installer 1.2.0"))).ToLowerInvariant();
            Assert.Equal(expected, await UpdateService.ComputeSha256Async(path));
        }
        finally
        {
            File.Delete(path);
        }
    }

    [Fact]
    public void Parse_ReadsSponsorPriority()
    {
        var server = ServerJsonParser.Parse(JsonDocument.Parse("""{ "name": "S", "ip": "1.2.3.4", "queryPort": 2302, "sponsor": true, "sponsorPriority": 7 }""").RootElement);

        Assert.True(server.Sponsor);
        Assert.Equal(7, server.SponsorPriority);
    }

    [Fact]
    public void Sponsors_AreSortedByPriorityBeforeOnline()
    {
        var servers = new[]
        {
            new DayZServer { Name = "Popular", Players = 100 },
            new DayZServer { Name = "Sponsor low", Sponsor = true, SponsorPriority = 1, Players = 50 },
            new DayZServer { Name = "Sponsor high", Sponsor = true, SponsorPriority = 10, Players = 0 }
        };

        var result = ServerSearch.FilterAndSort(servers, search: null, limit: null);

        Assert.Equal(["Sponsor high", "Sponsor low", "Popular"], result.Select(s => s.Name));
    }
}
