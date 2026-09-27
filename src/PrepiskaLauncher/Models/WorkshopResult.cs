using System.Globalization;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace PrepiskaLauncher.Models;

/// <summary>Ответ WorkshopHelper.exe (одна JSON-строка в stdout).</summary>
public sealed class WorkshopResult
{
    [JsonPropertyName("ok")]
    public bool Ok { get; set; }

    [JsonPropertyName("error")]
    public string? Error { get; set; }

    [JsonPropertyName("results")]
    public List<WorkshopItem> Results { get; set; } = [];

    public static WorkshopResult Fail(string error) => new() { Ok = false, Error = error };
}

/// <summary>Состояние одного Workshop-мода по данным Steam.</summary>
public sealed class WorkshopItem
{
    [JsonPropertyName("ok")]
    public bool Ok { get; set; }

    [JsonPropertyName("id")]
    [JsonConverter(typeof(FlexibleStringJsonConverter))]
    public string Id { get; set; } = "";

    [JsonPropertyName("name")]
    public string Name { get; set; } = "";

    /// <summary>downloading, pending, needs_update, installed, subscribed или not_subscribed.</summary>
    [JsonPropertyName("state")]
    public string State { get; set; } = "";

    [JsonPropertyName("message")]
    public string Message { get; set; } = "";

    [JsonPropertyName("path")]
    public string? Path { get; set; }

    [JsonPropertyName("bytesDownloaded")]
    public long BytesDownloaded { get; set; }

    [JsonPropertyName("bytesTotal")]
    public long BytesTotal { get; set; }

    [JsonPropertyName("sizeBytes")]
    public long SizeBytes { get; set; }

    [JsonPropertyName("percent")]
    public double Percent { get; set; }

    [JsonPropertyName("isSubscribed")]
    public bool IsSubscribed { get; set; }

    [JsonPropertyName("needsUpdate")]
    public bool NeedsUpdate { get; set; }

    [JsonPropertyName("isDownloading")]
    public bool IsDownloading { get; set; }

    [JsonPropertyName("isDownloadPending")]
    public bool IsDownloadPending { get; set; }
}

/// <summary>Читает JSON-значение любого примитивного типа как строку (Workshop ID приходит числом).</summary>
public sealed class FlexibleStringJsonConverter : JsonConverter<string>
{
    public override string? Read(ref Utf8JsonReader reader, Type typeToConvert, JsonSerializerOptions options)
    {
        switch (reader.TokenType)
        {
            case JsonTokenType.String:
                return reader.GetString();

            case JsonTokenType.Number:
                if (reader.TryGetInt64(out var longValue))
                    return longValue.ToString(CultureInfo.InvariantCulture);

                if (reader.TryGetDecimal(out var decimalValue))
                    return decimalValue.ToString(CultureInfo.InvariantCulture);

                return reader.GetDouble().ToString(CultureInfo.InvariantCulture);

            case JsonTokenType.True:
                return "true";

            case JsonTokenType.False:
                return "false";

            case JsonTokenType.Null:
                return null;

            default:
                using (var document = JsonDocument.ParseValue(ref reader))
                    return document.RootElement.ToString();
        }
    }

    public override void Write(Utf8JsonWriter writer, string value, JsonSerializerOptions options) =>
        writer.WriteStringValue(value);
}
