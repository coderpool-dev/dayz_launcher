using System.Xml.Linq;
using PrepiskaLauncher.Core;

namespace PrepiskaLauncher.Services;

/// <summary>
/// Опция «Включить QE»: переназначает наклоны влево/вправо на Q/E в пользовательских
/// пресетах управления DayZ (Documents\DayZ\*preset_User.xml).
/// </summary>
public static class LeanKeybindService
{
    private const string LeanLeftInput = "UALeanLeftGamepad";
    private const string LeanRightInput = "UALeanRightGamepad";
    private const string KeyQ = "kQ";
    private const string KeyE = "kE";
    private const string DefaultLeftButton = "x1ShoulderLeft";
    private const string DefaultRightButton = "x1ShoulderRight";

    /// <returns>Количество изменённых файлов пресетов.</returns>
    public static int Apply(bool enabled)
    {
        var leftButton = enabled ? KeyQ : DefaultLeftButton;
        var rightButton = enabled ? KeyE : DefaultRightButton;
        var changedFiles = 0;

        foreach (var file in PresetFiles())
        {
            try
            {
                var document = XDocument.Load(file, LoadOptions.PreserveWhitespace);
                var changed = SetSingleButton(document, LeanLeftInput, leftButton);
                changed |= SetSingleButton(document, LeanRightInput, rightButton);
                if (!changed)
                    continue;

                document.Save(file, SaveOptions.DisableFormatting);
                changedFiles++;
            }
            catch (Exception ex)
            {
                Log.Write($"QE preset update failed file='{file}' error={ex}");
            }
        }

        return changedFiles;
    }

    /// <returns>true/false по пресетам на диске, либо null, если ни один пресет не удалось прочитать.</returns>
    public static bool? DetectEnabled()
    {
        var parsedAnyPreset = false;
        foreach (var file in PresetFiles())
        {
            try
            {
                var document = XDocument.Load(file);
                parsedAnyPreset = true;

                if (ButtonNames(document, LeanLeftInput).Contains(KeyQ) && ButtonNames(document, LeanRightInput).Contains(KeyE))
                    return true;
            }
            catch (Exception ex)
            {
                Log.Write($"QE preset detect failed file='{file}' error={ex}");
            }
        }

        return parsedAnyPreset ? false : null;
    }

    private static XElement? FindInput(XDocument document, string inputName) =>
        document.Descendants("input")
            .FirstOrDefault(input => string.Equals((string?)input.Attribute("name"), inputName, StringComparison.Ordinal));

    private static HashSet<string> ButtonNames(XDocument document, string inputName) =>
        FindInput(document, inputName)?.Elements("btn")
            .Select(button => (string?)button.Attribute("name") ?? "")
            .Where(name => !string.IsNullOrWhiteSpace(name))
            .ToHashSet(StringComparer.Ordinal)
        ?? [];

    private static bool SetSingleButton(XDocument document, string inputName, string buttonName)
    {
        var input = FindInput(document, inputName);
        if (input is null)
            return false;

        var buttons = input.Elements("btn").ToList();
        if (buttons.Count == 1 && string.Equals((string?)buttons[0].Attribute("name"), buttonName, StringComparison.Ordinal))
            return false;

        foreach (var button in buttons)
            button.Remove();

        input.Add(new XElement("btn", new XAttribute("name", buttonName)));
        return true;
    }

    private static IEnumerable<string> PresetFiles()
    {
        foreach (var directory in DayZDocumentFolders())
        {
            if (!Directory.Exists(directory))
                continue;

            List<string> files;
            try
            {
                files = Directory.EnumerateFiles(directory, "*preset_User.xml", SearchOption.TopDirectoryOnly).ToList();
            }
            catch
            {
                continue;
            }

            foreach (var file in files)
                yield return file;
        }
    }

    private static IEnumerable<string> DayZDocumentFolders()
    {
        var userProfile = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
        return new[]
        {
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "DayZ"),
            Path.Combine(userProfile, "OneDrive", "Документы", "DayZ"),
            Path.Combine(userProfile, "OneDrive", "Documents", "DayZ"),
            Path.Combine(userProfile, "Documents", "DayZ")
        }.Distinct(StringComparer.OrdinalIgnoreCase);
    }
}
