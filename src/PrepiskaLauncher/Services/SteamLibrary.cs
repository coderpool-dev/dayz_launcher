using System.Text.RegularExpressions;
using Microsoft.Win32;
using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Services;

/// <summary>Поиск установленного Steam, его библиотек, DayZ, Workshop-модов и профиля пользователя.</summary>
public sealed class SteamLibrary
{
    public const int DayZAppId = 221100;
    private static readonly string DayZAppIdText = DayZAppId.ToString();

    private static readonly Regex LibraryPathPattern = new("\"path\"\\s+\"([^\"]+)\"", RegexOptions.IgnoreCase);
    private static readonly Regex LegacyLibraryPathPattern = new("\"\\d+\"\\s+\"([A-Za-z]:\\\\[^\"]+)\"");
    private static readonly Regex LoginUserBlockPattern = new("\"(?<id>\\d{15,20})\"\\s*\\{(?<body>.*?)\\}", RegexOptions.Singleline);

    /// <summary>Корневые папки всех библиотек Steam (основная + из libraryfolders.vdf).</summary>
    public IReadOnlyList<string> LibraryRoots()
    {
        var steamRoots = new List<string>();
        foreach (var value in new[]
        {
            ReadRegistryValue(Registry.CurrentUser, @"Software\Valve\Steam", "SteamPath"),
            ReadRegistryValue(Registry.CurrentUser, @"Software\Valve\Steam", "SteamExe"),
            ReadRegistryValue(Registry.LocalMachine, @"SOFTWARE\WOW6432Node\Valve\Steam", "InstallPath"),
            ReadRegistryValue(Registry.LocalMachine, @"SOFTWARE\Valve\Steam", "InstallPath"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), "Steam"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "Steam")
        })
        {
            if (string.IsNullOrWhiteSpace(value))
                continue;

            var path = value.Replace('/', '\\');
            if (Path.GetFileName(path).Equals("steam.exe", StringComparison.OrdinalIgnoreCase))
                path = Path.GetDirectoryName(path)!;

            steamRoots.Add(path);
        }

        var libraries = new List<string>();
        foreach (var root in steamRoots)
        {
            libraries.Add(root);
            libraries.AddRange(ParseLibraryFolders(Path.Combine(root, "steamapps", "libraryfolders.vdf")));
        }

