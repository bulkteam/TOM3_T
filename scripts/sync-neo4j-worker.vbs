' TOM3 - Neo4j Sync Worker (VBScript Wrapper)
' Startet das Batch-Script unsichtbar (keine Konsole)
' Wird vom Task Scheduler verwendet

Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

' Hole Script-Verzeichnis
scriptPath = fso.GetParentFolderName(WScript.ScriptFullName)
batchFile = scriptPath & "\sync-neo4j-worker.bat"

' Führe Batch-Script unsichtbar aus
WshShell.Run """" & batchFile & """", 0, False

' Exit-Code wird nicht zurückgegeben (VBScript-Limitierung)
' Aber das ist ok, da der Task Scheduler den Exit-Code des Batch-Scripts prüft


