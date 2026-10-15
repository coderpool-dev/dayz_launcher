using System.Text.RegularExpressions;
using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Services.Servers;

public sealed record ServerCategoryInfo(IReadOnlyList<string> Modes, string Style, bool Hardcore);

public static class ServerCategories
{
    private static readonly (string Mode, Regex Pattern)[] Patterns = [
        ("deathmatch", Pattern(@"\b(?:death[ -]?match|dm|tdm)\b|дезматч|дезмач")),
        ("pvp", Pattern(@"\bpvp\b|пвп")),
        ("pve", Pattern(@"\bpve\b|пве")),
        ("rp", Pattern(@"\b(?:rp|role[ -]?play)\b|ролеплей")),
        ("survival", Pattern(@"\bsurvival\b|выживание"))
    ];
    private static readonly Regex VanillaPlus = Pattern(@"\bvanilla\s*\+|\bvanilla[ -]?plus\b");
    private static readonly Regex Hardcore = Pattern(@"\bhardcore\b|хардкор");

    public static ServerCategoryInfo Classify(DayZServer server) => new(
        Patterns.Where(item => item.Pattern.IsMatch(server.Name)).Select(item => item.Mode).ToArray(),
        VanillaPlus.IsMatch(server.Name) ? "vanilla-plus" : server.ModIds.Count == 0 ? "vanilla" : "modded",
        Hardcore.IsMatch(server.Name));

    private static Regex Pattern(string value) => new(value, RegexOptions.IgnoreCase | RegexOptions.CultureInvariant, TimeSpan.FromMilliseconds(100));
}
