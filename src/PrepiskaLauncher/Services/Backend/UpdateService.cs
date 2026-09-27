using System.Diagnostics;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text.Json.Serialization;
using PrepiskaLauncher.Core;

namespace PrepiskaLauncher.Services.Backend;

/// <summary>Доступное обновление лаунчера (версия, загруженная через админку).</summary>
public sealed record UpdateInfo(string Version, string Notes, Uri Url, string Sha256, long Size);

/// <summary>
/// Проверяет, есть ли новая версия лаунчера, скачивает установщик, сверяет SHA-256
/// и запускает его в тихом режиме. После установки установщик сам перезапускает лаунчер.
/// </summary>
public sealed class UpdateService(BackendClient backend)
{
    /// <summary>
    /// /SILENT — установщик показывает только окно прогресса; /CLOSEAPPLICATIONS — закрывает
    /// лаунчер, если он ещё не завершился. Перезапуск лаунчера описан в [Run] установщика.
    /// </summary>
    private const string InstallerArguments = "/SILENT /SUPPRESSMSGBOXES /NORESTART /CLOSEAPPLICATIONS";

    public async Task<UpdateInfo?> CheckAsync(CancellationToken ct = default)
    {
        try
        {
            var url = backend.Url($"api/launcher/update?version={Uri.EscapeDataString(AppInfo.Version)}");
            var response = await backend.Http.GetFromJsonAsync<UpdateResponse>(url, ct);
            if (response is not { UpdateAvailable: true } || !IsNewer(response.LatestVersion, AppInfo.Version))
                return null;

            if (!Uri.TryCreate(response.Url, UriKind.Absolute, out var downloadUrl) || !IsTrustedDownloadUrl(downloadUrl)
                || string.IsNullOrWhiteSpace(response.Sha256))
            {
                Log.Write($"Update REJECTED url='{response.Url}'");
                return null;
            }

            return new UpdateInfo(response.LatestVersion!, response.Notes ?? "", downloadUrl, response.Sha256.ToLowerInvariant(), response.Size);
        }
        catch (Exception ex) when (ex is not OperationCanceledException || !ct.IsCancellationRequested)
        {
            Log.Write("Update CHECK ERROR " + ex.Message);
            return null;
        }
    }

    /// <summary>Скачивает установщик во временную папку и проверяет его контрольную сумму.</summary>
    /// <returns>Путь к проверенному установщику.</returns>
    public async Task<string> DownloadAsync(UpdateInfo update, IProgress<double>? progress, CancellationToken ct = default)
    {
        var directory = Path.Combine(Path.GetTempPath(), "PrepiskaLauncherUpdate");
        Directory.CreateDirectory(directory);
        var path = Path.Combine(directory, $"PREPISKA-DayZ-Launcher-Setup-{update.Version}.exe");

        using (var response = await backend.Http.GetAsync(update.Url, HttpCompletionOption.ResponseHeadersRead, ct))
        {
            response.EnsureSuccessStatusCode();
            var total = response.Content.Headers.ContentLength ?? update.Size;

            await using var source = await response.Content.ReadAsStreamAsync(ct);
            await using var target = File.Create(path);
            var buffer = new byte[81920];
            long received = 0;
            int read;
            while ((read = await source.ReadAsync(buffer, ct)) > 0)
            {
                await target.WriteAsync(buffer.AsMemory(0, read), ct);
                received += read;
                if (total > 0)
                    progress?.Report((double)received / total);
            }
        }

        var actualHash = await ComputeSha256Async(path, ct);
        if (!string.Equals(actualHash, update.Sha256, StringComparison.OrdinalIgnoreCase))
        {
            File.Delete(path);
            throw new InvalidOperationException("Контрольная сумма обновления не совпала — файл повреждён или подменён.");
        }

        return path;
    }

    public static void StartInstaller(string installerPath)
    {
        Process.Start(new ProcessStartInfo(installerPath, InstallerArguments) { UseShellExecute = true });
    }

    /// <summary>true, если <paramref name="candidate"/> — более новая версия, чем <paramref name="current"/>.</summary>
    public static bool IsNewer(string? candidate, string current) =>
        Version.TryParse(candidate, out var candidateVersion)
        && Version.TryParse(current, out var currentVersion)
        && candidateVersion > currentVersion;

    public static async Task<string> ComputeSha256Async(string path, CancellationToken ct = default)
    {
        await using var stream = File.OpenRead(path);
        return Convert.ToHexString(await SHA256.HashDataAsync(stream, ct)).ToLowerInvariant();
    }

    /// <summary>Установщик скачивается только по HTTPS с того же сервера (или по HTTP с локального — для разработки).</summary>
    private bool IsTrustedDownloadUrl(Uri url) =>
        string.Equals(url.Host, backend.BaseUri.Host, StringComparison.OrdinalIgnoreCase)
        && (url.Scheme == Uri.UriSchemeHttps || url.IsLoopback);

    private sealed record UpdateResponse(
        [property: JsonPropertyName("updateAvailable")] bool UpdateAvailable,
        [property: JsonPropertyName("latestVersion")] string? LatestVersion,
        [property: JsonPropertyName("notes")] string? Notes,
        [property: JsonPropertyName("url")] string? Url,
        [property: JsonPropertyName("sha256")] string? Sha256,
        [property: JsonPropertyName("size")] long Size);
}
