; Установщик PREPISKA DayZ Launcher (Inno Setup 6.1+ / 7).
;
; Сборка:
;   dotnet publish src/PrepiskaLauncher/PrepiskaLauncher.csproj -c Release -o publish -p:Version=1.2.3
;   ISCC installer/PrepiskaLauncher.iss /DAppVersion=1.2.3
;
; Ставится в профиль пользователя без прав администратора. Если на компьютере нет
; .NET 8 Desktop Runtime или WebView2 Runtime — скачивает их с сайта Microsoft и устанавливает.

#ifndef AppVersion
  #define AppVersion "1.0.0"
#endif
#ifndef SourceDir
  #define SourceDir "..\publish"
#endif

#define AppName "PREPISKA DayZ Launcher"
#define AppExe "PREPISKA DayZ Launcher.exe"
#define DotNetUrl "https://aka.ms/dotnet/8.0/windowsdesktop-runtime-win-x64.exe"
#define WebView2Url "https://go.microsoft.com/fwlink/p/?LinkId=2124703"

[Setup]
AppId={{8E7A2F4C-3B1D-4C6E-9A5F-2D8B7C1E4F90}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher=PREPISKA
VersionInfoVersion={#AppVersion}
DefaultDirName={localappdata}\Programs\{#AppName}
DefaultGroupName={#AppName}
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputDir=..\artifacts
OutputBaseFilename=PREPISKA-DayZ-Launcher-Setup-{#AppVersion}
SetupIconFile=..\src\PrepiskaLauncher\Assets\app.ico
UninstallDisplayIcon={app}\{#AppExe}
UninstallDisplayName={#AppName}
WizardStyle=modern
Compression=lzma2/max
SolidCompression=yes
CloseApplications=yes

[Languages]
Name: "russian"; MessagesFile: "compiler:Languages\Russian.isl"
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"

[InstallDelete]
; При обновлении убираем старые файлы интерфейса, чтобы не оставалось удалённых ресурсов.
Type: filesandordirs; Name: "{app}\Ui"

[Files]
Source: "{#SourceDir}\*"; DestDir: "{app}"; Excludes: "*.pdb,Microsoft.Web.WebView2.*.xml"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{autoprograms}\{#AppName}"; Filename: "{app}\{#AppExe}"
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#AppExe}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#AppExe}"; Description: "{cm:LaunchProgram,{#AppName}}"; Flags: nowait postinstall skipifsilent

[UninstallDelete]
Type: filesandordirs; Name: "{app}"

[CustomMessages]
russian.PrerequisitesTitle=Установка компонентов
russian.PrerequisitesDescription=Скачиваются компоненты Microsoft, необходимые для работы лаунчера.
russian.InstallingDotNet=Устанавливается .NET 8 Desktop Runtime...
russian.InstallingWebView2=Устанавливается Microsoft Edge WebView2 Runtime...
russian.PrerequisiteFailed=Не удалось установить %1.%n%nУстановите его вручную и запустите установку ещё раз:%n%2
english.PrerequisitesTitle=Installing components
english.PrerequisitesDescription=Downloading Microsoft components required by the launcher.
english.InstallingDotNet=Installing .NET 8 Desktop Runtime...
english.InstallingWebView2=Installing Microsoft Edge WebView2 Runtime...
english.PrerequisiteFailed=Failed to install %1.%n%nPlease install it manually and run the setup again:%n%2

[Code]
var
  DownloadPage: TDownloadWizardPage;

{ .NET 8 Desktop Runtime: ищем папку shared\Microsoft.WindowsDesktop.App\8.* — реестр для этого ненадёжен. }
function IsDotNetDesktop8Installed: Boolean;
var
  FindRec: TFindRec;
begin
  Result := FindFirst(ExpandConstant('{commonpf64}\dotnet\shared\Microsoft.WindowsDesktop.App\8.*'), FindRec);
  if Result then
    FindClose(FindRec);
end;

function HasWebView2Version(RootKey: Integer; const SubKey: String): Boolean;
var
  Version: String;
begin
  Result := RegQueryStringValue(RootKey, SubKey, 'pv', Version) and (Version <> '') and (Version <> '0.0.0.0');
end;

{ Проверка по документации Microsoft: версия Evergreen Runtime в ключе EdgeUpdate\Clients. }
function IsWebView2Installed: Boolean;
var
  ClientKey: String;
begin
  ClientKey := 'Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}';
  Result := HasWebView2Version(HKLM, 'SOFTWARE\WOW6432Node\' + ClientKey)
    or HasWebView2Version(HKLM, 'SOFTWARE\' + ClientKey)
    or HasWebView2Version(HKCU, 'Software\' + ClientKey);
end;

procedure InitializeWizard;
begin
  DownloadPage := CreateDownloadPage(CustomMessage('PrerequisitesTitle'), CustomMessage('PrerequisitesDescription'), nil);
end;

{ Запускает установщик компонента. ShellExec нужен, чтобы сработал запрос UAC (установщик .NET требует прав администратора). }
function RunPrerequisite(const FileName, Params: String): Boolean;
var
  ResultCode: Integer;
begin
  Result := ShellExec('', ExpandConstant('{tmp}\') + FileName, Params, '', SW_SHOW, ewWaitUntilTerminated, ResultCode)
    and ((ResultCode = 0) or (ResultCode = 3010));
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  NeedDotNet, NeedWebView2: Boolean;
begin
  Result := True;
  if CurPageID <> wpReady then
    Exit;

  NeedDotNet := not IsDotNetDesktop8Installed;
  NeedWebView2 := not IsWebView2Installed;
  if not NeedDotNet and not NeedWebView2 then
    Exit;

  DownloadPage.Clear;
  if NeedDotNet then
    DownloadPage.Add('{#DotNetUrl}', 'windowsdesktop-runtime-8-win-x64.exe', '');
  if NeedWebView2 then
    DownloadPage.Add('{#WebView2Url}', 'MicrosoftEdgeWebview2Setup.exe', '');

  DownloadPage.Show;
  try
    try
      DownloadPage.Download;

      if NeedDotNet then
      begin
        DownloadPage.SetText(CustomMessage('InstallingDotNet'), '');
        if not RunPrerequisite('windowsdesktop-runtime-8-win-x64.exe', '/install /quiet /norestart')
          or not IsDotNetDesktop8Installed then
        begin
          SuppressibleMsgBox(FmtMessage(CustomMessage('PrerequisiteFailed'), ['.NET 8 Desktop Runtime', 'https://dotnet.microsoft.com/download/dotnet/8.0']), mbError, MB_OK, IDOK);
          Result := False;
          Exit;
        end;
      end;

      if NeedWebView2 then
      begin
        DownloadPage.SetText(CustomMessage('InstallingWebView2'), '');
        if not RunPrerequisite('MicrosoftEdgeWebview2Setup.exe', '/silent /install')
          or not IsWebView2Installed then
        begin
          SuppressibleMsgBox(FmtMessage(CustomMessage('PrerequisiteFailed'), ['Microsoft Edge WebView2 Runtime', 'https://developer.microsoft.com/microsoft-edge/webview2/']), mbError, MB_OK, IDOK);
          Result := False;
          Exit;
        end;
      end;
    except
      if DownloadPage.AbortedByUser then
        Log('Загрузка компонентов отменена пользователем.')
      else
        SuppressibleMsgBox(AddPeriod(GetExceptionMessage), mbCriticalError, MB_OK, IDOK);
      Result := False;
    end;
  finally
    DownloadPage.Hide;
  end;
end;
