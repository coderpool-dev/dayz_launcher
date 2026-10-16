namespace PrepiskaLauncher.Models;

public sealed record ServerPopulation
{
    public bool Ready { get; init; }
    public double Coverage { get; init; }
    public double Average { get; init; }
    public double ActivePercent { get; init; }
    public double? Day { get; init; }
    public double? Night { get; init; }
    public double? DayAverage { get; init; }
    public double? NightAverage { get; init; }
}

public sealed record PopulationPoint(long Timestamp, double? Players, int Samples);
public sealed record PopulationSeries(List<PopulationPoint> Points);
