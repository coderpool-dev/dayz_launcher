namespace PrepiskaLauncher.Models;

/// <summary>Профиль Steam, найденный в loginusers.vdf.</summary>
public sealed record SteamProfile(string SteamId, string PersonaName, string? AvatarPath);
