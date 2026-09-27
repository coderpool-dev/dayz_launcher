using System.Net.Sockets;
using System.Text;
using PrepiskaLauncher.Models;

namespace PrepiskaLauncher.Services;

/// <summary>
/// Получает список модов напрямую с игрового сервера через Steam A2S_RULES.
/// DayZ кладёт туда бинарный блок A3SB со списком модов.
/// </summary>
public sealed class A2sRulesService
{
    private const byte RulesRequestHeader = 0x56;
    private const byte ChallengeResponseHeader = 0x41;
    private const byte RulesResponseHeader = 0x45;
    private const byte A3sbVersion = 2;

    /// <summary>Workshop ID DayZ-модов — девятизначные и больше.</summary>
    private const ulong MinWorkshopId = 100_000_000;

    private static readonly TimeSpan CacheTtl = TimeSpan.FromMinutes(5);
    private static readonly TimeSpan QueryTimeout = TimeSpan.FromMilliseconds(1150);

    private readonly Dictionary<string, (DateTimeOffset ReceivedAt, List<ServerMod> Mods)> _cache = new();

    public async Task<List<ServerMod>> QueryModsAsync(string ip, int queryPort, CancellationToken ct, bool forceRefresh = false)
    {
        var key = $"{ip}:{queryPort}";
        if (!forceRefresh && _cache.TryGetValue(key, out var cached) && DateTimeOffset.UtcNow - cached.ReceivedAt < CacheTtl)
            return cached.Mods;

        var packet = await ReadRulesPacketAsync(ip, queryPort, QueryTimeout, ct);
        var mods = ParseModsFromRules(packet);
        _cache[key] = (DateTimeOffset.UtcNow, mods);
        return mods;
    }

    private static async Task<byte[]?> ReadRulesPacketAsync(string ip, int queryPort, TimeSpan timeout, CancellationToken externalCt)
    {
        if (string.IsNullOrWhiteSpace(ip) || queryPort <= 0)
            return null;

        using var cts = CancellationTokenSource.CreateLinkedTokenSource(externalCt);
        cts.CancelAfter(timeout);
        using var udp = new UdpClient();
        udp.Connect(ip, queryPort);

        try
        {
            // Первый запрос с challenge = -1; сервер отвечает challenge-номером, который надо повторить.
            await udp.SendAsync(BuildRulesRequest(0xFF, 0xFF, 0xFF, 0xFF), cts.Token);
            while (!cts.IsCancellationRequested)
            {
                var response = (await udp.ReceiveAsync(cts.Token)).Buffer;
                if (response.Length >= 9 && HasSimpleHeader(response, ChallengeResponseHeader))
                {
                    await udp.SendAsync(BuildRulesRequest(response[5], response[6], response[7], response[8]), cts.Token);
                    continue;
                }

                if (response.Length >= 5 && HasSimpleHeader(response, RulesResponseHeader))
                    return response;
            }
        }
        catch
        {
            // Таймаут или недоступный сервер — просто нет данных.
        }

        return null;
    }

    private static byte[] BuildRulesRequest(byte c0, byte c1, byte c2, byte c3) =>
        [0xFF, 0xFF, 0xFF, 0xFF, RulesRequestHeader, c0, c1, c2, c3];

    private static bool HasSimpleHeader(byte[] packet, byte type) =>
        packet[0] == 0xFF && packet[1] == 0xFF && packet[2] == 0xFF && packet[3] == 0xFF && packet[4] == type;

    /// <summary>Разбор ответа A2S_RULES (internal — для тестов).</summary>
    internal static List<ServerMod> ParseModsFromRules(byte[]? packet)
    {
        if (packet is null || packet.Length < 10 || packet[4] != RulesResponseHeader)
            return [];

        var a3sb = ExtractA3sbPayload(packet);
        if (a3sb.Length > 0)
        {
            var mods = ParseCompactA3sbMods(a3sb);
            if (mods.Count == 0)
                mods = ParseLegacyA3sbMods(a3sb);
            if (mods.Count > 0)
                return mods;
        }

        return ScanPacketForMods(packet);
    }

