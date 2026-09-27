using System.Text.Json;
using PrepiskaLauncher.Services.Servers;

namespace PrepiskaLauncher.Tests;

public class ServerJsonParserTests
{
    private static JsonElement Json(string json) => JsonDocument.Parse(json).RootElement.Clone();

    [Fact]
    public void Parse_DzsaFormat()
    {
        var server = ServerJsonParser.Parse(Json("""
            {
              "name": " Test Server ",
              "endpoint": { "ip": "185.207.214.122", "port": 2602 },
              "gamePort": 2402,
              "map": "enoch",
              "players": 11,
              "maxPlayers": 90,
              "time": "12:30",
              "firstPersonOnly": true,
              "mods": [
                { "steamWorkshopId": 1559212036, "name": "CF" },
                { "steamWorkshopId": "2017015030", "name": "" },
                { "steamWorkshopId": "not-a-number", "name": "Broken" }
              ]
            }
            """));

        Assert.Equal("Test Server", server.Name);
        Assert.Equal("185.207.214.122", server.Ip);
        Assert.Equal(2602, server.QueryPort);
        Assert.Equal(2402, server.GamePort);
        Assert.Equal("enoch", server.Map);
        Assert.Equal("Livonia", server.MapName);
        Assert.Equal("1pp", server.Perspective);
        Assert.Equal("12:30", server.ServerTime);
        Assert.Equal("modded", server.Mode);
        Assert.Equal(["1559212036", "2017015030"], server.ModIds);
        Assert.Equal(["CF", "Workshop 2017015030"], server.Mods);
    }

    [Fact]
    public void Parse_ApiFormatWithSeparateModArraysAndDisplayName()
    {
        var server = ServerJsonParser.Parse(Json("""
            {
              "name": "Russian Classic Deathmatch | 24/7",
              "displayName": "Russian Classic Deathmatch",
              "ip": "1.2.3.4",
              "queryPort": 27016,
              "gamePort": 2302,
              "modIds": ["111111111", "222222222"],
              "mods": ["First", ""],
              "sponsor": true
            }
            """));

        Assert.Equal("Russian Classic Deathmatch", server.DisplayName);
        Assert.Equal(["First", "Workshop 222222222"], server.Mods);
        Assert.True(server.Sponsor);
    }

    [Theory]
    [InlineData("185.207.214.122", 2602, 1752401238L)]
    [InlineData("194.147.90.110", 2320, 1280125954L)]
    public void Parse_IdMatchesPhpStableId(string ip, int queryPort, long expectedId)
    {
        // Значения посчитаны функцией stableId() из server/api/servers.php.
        var server = ServerJsonParser.Parse(Json($$"""{ "name": "X", "ip": "{{ip}}", "queryPort": {{queryPort}} }"""));

        Assert.Equal(expectedId, server.Id);
    }

    [Theory]
    [InlineData("Normal name", true)]
    [InlineData("Unknown", false)]
    [InlineData("12345", false)]
    [InlineData("", false)]
    public void IsUsable_RequiresMeaningfulName(string name, bool expected)
    {
        var server = ServerJsonParser.Parse(Json($$"""{ "name": "{{name}}", "ip": "1.2.3.4", "queryPort": 2302 }"""));

        Assert.Equal(expected, ServerJsonParser.IsUsable(server));
    }

    [Fact]
    public void ExtractServerArray_FromWrapperObject()
    {
        var servers = ServerJsonParser.ExtractServerArray(Json("""{ "success": true, "servers": [ { "name": "A" }, 5, { "name": "B" } ] }"""));

        Assert.Equal(2, servers.Count);
    }

    [Fact]
    public void TryGetApiError_DetectsErrorStatus()
    {
        Assert.True(ServerJsonParser.TryGetApiError(Json("""{ "status": "error", "error": "boom" }"""), out var error));
        Assert.Equal("boom", error);
        Assert.False(ServerJsonParser.TryGetApiError(Json("""{ "success": true }"""), out _));
    }
}
