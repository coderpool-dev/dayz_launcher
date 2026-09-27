using System.Text.Json;

namespace PrepiskaLauncher.Bridge;

/// <summary>Сообщение из UI: <c>window.chrome.webview.postMessage({ type, ...payload })</c>.</summary>
public sealed class WebMessage
{
    private readonly JsonElement _root;

    private WebMessage(JsonElement root, string type)
    {
        _root = root;
        Type = type;
    }

    public string Type { get; }

    public static WebMessage Parse(string json)
    {
        using var document = JsonDocument.Parse(json);
        var root = document.RootElement.Clone();
        var type = root.ValueKind == JsonValueKind.Object && root.TryGetProperty("type", out var typeElement) && typeElement.ValueKind == JsonValueKind.String
            ? typeElement.GetString() ?? ""
            : "";

        return new WebMessage(root, type);
    }

    public string GetString(string property, string fallback = "") =>
        TryGet(property, out var value) && value.ValueKind == JsonValueKind.String
            ? value.GetString() ?? fallback
            : fallback;

    public int GetInt(string property)
    {
        if (!TryGet(property, out var value))
            return 0;

        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number))
            return number;

        if (value.ValueKind == JsonValueKind.String && int.TryParse(value.GetString(), out number))
            return number;

        return 0;
    }

    public long? GetLong(string property)
    {
        if (!TryGet(property, out var value))
            return null;

        if (value.ValueKind == JsonValueKind.Number && value.TryGetInt64(out var number))
            return number;

        if (value.ValueKind == JsonValueKind.String && long.TryParse(value.GetString(), out number))
            return number;

        return null;
    }

    public bool GetBool(string property)
    {
        if (!TryGet(property, out var value))
            return false;

        if (value.ValueKind is JsonValueKind.True or JsonValueKind.False)
            return value.GetBoolean();

        return value.ValueKind == JsonValueKind.String && bool.TryParse(value.GetString(), out var result) && result;
    }

    private bool TryGet(string property, out JsonElement value)
    {
        if (_root.ValueKind == JsonValueKind.Object)
            return _root.TryGetProperty(property, out value);

        value = default;
        return false;
    }
}