    /// <summary>Склеивает A3SB-данные, разбитые на правила с двухбайтовыми ключами-страницами.</summary>
    private static byte[] ExtractA3sbPayload(byte[] packet)
    {
        var payload = new List<byte>();
        var rulesCount = BitConverter.ToUInt16(packet, 5);
        var pos = 7;

        for (var rule = 0; rule < rulesCount && pos < packet.Length; rule++)
        {
            var key = ReadNullTerminated(packet, ref pos);
            var value = ReadNullTerminated(packet, ref pos);
            if (key is null || value is null)
                break;

            if (key.Length == 2 && key[0] <= key[1])
                AppendUnescapedPage(payload, value);
        }

        return payload.ToArray();
    }

    /// <summary>Компактный формат: версия, 3 байта метаданных, число модов, записи модов.</summary>
    private static List<ServerMod> ParseCompactA3sbMods(byte[] data)
    {
        var pos = 0;
        if (!TryReadByte(data, ref pos, out var version) || version != A3sbVersion)
            return [];

        if (!TrySkip(data, ref pos, 3) || !TryReadByte(data, ref pos, out var modCount))
            return [];

        return ReadModRecords(data, ref pos, modCount);
    }

    /// <summary>Старый формат: версия, флаги, маска DLC + их хэши, число модов, записи модов.</summary>
    private static List<ServerMod> ParseLegacyA3sbMods(byte[] data)
    {
        var pos = 0;
        if (!TryReadByte(data, ref pos, out var version) || version != A3sbVersion)
            return [];

        if (!TrySkip(data, ref pos, 1) || !TryReadUInt16(data, ref pos, out var dlcMask))
            return [];

        if (!TrySkip(data, ref pos, CountBits(dlcMask) * 4) || !TryReadByte(data, ref pos, out var modCount))
            return [];

        return ReadModRecords(data, ref pos, modCount);
    }

    private static List<ServerMod> ReadModRecords(byte[] data, ref int pos, int modCount)
    {
        var mods = new List<ServerMod>();
        var seen = new HashSet<ulong>();
        for (var i = 0; i < modCount; i++)
        {
            if (!TryReadModRecord(data, ref pos, out var modId, out var name))
                return [];

            if (modId >= MinWorkshopId && IsReadableModName(name) && seen.Add(modId))
                mods.Add(new ServerMod(modId.ToString(), name));
        }

        return mods;
    }

    /// <summary>Запись мода: 4 байта хэша, длина ID (1/4/8), ID, длина имени, имя в UTF-8.</summary>
    private static bool TryReadModRecord(byte[] data, ref int pos, out ulong modId, out string name)
    {
        modId = 0;
        name = "";

        if (!TrySkip(data, ref pos, 4) || !TryReadByte(data, ref pos, out var idLength))
            return false;

        switch (idLength)
        {
            case 1:
                if (!TryReadByte(data, ref pos, out var id8)) return false;
                modId = id8;
                break;
            case 4:
                if (!TryReadUInt32(data, ref pos, out var id32)) return false;
                modId = id32;
                break;
            case 8:
                if (!TryReadUInt64(data, ref pos, out modId)) return false;
                break;
            default:
                return false;
        }

        if (!TryReadByte(data, ref pos, out var nameLength) || pos + nameLength > data.Length)
            return false;

        name = Encoding.UTF8.GetString(data, pos, nameLength).Trim();
        pos += nameLength;
        return true;
    }

