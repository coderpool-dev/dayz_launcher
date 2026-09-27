using System.Diagnostics;
using PrepiskaLauncher.Core;
using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Services;

/// <summary>Запуск DayZ с подключением к серверу и нужными модами.</summary>
public sealed class GameLauncher(SteamLibrary steam, WorkshopService workshop)
{
    /// <summary>Папка внутри DayZ, где создаются junction-ссылки @ИмяМода на папки Workshop.</summary>
    private const string ModLinksFolderName = "!dayzlauncher";

    /// <summary>
    /// Запускает DayZ напрямую. Если сервер без модов и прямой запуск не удался —
    /// пробует запуск через steam:// (моды таким способом передать нельзя).
    /// </summary>
    public async Task<OperationResult> LaunchAsync(DayZServer server, string playerName)
    {
        var canFallBackToSteam = server.ModIds.Count == 0;
        try
        {
            var direct = await LaunchDirectAsync(server, playerName);
            if (direct.Ok || !canFallBackToSteam)
                return direct;

            var viaSteam = LaunchViaSteam(server, playerName);
            return viaSteam.Ok
                ? OperationResult.Success($"Запущено через Steam URL. Причина: {direct.Message}")
                : viaSteam;
        }
        catch (Exception ex)
        {
            if (canFallBackToSteam && LaunchViaSteam(server, playerName).Ok)
                return OperationResult.Success($"Запущено через Steam URL. Ошибка прямого запуска: {ex.Message}");

            return OperationResult.Failure(ex.Message);
        }
    }

    /// <summary>Уже запущен DayZ_x64 или только что стартовал DayZ_BE (BattlEye-загрузчик).</summary>
    public static bool IsGameRunning()
    {
        foreach (var processName in new[] { "DayZ_x64", "DayZ_BE" })
        {
            foreach (var process in Process.GetProcessesByName(processName))
            {
                using (process)
                {
                    try
                    {
                        if (!process.HasExited
                            && (processName == "DayZ_x64" || DateTime.Now - process.StartTime < TimeSpan.FromMinutes(2)))
                            return true;
                    }
                    catch (System.ComponentModel.Win32Exception) { }
                    catch (InvalidOperationException) { }
                }
            }
        }

        return false;
    }

    private async Task<OperationResult> LaunchDirectAsync(DayZServer server, string playerName)
    {
        var dayzPath = steam.FindDayZInstallPath();
        if (dayzPath is null)
            return OperationResult.Failure("DayZ не найден. Укажите переменную DAYZ_PATH с папкой игры.");

        var battlEyeExe = Path.Combine(dayzPath, "DayZ_BE.exe");
        var gameExe = File.Exists(battlEyeExe) ? battlEyeExe : Path.Combine(dayzPath, "DayZ_x64.exe");
        if (!File.Exists(gameExe))
            return OperationResult.Failure($"Исполняемый файл DayZ не найден в {dayzPath}");

        var args = new List<string>();
        if (gameExe == battlEyeExe)
            args.AddRange(["0", "1", "1", "-exe", "DayZ_x64.exe"]);

        if (server.ModIds.Count > 0)
        {
            var modPaths = await PrepareModPathsAsync(dayzPath, server);
            if (modPaths.Count > 0)
                args.Add($"-mod={string.Join(';', modPaths)}");
        }

        args.AddRange(ConnectArguments(server, playerName));

        Process.Start(new ProcessStartInfo
        {
            FileName = gameExe,
            WorkingDirectory = dayzPath,
            UseShellExecute = false,
            CreateNoWindow = true,
            Arguments = string.Join(' ', args.Select(QuoteArgument))
        });

        return OperationResult.Success("DayZ запущен.");
    }

    private static OperationResult LaunchViaSteam(DayZServer server, string playerName)
    {
        var args = string.Join(' ', ConnectArguments(server, playerName));
        var url = $"steam://run/{SteamLibrary.DayZAppId}//{Uri.EscapeDataString(args)}";
        Process.Start(new ProcessStartInfo { FileName = url, UseShellExecute = true });
        return OperationResult.Success(url);
    }

