' TOM3 - ClamAV Scan Worker (VBScript Wrapper)
' Startet das PHP-Script unsichtbar (keine Konsole)

Set fso = CreateObject("Scripting.FileSystemObject")
Set WshShell = CreateObject("WScript.Shell")

' Projekt-Root ermitteln (ein Verzeichnis über scripts)
scriptPath = fso.GetParentFolderName(WScript.ScriptFullName)
projectRoot = fso.GetParentFolderName(scriptPath)

' PHP-Pfad (versuche verschiedene Möglichkeiten)
phpExe = "php.exe"
phpPaths = Array( _
    "C:\xampp\php\php.exe", _
    projectRoot & "\php\php.exe", _
    "php.exe" _
)

For Each path In phpPaths
    If fso.FileExists(path) Then
        phpExe = path
        Exit For
    End If
Next

' Worker-Script-Pfad (VBScript liegt in scripts/, Worker liegt in scripts/jobs/)
workerScript = scriptPath & "\jobs\scan-blob-worker.php"

' Führe PHP-Script unsichtbar aus
WshShell.Run """" & phpExe & """ """ & workerScript & """", 0, False

' Exit-Code wird nicht zurückgegeben (VBScript-Limitierung)
' Aber das Script läuft unsichtbar im Hintergrund


