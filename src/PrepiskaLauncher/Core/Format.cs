namespace PrepiskaLauncher.Core;

public static class Format
{
    private static readonly string[] SizeUnits = ["B", "KB", "MB", "GB", "TB"];

    public static string Bytes(long bytes)
    {
        if (bytes <= 0)
            return "0 B";

        var value = (double)bytes;
        var unit = 0;
        while (value >= 1024 && unit < SizeUnits.Length - 1)
        {
            value /= 1024;
            unit++;
        }

        return $"{value:0.##} {SizeUnits[unit]}";
    }

    public static bool IsWorkshopId(string? value) =>
        !string.IsNullOrWhiteSpace(value) && value.All(char.IsDigit);
}