    private static string[] ConnectArguments(DayZServer server, string playerName)
    {
        var gamePort = server.GamePort > 0 ? server.GamePort : server.Port;
        var queryPort = server.QueryPort > 0 ? server.QueryPort : gamePort;
        return
        [
            $"-name={PlayerNames.Normalize(playerName)}",
            $"-connect={server.Ip}:{gamePort}:{queryPort}"
        ];
    }

    /// <summary>
    /// Создаёт junction-ссылки с читаемыми именами на папки модов и возвращает пути для -mod=.
    /// </summary>
    private async Task<List<string>> PrepareModPathsAsync(string dayzPath, DayZServer server)
    {
        var status = await workshop.RunAsync(WorkshopAction.Status, server.ModIds, TimeSpan.FromMinutes(5));
        if (!status.Ok)
            throw new InvalidOperationException(status.Error ?? "Не удалось запросить статус Workshop.");

        var requestedIds = server.ModIds.Where(id => !string.IsNullOrWhiteSpace(id)).ToHashSet(StringComparer.Ordinal);
        var returnedIds = status.Results.Select(item => item.Id).ToHashSet(StringComparer.Ordinal);
        if (!requestedIds.IsSubsetOf(returnedIds))
            throw new InvalidOperationException("Steam не вернул статус для всех модов сервера.");

        var linksRoot = Path.Combine(dayzPath, ModLinksFolderName);
        Directory.CreateDirectory(linksRoot);

        var libraryRoots = steam.LibraryRoots();
        var modPaths = new List<string>();

        // Сервера перечисляют зависимые моды раньше базовых (например, "PODPIVAS Lite" перед
        // "PODPIVAS" и CF), а DayZ должен загружать базовые моды первыми — поэтому идём с конца.
        foreach (var item in status.Results.AsEnumerable().Reverse())
        {
            if (!requestedIds.Contains(item.Id))
                continue;

            if (string.IsNullOrWhiteSpace(item.Path) || !steam.IsWorkshopModReady(item.Id, libraryRoots))
                throw new InvalidOperationException($"Мод {item.Name} ({item.Id}) ещё загружается в Steam.");

            var displayName = !string.IsNullOrWhiteSpace(item.Name)
                ? item.Name
                : server.ModNameAt(server.ModIds.IndexOf(item.Id)) is { Length: > 0 } serverName ? serverName : $"mod_{item.Id}";

            var link = Path.Combine(linksRoot, ModFolderName(displayName, item.Id));
            CreateJunction(link, item.Path);
            modPaths.Add(Directory.Exists(link) ? link : item.Path);
        }

        return modPaths;
    }

    private static void CreateJunction(string link, string target)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(link)!);
        if (Directory.Exists(link))
        {
            try { Directory.Delete(link); }
            catch { RunHidden("cmd", $"/c rmdir \"{link}\""); }
        }

        var exitCode = RunHidden("cmd", $"/c mklink /J \"{link}\" \"{target}\"");
        if (exitCode != 0 && !Directory.Exists(link))
            throw new InvalidOperationException("mklink /J не смог создать junction для мода.");
    }

    private static int RunHidden(string fileName, string arguments)
    {
        using var process = Process.Start(new ProcessStartInfo
        {
            FileName = fileName,
            Arguments = arguments,
            CreateNoWindow = true,
            UseShellExecute = false
        });
        process?.WaitForExit(5000);
        return process?.ExitCode ?? -1;
    }

    private static string ModFolderName(string name, string modId)
    {
        var invalidChars = Path.GetInvalidFileNameChars().Concat(['<', '>', ':', '"', '/', '\\', '|', '?', '*']).ToHashSet();
        var cleaned = new string(name.Select(ch => invalidChars.Contains(ch) || char.IsControl(ch) ? '_' : ch).ToArray())
            .Trim()
            .TrimEnd('.', ' ');

        if (string.IsNullOrWhiteSpace(cleaned))
            cleaned = $"mod_{modId}";

        if (!cleaned.StartsWith('@'))
            cleaned = "@" + cleaned;

        return cleaned.Length > 120 ? cleaned[..120] : cleaned;
    }

    private static string QuoteArgument(string value) =>
        value.Contains(' ') || value.Contains(';') ? $"\"{value.Replace("\"", "\\\"")}\"" : value;
}
