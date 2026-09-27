using System.Text;
using System.Text.Json;
using PrepiskaLauncher.Core;
using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Services.Servers;

/// <summary>Преобразование JSON сервера (формат DZSA / нашего API) в <see cref="DayZServer"/>.</summary>
public static class ServerJsonParser
{
    private static readonly string[] ServerArrayPropertyNames = ["servers", "result", "data", "items"];

    /// <summary>Достаёт массив серверов из ответа: либо корневой массив, либо поле servers/result/data/items.</summary>
    public static List<JsonElement> ExtractServerArray(JsonElement root)
    {
        if (root.ValueKind == JsonValueKind.Array)
            return CloneObjects(root);

        if (root.ValueKind != JsonValueKind.Object)
            return [];

        foreach (var name in ServerArrayPropertyNames)
        {
            if (root.TryGetProperty(name, out var array) && array.ValueKind == JsonValueKind.Array)
                return CloneObjects(array);
        }

        return [];
    }

    /// <summary>Ответ API вида { "status": "error" | 1, "error": "..." }.</summary>
    public static bool TryGetApiError(JsonElement root, out string? error)
    {
        error = null;
        if (root.ValueKind != JsonValueKind.Object || !root.TryGetProperty("status", out var status))
            return false;

        var statusText = status.ValueKind == JsonValueKind.String ? status.GetString() : status.GetRawText();
        var isError = string.Equals(statusText, "error", StringComparison.OrdinalIgnoreCase) || statusText == "1";
        if (isError && root.TryGetProperty("error", out var errorElement))
            error = errorElement.ValueKind == JsonValueKind.String ? errorElement.GetString() : errorElement.GetRawText();

        return isError;
    }

    public static DayZServer Parse(JsonElement raw)
    {
        var endpoint = raw.TryGetProperty("endpoint", out var endpointElement) && endpointElement.ValueKind == JsonValueKind.Object
            ? endpointElement
            : default;

        var ip = GetString(endpoint, "ip") ?? GetString(raw, "ip") ?? "";
        var queryPort = GetInt(endpoint, "port", GetInt(raw, "queryPort", GetInt(raw, "port", 2302)));
        var gamePort = GetInt(raw, "gamePort", GetInt(raw, "game_port", queryPort));
        var name = GetString(raw, "name")?.Trim() ?? "";
        var mods = ParseMods(raw);
        var map = GetString(raw, "map") ?? GetString(raw, "mission") ?? DayZMaps.DetectFromServerName(name);
        var isOfficial = string.Equals(GetString(raw, "shard"), "public", StringComparison.OrdinalIgnoreCase) && mods.Count == 0;
        var serverTime = GetString(raw, "time");
        var hasClock = IsClock(serverTime);

        return new DayZServer
        {
            Id = StableServerId(ip, queryPort),
            Source = "dzsa",
            Name = name,
            DisplayName = GetString(raw, "displayName")?.Trim() ?? "",
            Ip = ip,
            Port = gamePort,
            GamePort = gamePort,
            QueryPort = queryPort,
            Map = DayZMaps.ToKey(map),
            MapName = DayZMaps.ToDisplayName(map),
            Players = GetInt(raw, "players", 0),
            MaxPlayers = GetInt(raw, "maxPlayers", GetInt(raw, "max_players", 0)),
            Ping = 0,
            Version = GetString(raw, "version") ?? "",
            Online = true,
            Password = GetBool(raw, "password", false),
            Vac = GetBool(raw, "vac", true),
            Perspective = GetBool(raw, "firstPersonOnly", false) ? "1pp" : "3pp",
            Time = hasClock ? serverTime! : "-",
            ServerTime = hasClock ? serverTime! : "",
            Mode = isOfficial ? "official" : mods.Count > 0 ? "modded" : "community",
            Mods = mods.Select(mod => mod.Name).ToList(),
            ModIds = mods.Select(mod => mod.Id).ToList(),
            ModsSource = mods.Count > 0 ? "dzsa" : "",
            Hive = isOfficial ? "Public" : "Private",
            Battleye = GetBool(raw, "battlEye", GetBool(raw, "battleye", true)),
            Profile = GetBool(raw, "profile", false),
            StaticName = GetBool(raw, "nameOverride", GetBool(raw, "name_override", false)),
            Sponsor = GetBool(raw, "sponsor", false),
            SponsorPriority = GetInt(raw, "sponsorPriority", 0)
        };
    }