        return libraries.Where(Directory.Exists).Distinct(StringComparer.OrdinalIgnoreCase).ToList();
    }

    /// <summary>Папка установки DayZ или null, если игра не найдена.</summary>
    public string? FindDayZInstallPath()
    {
        var candidates = new List<string>();
        var fromEnvironment = Environment.GetEnvironmentVariable("DAYZ_PATH") ?? Environment.GetEnvironmentVariable("DAYZ_INSTALL_PATH");
        if (!string.IsNullOrWhiteSpace(fromEnvironment))
            candidates.Add(fromEnvironment);

        foreach (var uninstallKey in new[]
        {
            $@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\Steam App {DayZAppId}",
            $@"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\Steam App {DayZAppId}"
        })
        {
            var installLocation = ReadRegistryValue(Registry.LocalMachine, uninstallKey, "InstallLocation")
                ?? ReadRegistryValue(Registry.CurrentUser, uninstallKey, "InstallLocation");
            if (!string.IsNullOrWhiteSpace(installLocation))
                candidates.Add(installLocation);
        }

        foreach (var root in LibraryRoots())
            candidates.Add(Path.Combine(root, "steamapps", "common", "DayZ"));

        return candidates.Distinct(StringComparer.OrdinalIgnoreCase).FirstOrDefault(IsDayZInstallFolder);
    }

    /// <summary>Возможные папки мода: готовый контент и незавершённая загрузка во всех библиотеках.</summary>
    public IEnumerable<string> WorkshopModFolders(string modId)
    {
        foreach (var root in LibraryRoots())
        {
            yield return WorkshopContentFolder(root, modId);
            yield return WorkshopDownloadFolder(root, modId);
        }
    }

    /// <summary>Папки workshop/content/221100 во всех библиотеках.</summary>
    public IEnumerable<string> WorkshopContentRoots() =>
        LibraryRoots().Select(root => Path.Combine(root, "steamapps", "workshop", "content", DayZAppIdText));

    /// <summary>
    /// Мод полностью скачан: в content есть .pbo и в downloads не осталось незавершённых файлов.
    /// </summary>
    public bool IsWorkshopModReady(string modId, IReadOnlyList<string>? libraryRoots = null)
    {
        foreach (var root in libraryRoots ?? LibraryRoots())
        {
            try
            {
                var addons = Path.Combine(WorkshopContentFolder(root, modId), "addons");
                if (!Directory.Exists(addons) || !Directory.EnumerateFiles(addons, "*.pbo", SearchOption.AllDirectories).Any())
                    continue;

                var download = WorkshopDownloadFolder(root, modId);
                if (Directory.Exists(download) && Directory.EnumerateFiles(download, "*", SearchOption.AllDirectories).Any())
                    continue;

                return true;
            }
            catch (IOException) { }
            catch (UnauthorizedAccessException) { }
        }

        return false;
    }

    /// <summary>Последний активный профиль Steam на этом компьютере.</summary>
    public SteamProfile? FindProfile()
    {
        foreach (var root in LibraryRoots())
        {
            var profile = ParseLoginUsers(Path.Combine(root, "config", "loginusers.vdf"), root);
            if (profile is not null)
                return profile;
        }

        return null;
    }

    private static string WorkshopContentFolder(string libraryRoot, string modId) =>
        Path.Combine(libraryRoot, "steamapps", "workshop", "content", DayZAppIdText, modId);

    private static string WorkshopDownloadFolder(string libraryRoot, string modId) =>
        Path.Combine(libraryRoot, "steamapps", "workshop", "downloads", DayZAppIdText, modId);

    private static bool IsDayZInstallFolder(string folder) =>
        Directory.Exists(folder)
        && (File.Exists(Path.Combine(folder, "DayZ_x64.exe")) || File.Exists(Path.Combine(folder, "DayZ_BE.exe")));

    private static string? ReadRegistryValue(RegistryKey hive, string subKey, string valueName)
    {
        try
        {
            return hive.OpenSubKey(subKey)?.GetValue(valueName)?.ToString();
        }
        catch
        {
            return null;
        }
    }

    private static IEnumerable<string> ParseLibraryFolders(string vdfPath)
    {
        if (!File.Exists(vdfPath))
            yield break;

        string text;
        try { text = File.ReadAllText(vdfPath); }
        catch { yield break; }

        foreach (Match match in LibraryPathPattern.Matches(text))
            yield return match.Groups[1].Value.Replace("\\\\", "\\");

        foreach (Match match in LegacyLibraryPathPattern.Matches(text))
            yield return match.Groups[1].Value.Replace("\\\\", "\\");
    }

    private static SteamProfile? ParseLoginUsers(string loginUsersPath, string steamRoot)
    {
        if (!File.Exists(loginUsersPath))
            return null;

        string text;
        try { text = File.ReadAllText(loginUsersPath); }
        catch { return null; }

        var bestScore = int.MinValue;
        SteamProfile? best = null;
        foreach (Match block in LoginUserBlockPattern.Matches(text))
        {
            var body = block.Groups["body"].Value;
            var personaName = ReadVdfString(body, "PersonaName");
            if (string.IsNullOrWhiteSpace(personaName))
                continue;

            var score = 0;
            if (ReadVdfString(body, "MostRecent") == "1") score += 4;
            if (ReadVdfString(body, "AllowAutoLogin") == "1") score += 2;
            if (!string.IsNullOrWhiteSpace(ReadVdfString(body, "Timestamp"))) score += 1;

            if (score > bestScore)
            {
                var steamId = block.Groups["id"].Value;
                bestScore = score;
                best = new SteamProfile(steamId, personaName, FindAvatarPath(steamRoot, steamId));
            }
        }

        return best;
    }

    private static string ReadVdfString(string text, string key)
    {
        var match = Regex.Match(text, $"\"{Regex.Escape(key)}\"\\s+\"(?<value>[^\"]*)\"", RegexOptions.IgnoreCase);
        return match.Success ? match.Groups["value"].Value : "";
    }

    private static string? FindAvatarPath(string steamRoot, string steamId)
    {
        var avatarCache = Path.Combine(steamRoot, "config", "avatarcache");
        if (!Directory.Exists(avatarCache))
            return null;

        foreach (var pattern in new[] { $"{steamId}*.*", $"{steamId[3..]}*.*" })
        {
            var avatar = Directory.EnumerateFiles(avatarCache, pattern)
                .Where(path => path.EndsWith(".png", StringComparison.OrdinalIgnoreCase)
                    || path.EndsWith(".jpg", StringComparison.OrdinalIgnoreCase)
                    || path.EndsWith(".jpeg", StringComparison.OrdinalIgnoreCase))
                .OrderByDescending(File.GetLastWriteTimeUtc)
                .FirstOrDefault();

            if (avatar is not null)
                return avatar;
        }

        return null;
    }
}
