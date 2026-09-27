namespace PrepiskaLauncher.Core;

/// <summary>Простой потокобезопасный файловый лог (%AppData%\...\debug.log).</summary>
public static class Log
{
    private static readonly object Sync = new();

    public static void Write(string message)
    {
        try
        {
            var line = $"{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff} | {message}{Environment.NewLine}";
            lock (Sync)
            {
                Directory.CreateDirectory(AppPaths.DataDirectory);
                File.AppendAllText(AppPaths.LogFile, line);
            }
        }
        catch
        {
            // Логирование никогда не должно ронять приложение.
        }
    }
}
