using System.Diagnostics;
using System.Text.Json;
using PrepiskaLauncher.Core;
using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Services;

public enum WorkshopAction
{
    Status,
    Subscribe,
    Verify,
    Unsubscribe
}

/// <summary>
/// Работа со Steam Workshop через WorkshopHelper.exe. Steamworks вызывается в отдельном
/// процессе, чтобы не держать SteamClient инициализированным внутри лаунчера.
/// </summary>
public sealed class WorkshopService(SteamLibrary steam)
{
    private const string HelperFileName = "WorkshopHelper.exe";
    private static readonly JsonSerializerOptions JsonOptions = new() { PropertyNameCaseInsensitive = true };

    public async Task<WorkshopResult> RunAsync(WorkshopAction action, IEnumerable<string> modIds, TimeSpan timeout)
    {
        var ids = modIds.Select(id => id.Trim()).Where(Format.IsWorkshopId).Distinct().ToList();
        if (ids.Count == 0)
            return WorkshopResult.Fail("Не переданы Workshop ID модов.");

        var helperPath = FindHelper();
        if (helperPath is null)
            return WorkshopResult.Fail($"{HelperFileName} не найден. Положите его рядом с приложением или задайте WORKSHOP_HELPER_PATH.");

        var command = action.ToString().ToLowerInvariant();
        var startInfo = new ProcessStartInfo
        {
            FileName = helperPath,
            WorkingDirectory = Path.GetDirectoryName(helperPath)!,
            Arguments = string.Join(' ', ids.Prepend(command).Select(value => $"\"{value}\"")),
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true
        };

        using var timeoutCts = new CancellationTokenSource(timeout);
        try
        {
            using var process = Process.Start(startInfo)!;
            var stdoutTask = process.StandardOutput.ReadToEndAsync(timeoutCts.Token);
            var stderrTask = process.StandardError.ReadToEndAsync(timeoutCts.Token);
            await process.WaitForExitAsync(timeoutCts.Token);
            var stdout = await stdoutTask;
            var stderr = await stderrTask;

            // Хелпер печатает результат последней строкой JSON.
            var lastLine = stdout.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries).LastOrDefault() ?? "{}";
            var result = JsonSerializer.Deserialize<WorkshopResult>(lastLine, JsonOptions) ?? new WorkshopResult();
            result.Ok = process.ExitCode == 0 && result.Ok;
            if (!result.Ok && string.IsNullOrWhiteSpace(result.Error) && !string.IsNullOrWhiteSpace(stderr))
                result.Error = stderr.Trim();

            return result;
        }
        catch (OperationCanceledException)
        {
            return WorkshopResult.Fail("WorkshopHelper не ответил вовремя.");
        }
        catch (Exception ex)
        {
            return WorkshopResult.Fail(ex.Message);
        }
    }

    /// <summary>Все моды DayZ, лежащие в папках Workshop, от новых к старым.</summary>
    public List<InstalledWorkshopMod> GetInstalledMods()
    {
        var mods = new List<InstalledWorkshopMod>();
        foreach (var contentRoot in steam.WorkshopContentRoots())
        {
            if (!Directory.Exists(contentRoot))
                continue;

            foreach (var modFolder in Directory.EnumerateDirectories(contentRoot))
            {
                var modId = Path.GetFileName(modFolder);
                if (Format.IsWorkshopId(modId))
                    mods.Add(new InstalledWorkshopMod(modId, modFolder, Directory.GetLastWriteTimeUtc(modFolder)));
            }
        }

        return mods
            .DistinctBy(mod => mod.Id, StringComparer.Ordinal)
            .OrderByDescending(mod => mod.UpdatedAt)
            .ToList();
    }

    /// <summary>Отписывается от мода в Steam и удаляет его файлы с диска.</summary>
    public async Task<OperationResult> DeleteModAsync(string modId)
    {
        modId = modId.Trim();
        if (!Format.IsWorkshopId(modId))
            return OperationResult.Failure("Некорректный Workshop ID.");

        var unsubscribe = await RunAsync(WorkshopAction.Unsubscribe, [modId], TimeSpan.FromMinutes(2));
        var item = unsubscribe.Results.FirstOrDefault(x => x.Id == modId);
        if (!unsubscribe.Ok || item is null || !item.Ok || item.IsSubscribed)
        {
            var reason = unsubscribe.Error ?? item?.Message ?? "нет ответа Steam";
            return OperationResult.Failure("Steam не подтвердил отписку от мода. Файлы оставлены: " + reason);
        }

        var deletedFolders = 0;
        var errors = new List<string>();
        foreach (var folder in steam.WorkshopModFolders(modId).Distinct(StringComparer.OrdinalIgnoreCase))
        {
            if (!Directory.Exists(folder) || !IsWorkshopModFolder(folder, modId))
                continue;

            try
            {
                ClearReadOnlyAttributes(folder);
                Directory.Delete(folder, recursive: true);
                deletedFolders++;
            }
            catch (Exception ex)
            {
                errors.Add(ex.Message);
            }
        }

        if (errors.Count > 0)
            return OperationResult.Failure("Не удалось удалить папку мода: " + string.Join("; ", errors.Distinct()));

        return OperationResult.Success(deletedFolders > 0
            ? $"Мод {modId} удалён. Папок удалено: {deletedFolders}."
            : $"Отписка от мода {modId} подтверждена Steam. Папка уже отсутствует.");
    }

    private static string? FindHelper()
    {
        var fromEnvironment = Environment.GetEnvironmentVariable("WORKSHOP_HELPER_PATH");
        if (!string.IsNullOrWhiteSpace(fromEnvironment) && File.Exists(fromEnvironment))
            return fromEnvironment;

        var nextToLauncher = Path.Combine(AppContext.BaseDirectory, HelperFileName);
        return File.Exists(nextToLauncher) ? nextToLauncher : null;
    }

    /// <summary>
    /// Защита от удаления лишнего: путь обязан иметь вид
    /// ...\workshop\(content|downloads)\221100\{modId}.
    /// </summary>
    private static bool IsWorkshopModFolder(string path, string modId)
    {
        var fullPath = Path.GetFullPath(path).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        if (!string.Equals(Path.GetFileName(fullPath), modId, StringComparison.OrdinalIgnoreCase))
            return false;

        var appFolder = Directory.GetParent(fullPath)?.FullName;
        if (appFolder is null || !string.Equals(Path.GetFileName(appFolder), SteamLibrary.DayZAppId.ToString(), StringComparison.OrdinalIgnoreCase))
            return false;

        var kindFolder = Directory.GetParent(appFolder)?.FullName;
        var kind = Path.GetFileName(kindFolder ?? "");
        if (!string.Equals(kind, "content", StringComparison.OrdinalIgnoreCase) && !string.Equals(kind, "downloads", StringComparison.OrdinalIgnoreCase))
            return false;

        var workshopFolder = Directory.GetParent(kindFolder!)?.FullName;
        return string.Equals(Path.GetFileName(workshopFolder ?? ""), "workshop", StringComparison.OrdinalIgnoreCase);
    }

    private static void ClearReadOnlyAttributes(string root)
    {
        try
        {
            foreach (var entry in Directory.EnumerateFileSystemEntries(root, "*", SearchOption.AllDirectories).Append(root))
            {
                try { File.SetAttributes(entry, File.GetAttributes(entry) & ~FileAttributes.ReadOnly); }
                catch { }
            }
        }
        catch { }
    }
}
