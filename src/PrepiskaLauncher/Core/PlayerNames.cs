namespace PrepiskaLauncher.Core;

public static class PlayerNames
{
    public const string Default = "Survivor";
    private const int MaxLength = 32;

    /// <summary>Приводит ник к виду, безопасному для аргумента командной строки DayZ.</summary>
    public static string Normalize(string? value)
    {
        var source = string.IsNullOrWhiteSpace(value) ? Default : value.Trim();
        var normalized = new string(source
            .Where(c => c is not ('\r' or '\n' or '"'))
            .Take(MaxLength)
            .ToArray());

        return string.IsNullOrWhiteSpace(normalized) ? Default : normalized;
    }
}
