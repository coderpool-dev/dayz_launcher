namespace PrepiskaLauncher.Services.Servers;

/// <summary>Нормализация названий карт DayZ.</summary>
public static class DayZMaps
{
    private const string DefaultKey = "chernarusplus";
    private const string DefaultDisplayName = "Chernarus";

    /// <summary>Внутренний ключ карты (имя миссии), например "enoch" для Livonia.</summary>
    public static string ToKey(string? map)
    {
        var lower = (map ?? "").Trim().ToLowerInvariant();
        return lower switch
        {
            "" or "chernarus" => DefaultKey,
            "livonia" => "enoch",
            "deer isle" => "deerisle",
            "takistan" => "takistanplus",
            _ => lower
        };
    }

    public static string ToDisplayName(string? map)
    {
        return ToKey(map) switch
        {
            "chernarusplus" => "Chernarus",
            "enoch" => "Livonia",
            "deerisle" => "Deer Isle",
            "namalsk" => "Namalsk",
            "takistanplus" => "Takistan",
            "sakhal" => "Sakhal",
            "esseker" => "Esseker",
            "rostow" => "Rostow",
            "banov" => "Banov",
            "iztek" => "Iztek",
            "melkart" => "Melkart",
            "exclusionzone" => "Exclusion Zone",
            "valning" => "Valning",
            _ => string.IsNullOrWhiteSpace(map) ? DefaultDisplayName : char.ToUpper(map[0]) + map[1..]
        };
    }

    /// <summary>Угадывает карту по названию сервера, если API её не прислало.</summary>
    public static string DetectFromServerName(string serverName)
    {
        var lower = serverName.ToLowerInvariant();
        if (lower.Contains("livonia") || lower.Contains("enoch")) return "enoch";
        if (lower.Contains("namalsk")) return "namalsk";
        if (lower.Contains("deer isle") || lower.Contains("deerisle")) return "deerisle";
        if (lower.Contains("takistan")) return "takistanplus";
        if (lower.Contains("sakhal")) return "sakhal";
        if (lower.Contains("esseker")) return "esseker";
        if (lower.Contains("banov")) return "banov";
        return DefaultKey;
    }
}
