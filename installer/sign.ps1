# Подписывает файлы сертификатом подписи кода, если он задан.
#   SIGNING_CERT      — .pfx в base64
#   SIGNING_PASSWORD  — пароль от .pfx
# Без сертификата скрипт ничего не делает (сборка остаётся неподписанной).
param([Parameter(Mandatory)][string[]]$Files)

if (-not $env:SIGNING_CERT) {
    Write-Host 'Сертификат подписи не задан — пропускаю подпись.'
    return
}

$pfx = Join-Path $env:RUNNER_TEMP 'signing-cert.pfx'
[IO.File]::WriteAllBytes($pfx, [Convert]::FromBase64String($env:SIGNING_CERT))
try {
    $signtool = Get-ChildItem "${env:ProgramFiles(x86)}\Windows Kits\10\bin\*\x64\signtool.exe" | Sort-Object FullName | Select-Object -Last 1
    & $signtool.FullName sign /f $pfx /p $env:SIGNING_PASSWORD /fd SHA256 /tr http://timestamp.digicert.com /td SHA256 @Files
    if ($LASTEXITCODE -ne 0) { throw "signtool завершился с кодом $LASTEXITCODE" }
}
finally {
    Remove-Item $pfx -Force
}
