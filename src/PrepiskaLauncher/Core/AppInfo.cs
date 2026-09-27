using System.Reflection;

namespace PrepiskaLauncher.Core;

public static class AppInfo
{
    public const string ProductName = "PREPISKA DayZ Launcher";

    /// <summary>Версия из свойства &lt;Version&gt; в PrepiskaLauncher.csproj (без хэша коммита).</summary>
    public static string Version { get; } =
        typeof(AppInfo).Assembly.GetCustomAttribute<AssemblyInformationalVersionAttribute>()?.InformationalVersion.Split('+')[0]
        ?? "0.0.0";
}
