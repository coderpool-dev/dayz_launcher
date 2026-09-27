using System.Text;
using PrepiskaLauncher.Services;

namespace PrepiskaLauncher.Tests;

public class A2sRulesParserTests
{
    [Fact]
    public void ParsesCompactA3sbModList()
    {
        var payload = new List<byte> { 2, 0, 0, 0, 2 }; // версия, 3 байта метаданных, 2 мода
        payload.AddRange(ModRecord(1559212036, "CF"));
        payload.AddRange(ModRecord(2017015030, "PODPIVAS"));

        var mods = A2sRulesService.ParseModsFromRules(RulesPacket(payload.ToArray()));

        Assert.Equal(["1559212036", "2017015030"], mods.Select(m => m.Id));
        Assert.Equal(["CF", "PODPIVAS"], mods.Select(m => m.Name));
    }

    [Fact]
    public void SkipsDuplicateAndTooSmallIds()
    {
        var payload = new List<byte> { 2, 0, 0, 0, 3 };
        payload.AddRange(ModRecord(1559212036, "CF"));
        payload.AddRange(ModRecord(1559212036, "CF"));
        payload.AddRange(ModRecord(12345, "Tiny"));

        var mods = A2sRulesService.ParseModsFromRules(RulesPacket(payload.ToArray()));

        Assert.Equal(["1559212036"], mods.Select(m => m.Id));
    }

    [Theory]
    [InlineData(null)]
    [InlineData(new byte[] { 0xFF, 0xFF, 0xFF, 0xFF, 0x41, 0, 0, 0, 0, 0 })]
    public void InvalidPacket_ReturnsEmpty(byte[]? packet)
    {
        Assert.Empty(A2sRulesService.ParseModsFromRules(packet));
    }

    /// <summary>Запись мода: 4 байта хэша, длина ID = 4, uint32 ID, длина имени, имя.</summary>
    private static byte[] ModRecord(uint id, string name)
    {
        var nameBytes = Encoding.UTF8.GetBytes(name);
        return [9, 9, 9, 9, 4, .. BitConverter.GetBytes(id), (byte)nameBytes.Length, .. nameBytes];
    }

    /// <summary>Ответ A2S_RULES с одним правилом-страницей A3SB (ключ из двух байт, экранирование 0x00/0x01).</summary>
    private static byte[] RulesPacket(byte[] a3sbPayload)
    {
        var escaped = new List<byte>();
        foreach (var b in a3sbPayload)
        {
            if (b == 0) escaped.AddRange([1, 1]);
            else if (b == 1) escaped.AddRange([1, 2]);
            else escaped.Add(b);
        }

        var packet = new List<byte> { 0xFF, 0xFF, 0xFF, 0xFF, 0x45 };
        packet.AddRange(BitConverter.GetBytes((ushort)1));
        packet.AddRange([1, 1, 0]); // ключ страницы + терминатор
        packet.AddRange(escaped);
        packet.Add(0);
        return packet.ToArray();
    }
}
