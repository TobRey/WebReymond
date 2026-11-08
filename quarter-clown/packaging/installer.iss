; Inno-Setup-Skript: erzeugt QuarterClown-Setup.exe
; Installation pro Benutzer (keine Admin-Rechte nötig), Start-Menü- und Desktop-Verknüpfung.
#ifndef AppVersion
  #define AppVersion "1.0.0"
#endif

[Setup]
AppId={{8F3A6C2E-5B1D-4E7A-9C15-2D4F6A8B0C15}
AppName=Quarter Clown
AppVersion={#AppVersion}
AppPublisher=Quarter Clown
DefaultDirName={localappdata}\Programs\Quarter Clown
DefaultGroupName=Quarter Clown
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
OutputDir=..\dist
OutputBaseFilename=QuarterClown-Setup
SetupIconFile=..\quarter_clown\assets\icon.ico
UninstallDisplayIcon={app}\QuarterClown.exe
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
ArchitecturesInstallIn64BitMode=x64compatible
CloseApplications=yes

[Languages]
Name: "german"; MessagesFile: "compiler:Languages\German.isl"

[Tasks]
Name: "desktopicon"; Description: "Verknüpfung auf dem Desktop"; GroupDescription: "Zusätzlich:"

[Files]
Source: "..\dist\QuarterClown\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\Quarter Clown"; Filename: "{app}\QuarterClown.exe"
Name: "{group}\Quarter Clown – Testmodus"; Filename: "{app}\QuarterClown.exe"; Parameters: "--test"
Name: "{group}\Quarter Clown deinstallieren"; Filename: "{uninstallexe}"
Name: "{userdesktop}\Quarter Clown"; Filename: "{app}\QuarterClown.exe"; Tasks: desktopicon

[Run]
Filename: "{app}\QuarterClown.exe"; Description: "Quarter Clown jetzt starten"; Flags: nowait postinstall skipifsilent
