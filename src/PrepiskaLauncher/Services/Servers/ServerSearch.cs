using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Services.Servers;

/// <summary>Нечёткий поиск по названию/адресу сервера и сортировка результатов.</summary>
public static class ServerSearch
{
    private static readonly char[] TokenSeparators =
        [' ', '|', '[', ']', '(', ')', '-', '_', '+', '/', '\\', ':', ';', ',', '.', '#', '!', '?', '\t', '\r', '\n'];

    public static List<DayZServer> FilterAndSort(IEnumerable<DayZServer> servers, string? search, int? limit)
    {
        var query = search?.Trim() ?? "";
        IEnumerable<DayZServer> result;

        if (query.Length == 0)
        {
            result = servers
                .OrderByDescending(s => s.Sponsor)
                .ThenByDescending(s => s.Players)
                .ThenByDescending(s => s.MaxPlayers)
                .ThenBy(SortName, StringComparer.OrdinalIgnoreCase);
        }
        else
        {
            result = servers
                .Where(s => Matches(s, query))
                .OrderByDescending(s => s.Sponsor)
                .ThenBy(s => Rank(s, query))
                .ThenByDescending(s => s.Players > 0)
                .ThenByDescending(s => s.Players)
                .ThenBy(s => s.Ping)
                .ThenBy(SortName, StringComparer.OrdinalIgnoreCase);
        }

        if (limit is > 0)
            result = result.Take(limit.Value);

        return result.ToList();
    }

    public static string SortName(DayZServer server) =>
        string.IsNullOrWhiteSpace(server.Name) ? server.Ip : server.Name.Trim();

    private static bool Matches(DayZServer server, string query)
    {
        if (server.Name.Contains(query, StringComparison.OrdinalIgnoreCase))
            return true;

        if (LooksLikeEndpoint(query) && $"{server.Ip}:{server.QueryPort}".Contains(query, StringComparison.OrdinalIgnoreCase))
            return true;

        var queryTokens = Tokenize(query);
        if (queryTokens.Count == 0)
            return true;

        var nameTokens = Tokenize(server.Name);
        return queryTokens.All(token => nameTokens.Any(candidate => TokenMatches(candidate, token)));
    }

    /// <summary>Чем меньше — тем релевантнее: точное совпадение, префикс, подстрока, токены, опечатки.</summary>
    private static int Rank(DayZServer server, string query)
    {
        var name = server.Name.Trim().ToLowerInvariant();
        var normalizedQuery = query.ToLowerInvariant();

        if (name == normalizedQuery) return 0;
        if (name.StartsWith(normalizedQuery, StringComparison.Ordinal)) return 1;
        if (name.Contains(normalizedQuery, StringComparison.Ordinal)) return 2;

        var queryTokens = Tokenize(normalizedQuery);
        if (queryTokens.Count == 0)
            return 9;

        var nameTokens = Tokenize(name);
        if (queryTokens.All(nameTokens.Contains)) return 3;
        if (queryTokens.All(token => nameTokens.Any(candidate => candidate.StartsWith(token, StringComparison.Ordinal)))) return 4;
        if (queryTokens.All(token => nameTokens.Any(candidate => TokenMatches(candidate, token)))) return 5;

        return 9;
    }

    private static bool LooksLikeEndpoint(string query) =>
        query.Any(char.IsDigit) && query.All(c => char.IsDigit(c) || c is '.' or ':' or ' ' or '\t');

    /// <summary>Подстрока, либо (для слов от 5 символов) расстояние Левенштейна 1–2.</summary>
    private static bool TokenMatches(string candidate, string token)
    {
        if (candidate.Contains(token, StringComparison.OrdinalIgnoreCase))
            return true;

        if (token.Length < 5 || candidate.Length < 5)
            return false;

        var maxDistance = token.Length <= 8 ? 1 : 2;
        return LevenshteinDistanceAtMost(candidate, token, maxDistance);
    }

    private static List<string> Tokenize(string text) =>
        text.ToLowerInvariant()
            .Split(TokenSeparators, StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Distinct(StringComparer.Ordinal)
            .ToList();

    private static bool LevenshteinDistanceAtMost(string left, string right, int maxDistance)
    {
        if (Math.Abs(left.Length - right.Length) > maxDistance)
            return false;

        var previous = new int[right.Length + 1];
        var current = new int[right.Length + 1];
        for (var j = 0; j <= right.Length; j++)
            previous[j] = j;

        for (var i = 1; i <= left.Length; i++)
        {
            current[0] = i;
            var rowMin = current[0];
            for (var j = 1; j <= right.Length; j++)
            {
                var cost = left[i - 1] == right[j - 1] ? 0 : 1;
                current[j] = Math.Min(Math.Min(current[j - 1] + 1, previous[j] + 1), previous[j - 1] + cost);
                rowMin = Math.Min(rowMin, current[j]);
            }

            // Раннее завершение: вся строка уже хуже порога.
            if (rowMin > maxDistance)
                return false;

            (previous, current) = (current, previous);
        }

        return previous[right.Length] <= maxDistance;
    }
}
