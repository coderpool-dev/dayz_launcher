using System.Text.Json.Serialization;
using PrepiskaLauncher.Core;
using PrepiskaLauncher.Models;
using PrepiskaLauncher.Services.Mods;
using static PrepiskaLauncher.Bridge.DtoNames;

namespace PrepiskaLauncher.Bridge;

/// <summary>
/// Снимок состояния, который отправляется в UI через <c>CoreWebView2.PostWebMessageAsJson</c>.
/// Сериализуется в camelCase.
/// </summary>
public sealed record UiState
{
    public required bool Busy { get; init; }
    public required bool WaitingForSteamDownloads { get; init; }
    public required string Status { get; init; }
    public required string Search { get; init; }
    public required int Limit { get; init; }
    public required string PlayerName { get; init; }
    public required bool QeLeanEnabled { get; init; }
    public required string ModSource { get; init; }
    public required ProfileDto Profile { get; init; }
    public required long? SelectedId { get; init; }
    public required SelectedServerDto? Selected { get; init; }
    public required IReadOnlyList<ServerDto> FavoriteServers { get; init; }
    public required IReadOnlyList<ServerDto> HistoryServers { get; init; }
    public required IReadOnlyList<InstalledModDto> InstalledMods { get; init; }

    /// <summary>Увеличивается при изменении списка серверов, чтобы UI перерисовывал его только при необходимости.</summary>
    public required int ServersRevision { get; init; }

    /// <summary>Доступное обновление лаунчера; null — версия актуальна.</summary>
    public UpdateDto? Update { get; init; }

    /// <summary>Полный список серверов; передаётся только когда он изменился.</summary>
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public IReadOnlyList<ServerDto>? Servers { get; init; }
}

public sealed record ProfileDto(string SteamName, string DisplayName, string AvatarUri)
{
    public static ProfileDto From(SteamProfile? steamProfile, string playerName)
    {
        var avatarPath = steamProfile?.AvatarPath;
        var avatarUri = !string.IsNullOrWhiteSpace(avatarPath) && File.Exists(avatarPath) ? new Uri(avatarPath).AbsoluteUri : "";
        return new ProfileDto(steamProfile?.PersonaName ?? "", playerName, avatarUri);
    }
}

public sealed record ServerDto(
    long Id,
    string Name,
    string DisplayName,
    string Ip,
    int Port,
    string Map,
    string MapName,
    int Players,
    int MaxPlayers,
    int Ping,
    string Perspective,
    int ModsCount,
    bool Sponsor,
    bool Favorite,
    bool Unavailable)
{
    public static ServerDto From(DayZServer server, bool isFavorite) => new(
        server.Id,
        server.Name,
        DisplayNameOf(server),
        server.Ip,
        server.Port,
        server.Map,
        server.MapName,
        server.Players,
        server.MaxPlayers,
        server.Ping,
        server.Perspective,
        server.ModIds.Count,
        server.Sponsor,
        isFavorite,
        Unavailable: string.IsNullOrWhiteSpace(server.Ip));
}

public sealed record SelectedServerDto(
    long Id,
    string Name,
    string DisplayName,
    string Ip,
    int Port,
    string Map,
    string MapName,
    int Players,
    int MaxPlayers,
    string Perspective,
    string ModsSource,
    bool Favorite,
    IReadOnlyList<ModDto> Mods)
{
    public static SelectedServerDto From(DayZServer server, bool isFavorite) => new(
        server.Id,
        server.Name,
        DisplayNameOf(server),
        server.Ip,
        server.Port,
        server.Map,
        server.MapName,
        server.Players,
        server.MaxPlayers,
        server.Perspective,
        server.ModsSource,
        isFavorite,
        server.ModIds.Select((id, index) => new ModDto(id, server.ModNameAt(index))).ToList());
}

public sealed record ModDto(string Id, string Name);

public sealed record UpdateDto(string Version, string Notes);

public sealed record InstalledModDto(
    string Id,
    string Name,
    string Path,
    string Size,
    string State,
    string Message,
    bool NeedsUpdate,
    bool IsDownloading,
    bool IsDownloadPending,
    double Percent,
    string UpdatedAt)
{
    public static InstalledModDto From(InstalledModStatus mod) => new(
        mod.Id,
        mod.Name,
        mod.Path,
        Format.Bytes(mod.SizeBytes),
        mod.State,
        mod.Message,
        mod.NeedsUpdate,
        mod.IsDownloading,
        mod.IsDownloadPending,
        mod.Percent,
        mod.UpdatedAt.ToLocalTime().ToString("dd.MM.yyyy HH:mm"));
}

internal static class DtoNames
{
    /// <summary>Короткое имя от API, если оно есть, иначе полное название сервера.</summary>
    public static string DisplayNameOf(DayZServer server) =>
        string.IsNullOrWhiteSpace(server.DisplayName) ? server.Name : server.DisplayName;
}