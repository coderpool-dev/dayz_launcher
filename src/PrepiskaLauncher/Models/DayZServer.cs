using System.Text.Json.Serialization;

namespace PrepiskaLauncher.Models;

/// <summary>Сервер DayZ из списка серверов.</summary>
/// <remarks>Сериализуется в кэш серверов и settings.json — имена свойств не переименовывать.</remarks>
public sealed class DayZServer
{
    public long Id { get; set; }
    public string Source { get; set; } = "dzsa";
    public string Name { get; set; } = "Unknown";

    /// <summary>Короткое имя для отображения (задаётся API); пусто — показывается <see cref="Name"/>.</summary>
    public string DisplayName { get; set; } = "";

    public string Ip { get; set; } = "";
    public int Port { get; set; }
    public int GamePort { get; set; }
    public int QueryPort { get; set; }
    public string Map { get; set; } = "chernarusplus";
    public string MapName { get; set; } = "Chernarus";
    public int Players { get; set; }
    public int MaxPlayers { get; set; }
    public int Ping { get; set; }
    public string Version { get; set; } = "";
    public bool Online { get; set; } = true;
    public bool Password { get; set; }
    public bool Vac { get; set; } = true;
    public string Perspective { get; set; } = "3pp";
    public string Time { get; set; } = "day";
    public string ServerTime { get; set; } = "";
    public string Mode { get; set; } = "community";

    /// <summary>Названия модов; индекс совпадает с <see cref="ModIds"/>.</summary>
    public List<string> Mods { get; set; } = [];

    /// <summary>Steam Workshop ID модов в порядке, в котором их отдал сервер.</summary>
    public List<string> ModIds { get; set; } = [];

    /// <summary>Откуда получен список модов: dzsa, a2s или unknown.</summary>
    public string ModsSource { get; set; } = "";

    public string Description { get; set; } = "";
    public string Hive { get; set; } = "Private";
    public bool Battleye { get; set; } = true;
    public int Rank { get; set; }
    public string Country { get; set; } = "";
    public bool Profile { get; set; }
    public bool StaticName { get; set; }
    public bool Sponsor { get; set; }

    public int FillPercent => MaxPlayers > 0 ? (int)Math.Round((double)Players / MaxPlayers * 100) : 0;

    /// <summary>Порт для A2S-запросов (query port, либо игровой порт, если query неизвестен).</summary>
    [JsonIgnore]
    public int EffectiveQueryPort => QueryPort > 0 ? QueryPort : Port;

    public string ModNameAt(int index) =>
        index >= 0 && index < Mods.Count ? Mods[index] : "";

    /// <summary>Копия сервера с собственными списками модов.</summary>
    public DayZServer Clone()
    {
        var copy = (DayZServer)MemberwiseClone();
        copy.Mods = [.. Mods];
        copy.ModIds = [.. ModIds];
        return copy;
    }
}
