using System.Text.Json;
using Steamworks;
using Steamworks.Data;
using Steamworks.Ugc;

namespace WorkshopHelper;

/// <summary>
/// Консольный помощник лаунчера для работы со Steam Workshop через Steamworks.
///
/// Использование: WorkshopHelper.exe status|subscribe|verify|unsubscribe &lt;workshopId&gt; [workshopId...]
/// Результат печатается одной строкой JSON: { ok, action, count, results: [...] } или { ok: false, error }.
/// Коды выхода: 0 — успех, 1 — ошибка по одному из модов, 2 — Steam недоступен, 64 — неверные аргументы.
/// </summary>
public static class Program
{
    private const uint DayZAppId = 221100;
    private const int ExitOk = 0;
    private const int ExitItemFailed = 1;
    private const int ExitSteamUnavailable = 2;
    private const int ExitUsage = 64;

    /// <summary>Таймаут запроса метаданных Workshop-предмета, в секундах.</summary>
    private const int ItemQueryMaxAgeSeconds = 1800;

    private static readonly string[] Commands = ["status", "subscribe", "verify", "unsubscribe"];

    private static readonly JsonSerializerOptions JsonOptions = new() { PropertyNamingPolicy = JsonNamingPolicy.CamelCase };

    public static async Task<int> Main(string[] args)
    {
        if (args.Length < 1)
            return Fail($"Usage: WorkshopHelper.exe {string.Join('|', Commands)} <workshopId> [workshopId...]", ExitUsage);

        var command = args[0].Trim().ToLowerInvariant();
        if (!Commands.Contains(command))
            return Fail("Unknown command. Use status, subscribe, verify or unsubscribe.", ExitUsage);

        if (args.Length < 2)
            return Fail("No Workshop IDs provided.", ExitUsage);

        var ids = new List<ulong>();
        foreach (var raw in args.Skip(1))
        {
            if (!ulong.TryParse(raw, out var id) || id == 0)
                return Fail($"Invalid Workshop id: {raw}", ExitUsage);

            ids.Add(id);
        }

        var results = new List<WorkshopItemResult>();
        var exitCode = ExitOk;

        try
        {
            SteamClient.Init(DayZAppId, asyncCallbacks: true);
            SteamClient.RunCallbacks();

            foreach (var id in ids.Distinct())
            {
                try
                {
                    results.Add(command switch
                    {
                        "subscribe" => await SubscribeAsync(id),
                        "verify" => await RequestDownloadAsync(id, "verify"),
                        "unsubscribe" => await UnsubscribeAsync(id),
                        _ => await LoadStatusAsync(id, "status")
                    });
                }
                catch (Exception ex)
                {
                    results.Add(new WorkshopItemResult(id, false, command, ex.Message));
                    exitCode = ExitItemFailed;
                }
            }
        }
        catch (Exception ex)
        {
            PrintJson(new
            {
                ok = false,
                error = "Не удалось подключиться к Steam. Запустите Steam, войдите в аккаунт и проверьте, что DayZ установлен.",
                details = ex.Message
            });
            return ExitSteamUnavailable;
        }
        finally
        {
            try { SteamClient.Shutdown(); } catch { }
        }

        PrintJson(new { ok = exitCode == ExitOk, action = command, count = results.Count, results });
        return exitCode;
    }

    private static async Task<WorkshopItemResult> SubscribeAsync(ulong id)
    {
        var item = await Item.GetAsync(ToFileId(id), ItemQueryMaxAgeSeconds);
        if (!item.HasValue)
            return NotFound(id, "subscribe");

        if (item.Value.IsSubscribed)
            return await LoadStatusAsync(id, "subscribe");

        await item.Value.Subscribe();
        await PumpCallbacksAsync(2500);
        return await RequestDownloadAsync(id, "subscribe");
    }

    private static async Task<WorkshopItemResult> UnsubscribeAsync(ulong id)
    {
        var item = await Item.GetAsync(ToFileId(id), ItemQueryMaxAgeSeconds);
        if (!item.HasValue)
            return NotFound(id, "unsubscribe");

        if (!item.Value.IsSubscribed)
            return FromItem(id, "unsubscribe", item.Value, message: "Already unsubscribed");

        await item.Value.Unsubscribe();
        for (var attempt = 0; attempt < 5; attempt++)
        {
            await PumpCallbacksAsync(1000);
            var status = await LoadStatusAsync(id, "unsubscribe");
            if (status.Ok && !status.IsSubscribed)
                return status with { Message = "Unsubscribed" };
        }

        return new WorkshopItemResult(id, false, "unsubscribe", "Steam did not confirm unsubscribe", IsSubscribed: true);
    }

