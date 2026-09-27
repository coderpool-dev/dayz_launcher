namespace PrepiskaLauncher.Models;

/// <summary>Результат действия с сообщением для строки статуса.</summary>
public sealed record OperationResult(bool Ok, string Message)
{
    public static OperationResult Success(string message) => new(true, message);
    public static OperationResult Failure(string message) => new(false, message);
}