    /// <summary>Сервер пригоден для показа: есть адрес, порты и осмысленное имя.</summary>
    /// <remarks>Фейковые "зеркала" отфильтровывает API (см. $mirrorSubnets в server/api/servers.php).</remarks>
    public static bool IsUsable(DayZServer server) =>
        !string.IsNullOrWhiteSpace(server.Ip)
        && server.QueryPort > 0
        && server.GamePort > 0
        && HasMeaningfulName(server.Name);

    /// <summary>
    /// Моды приходят либо массивом объектов { steamWorkshopId, name }, либо
    /// парой массивов modIds + mods (строки с именами).
    /// </summary>
    private static List<ServerMod> ParseMods(JsonElement raw)
    {
        var ids = new List<string>();
        var names = new List<string>();
        var mods = new List<ServerMod>();

        if (raw.TryGetProperty("modIds", out var modIdsJson) && modIdsJson.ValueKind == JsonValueKind.Array)
        {
            ids.AddRange(modIdsJson.EnumerateArray()
                .Select(x => x.ValueKind == JsonValueKind.String ? x.GetString() ?? "" : x.GetRawText())
                .Where(Format.IsWorkshopId));
        }

        if (raw.TryGetProperty("mods", out var modsJson) && modsJson.ValueKind == JsonValueKind.Array)
        {
            foreach (var mod in modsJson.EnumerateArray())
            {
                if (mod.ValueKind == JsonValueKind.Object)
                {
                    var id = GetString(mod, "steamWorkshopId") ?? GetString(mod, "steam_workshop_id") ?? GetString(mod, "workshopId") ?? GetString(mod, "id");
                    if (!Format.IsWorkshopId(id))
                        continue;

                    var name = GetString(mod, "name")?.Trim();
                    mods.Add(new ServerMod(id!, string.IsNullOrWhiteSpace(name) ? $"Workshop {id}" : name));
                }
                else if (mod.ValueKind == JsonValueKind.String)
                {
                    names.Add(mod.GetString()?.Trim() ?? "");
                }
            }
        }

        if (mods.Count > 0)
            return mods;

        for (var i = 0; i < ids.Count; i++)
        {
            var name = i < names.Count && !string.IsNullOrWhiteSpace(names[i]) ? names[i] : $"Workshop {ids[i]}";
            mods.Add(new ServerMod(ids[i], name));
        }

        return mods;
    }

    private static bool HasMeaningfulName(string name) =>
        !string.IsNullOrWhiteSpace(name)
        && !string.Equals(name.Trim(), "Unknown", StringComparison.OrdinalIgnoreCase)
        && name.Any(char.IsLetter);

    private static bool IsClock(string? value) =>
        value is { Length: 5 }
        && value[2] == ':'
        && int.TryParse(value[..2], out var hours) && hours is >= 0 and <= 23
        && int.TryParse(value[3..], out var minutes) && minutes is >= 0 and <= 59;

    /// <summary>Стабильный ID сервера (совпадает с stableId() в server/api/servers.php).</summary>
    private static long StableServerId(string ip, int queryPort) =>
        1_000_000_000L + Crc32($"dzsa:{ip}:{queryPort}") % 1_000_000_000L;

    private static uint Crc32(string text)
    {
        var crc = 0xFFFFFFFFu;
        foreach (var b in Encoding.UTF8.GetBytes(text))
        {
            crc ^= b;
            for (var i = 0; i < 8; i++)
                crc = (crc & 1) != 0 ? 0xEDB88320 ^ (crc >> 1) : crc >> 1;
        }

        return crc ^ 0xFFFFFFFF;
    }

    private static List<JsonElement> CloneObjects(JsonElement array) =>
        array.EnumerateArray().Where(x => x.ValueKind == JsonValueKind.Object).Select(x => x.Clone()).ToList();

    private static string? GetString(JsonElement element, string name)
    {
        if (element.ValueKind != JsonValueKind.Object || !element.TryGetProperty(name, out var property))
            return null;

        return property.ValueKind switch
        {
            JsonValueKind.String => property.GetString(),
            JsonValueKind.Number => property.GetRawText(),
            JsonValueKind.True => "true",
            JsonValueKind.False => "false",
            _ => null
        };
    }

    private static int GetInt(JsonElement element, string name, int fallback) =>
        int.TryParse(GetString(element, name), out var value) ? value : fallback;

    private static bool GetBool(JsonElement element, string name, bool fallback)
    {
        if (element.ValueKind != JsonValueKind.Object || !element.TryGetProperty(name, out var property))
            return fallback;

        return property.ValueKind switch
        {
            JsonValueKind.True => true,
            JsonValueKind.False => false,
            _ => bool.TryParse(GetString(element, name), out var value) ? value : fallback
        };
    }
}