    /// <summary>
    /// Просит Steam скачать (или перепроверить) мод. Если через ~8 секунд загрузка так и не
    /// началась — отправляет запрос повторно: Steam иногда игнорирует первый.
    /// </summary>
    private static async Task<WorkshopItemResult> RequestDownloadAsync(ulong id, string action)
    {
        var item = await Item.GetAsync(ToFileId(id), ItemQueryMaxAgeSeconds);
        if (!item.HasValue)
            return NotFound(id, action);

        var accepted = true;
        if (!item.Value.IsDownloading && !item.Value.IsDownloadPending)
            accepted = SteamUGC.Download(ToFileId(id), highPriority: true);

        await PumpCallbacksAsync(8000);
        var result = await LoadStatusAsync(id, action);

        if (LooksStuck(result))
        {
            accepted &= SteamUGC.Download(ToFileId(id), highPriority: true);
            await PumpCallbacksAsync(10000);
            result = await LoadStatusAsync(id, action);
            return result with
            {
                Ok = accepted && result.Ok,
                Message = result.Message + " / second download kick sent"
            };
        }

        return result with { Ok = accepted && result.Ok };
    }

    private static async Task<WorkshopItemResult> LoadStatusAsync(ulong id, string action)
    {
        SteamClient.RunCallbacks();
        var item = await Item.GetAsync(ToFileId(id), ItemQueryMaxAgeSeconds);
        SteamClient.RunCallbacks();
        return item.HasValue ? FromItem(id, action, item.Value) : NotFound(id, action);
    }

    private static bool LooksStuck(WorkshopItemResult result) =>
        result.Ok
        && !result.IsDownloading
        && result.BytesDownloaded == 0
        && result.State is "pending" or "subscribed" or "needs_update";

    private static async Task PumpCallbacksAsync(int milliseconds)
    {
        var until = DateTime.UtcNow.AddMilliseconds(milliseconds);
        while (DateTime.UtcNow < until)
        {
            SteamClient.RunCallbacks();
            await Task.Delay(250);
        }

        SteamClient.RunCallbacks();
    }

    private static WorkshopItemResult FromItem(ulong id, string action, Item item, string? message = null)
    {
        var downloaded = item.IsDownloading ? Convert.ToUInt64(item.DownloadBytesDownloaded) : 0UL;
        var total = item.IsDownloading ? Convert.ToUInt64(item.DownloadBytesTotal) : Convert.ToUInt64(Math.Max(item.SizeBytes, 0));
        var percent = total > 0 ? Math.Round((double)downloaded / total * 100.0, 2) : 0.0;
        var state = GetState(item);

        message ??= state switch
        {
            "downloading" => "Downloading",
            "pending" => "Download pending / verifying",
            "needs_update" => "Needs update",
            "installed" => "Installed",
            "subscribed" => "Subscribed",
            _ => "Not subscribed"
        };

        return new WorkshopItemResult(
            Id: id,
            Ok: true,
            Action: action,
            Message: message,
            Name: item.Title,
            State: state,
            BytesDownloaded: downloaded,
            BytesTotal: total,
            Percent: percent,
            SizeBytes: item.SizeBytes,
            Path: item.Directory,
            IsSubscribed: item.IsSubscribed,
            NeedsUpdate: item.NeedsUpdate,
            IsDownloading: item.IsDownloading,
            IsDownloadPending: item.IsDownloadPending);
    }

    private static string GetState(Item item)
    {
        if (item.IsDownloading) return "downloading";
        if (item.IsDownloadPending) return "pending";
        if (item.NeedsUpdate) return "needs_update";
        if (item.IsSubscribed && !string.IsNullOrWhiteSpace(item.Directory) && Directory.Exists(item.Directory)) return "installed";
        if (item.IsSubscribed) return "subscribed";
        return "not_subscribed";
    }

    private static WorkshopItemResult NotFound(ulong id, string action) =>
        new(id, false, action, "Workshop item not found");

    private static PublishedFileId ToFileId(ulong id) => new() { Value = id };

    private static int Fail(string error, int exitCode)
    {
        PrintJson(new { ok = false, error });
        return exitCode;
    }

    private static void PrintJson(object value) =>
        Console.WriteLine(JsonSerializer.Serialize(value, JsonOptions));
}

/// <summary>Результат по одному моду. Сериализуется в camelCase — это контракт с лаунчером.</summary>
public sealed record WorkshopItemResult(
    ulong Id,
    bool Ok,
    string Action,
    string Message,
    string? Name = null,
    string? State = null,
    ulong BytesDownloaded = 0,
    ulong BytesTotal = 0,
    double Percent = 0,
    long SizeBytes = 0,
    string? Path = null,
    bool IsSubscribed = false,
    bool NeedsUpdate = false,
    bool IsDownloading = false,
    bool IsDownloadPending = false);
