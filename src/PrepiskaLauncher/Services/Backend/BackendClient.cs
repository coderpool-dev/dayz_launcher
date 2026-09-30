using System.Net;
using System.Net.Http.Headers;
using PrepiskaLauncher.Core;

namespace PrepiskaLauncher.Services.Backend;

/// <summary>
/// HTTP-клиент к серверу проекта (server/): список серверов, статистика, обновления.
/// Каждый запрос несёт анонимный ID установки (x-launcher-guid) и версию лаунчера.
/// </summary>
public sealed class BackendClient
{
    private const string DefaultBaseUrl = "https://dayz.sonetcord.ru";

    /// <summary>Переменная окружения для подмены адреса сервера (например, локальный сервер при разработке).</summary>
    private const string BaseUrlVariable = "PREPISKA_API_URL";

    public BackendClient()
    {
        Directory.CreateDirectory(AppPaths.DataDirectory);

        var urlOverride = Environment.GetEnvironmentVariable(BaseUrlVariable);
        BaseUri = new Uri((string.IsNullOrWhiteSpace(urlOverride) ? DefaultBaseUrl : urlOverride.Trim()).TrimEnd('/') + "/");
        LauncherId = LoadOrCreateLauncherId();

        // Ответы API — мегабайты JSON; со сжатием они в разы меньше.
        Http = new HttpClient(new HttpClientHandler { AutomaticDecompression = DecompressionMethods.All })
        {
            Timeout = TimeSpan.FromSeconds(45)
        };
        Http.DefaultRequestHeaders.UserAgent.ParseAdd($"PrepiskaLauncher/{AppInfo.Version}");
        Http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        Http.DefaultRequestHeaders.TryAddWithoutValidation("x-launcher-guid", LauncherId);
        Http.DefaultRequestHeaders.TryAddWithoutValidation("x-launcher-version", AppInfo.Version);
    }

    public HttpClient Http { get; }

    public Uri BaseUri { get; }

    /// <summary>Анонимный ID установки (GUID в %AppData%).</summary>
    public string LauncherId { get; }

    public Uri Url(string relativePath) => new(BaseUri, relativePath.TrimStart('/'));

    private static string LoadOrCreateLauncherId()
    {
        try
        {
            if (File.Exists(AppPaths.LauncherIdFile))
            {
                var existing = File.ReadAllText(AppPaths.LauncherIdFile).Trim();
                if (Guid.TryParse(existing, out _))
                    return existing;
            }

            var created = Guid.NewGuid().ToString();
            File.WriteAllText(AppPaths.LauncherIdFile, created);
            return created;
        }
        catch
        {
            return Guid.NewGuid().ToString();
        }
    }
}
