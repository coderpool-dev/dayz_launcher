using System.Net.Http.Json;
using PrepiskaLauncher.Core;
using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Services.Backend;

/// <summary>
/// Отправляет на сервер события для статистики в админке: запуск лаунчера,
/// «лаунчер открыт» (раз в несколько минут, для онлайна) и нажатие «Играть».
/// Ошибки сети игнорируются — статистика не должна мешать игре.
/// </summary>
public sealed class LauncherTelemetry(BackendClient backend)
{
    public const string Start = "start";
    public const string Heartbeat = "heartbeat";
    public const string Play = "play";

    private static readonly TimeSpan RequestTimeout = TimeSpan.FromSeconds(10);

    /// <summary>Отправляет событие в фоне, не дожидаясь ответа.</summary>
    public void Report(string eventType, DayZServer? server = null)
    {
        _ = SendAsync(eventType, server);
    }

    private async Task SendAsync(string eventType, DayZServer? server)
    {
        var payload = new Dictionary<string, string>
        {
            ["event"] = eventType,
            ["version"] = AppInfo.Version
        };

        if (server is not null)
        {
            payload["serverId"] = server.Id.ToString();
            payload["serverName"] = server.Name;
            payload["serverAddress"] = $"{server.Ip}:{(server.GamePort > 0 ? server.GamePort : server.Port)}";
        }

        try
        {
            using var cts = new CancellationTokenSource(RequestTimeout);
            using var response = await backend.Http.PostAsJsonAsync(backend.Url("api/launcher/events"), payload, cts.Token);
            if (!response.IsSuccessStatusCode)
                Log.Write($"Telemetry {eventType} HTTP {(int)response.StatusCode}");
        }
        catch (Exception ex)
        {
            Log.Write($"Telemetry {eventType} ERROR {ex.Message}");
        }
    }
}
