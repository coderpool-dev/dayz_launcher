using System.Text.Json;
using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Core;

/// <summary>Пользовательские настройки, сохраняемые в settings.json.</summary>
/// <remarks>Имена свойств являются форматом файла — не переименовывать.</remarks>
public sealed class AppSettings
{
    private static readonly JsonSerializerOptions JsonOptions = new() { WriteIndented = true };

    public string PlayerName { get; set; } = PlayerNames.Default;
    public string ModSource { get; set; } = ModSources.Auto;
    public bool QeLeanEnabled { get; set; }
    public List<long> Favorites { get; set; } = [];
    public List<long> History { get; set; } = [];
    public List<DayZServer> SavedServers { get; set; } = [];

    /// <summary>Workshop ID, которые оказались недоступны, по ID сервера.</summary>
    public Dictionary<long, List<string>> UnavailableWorkshopIds { get; set; } = [];

    public static AppSettings Load()
    {
        try
        {
            if (File.Exists(AppPaths.SettingsFile))
                return JsonSerializer.Deserialize<AppSettings>(File.ReadAllText(AppPaths.SettingsFile)) ?? new AppSettings();
        }
        catch (Exception ex)
        {
            Log.Write("Settings LOAD ERROR " + ex.Message);
        }

        return new AppSettings();
    }

    public void Save()
    {
        Directory.CreateDirectory(AppPaths.DataDirectory);
        File.WriteAllText(AppPaths.SettingsFile, JsonSerializer.Serialize(this, JsonOptions));
    }
}

/// <summary>Откуда брать список модов сервера.</summary>
public static class ModSources
{
    /// <summary>Из API, а если там пусто — через A2S.</summary>
    public const string Auto = "auto";

    /// <summary>Только из API списка серверов.</summary>
    public const string Api = "api";

    /// <summary>Всегда запрашивать у самого сервера через A2S.</summary>
    public const string A2s = "a2s";

    public static string Normalize(string? value)
    {
        if (string.Equals(value, A2s, StringComparison.OrdinalIgnoreCase))
            return A2s;

        if (string.Equals(value, Api, StringComparison.OrdinalIgnoreCase))
            return Api;

        return Auto;
    }
}
