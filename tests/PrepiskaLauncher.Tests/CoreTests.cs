using PrepiskaLauncher.Core;
using PrepiskaLauncher.Models;
using PrepiskaLauncher.Services.Servers;

namespace PrepiskaLauncher.Tests;

public class CoreTests
{
    [Theory]
    [InlineData(null, "Survivor")]
    [InlineData("   ", "Survivor")]
    [InlineData(" Player ", "Player")]
    [InlineData("Bad\"Name\r\n", "BadName")]
    [InlineData("\"\"\"", "Survivor")]
    public void PlayerNames_Normalize(string? input, string expected) =>
        Assert.Equal(expected, PlayerNames.Normalize(input));

    [Fact]
    public void PlayerNames_TruncatesTo32() =>
        Assert.Equal(32, PlayerNames.Normalize(new string('a', 50)).Length);

    [Theory]
    [InlineData("A2S", ModSources.A2s)]
    [InlineData("api", ModSources.Api)]
    [InlineData("garbage", ModSources.Auto)]
    [InlineData(null, ModSources.Auto)]
    public void ModSources_Normalize(string? input, string expected) =>
        Assert.Equal(expected, ModSources.Normalize(input));

    [Theory]
    [InlineData(0, "0 B")]
    [InlineData(1536, "1,5 KB")]
    [InlineData(3L * 1024 * 1024 * 1024, "3 GB")]
    public void Format_Bytes(long bytes, string expected) =>
        Assert.Equal(expected, Format.Bytes(bytes).Replace('.', ','));

    [Theory]
    [InlineData("livonia", "enoch", "Livonia")]
    [InlineData("", "chernarusplus", "Chernarus")]
    [InlineData("deer isle", "deerisle", "Deer Isle")]
    [InlineData("pripyat", "pripyat", "Pripyat")]
    public void DayZMaps_Normalize(string map, string key, string displayName)
    {
        Assert.Equal(key, DayZMaps.ToKey(map));
        Assert.Equal(displayName, DayZMaps.ToDisplayName(map));
    }

    [Fact]
    public void DayZServer_CloneHasIndependentModLists()
    {
        var original = new DayZServer { ModIds = ["1"], Mods = ["A"] };

        var copy = original.Clone();
        copy.ModIds.Add("2");
        copy.Mods[0] = "B";

        Assert.Equal(["1"], original.ModIds);
        Assert.Equal(["A"], original.Mods);
    }
}