    /// <summary>Резервный разбор: ищет в пакете записи вида [4 байта][0x04][uint32 ID][длина][имя].</summary>
    private static List<ServerMod> ScanPacketForMods(byte[] packet)
    {
        const int start = 7;
        var endMarkers = new[] { "allowedBuild\0", "clientPort\0", "dedicated\0", "island\0" }
            .Select(marker => IndexOf(packet, Encoding.UTF8.GetBytes(marker), start))
            .Where(index => index > start)
            .ToList();
        var end = endMarkers.Count > 0 ? endMarkers.Min() : packet.Length;

        var mods = new List<ServerMod>();
        var seen = new HashSet<uint>();
        var i = start;
        while (i + 10 <= end)
        {
            if (packet[i + 4] != 4)
            {
                i++;
                continue;
            }

            var modId = BitConverter.ToUInt32(packet, i + 5);
            var nameLength = packet[i + 9];
            if (modId < MinWorkshopId || nameLength < 2 || nameLength > 96 || i + 10 + nameLength > end)
            {
                i++;
                continue;
            }

            var name = Encoding.UTF8.GetString(packet, i + 10, nameLength).Trim();
            if (IsReadableModName(name) && IsLikelyClientModName(name) && seen.Add(modId))
            {
                mods.Add(new ServerMod(modId.ToString(), name));
                i += 10 + nameLength;
                continue;
            }

            i++;
        }

        return mods;
    }

    private static bool IsReadableModName(string name) =>
        name.Length is >= 2 and <= 96
        && !name.Contains('�')
        && name.Any(char.IsLetter)
        && !name.Any(char.IsControl);

    private static bool IsLikelyClientModName(string name)
    {
        var normalized = name.Trim();
        if (normalized.Equals("CF", StringComparison.OrdinalIgnoreCase))
            return true;

        // При побайтовом сканировании после списка модов попадаются имена ключей подписи
        // (короткие, вроде VPP) — их нельзя качать как клиентские моды.
        if (normalized.Length <= 3)
            return false;

        return !normalized.Equals("VPP", StringComparison.OrdinalIgnoreCase)
            && !normalized.Equals("dayz", StringComparison.OrdinalIgnoreCase);
    }

    private static byte[]? ReadNullTerminated(byte[] source, ref int pos)
    {
        if (pos >= source.Length)
            return null;

        var start = pos;
        while (pos < source.Length && source[pos] != 0)
            pos++;

        if (pos >= source.Length)
            return null;

        var result = source[start..pos];
        pos++;
        return result;
    }

    /// <summary>В A3SB-страницах 0x01 0x01 → 0x00, 0x01 0x02 → 0x01.</summary>
    private static void AppendUnescapedPage(List<byte> target, byte[] page)
    {
        for (var i = 0; i < page.Length; i++)
        {
            if (page[i] == 1 && i + 1 < page.Length)
            {
                var next = page[++i];
                target.Add(next switch
                {
                    1 => (byte)0,
                    2 => (byte)1,
                    _ => next
                });
                continue;
            }

            target.Add(page[i]);
        }
    }

    private static bool TryReadByte(byte[] data, ref int pos, out byte value)
    {
        value = 0;
        if (pos >= data.Length)
            return false;

        value = data[pos++];
        return true;
    }

    private static bool TryReadUInt16(byte[] data, ref int pos, out ushort value)
    {
        value = 0;
        if (pos + 2 > data.Length)
            return false;

        value = BitConverter.ToUInt16(data, pos);
        pos += 2;
        return true;
    }

    private static bool TryReadUInt32(byte[] data, ref int pos, out uint value)
    {
        value = 0;
        if (pos + 4 > data.Length)
            return false;

        value = BitConverter.ToUInt32(data, pos);
        pos += 4;
        return true;
    }

    private static bool TryReadUInt64(byte[] data, ref int pos, out ulong value)
    {
        value = 0;
        if (pos + 8 > data.Length)
            return false;

        value = BitConverter.ToUInt64(data, pos);
        pos += 8;
        return true;
    }

    private static bool TrySkip(byte[] data, ref int pos, int count)
    {
        if (count < 0 || pos + count > data.Length)
            return false;

        pos += count;
        return true;
    }

    private static int CountBits(ushort value) => System.Numerics.BitOperations.PopCount(value);

    private static int IndexOf(byte[] source, byte[] pattern, int start)
    {
        if (start > source.Length)
            return -1;

        var index = source.AsSpan(start).IndexOf(pattern);
        return index >= 0 ? index + start : -1;
    }
}
