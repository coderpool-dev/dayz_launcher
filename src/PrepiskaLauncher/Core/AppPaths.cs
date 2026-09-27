namespace PrepiskaLauncher.Core;

/// <summary>Пути к файлам и папкам лаунчера.</summary>
public static class AppPaths
{
    // Имя папки оставлено прежним, чтобы у существующих пользователей сохранились настройки и кэш.
    private const string DataFolderName = "PREPISKA DayZ Launcher Native";

    public static string DataDirectory { get; } = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
        DataFolderName);

    public static string SettingsFile => Path.Combine(DataDirectory, "settings.json");
    public static string LogFile => Path.Combine(DataDirectory, "debug.log");
    public static string LauncherIdFile => Path.Combine(DataDirectory, "launcher-guid.txt");

    /// <summary>Отфильтрованный и нормализованный список серверов, из которого читает UI.</summary>
    public static string ServerCacheFile => Path.Combine(DataDirectory, "servers-cache.json");

    /// <summary>Сырой ответ API серверов (используется как резерв, если API недоступно).</summary>
    public static string RawServerCacheFile => Path.Combine(DataDirectory, "dayz-servers.json");

    public static string UiIndexFile => Path.Combine(AppContext.BaseDirectory, "Ui", "index.html");
}
